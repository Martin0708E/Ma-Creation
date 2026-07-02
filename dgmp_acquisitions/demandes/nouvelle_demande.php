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

// Récupérer catégories
$categories_list = $pdo->query("
    SELECT * FROM categories_materiel
    ORDER BY nom_categorie
")->fetchAll();

// Grouper matériels par catégorie
$categories = [];
foreach ($materiels as $m) {
    $categories[$m['nom_categorie']][] = $m;
}

// ============================================
// TRAITEMENT AJOUT MATÉRIEL PAR L'UTILISATEUR
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'ajouter_materiel') {

    $nom_mat  = trim($_POST['nouveau_nom_materiel']  ?? '');
    $cat_mat  = (int)($_POST['nouvelle_categorie']   ?? 0);
    $spec_mat = trim($_POST['nouvelle_specification'] ?? '');
    $unite_mat = trim($_POST['nouvelle_unite']        ?? 'Unite');

    if (empty($nom_mat) || !$cat_mat) {
        $erreur = "Nom et catégorie du matériel obligatoires !";
    } else {
        try {
            $pdo->prepare("
                INSERT INTO materiels
                (id_categorie, nom_materiel,
                 specification_technique, unite_mesure)
                VALUES (:cat, :nom, :spec, :unite)
            ")->execute([
                ':cat'   => $cat_mat,
                ':nom'   => $nom_mat,
                ':spec'  => !empty($spec_mat) ? $spec_mat : null,
                ':unite' => $unite_mat
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Ajout materiel par utilisateur: " . $nom_mat,
                'materiels');

            // Recharger la page pour voir le nouveau matériel
            header('Location: nouvelle_demande.php?mat_ajoute=' .
                urlencode($nom_mat));
            exit();

        } catch (Exception $e) {
            $erreur = "Erreur ajout matériel : " . $e->getMessage();
        }
    }
}

// Message matériel ajouté
$mat_ajoute = isset($_GET['mat_ajoute'])
              ? htmlspecialchars($_GET['mat_ajoute']) : '';

// ============================================
// TRAITEMENT DEMANDE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (!isset($_POST['action'])
        || $_POST['action'] !== 'ajouter_materiel')) {

    $departement  = trim($_POST['departement'] ?? '');
    $motif        = trim($_POST['motif']       ?? '');
    $priorite     = $_POST['priorite']         ?? 'normale';
    $mat_selected = $_POST['materiels']        ?? [];

    // Articles valides
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

            // INSERT demande
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

            // Insérer détails
            foreach ($articles_valides as $id_mat => $detail) {
                $pdo->prepare("
                    INSERT INTO details_demande
                    (id_demande, id_materiel, quantite)
                    VALUES (:id_d, :id_m, :qte)
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

// Recharger matériels après ajout
$materiels = $pdo->query("
    SELECT m.*, c.nom_categorie
    FROM materiels m
    JOIN categories_materiel c
        ON m.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, m.nom_materiel
")->fetchAll();

$categories = [];
foreach ($materiels as $m) {
    $categories[$m['nom_categorie']][] = $m;
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
                <p class="page-subtitle">
                    Le fournisseur et les prix seront
                    définis par le validateur
                </p>
            </div>
            <a href="index.php" class="btn btn-secondary">
                ← Retour
            </a>
        </div>

        <?php if ($erreur): ?>
        <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <?php if ($mat_ajoute): ?>
        <div class="alert alert-success">
            ✅ Matériel "<strong><?= $mat_ajoute ?></strong>"
            ajouté avec succès !
            Vous pouvez maintenant le sélectionner.
        </div>
        <?php endif; ?>

        <!-- Workflow -->

        <!-- Info -->
        <div class="alert alert-info">
            ℹ️ Le <strong>fournisseur</strong> et les
            <strong>prix</strong> seront définis par le validateur.
            Choisissez uniquement les
            <strong>matériels</strong> et les
            <strong>quantités</strong>.
            <br>
            📦 Si le matériel que vous cherchez n'existe pas,
            cliquez sur
            "<strong>➕ Ajouter un nouveau matériel</strong>".
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
                                    class="form-control"
                                    required>
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
                    <div class="header-right">
                        <span class="badge bg-info" id="nb_sel">
                            0 sélectionné(s)
                        </span>
                        <!-- BOUTON AJOUTER MATÉRIEL -->
                        <button type="button"
                                onclick="ouvrirModal('modalAjouterMateriel')"
                                class="btn btn-sm btn-success">
                            ➕ Nouveau Matériel
                        </button>
                    </div>
                </div>
                <div class="card-body">

                    <div class="alert alert-warning"
                         style="margin-bottom:14px">
                        ⚠️ Choisissez les matériels et les quantités.
                        <br>
                        📦 Matériel introuvable ?
                        → Cliquez sur
                        "<strong>➕ Nouveau Matériel</strong>"
                        pour l'ajouter !
                    </div>

                    <?php if (empty($materiels)): ?>
                    <div class="alert alert-danger">
                        ❌ Aucun matériel disponible !
                        <button type="button"
                                onclick="ouvrirModal('modalAjouterMateriel')"
                                class="btn btn-sm btn-success"
                                style="margin-left:10px">
                            ➕ Ajouter un matériel
                        </button>
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
                                    <th width="110">Quantité *</th>
                                    <th>Prix</th>
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
                                        <input type="number"
                                               name="materiels[<?= $m['id_materiel'] ?>][quantite]"
                                               id="qte_<?= $m['id_materiel'] ?>"
                                               class="form-control input-sm"
                                               min="1" value="1"
                                               disabled>
                                    </td>
                                    <td>
                                        <div style="background:#f3e5f5;
                                                    border:2px solid #6a1b9a;
                                                    border-radius:8px;
                                                    padding:4px 10px;
                                                    font-size:11px;
                                                    color:#6a1b9a;
                                                    font-weight:700">
                                            🟣 Défini par validateur
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

<!-- ============================================ -->
<!-- MODAL : AJOUTER NOUVEAU MATÉRIEL             -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalAjouterMateriel">
    <div class="modal-box">
        <div class="modal-header"
             style="background:linear-gradient(135deg,#e8f5e9,#c8e6c9)">
            <h3 style="color:#2e7d32">
                ➕ Ajouter un Nouveau Matériel
            </h3>
            <button onclick="fermerModal('modalAjouterMateriel')"
                    class="modal-close">✕</button>
        </div>

        <!-- Formulaire séparé pour l'ajout matériel -->
        <form method="POST" action=""
              id="formAjouterMateriel">
            <input type="hidden"
                   name="action"
                   value="ajouter_materiel">

            <div class="modal-body">

                <div class="form-group">
                    <label>📦 Catégorie *</label>
                    <select name="nouvelle_categorie"
                            class="form-control"
                            required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($categories_list as $cat): ?>
                        <option value="<?= $cat['id_categorie'] ?>">
                            <?= clean($cat['nom_categorie']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>🖥️ Nom du Matériel *</label>
                    <input type="text"
                           name="nouveau_nom_materiel"
                           class="form-control"
                           placeholder="Ex: Scanner HP ScanJet Pro 3500"
                           required>
                </div>

                <div class="form-group">
                    <label>⚙️ Spécifications Techniques</label>
                    <textarea name="nouvelle_specification"
                              class="form-control"
                              rows="2"
                              placeholder="Ex: A4, USB 3.0, 25ppm, Recto-verso">
                    </textarea>
                </div>

                <div class="form-group">
                    <label>📏 Unité de Mesure</label>
                    <select name="nouvelle_unite"
                            class="form-control">
                        <option value="Unite">Unité</option>
                        <option value="Licence">Licence</option>
                        <option value="Boite">Boîte</option>
                        <option value="Lot">Lot</option>
                        <option value="Bobine">Bobine</option>
                        <option value="Metre">Mètre</option>
                    </select>
                </div>

                <div class="alert alert-info"
                     style="margin-top:8px">
                    ℹ️ Le <strong>prix</strong> sera défini par
                    le <strong>validateur</strong> lors de la
                    validation de la commande.
                </div>

            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalAjouterMateriel')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit"
                        class="btn btn-success">
                    ➕ Ajouter ce Matériel
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
var nbSel = 0;

// Ouvrir/fermer modal
function ouvrirModal(id) {
    document.getElementById(id).classList.add('show');
}
function fermerModal(id) {
    document.getElementById(id).classList.remove('show');
}
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) fermerModal(this.id);
    });
});

// Toggle ligne matériel
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

// Validation formulaire demande
document.getElementById('formDemande')
        .addEventListener('submit', function(e) {
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
        alert('Sélectionnez au moins un matériel !\n\n' +
              'Si le matériel n\'existe pas, cliquez sur ' +
              '"➕ Nouveau Matériel" pour l\'ajouter.');
        return;
    }
});

// Validation formulaire ajout matériel
document.getElementById('formAjouterMateriel')
        .addEventListener('submit', function(e) {
    var nom = this.querySelector('[name="nouveau_nom_materiel"]').value.trim();
    var cat = this.querySelector('[name="nouvelle_categorie"]').value;

    if (!nom) {
        e.preventDefault();
        alert('Le nom du matériel est obligatoire !');
        return;
    }
    if (!cat) {
        e.preventDefault();
        alert('Choisissez une catégorie !');
        return;
    }
});
</script>
</body>
</html>