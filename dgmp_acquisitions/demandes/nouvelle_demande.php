<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo     = getConnexion();
$success = '';
$erreur  = '';

// Récupérer matériels
$materiels = $pdo->query("
    SELECT m.*, c.nom_categorie
    FROM materiels m
    JOIN categories_materiel c
        ON m.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, m.nom_materiel
")->fetchAll();

// Grouper par catégorie
$categories = [];
foreach ($materiels as $m) {
    $categories[$m['nom_categorie']][] = $m;
}

// ============================================
// TRAITEMENT FORMULAIRE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $departement  = trim($_POST['departement'] ?? '');
    $motif        = trim($_POST['motif']       ?? '');
    $priorite     = $_POST['priorite']         ?? 'normale';
    $mat_selected = $_POST['materiels']        ?? [];

    // Articles valides (quantité seulement)
    $articles_valides = [];
    foreach ($mat_selected as $id_mat => $detail) {
        if (!empty($detail['quantite']) &&
            (int)$detail['quantite'] > 0) {
            $articles_valides[$id_mat] = $detail;
        }
    }

    // Validations
    if (empty($departement)) {
        $erreur = "Le département est obligatoire.";
    } elseif (empty($motif)) {
        $erreur = "Le motif est obligatoire.";
    } elseif (empty($articles_valides)) {
        $erreur = "Sélectionnez au moins un matériel.";
    } else {

        try {
            $pdo->beginTransaction();

            // Référence unique
            $ref_demande = 'DAI-' . date('Y') . '-' .
                str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // INSERT demande SANS fournisseur SANS budget
            $stmt = $pdo->prepare("
                INSERT INTO demandes_acquisition
                (reference_demande, id_utilisateur,
                 departement_demandeur, motif,
                 priorite, statut)
                VALUES
                (:ref, :id, :dept, :motif,
                 :prior, 'en_attente')
                RETURNING id_demande
            ");
            $stmt->execute([
                ':ref'   => $ref_demande,
                ':id'    => $_SESSION['id_utilisateur'],
                ':dept'  => $departement,
                ':motif' => $motif,
                ':prior' => $priorite
            ]);
            $id_demande = $stmt->fetch()['id_demande'];

            // Insérer détails SANS prix
            // Le prix sera saisi par le validateur
            foreach ($articles_valides as $id_mat => $detail) {
                $pdo->prepare("
                    INSERT INTO details_demande
                    (id_demande, id_materiel,
                     quantite, prix_unitaire_estime)
                    VALUES (:id_d, :id_m, :qte, NULL)
                ")->execute([
                    ':id_d' => $id_demande,
                    ':id_m' => (int)$id_mat,
                    ':qte'  => (int)$detail['quantite']
                ]);
            }

            // Notifier responsables
            $responsables = $pdo->query("
                SELECT id_utilisateur FROM utilisateurs
                WHERE role = 'responsable'
                AND statut = TRUE
            ")->fetchAll();

            foreach ($responsables as $r) {
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'info')
                ")->execute([
                    ':id'  => $r['id_utilisateur'],
                    ':msg' => "Nouvelle demande " .
                              $ref_demande .
                              " en attente de validation."
                ]);
            }

            // Log
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action,
                 table_concernee, id_enregistrement)
                VALUES (:id, :action,
                        'demandes_acquisition', :id_enreg)
            ")->execute([
                ':id'      => $_SESSION['id_utilisateur'],
                ':action'  => "Creation demande " . $ref_demande,
                ':id_enreg'=> $id_demande
            ]);

            $pdo->commit();

            header('Location: index.php?success=' . urlencode(
                "Demande " . $ref_demande .
                " soumise ! En attente de validation."
            ));
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Demande — DGMP</title>
    <link rel="icon" href="../assets/images/logo_dgmp.png">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>➕ Nouvelle Demande d'Acquisition</h1>
            </div>
            <a href="index.php" class="btn btn-secondary">
                ← Retour
            </a>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger">
                ⚠️ <?= $erreur ?>
            </div>
        <?php endif; ?>

        <!-- Workflow -->

        <!-- Info -->
        <div class="alert alert-info">
            ℹ️ <strong>Information :</strong>
            Vous choisissez uniquement les matériels et les quantités.
            Le <strong>fournisseur</strong> et les
            <strong>prix unitaires</strong> seront définis
            par le validateur selon le fournisseur choisi.
        </div>

        <form method="POST" action="" id="formDemande">

            <!-- SECTION 1 : Infos -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>📌 Informations Générales</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>🏢 Département *</label>
                            <input type="text"
                                   name="departement"
                                   class="form-control"
                                   value="<?= clean(
                                       $_POST['departement'] ??
                                       $_SESSION['utilisateur']['departement'] ??
                                       ''
                                   ) ?>"
                                   placeholder="Ex: Direction Informatique"
                                   required>
                        </div>
                        <div class="form-group col-6">
                            <label>⚡ Priorité *</label>
                            <select name="priorite"
                                    class="form-control" required>
                                <option value="normale"
                                    <?= (($_POST['priorite'] ?? 'normale') === 'normale') ? 'selected' : '' ?>>
                                    🟢 Normale
                                </option>
                                <option value="haute"
                                    <?= (($_POST['priorite'] ?? '') === 'haute') ? 'selected' : '' ?>>
                                    🟠 Haute
                                </option>
                                <option value="urgente"
                                    <?= (($_POST['priorite'] ?? '') === 'urgente') ? 'selected' : '' ?>>
                                    🔴 Urgente
                                </option>
                                <option value="basse"
                                    <?= (($_POST['priorite'] ?? '') === 'basse') ? 'selected' : '' ?>>
                                    ⚪ Basse
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>📝 Motif / Justification *</label>
                        <textarea name="motif"
                                  class="form-control"
                                  rows="3"
                                  placeholder="Expliquez le besoin..."
                                  required><?= clean($_POST['motif'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- SECTION 2 : Matériels -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>🖥️ Matériels à Acquérir *</h2>
                    <span class="badge bg-info" id="nb_sel">
                        0 sélectionné(s)
                    </span>
                </div>
                <div class="card-body">

                    <?php if (empty($materiels)): ?>
                    <div class="alert alert-danger">
                        ❌ Aucun matériel disponible !
                    </div>
                    <?php else: ?>

                    <?php foreach ($categories as $categorie => $mats): ?>
                    <div class="categorie-section">
                        <h4 class="categorie-title">
                            📁 <?= clean($categorie) ?>
                        </h4>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th width="40">✓</th>
                                    <th>Matériel</th>
                                    <th>Spécifications</th>
                                    <th width="120">Quantité *</th>
                                    <th width="180">Prix Unitaire</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mats as $m): ?>
                                <tr id="row_<?= $m['id_materiel'] ?>">
                                    <td>
                                        <input type="checkbox"
                                               class="materiel-check"
                                               data-id="<?= $m['id_materiel'] ?>"
                                               onchange="toggleRow(this)">
                                    </td>
                                    <td>
                                        <strong>
                                            <?= clean($m['nom_materiel']) ?>
                                        </strong>
                                        <br>
                                        <small class="text-muted">
                                            <?= clean($m['unite_mesure'] ?? 'Unite') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            <?= clean($m['specification_technique'] ?? '—') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <!-- Quantité MODIFIABLE -->
                                        <input type="number"
                                               name="materiels[<?= $m['id_materiel'] ?>][quantite]"
                                               id="qte_<?= $m['id_materiel'] ?>"
                                               class="form-control input-sm"
                                               min="1" value="1"
                                               disabled>
                                    </td>
                                    <td>
                                        <!-- Prix NON MODIFIABLE -->
                                        <div style="background:#f5f5f5;
                                                    border:2px dashed #ccc;
                                                    border-radius:8px;
                                                    padding:6px 12px;
                                                    color:#757575;
                                                    font-size:13px;
                                                    text-align:center">
                                            🟣 Défini par le validateur
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>

                    <?php endif; ?>
                </div>
            </div>

            <!-- Boutons -->
            <div class="form-actions-bottom">
                <a href="index.php" class="btn btn-secondary">
                    ← Annuler
                </a>
                <button type="submit"
                        class="btn btn-primary btn-lg">
                    📤 Soumettre la Demande
                </button>
            </div>

        </form>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
var nbSel = 0;

function toggleRow(checkbox) {
    var id  = checkbox.dataset.id;
    var row = document.getElementById('row_' + id);
    var qte = document.getElementById('qte_' + id);

    if (checkbox.checked) {
        nbSel++;
        row.style.background = '#e8f5e9';
        qte.disabled = false;
        qte.value    = 1;
    } else {
        nbSel--;
        row.style.background = '';
        qte.disabled = true;
        qte.value    = '';
    }
    document.getElementById('nb_sel').textContent =
        nbSel + ' sélectionné(s)';
}

document.getElementById('formDemande').addEventListener('submit', function(e) {
    var dept    = document.querySelector('[name="departement"]').value.trim();
    var motif   = document.querySelector('[name="motif"]').value.trim();
    var checked = document.querySelectorAll('.materiel-check:checked');

    if (!dept) {
        e.preventDefault();
        alert('Département obligatoire !');
        return;
    }
    if (!motif) {
        e.preventDefault();
        alert('Motif obligatoire !');
        return;
    }
    if (checked.length === 0) {
        e.preventDefault();
        alert('Sélectionnez au moins un matériel !');
        return;
    }
});
</script>
</body>
</html>