<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

// ============================================
// RESTRICTION : VALIDATEUR SEULEMENT
// ============================================
if ($_SESSION['role'] !== 'validateur') {
    header('Location: ../dashboard/index.php?erreur=' . urlencode(
        '⛔ Accès refusé ! Seul le validateur gère les livraisons.'
    ));
    exit();
}

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');

// Filtres
$filtre_statut = $_GET['statut']    ?? '';
$recherche     = $_GET['recherche'] ?? '';

$where  = "WHERE 1=1";
$params = [];

if (!empty($filtre_statut)) {
    $where .= " AND l.statut = :statut";
    $params[':statut'] = $filtre_statut;
}
if (!empty($recherche)) {
    $where .= " AND (
        c.reference_commande ILIKE :rech
        OR f.nom_entreprise  ILIKE :rech
        OR d.reference_demande ILIKE :rech
    )";
    $params[':rech'] = "%$recherche%";
}

// Récupérer livraisons
$livraisons = $pdo->prepare("
    SELECT l.*,
           c.reference_commande,
           c.montant_total,
           f.nom_entreprise,
           d.reference_demande,
           d.departement_demandeur,
           u.nom      as recept_nom,
           u.prenom   as recept_prenom,
           dem.nom    as demandeur_nom,
           dem.prenom as demandeur_prenom,
           COUNT(dl.id_detail_livraison) as nb_articles
    FROM livraisons l
    JOIN commandes c ON l.id_commande = c.id_commande
    JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d ON c.id_demande = d.id_demande
    JOIN utilisateurs dem ON d.id_utilisateur = dem.id_utilisateur
    LEFT JOIN utilisateurs u ON l.id_receptionnaire = u.id_utilisateur
    LEFT JOIN details_livraison dl ON l.id_livraison = dl.id_livraison
    $where
    GROUP BY l.id_livraison, c.reference_commande,
             c.montant_total, f.nom_entreprise,
             d.reference_demande, d.departement_demandeur,
             u.nom, u.prenom, dem.nom, dem.prenom
    ORDER BY l.date_livraison DESC
");
$livraisons->execute($params);
$livraisons = $livraisons->fetchAll();

// Commandes validées prêtes à livrer
$commandes_a_livrer = $pdo->query("
    SELECT c.*,
           f.nom_entreprise,
           d.reference_demande,
           d.departement_demandeur
    FROM commandes c
    JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d ON c.id_demande = d.id_demande
    WHERE c.statut = 'validee'
    ORDER BY c.date_commande DESC
")->fetchAll();

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'conforme'
            THEN 1 ELSE 0 END) as conformes,
        SUM(CASE WHEN statut = 'non_conforme'
            THEN 1 ELSE 0 END) as non_conformes,
        SUM(CASE WHEN statut = 'partielle'
            THEN 1 ELSE 0 END) as partielles
    FROM livraisons
")->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Livraisons — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>📦 Gestion des Livraisons</h1>
                <p class="page-subtitle">
                    Enregistrement des réceptions de commandes
                </p>
            </div>
            <div class="btn-group">
                <?php if (!empty($commandes_a_livrer)): ?>
                <span class="badge bg-success"
                      style="font-size:13px;padding:10px 14px">
                    ✅ <?= count($commandes_a_livrer) ?>
                    commande(s) prête(s)
                </span>
                <?php endif; ?>
                <a href="nouvelle_livraison.php"
                   class="btn btn-primary">
                    ➕ Enregistrer Livraison
                </a>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- Info validateur -->
        <div class="validateur-info-box">
            <div class="vib-icon">🟣</div>
            <div class="vib-content">
                <h3>Connecté en tant que Validateur</h3>
                <p>
                    👤 <strong><?= clean($_SESSION['nom_complet']) ?></strong>
                    | Vous gérez les livraisons des commandes validées.
                </p>
            </div>
        </div>

        <!-- Commandes prêtes à livrer -->
        <?php if (!empty($commandes_a_livrer)): ?>
        <div class="card mb-3">
            <div class="card-header"
                 style="background:linear-gradient(135deg,#e8f5e9,#c8e6c9)">
                <h2 style="color:var(--success)">
                    ✅ Commandes Prêtes à Livrer
                </h2>
                <span class="badge bg-success">
                    <?= count($commandes_a_livrer) ?>
                </span>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Réf. Commande</th>
                            <th>Réf. Demande</th>
                            <th>Fournisseur</th>
                            <th>Département</th>
                            <th>Montant</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($commandes_a_livrer as $i => $cmd): ?>
                        <tr style="background:#f1f8e9">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong>
                                    <?= clean($cmd['reference_commande']) ?>
                                </strong>
                            </td>
                            <td><?= clean($cmd['reference_demande']) ?></td>
                            <td>
                                <strong>
                                    <?= clean($cmd['nom_entreprise']) ?>
                                </strong>
                            </td>
                            <td>
                                <?= clean($cmd['departement_demandeur']) ?>
                            </td>
                            <td>
                                <strong style="color:var(--primary)">
                                    <?= formaterMontant($cmd['montant_total']) ?>
                                </strong>
                            </td>
                            <td>
                                <?= date('d/m/Y',
                                    strtotime($cmd['date_commande'])) ?>
                            </td>
                            <td>
                                <a href="nouvelle_livraison.php?cmd=<?= $cmd['id_commande'] ?>"
                                   class="btn btn-sm btn-success">
                                    📦 Livrer
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid"
             style="grid-template-columns:repeat(4,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <h3><?= $stats['total'] ?></h3>
                    <p>Total Livraisons</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $stats['conformes'] ?></h3>
                    <p>Conformes</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">❌</div>
                <div class="stat-info">
                    <h3><?= $stats['non_conformes'] ?></h3>
                    <p>Non Conformes</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⚠️</div>
                <div class="stat-info">
                    <h3><?= $stats['partielles'] ?></h3>
                    <p>Partielles</p>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>🔍 Recherche</label>
                            <input type="text" name="recherche"
                                   class="form-control"
                                   placeholder="Réf. commande, fournisseur..."
                                   value="<?= clean($recherche) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label>📌 Statut</label>
                            <select name="statut" class="form-control">
                                <option value="">Tous</option>
                                <option value="conforme"
                                    <?= $filtre_statut=='conforme'
                                        ?'selected':'' ?>>
                                    ✅ Conforme
                                </option>
                                <option value="non_conforme"
                                    <?= $filtre_statut=='non_conforme'
                                        ?'selected':'' ?>>
                                    ❌ Non Conforme
                                </option>
                                <option value="partielle"
                                    <?= $filtre_statut=='partielle'
                                        ?'selected':'' ?>>
                                    📦 Partielle
                                </option>
                            </select>
                        </div>
                        <div class="form-group col-4 align-end">
                            <label>&nbsp;</label>
                            <div class="btn-group">
                                <button type="submit"
                                        class="btn btn-primary">
                                    🔍 Filtrer
                                </button>
                                <a href="index.php"
                                   class="btn btn-secondary">
                                    🔄 Reset
                                </a>
                                <button type="button"
                                        onclick="window.print()"
                                        class="btn btn-info">
                                    🖨️ Imprimer
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Historique des Livraisons
                    <span class="badge bg-primary">
                        <?= count($livraisons) ?>
                    </span>
                </h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Réf. Commande</th>
                                <th>Demandeur</th>
                                <th>Fournisseur</th>
                                <th>Articles</th>
                                <th>Réceptionné par</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($livraisons)): ?>
                            <tr>
                                <td colspan="9"
                                    class="text-center py-4">
                                    😕 Aucune livraison enregistrée
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($livraisons as $i => $l): ?>
                            <?php
                            $bl  = [
                                'conforme'     => ['success','✅ Conforme'],
                                'non_conforme' => ['danger', '❌ Non Conforme'],
                                'partielle'    => ['warning','📦 Partielle'],
                            ];
                            $bls = $bl[$l['statut']]
                                   ?? ['secondary', $l['statut']];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($l['reference_commande']) ?>
                                    </strong>
                                    <br>
                                    <small class="text-muted">
                                        <?= clean($l['reference_demande']) ?>
                                    </small>
                                </td>
                                <td>
                                    <?= clean(
                                        $l['demandeur_prenom'] . ' ' .
                                        $l['demandeur_nom']
                                    ) ?>
                                    <br>
                                    <small class="text-muted">
                                        <?= clean($l['departement_demandeur']) ?>
                                    </small>
                                </td>
                                <td><?= clean($l['nom_entreprise']) ?></td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= $l['nb_articles'] ?>
                                        article(s)
                                    </span>
                                </td>
                                <td>
                                    <?= clean(
                                        ($l['recept_prenom'] ?? '') . ' ' .
                                        ($l['recept_nom'] ?? '')
                                    ) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y',
                                        strtotime($l['date_livraison'])) ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $bls[0] ?>">
                                        <?= $bls[1] ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="detail_livraison.php?id=<?= $l['id_livraison'] ?>"
                                       class="btn btn-sm btn-info">
                                        👁️ Voir
                                    </a>
                                </td>
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

<?php include '../includes/footer.php'; ?>
</body>
</html>