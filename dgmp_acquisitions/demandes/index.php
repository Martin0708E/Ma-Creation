<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo  = getConnexion();
$role = $_SESSION['role'];

// Filtres
$filtre_statut   = $_GET['statut'] ?? '';
$filtre_priorite = $_GET['priorite'] ?? '';
$recherche       = $_GET['recherche'] ?? '';

// Construction requête
$where  = "WHERE 1=1";
$params = [];

if (!empty($filtre_statut)) {
    $where .= " AND d.statut = :statut";
    $params[':statut'] = $filtre_statut;
}
if (!empty($filtre_priorite)) {
    $where .= " AND d.priorite = :priorite";
    $params[':priorite'] = $filtre_priorite;
}
if (!empty($recherche)) {
    $where .= " AND (d.reference_demande ILIKE :rech OR u.nom ILIKE :rech OR d.departement_demandeur ILIKE :rech)";
    $params[':rech'] = "%$recherche%";
}

// Si agent : voir seulement ses propres demandes
if ($role === 'agent') {
    $where .= " AND d.id_utilisateur = :id_user";
    $params[':id_user'] = $_SESSION['id_utilisateur'];
}

$demandes = $pdo->prepare("
    SELECT d.*, u.nom, u.prenom,
           COUNT(dd.id_detail) as nb_articles
    FROM demandes_acquisition d
    JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
    LEFT JOIN details_demande dd ON d.id_demande = dd.id_demande
    $where
    GROUP BY d.id_demande, u.nom, u.prenom
    ORDER BY d.date_demande DESC
");
$demandes->execute($params);
$demandes = $demandes->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>DGMP — Page</title>
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
            <h1>📋 Demandes d'Acquisition</h1>
            <a href="nouvelle_demande.php" class="btn btn-primary">
                ➕ Nouvelle Demande
            </a>
        </div>

        <!-- Filtres -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="" class="filter-form">
                    <div class="form-row">
                        <div class="form-group col-3">
                            <label>🔍 Recherche</label>
                            <input type="text" name="recherche" 
                                   class="form-control"
                                   placeholder="Référence, nom, département..."
                                   value="<?= clean($recherche) ?>">
                        </div>
                        <div class="form-group col-3">
                            <label>📌 Statut</label>
                            <select name="statut" class="form-control">
                                <option value="">Tous les statuts</option>
                                <option value="en_attente" <?= $filtre_statut=='en_attente'?'selected':'' ?>>En Attente</option>
                                <option value="validee" <?= $filtre_statut=='validee'?'selected':'' ?>>Validée</option>
                                <option value="rejetee" <?= $filtre_statut=='rejetee'?'selected':'' ?>>Rejetée</option>
                                <option value="commandee" <?= $filtre_statut=='commandee'?'selected':'' ?>>Commandée</option>
                                <option value="livree" <?= $filtre_statut=='livree'?'selected':'' ?>>Livrée</option>
                            </select>
                        </div>
                        <div class="form-group col-3">
                            <label>⚡ Priorité</label>
                            <select name="priorite" class="form-control">
                                <option value="">Toutes les priorités</option>
                                <option value="urgente" <?= $filtre_priorite=='urgente'?'selected':'' ?>>Urgente</option>
                                <option value="haute" <?= $filtre_priorite=='haute'?'selected':'' ?>>Haute</option>
                                <option value="normale" <?= $filtre_priorite=='normale'?'selected':'' ?>>Normale</option>
                                <option value="basse" <?= $filtre_priorite=='basse'?'selected':'' ?>>Basse</option>
                            </select>
                        </div>
                        <div class="form-group col-3 align-end">
                            <label>&nbsp;</label>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary">
                                    🔍 Filtrer
                                </button>
                                <a href="index.php" class="btn btn-secondary">
                                    🔄 Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-header">
                <h2>Liste des Demandes 
                    <span class="badge bg-primary"><?= count($demandes) ?></span>
                </h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Référence</th>
                                <th>Demandeur</th>
                                <th>Département</th>
                                <th>Articles</th>
                                <th>Priorité</th>
                                <th>Budget</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($demandes)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-4">
                                    😕 Aucune demande trouvée
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($demandes as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= clean($d['reference_demande']) ?></strong></td>
                                <td><?= clean($d['prenom'] . ' ' . $d['nom']) ?></td>
                                <td><?= clean($d['departement_demandeur']) ?></td>
                                <td>
                                    <span class="badge bg-info"><?= $d['nb_articles'] ?> article(s)</span>
                                </td>
                                <td><?= getBadgePriorite($d['priorite']) ?></td>
                                <td><?= formaterMontant($d['budget_estime']) ?></td>
                                <td><?= getBadgeStatut($d['statut']) ?></td>
                                <td><?= date('d/m/Y', strtotime($d['date_demande'])) ?></td>
                                <td>
                                    <div class="action-btns">
                                        <a href="detail_demande.php?id=<?= $d['id_demande'] ?>"
                                           class="btn btn-sm btn-info" title="Voir">👁️</a>
                                        <?php if ($role === 'admin' && $d['statut'] === 'en_attente'): ?>
                                        <a href="valider_demande.php?id=<?= $d['id_demande'] ?>"
                                           class="btn btn-sm btn-success" title="Valider">✅</a>
                                        <?php endif; ?>
                                    </div>
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