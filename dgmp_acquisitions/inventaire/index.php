<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');
$role    = $_SESSION['role'];

// ============================================
// FILTRES
// ============================================
$filtre_etat      = $_GET['etat']      ?? '';
$filtre_categorie = $_GET['categorie'] ?? '';
$recherche        = $_GET['recherche'] ?? '';

$where  = "WHERE 1=1";
$params = [];

if (!empty($filtre_etat)) {
    $where .= " AND i.etat = :etat";
    $params[':etat'] = $filtre_etat;
}
if (!empty($filtre_categorie)) {
    $where .= " AND c.id_categorie = :cat";
    $params[':cat'] = (int)$filtre_categorie;
}
if (!empty($recherche)) {
    $where .= " AND (
        m.nom_materiel    ILIKE :rech
        OR i.numero_serie ILIKE :rech
        OR i.localisation ILIKE :rech
    )";
    $params[':rech'] = "%" . $recherche . "%";
}

// ============================================
// RÉCUPÉRER INVENTAIRE
// ============================================
$stmt_inv = $pdo->prepare("
    SELECT
        i.id_inventaire,
        i.id_materiel,
        i.id_livraison,
        i.numero_serie,
        i.etat,
        i.localisation,
        i.date_entree,
        m.nom_materiel,
        m.specification_technique,
        m.prix_unitaire,
        c.nom_categorie,
        co.reference_commande,
        f.nom_entreprise
    FROM inventaire i
    INNER JOIN materiels m
        ON i.id_materiel = m.id_materiel
    INNER JOIN categories_materiel c
        ON m.id_categorie = c.id_categorie
    LEFT JOIN livraisons l
        ON i.id_livraison = l.id_livraison
    LEFT JOIN commandes co
        ON l.id_commande = co.id_commande
    LEFT JOIN fournisseurs f
        ON co.id_fournisseur = f.id_fournisseur
    $where
    ORDER BY i.date_entree DESC
");
$stmt_inv->execute($params);
$inventaire = $stmt_inv->fetchAll();

// ============================================
// CATÉGORIES POUR FILTRE
// ============================================
$categories = $pdo->query("
    SELECT * FROM categories_materiel
    ORDER BY nom_categorie
")->fetchAll();

// ============================================
// MATÉRIELS POUR FORMULAIRE
// ============================================
$materiels = $pdo->query("
    SELECT m.*, c.nom_categorie
    FROM materiels m
    INNER JOIN categories_materiel c
        ON m.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, m.nom_materiel
")->fetchAll();

// ============================================
// LIVRAISONS POUR FORMULAIRE
// ============================================
$livraisons = $pdo->query("
    SELECT l.id_livraison,
           l.date_livraison,
           co.reference_commande,
           f.nom_entreprise
    FROM livraisons l
    INNER JOIN commandes co
        ON l.id_commande = co.id_commande
    INNER JOIN fournisseurs f
        ON co.id_fournisseur = f.id_fournisseur
    ORDER BY l.date_livraison DESC
")->fetchAll();

// ============================================
// STATISTIQUES SIMPLES (PAS DE JOIN)
// ============================================
$total_inventaire  = $pdo->query("SELECT COUNT(*) as t FROM inventaire")->fetch()['t'];
$total_neuf        = $pdo->query("SELECT COUNT(*) as t FROM inventaire WHERE etat = 'neuf'")->fetch()['t'];
$total_bon         = $pdo->query("SELECT COUNT(*) as t FROM inventaire WHERE etat = 'bon'")->fetch()['t'];
$total_moyen       = $pdo->query("SELECT COUNT(*) as t FROM inventaire WHERE etat = 'moyen'")->fetch()['t'];
$total_mauvais     = $pdo->query("SELECT COUNT(*) as t FROM inventaire WHERE etat = 'mauvais'")->fetch()['t'];

// Valeur totale
$valeur_totale = $pdo->query("
    SELECT COALESCE(SUM(m.prix_unitaire), 0) as t
    FROM inventaire i
    INNER JOIN materiels m ON i.id_materiel = m.id_materiel
")->fetch()['t'];

// ============================================
// STATS PAR CATÉGORIE (MÉTHODE SIMPLE - SANS DOUBLONS)
// ============================================
$stats_categories = [];
foreach ($categories as $cat) {
    $stmt_cat = $pdo->prepare("
        SELECT COUNT(i.id_inventaire) as nb,
               COALESCE(SUM(m.prix_unitaire), 0) as valeur
        FROM inventaire i
        INNER JOIN materiels m
            ON i.id_materiel = m.id_materiel
        WHERE m.id_categorie = :cat_id
    ");
    $stmt_cat->execute([':cat_id' => $cat['id_categorie']]);
    $result = $stmt_cat->fetch();

    // Ajouter seulement si la catégorie a des équipements
    if ($result['nb'] > 0) {
        $stats_categories[] = [
            'nom_categorie' => $cat['nom_categorie'],
            'nb'            => $result['nb'],
            'valeur'        => $result['valeur']
        ];
    }
}

// ============================================
// TRAITEMENT FORMULAIRE (ADMIN)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && $role === 'admin') {

    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $id_materiel  = (int)($_POST['id_materiel']  ?? 0);
        $id_livraison = !empty($_POST['id_livraison'])
                        ? (int)$_POST['id_livraison'] : null;
        $numero_serie = trim($_POST['numero_serie']  ?? '');
        $etat         = $_POST['etat']               ?? 'neuf';
        $localisation = trim($_POST['localisation']  ?? '');
        $date_entree  = $_POST['date_entree']        ?? date('Y-m-d');

        if (!$id_materiel || empty($localisation)) {
            $erreur = "Matériel et localisation obligatoires !";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO inventaire
                    (id_materiel, id_livraison,
                     numero_serie, etat,
                     localisation, date_entree)
                    VALUES (:mat, :liv, :serie,
                            :etat, :loc, :date)
                ")->execute([
                    ':mat'   => $id_materiel,
                    ':liv'   => $id_livraison,
                    ':serie' => !empty($numero_serie)
                                ? $numero_serie : null,
                    ':etat'  => $etat,
                    ':loc'   => $localisation,
                    ':date'  => $date_entree
                ]);

                header('Location: index.php?success=' . urlencode(
                    "✅ Équipement ajouté !"
                ));
                exit();
            } catch (Exception $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }

    if ($action === 'modifier') {
        $id           = (int)($_POST['id_inventaire'] ?? 0);
        $etat         = $_POST['etat']                ?? 'bon';
        $localisation = trim($_POST['localisation']   ?? '');
        $numero_serie = trim($_POST['numero_serie']   ?? '');

        if (!$id || empty($localisation)) {
            $erreur = "Données invalides !";
        } else {
            try {
                $pdo->prepare("
                    UPDATE inventaire SET
                        etat         = :etat,
                        localisation = :loc,
                        numero_serie = :serie
                    WHERE id_inventaire = :id
                ")->execute([
                    ':etat'  => $etat,
                    ':loc'   => $localisation,
                    ':serie' => !empty($numero_serie)
                                ? $numero_serie : null,
                    ':id'    => $id
                ]);

                header('Location: index.php?success=' . urlencode(
                    "✅ Équipement modifié !"
                ));
                exit();
            } catch (Exception $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }

    if ($action === 'supprimer') {
        $id = (int)($_POST['id_inventaire'] ?? 0);
        if ($id) {
            try {
                $pdo->prepare("
                    DELETE FROM inventaire
                    WHERE id_inventaire = :id
                ")->execute([':id' => $id]);

                header('Location: index.php?success=' . urlencode(
                    "✅ Équipement supprimé !"
                ));
                exit();
            } catch (Exception $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventaire — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>🗃️ Inventaire des Équipements</h1>
                <p class="page-subtitle">
                    Suivi de tous les équipements informatiques
                </p>
            </div>
            <?php if ($role === 'admin'): ?>
            <button onclick="ouvrirModal('modalAjouter')"
                    class="btn btn-primary">
                ➕ Ajouter Équipement
            </button>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- STATISTIQUES -->
        <div class="stats-grid"
             style="grid-template-columns:repeat(6,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">🗃️</div>
                <div class="stat-info">
                    <h3><?= $total_inventaire ?></h3>
                    <p>Total</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✨</div>
                <div class="stat-info">
                    <h3><?= $total_neuf ?></h3>
                    <p>Neuf</p>
                </div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon">👍</div>
                <div class="stat-info">
                    <h3><?= $total_bon ?></h3>
                    <p>Bon</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⚠️</div>
                <div class="stat-info">
                    <h3><?= $total_moyen ?></h3>
                    <p>Moyen</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">🔴</div>
                <div class="stat-info">
                    <h3><?= $total_mauvais ?></h3>
                    <p>Mauvais</p>
                </div>
            </div>
            <div class="stat-card gold">
                <div class="stat-icon">💰</div>
                <div class="stat-info">
                    <h3><?= formaterMontant($valeur_totale) ?></h3>
                    <p>Valeur</p>
                </div>
            </div>
        </div>

        <!-- STATS PAR CATÉGORIE -->
        <?php if (!empty($stats_categories)): ?>
        <div class="card mb-3">
            <div class="card-header">
                <h2>📊 Répartition par Catégorie</h2>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Catégorie</th>
                            <th>Nb Équipements</th>
                            <th>Valeur Totale</th>
                            <th>Pourcentage</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats_categories as $sc): ?>
                        <?php
                        $pct = ($total_inventaire > 0)
                            ? round(($sc['nb'] / $total_inventaire) * 100)
                            : 0;
                        ?>
                        <tr>
                            <td>
                                <span class="badge bg-info">
                                    <?= clean($sc['nom_categorie']) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= $sc['nb'] ?></strong>
                            </td>
                            <td>
                                <strong style="color:var(--primary)">
                                    <?= formaterMontant($sc['valeur']) ?>
                                </strong>
                            </td>
                            <td>
                                <div class="progress-bar-container">
                                    <div class="progress-bar"
                                         style="width:<?= $pct ?>%">
                                    </div>
                                    <span><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- FILTRES -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group col-3">
                            <label>🔍 Recherche</label>
                            <input type="text"
                                   name="recherche"
                                   class="form-control"
                                   placeholder="Matériel, N° série..."
                                   value="<?= clean($recherche) ?>">
                        </div>
                        <div class="form-group col-3">
                            <label>📦 Catégorie</label>
                            <select name="categorie"
                                    class="form-control">
                                <option value="">Toutes</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id_categorie'] ?>"
                                    <?= $filtre_categorie ==
                                        $cat['id_categorie']
                                        ? 'selected' : '' ?>>
                                    <?= clean($cat['nom_categorie']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-3">
                            <label>🔵 État</label>
                            <select name="etat" class="form-control">
                                <option value="">Tous</option>
                                <option value="neuf"
                                    <?= $filtre_etat === 'neuf'
                                        ? 'selected' : '' ?>>
                                    ✨ Neuf
                                </option>
                                <option value="bon"
                                    <?= $filtre_etat === 'bon'
                                        ? 'selected' : '' ?>>
                                    👍 Bon
                                </option>
                                <option value="moyen"
                                    <?= $filtre_etat === 'moyen'
                                        ? 'selected' : '' ?>>
                                    ⚠️ Moyen
                                </option>
                                <option value="mauvais"
                                    <?= $filtre_etat === 'mauvais'
                                        ? 'selected' : '' ?>>
                                    🔴 Mauvais
                                </option>
                            </select>
                        </div>
                        <div class="form-group col-3 align-end">
                            <label>&nbsp;</label>
                            <div class="btn-group">
                                <button type="submit"
                                        class="btn btn-primary">
                                    🔍 Filtrer
                                </button>
                                <a href="index.php"
                                   class="btn btn-secondary">
                                    🔄
                                </a>
                                <button type="button"
                                        onclick="window.print()"
                                        class="btn btn-info">
                                    🖨️
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- TABLEAU INVENTAIRE -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Liste
                    <span class="badge bg-primary">
                        <?= count($inventaire) ?>
                    </span>
                </h2>
                <input type="text"
                       id="rechercheRapide"
                       class="form-control"
                       style="width:220px"
                       placeholder="🔍 Recherche..."
                       onkeyup="filtrerTableau()">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table" id="tableInventaire">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Catégorie</th>
                                <th>Équipement</th>
                                <th>N° Série</th>
                                <th>État</th>
                                <th>Localisation</th>
                                <th>Fournisseur</th>
                                <th>Prix</th>
                                <th>Date</th>
                                <?php if ($role === 'admin'): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inventaire)): ?>
                            <tr>
                                <td colspan="<?= $role === 'admin' ? 10 : 9 ?>"
                                    class="text-center py-4">
                                    😕 Aucun équipement
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($inventaire as $i => $item): ?>
                            <?php
                            $eb_list = [
                                'neuf'    => ['success', '✨ Neuf'],
                                'bon'     => ['info',    '👍 Bon'],
                                'moyen'   => ['warning', '⚠️ Moyen'],
                                'mauvais' => ['danger',  '🔴 Mauvais'],
                            ];
                            $eb = $eb_list[$item['etat']]
                                  ?? ['secondary', $item['etat']];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= clean($item['nom_categorie']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong>
                                        <?= clean($item['nom_materiel']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php if (!empty($item['numero_serie'])): ?>
                                    <code><?= clean($item['numero_serie']) ?></code>
                                    <?php else: ?>
                                    <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $eb[0] ?>">
                                        <?= $eb[1] ?>
                                    </span>
                                </td>
                                <td>
                                    📍 <?= clean($item['localisation'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= clean($item['nom_entreprise'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= formaterMontant($item['prix_unitaire'] ?? 0) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y',
                                        strtotime($item['date_entree'])) ?>
                                </td>
                                <?php if ($role === 'admin'): ?>
                                <td>
                                    <div class="action-btns">
                                        <button onclick="ouvrirModifier(
                                            <?= $item['id_inventaire'] ?>,
                                            '<?= addslashes($item['etat']) ?>',
                                            '<?= addslashes($item['localisation'] ?? '') ?>',
                                            '<?= addslashes($item['numero_serie'] ?? '') ?>'
                                        )" class="btn btn-sm btn-warning">✏️</button>
                                        <button onclick="confirmerSuppression(
                                            <?= $item['id_inventaire'] ?>,
                                            '<?= addslashes($item['nom_materiel']) ?>'
                                        )" class="btn btn-sm btn-danger">🗑️</button>
                                    </div>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<!-- MODALS (ADMIN SEULEMENT) -->
<?php if ($role === 'admin'): ?>

<!-- AJOUTER -->
<div class="modal-overlay" id="modalAjouter">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Ajouter Équipement</h3>
            <button onclick="fermerModal('modalAjouter')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-body">
                <div class="form-group">
                    <label>🖥️ Matériel *</label>
                    <select name="id_materiel" class="form-control" required>
                        <option value="">-- Choisir --</option>
                        <?php
                        $cc = '';
                        foreach ($materiels as $m):
                            if ($m['nom_categorie'] !== $cc):
                                if ($cc !== '') echo '</optgroup>';
                                echo '<optgroup label="' . clean($m['nom_categorie']) . '">';
                                $cc = $m['nom_categorie'];
                            endif;
                        ?>
                        <option value="<?= $m['id_materiel'] ?>">
                            <?= clean($m['nom_materiel']) ?>
                        </option>
                        <?php endforeach; if ($cc !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>📦 Livraison</label>
                    <select name="id_livraison" class="form-control">
                        <option value="">-- Aucune --</option>
                        <?php foreach ($livraisons as $l): ?>
                        <option value="<?= $l['id_livraison'] ?>">
                            <?= clean($l['reference_commande']) ?> |
                            <?= date('d/m/Y', strtotime($l['date_livraison'])) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>🔢 N° Série</label>
                    <input type="text" name="numero_serie" class="form-control"
                           placeholder="Ex: SN-2024-001">
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>État *</label>
                        <select name="etat" class="form-control" required>
                            <option value="neuf">✨ Neuf</option>
                            <option value="bon">👍 Bon</option>
                            <option value="moyen">⚠️ Moyen</option>
                            <option value="mauvais">🔴 Mauvais</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>📍 Localisation *</label>
                        <input type="text" name="localisation"
                               class="form-control" required
                               placeholder="Bureau 201">
                    </div>
                </div>
                <div class="form-group">
                    <label>📅 Date</label>
                    <input type="date" name="date_entree"
                           class="form-control"
                           value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fermerModal('modalAjouter')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- MODIFIER -->
<div class="modal-overlay" id="modalModifier">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>✏️ Modifier</h3>
            <button onclick="fermerModal('modalModifier')" class="modal-close">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id_inventaire" id="mod_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>N° Série</label>
                    <input type="text" name="numero_serie" id="mod_serie" class="form-control">
                </div>
                <div class="form-group">
                    <label>État *</label>
                    <select name="etat" id="mod_etat" class="form-control" required>
                        <option value="neuf">✨ Neuf</option>
                        <option value="bon">👍 Bon</option>
                        <option value="moyen">⚠️ Moyen</option>
                        <option value="mauvais">🔴 Mauvais</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>📍 Localisation *</label>
                    <input type="text" name="localisation" id="mod_localisation"
                           class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fermerModal('modalModifier')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-warning">✏️ Modifier</button>
            </div>
        </form>
    </div>
</div>

<!-- SUPPRIMER -->
<div class="modal-overlay" id="modalSupprimer">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>🗑️ Supprimer</h3>
            <button onclick="fermerModal('modalSupprimer')" class="modal-close">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id_inventaire" id="sup_id">
            <div class="modal-body">
                <p>Supprimer : <strong id="sup_nom" style="color:red"></strong> ?</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="fermerModal('modalSupprimer')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-danger">🗑️ Supprimer</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<?php include '../includes/footer.php'; ?>

<script>
function ouvrirModal(id) { document.getElementById(id).classList.add('show'); }
function fermerModal(id) { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === this) fermerModal(this.id); });
});
function ouvrirModifier(id, etat, loc, serie) {
    document.getElementById('mod_id').value           = id;
    document.getElementById('mod_etat').value         = etat;
    document.getElementById('mod_localisation').value = loc;
    document.getElementById('mod_serie').value        = serie;
    ouvrirModal('modalModifier');
}
function confirmerSuppression(id, nom) {
    document.getElementById('sup_id').value        = id;
    document.getElementById('sup_nom').textContent = nom;
    ouvrirModal('modalSupprimer');
}
function filtrerTableau() {
    var val = document.getElementById('rechercheRapide').value.toLowerCase();
    document.querySelectorAll('#tableInventaire tbody tr').forEach(function(r) {
        r.style.display = r.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
}
</script>
</body>
</html>