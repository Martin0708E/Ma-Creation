<?php
// ============================================
// DÉMARRER SESSION EN PREMIER
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// INCLURE LES FICHIERS NÉCESSAIRES
// ============================================
require_once '../config/database.php';
require_once '../includes/functions.php';

// ============================================
// VÉRIFIER CONNEXION
// ============================================
verifierConnexion();

// ============================================
// CONNEXION BASE DE DONNÉES
// ============================================
$pdo     = getConnexion(); // ← ICI C'EST LE PROBLÈME QUI MANQUAIT
$success = '';
$erreur  = '';

// ============================================
// RÉCUPÉRER LES MATÉRIELS
// ============================================
$materiels = $pdo->query("
    SELECT m.*, c.nom_categorie
    FROM materiels m
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, m.nom_materiel
")->fetchAll();

// ============================================
// RÉCUPÉRER LES FOURNISSEURS ACTIFS
// ============================================
$fournisseurs = $pdo->query("
    SELECT * FROM fournisseurs
    WHERE statut = 'actif'
    ORDER BY nom_entreprise
")->fetchAll();

// ============================================
// GROUPER MATÉRIELS PAR CATÉGORIE
// ============================================
$categories = [];
foreach ($materiels as $m) {
    $categories[$m['nom_categorie']][] = $m;
}

// ============================================
// TRAITEMENT FORMULAIRE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $departement    = trim($_POST['departement']         ?? '');
    $motif          = trim($_POST['motif']               ?? '');
    $priorite       = $_POST['priorite']                 ?? 'normale';
    $budget         = $_POST['budget_estime']            ?? null;
    $id_fournisseur = (int)($_POST['id_fournisseur']     ?? 0);
    $date_livraison = $_POST['date_livraison_prevue']    ?? null;
    $mat_selected   = $_POST['materiels']                ?? [];

    // Articles valides
    $articles_valides = [];
    foreach ($mat_selected as $id_mat => $detail) {
        if (!empty($detail['quantite']) &&
            (int)$detail['quantite'] > 0) {
            $articles_valides[$id_mat] = $detail;
        }
    }

    // ---- Validations ----
    if (empty($departement)) {
        $erreur = "Le département est obligatoire.";
    } elseif (empty($motif)) {
        $erreur = "Le motif est obligatoire.";
    } elseif (!$id_fournisseur) {
        $erreur = "Veuillez choisir un fournisseur.";
    } elseif (empty($articles_valides)) {
        $erreur = "Sélectionnez au moins un matériel.";
    } else {

        try {
            $pdo->beginTransaction();

            // Calculer budget total
            $budget_total = 0;
            foreach ($articles_valides as $id_mat => $detail) {
                // Récupérer le prix depuis la BD (pas depuis le formulaire)
                $stmt_prix = $pdo->prepare("
                    SELECT prix_unitaire FROM materiels
                    WHERE id_materiel = :id
                ");
                $stmt_prix->execute([':id' => $id_mat]);
                $prix_unitaire = $stmt_prix->fetch()['prix_unitaire'] ?? 0;
                $budget_total += ((int)$detail['quantite'] * (float)$prix_unitaire);
            }

            // Référence unique
            $ref_demande = 'DAI-' . date('Y') . '-' .
                str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Vérifier colonnes disponibles
            $check_col = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = 'demandes_acquisition'
                AND column_name = 'id_fournisseur'
            ");
            $check_col->execute();
            $has_fournisseur = $check_col->fetch() ? true : false;

            $check_date = $pdo->prepare("
                SELECT column_name
                FROM information_schema.columns
                WHERE table_name = 'demandes_acquisition'
                AND column_name = 'date_livraison_prevue'
            ");
            $check_date->execute();
            $has_date_liv = $check_date->fetch() ? true : false;

            // ============================================
            // INSERT DEMANDE SELON COLONNES DISPONIBLES
            // ============================================
            if ($has_fournisseur && $has_date_liv) {

                $stmt = $pdo->prepare("
                    INSERT INTO demandes_acquisition
                    (reference_demande, id_utilisateur,
                     departement_demandeur, motif, priorite,
                     budget_estime, statut,
                     id_fournisseur, date_livraison_prevue)
                    VALUES
                    (:ref, :id, :dept, :motif, :prior,
                     :budget, 'en_attente',
                     :fourn, :date_liv)
                    RETURNING id_demande
                ");
                $stmt->execute([
                    ':ref'      => $ref_demande,
                    ':id'       => $_SESSION['id_utilisateur'],
                    ':dept'     => $departement,
                    ':motif'    => $motif,
                    ':prior'    => $priorite,
                    ':budget'   => $budget_total > 0
                                   ? $budget_total : null,
                    ':fourn'    => $id_fournisseur,
                    ':date_liv' => !empty($date_livraison)
                                   ? $date_livraison : null
                ]);

            } elseif ($has_fournisseur) {

                $stmt = $pdo->prepare("
                    INSERT INTO demandes_acquisition
                    (reference_demande, id_utilisateur,
                     departement_demandeur, motif, priorite,
                     budget_estime, statut, id_fournisseur)
                    VALUES
                    (:ref, :id, :dept, :motif, :prior,
                     :budget, 'en_attente', :fourn)
                    RETURNING id_demande
                ");
                $stmt->execute([
                    ':ref'    => $ref_demande,
                    ':id'     => $_SESSION['id_utilisateur'],
                    ':dept'   => $departement,
                    ':motif'  => $motif,
                    ':prior'  => $priorite,
                    ':budget' => $budget_total > 0
                                 ? $budget_total : null,
                    ':fourn'  => $id_fournisseur
                ]);

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO demandes_acquisition
                    (reference_demande, id_utilisateur,
                     departement_demandeur, motif, priorite,
                     budget_estime, statut)
                    VALUES
                    (:ref, :id, :dept, :motif, :prior,
                     :budget, 'en_attente')
                    RETURNING id_demande
                ");
                $stmt->execute([
                    ':ref'    => $ref_demande,
                    ':id'     => $_SESSION['id_utilisateur'],
                    ':dept'   => $departement,
                    ':motif'  => $motif,
                    ':prior'  => $priorite,
                    ':budget' => $budget_total > 0
                                 ? $budget_total : null
                ]);
            }

            $id_demande = $stmt->fetch()['id_demande'];

            // ============================================
            // INSÉRER DÉTAILS DEMANDE
            // ============================================
            foreach ($articles_valides as $id_mat => $detail) {

                // Récupérer prix depuis BD
                $stmt_p = $pdo->prepare("
                    SELECT prix_unitaire FROM materiels
                    WHERE id_materiel = :id
                ");
                $stmt_p->execute([':id' => $id_mat]);
                $prix = $stmt_p->fetch()['prix_unitaire'] ?? 0;

                $pdo->prepare("
                    INSERT INTO details_demande
                    (id_demande, id_materiel,
                     quantite, prix_unitaire_estime)
                    VALUES (:id_d, :id_m, :qte, :prix)
                ")->execute([
                    ':id_d' => $id_demande,
                    ':id_m' => (int)$id_mat,
                    ':qte'  => (int)$detail['quantite'],
                    ':prix' => (float)$prix
                ]);
            }

            // ============================================
            // NOTIFIER LE RESPONSABLE
            // ============================================
            $responsables = $pdo->query("
                SELECT id_utilisateur FROM utilisateurs
                WHERE role = 'responsable' AND statut = TRUE
            ")->fetchAll();

            foreach ($responsables as $r) {
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'info')
                ")->execute([
                    ':id'  => $r['id_utilisateur'],
                    ':msg' => "📋 Nouvelle demande " .
                              $ref_demande .
                              " en attente de votre validation."
                ]);
            }

            // ============================================
            // LOG
            // ============================================
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action,
                 table_concernee, id_enregistrement)
                VALUES (:id, :action,
                        'demandes_acquisition', :id_enreg)
            ")->execute([
                ':id'      => $_SESSION['id_utilisateur'],
                ':action'  => "Création demande " . $ref_demande,
                ':id_enreg'=> $id_demande
            ]);

            $pdo->commit();

            // Rediriger vers liste demandes
            header('Location: index.php?success=' . urlencode(
                "✅ Demande " . $ref_demande .
                " soumise ! En attente de validation du responsable."
            ));
            exit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            $erreur = "❌ Erreur base de données : " .
                      $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "❌ Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acquisitions Informatiques</title>
    <!-- FAVICON -->
    <?php include '../includes/head.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php include '../includes/pwa_head.php'; ?>
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
                    La commande sera créée après validation
                    du responsable
                </p>
            </div>
            <a href="index.php" class="btn btn-secondary">
                ← Retour
            </a>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- Workflow -->

        <form method="POST" action="" id="formDemande">

            <!-- SECTION 1 : Infos générales -->
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
                                    <?= (($_POST['priorite'] ?? 'normale') === 'normale')
                                        ? 'selected' : '' ?>>
                                    🟢 Normale
                                </option>
                                <option value="haute"
                                    <?= (($_POST['priorite'] ?? '') === 'haute')
                                        ? 'selected' : '' ?>>
                                    🟠 Haute
                                </option>
                                <option value="urgente"
                                    <?= (($_POST['priorite'] ?? '') === 'urgente')
                                        ? 'selected' : '' ?>>
                                    🔴 Urgente
                                </option>
                                <option value="basse"
                                    <?= (($_POST['priorite'] ?? '') === 'basse')
                                        ? 'selected' : '' ?>>
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
                                  required>
                            <?= clean($_POST['motif'] ?? '') ?>
                        </textarea>
                    </div>

                    <div class="form-group col-4">
                        <label>💰 Budget Estimé (FCFA)</label>
                        <input type="number"
                               name="budget_estime"
                               class="form-control"
                               placeholder="Ex: 5000000"
                               min="0"
                               value="<?= clean($_POST['budget_estime'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- SECTION 2 : Fournisseur -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>🏢 Fournisseur *</h2>
                </div>
                <div class="card-body">

                    <?php if (empty($fournisseurs)): ?>
                    <div class="alert alert-danger">
                        ❌ Aucun fournisseur actif trouvé !
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                        <a href="../fournisseurs/ajouter.php">
                            → Ajouter un fournisseur
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>

                    <div class="form-group">
                        <label>Sélectionnez un Fournisseur *</label>
                        <select name="id_fournisseur"
                                id="selectFournisseur"
                                class="form-control"
                                onchange="afficherFournisseur(this)"
                                required>
                            <option value="">-- Choisir --</option>
                            <?php foreach ($fournisseurs as $f): ?>
                            <option value="<?= $f['id_fournisseur'] ?>"
                                    data-email="<?= clean($f['email']) ?>"
                                    data-tel="<?= clean($f['telephone'] ?? '') ?>"
                                    data-ville="<?= clean($f['ville'] ?? '') ?>"
                                    data-contact="<?= clean($f['contact_nom'] ?? '') ?>"
                                    <?= (($_POST['id_fournisseur'] ?? '') ==
                                        $f['id_fournisseur'])
                                        ? 'selected' : '' ?>>
                                <?= clean($f['nom_entreprise']) ?>
                                <?php if ($f['ville']): ?>
                                    — <?= clean($f['ville']) ?>
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Fiche fournisseur -->
                    <div id="ficheFournisseur"
                         class="fiche-fournisseur"
                         style="display:none">
                        <div class="fiche-header">
                            <span>🏢</span>
                            <strong id="f_nom"></strong>
                        </div>
                        <div class="fiche-body">
                            <div class="fiche-item">
                                <span>📧 Email :</span>
                                <strong id="f_email"></strong>
                            </div>
                            <div class="fiche-item">
                                <span>📞 Téléphone :</span>
                                <strong id="f_tel"></strong>
                            </div>
                            <div class="fiche-item">
                                <span>📍 Ville :</span>
                                <strong id="f_ville"></strong>
                            </div>
                            <div class="fiche-item">
                                <span>👤 Contact :</span>
                                <strong id="f_contact"></strong>
                            </div>
                        </div>
                    </div>

                    <?php endif; ?>

                    <div class="form-group" style="margin-top:14px">
                        <label>📅 Date de Livraison Souhaitée</label>
                        <input type="date"
                               name="date_livraison_prevue"
                               class="form-control"
                               min="<?= date('Y-m-d') ?>"
                               value="<?= clean($_POST['date_livraison_prevue'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- SECTION 3 : Matériels -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>🖥️ Matériels à Acquérir *</h2>
                    <div class="header-right">
                        <span class="badge bg-info" id="nb_sel">
                            0 sélectionné(s)
                        </span>
                        <span class="badge bg-success" id="total_badge">
                            0 FCFA
                        </span>
                    </div>
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
                                    <th width="110">Quantité</th>
                                    <th width="180">
                                        💰 Prix Unitaire
                                        <small style="display:block;
                                                       font-weight:400;
                                                       font-size:10px">
                                            (fixé par admin)
                                        </small>
                                    </th>
                                    <th width="160">Sous-Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mats as $m): ?>
                                <tr id="row_<?= $m['id_materiel'] ?>">
                                    <td>
                                        <input type="checkbox"
                                               class="materiel-check"
                                               data-id="<?= $m['id_materiel'] ?>"
                                               data-prix="<?= $m['prix_unitaire'] ?? 0 ?>"
                                               onchange="toggleRow(this)">
                                    </td>
                                    <td>
                                        <strong>
                                            <?= clean($m['nom_materiel']) ?>
                                        </strong>
                                        <br>
                                        <small class="text-muted">
                                            <?= clean($m['unite_mesure'] ?? 'Unité') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            <?= clean($m['specification_technique'] ?? '—') ?>
                                        </small>
                                    </td>
                                    <td>
                                        <!-- Quantité modifiable -->
                                        <input type="number"
                                               name="materiels[<?= $m['id_materiel'] ?>][quantite]"
                                               id="qte_<?= $m['id_materiel'] ?>"
                                               class="form-control input-sm"
                                               min="1" value="1"
                                               onchange="calcST(<?= $m['id_materiel'] ?>)"
                                               disabled>
                                    </td>
                                    <td>
                                        <!-- Prix NON modifiable -->
                                        <?php if (($m['prix_unitaire'] ?? 0) > 0): ?>
                                        <div class="prix-fixe">
                                            🔒 <?= formaterMontant($m['prix_unitaire']) ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="prix-fixe prix-non-defini">
                                            ⚠️ Prix non défini
                                        </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span id="st_<?= $m['id_materiel'] ?>"
                                              class="sous-total">
                                            0 FCFA
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endforeach; ?>

                    <!-- Total -->
                    <div class="total-general">
                        <table style="width:auto;margin-left:auto">
                            <tr>
                                <th style="padding:14px 20px;font-size:16px">
                                    💰 MONTANT TOTAL ESTIMÉ :
                                </th>
                                <td style="padding:14px 20px;
                                           font-size:20px;
                                           font-weight:900;
                                           color:var(--primary)">
                                    <span id="total_general">0 FCFA</span>
                                </td>
                            </tr>
                        </table>
                    </div>

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
let nbSel = 0;

// Afficher fournisseur sélectionné
function afficherFournisseur(select) {
    var opt   = select.options[select.selectedIndex];
    var fiche = document.getElementById('ficheFournisseur');
    if (!select.value) {
        fiche.style.display = 'none';
        return;
    }
    document.getElementById('f_nom').textContent     = opt.text;
    document.getElementById('f_email').textContent   = opt.dataset.email   || '—';
    document.getElementById('f_tel').textContent     = opt.dataset.tel     || '—';
    document.getElementById('f_ville').textContent   = opt.dataset.ville   || '—';
    document.getElementById('f_contact').textContent = opt.dataset.contact || '—';
    fiche.style.display = 'block';
}

// Activer/désactiver ligne matériel
function toggleRow(checkbox) {
    var id   = checkbox.dataset.id;
    var prix = parseFloat(checkbox.dataset.prix) || 0;
    var row  = document.getElementById('row_' + id);
    var qte  = document.getElementById('qte_' + id);

    if (checkbox.checked) {
        if (prix <= 0) {
            checkbox.checked = false;
            alert('⚠️ Ce matériel n\'a pas de prix défini !\nContactez l\'administrateur.');
            return;
        }
        nbSel++;
        row.style.background = '#e8f5e9';
        qte.disabled = false;
        qte.value    = 1;
        calcST(id);
    } else {
        nbSel--;
        row.style.background = '';
        qte.disabled = true;
        qte.value    = '';
        document.getElementById('st_' + id).textContent = '0 FCFA';
        calcTotal();
    }
    document.getElementById('nb_sel').textContent =
        nbSel + ' sélectionné(s)';
}

// Calculer sous-total
function calcST(id) {
    var cb   = document.querySelector('[data-id="' + id + '"]');
    var prix = parseFloat(cb.dataset.prix) || 0;
    var qte  = parseFloat(document.getElementById('qte_' + id).value) || 0;
    document.getElementById('st_' + id).textContent =
        (qte * prix).toLocaleString('fr-FR') + ' FCFA';
    calcTotal();
}

// Calculer total général
function calcTotal() {
    var total = 0;
    document.querySelectorAll('.materiel-check:checked').forEach(function(cb) {
        var id   = cb.dataset.id;
        var prix = parseFloat(cb.dataset.prix) || 0;
        var qte  = parseFloat(document.getElementById('qte_' + id).value) || 0;
        total += qte * prix;
    });
    var str = total.toLocaleString('fr-FR') + ' FCFA';
    document.getElementById('total_general').textContent = str;
    document.getElementById('total_badge').textContent   = str;
}

// Validation avant soumission
document.getElementById('formDemande').addEventListener('submit', function(e) {
    var dept    = document.querySelector('[name="departement"]').value.trim();
    var motif   = document.querySelector('[name="motif"]').value.trim();
    var fourn   = document.querySelector('[name="id_fournisseur"]') ?
                  document.querySelector('[name="id_fournisseur"]').value : '1';
    var checked = document.querySelectorAll('.materiel-check:checked');

    if (!dept) {
        e.preventDefault();
        alert('⚠️ Le département est obligatoire !');
        return;
    }
    if (!motif) {
        e.preventDefault();
        alert('⚠️ Le motif est obligatoire !');
        return;
    }
    if (!fourn) {
        e.preventDefault();
        alert('⚠️ Veuillez choisir un fournisseur !');
        return;
    }
    if (checked.length === 0) {
        e.preventDefault();
        alert('⚠️ Sélectionnez au moins un matériel !');
        return;
    }
});
</script>
</body>
</html>