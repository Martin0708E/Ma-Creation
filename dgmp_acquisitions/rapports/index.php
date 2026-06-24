<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin', 'responsable']);

$pdo = getConnexion();

// Rapport demandes par mois
$par_mois = $pdo->query("
    SELECT 
        TO_CHAR(date_demande, 'MM/YYYY') as mois,
        COUNT(*) as nb_demandes,
        SUM(CASE WHEN statut='validee' THEN 1 ELSE 0 END) as validees,
        SUM(CASE WHEN statut='rejetee' THEN 1 ELSE 0 END) as rejetees,
        COALESCE(SUM(budget_estime), 0) as budget_total
    FROM demandes_acquisition
    GROUP BY TO_CHAR(date_demande, 'MM/YYYY')
    ORDER BY MIN(date_demande) DESC
    LIMIT 12
")->fetchAll();

// Top matériels demandés
$top_materiels = $pdo->query("
    SELECT m.nom_materiel, c.nom_categorie,
           SUM(dd.quantite) as total_demande,
           COUNT(DISTINCT dd.id_demande) as nb_demandes
    FROM details_demande dd
    JOIN materiels m ON dd.id_materiel = m.id_materiel
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    GROUP BY m.nom_materiel, c.nom_categorie
    ORDER BY total_demande DESC
    LIMIT 10
")->fetchAll();

// Top fournisseurs
$top_fournisseurs = $pdo->query("
    SELECT f.nom_entreprise,
           COUNT(c.id_commande) as nb_commandes,
           COALESCE(SUM(c.montant_total), 0) as montant_total
    FROM fournisseurs f
    LEFT JOIN commandes c ON f.id_fournisseur = c.id_fournisseur
    GROUP BY f.nom_entreprise
    ORDER BY montant_total DESC
    LIMIT 10
")->fetchAll();

// Budget par département
$par_departement = $pdo->query("
    SELECT departement_demandeur,
           COUNT(*) as nb_demandes,
           COALESCE(SUM(budget_estime), 0) as budget_total
    FROM demandes_acquisition
    WHERE statut IN ('validee', 'commandee', 'livree')
    GROUP BY departement_demandeur
    ORDER BY budget_total DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapports — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>
<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>
    <main class="main-content">

        <div class="page-header">
            <h1>📈 Rapports & Statistiques</h1>
            <button onclick="window.print()" class="btn btn-info">🖨️ Imprimer</button>
        </div>

        <!-- Rapport par mois -->
        <div class="card mb-3">
            <div class="card-header"><h2>📅 Demandes par Mois</h2></div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Mois</th>
                            <th>Total Demandes</th>
                            <th>Validées</th>
                            <th>Rejetées</th>
                            <th>Budget Total Estimé</th>
                            <th>Taux Validation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($par_mois as $m): ?>
                        <tr>
                            <td><strong><?= $m['mois'] ?></strong></td>
                            <td><?= $m['nb_demandes'] ?></td>
                            <td><span class="badge bg-success"><?= $m['validees'] ?></span></td>
                            <td><span class="badge bg-danger"><?= $m['rejetees'] ?></span></td>
                            <td><?= formaterMontant($m['budget_total']) ?></td>
                            <td>
                                <?php 
                                $taux = $m['nb_demandes'] > 0 
                                    ? round(($m['validees'] / $m['nb_demandes']) * 100) 
                                    : 0;
                                ?>
                                <div class="progress-bar-container">
                                    <div class="progress-bar" style="width:<?= $taux ?>%"></div>
                                    <span><?= $taux ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rapport-grid">
            <!-- Top matériels -->
            <div class="card">
                <div class="card-header"><h2>🖥️ Top Matériels Demandés</h2></div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Matériel</th>
                                <th>Catégorie</th>
                                <th>Qté Totale</th>
                                <th>Nb Demandes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_materiels as $i => $mat): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= clean($mat['nom_materiel']) ?></td>
                                <td><?= clean($mat['nom_categorie']) ?></td>
                                <td><strong><?= $mat['total_demande'] ?></strong></td>
                                <td><?= $mat['nb_demandes'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top fournisseurs -->
            <div class="card">
                <div class="card-header"><h2>🏢 Top Fournisseurs</h2></div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Fournisseur</th>
                                <th>Commandes</th>
                                <th>Montant Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_fournisseurs as $i => $f): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= clean($f['nom_entreprise']) ?></td>
                                <td><?= $f['nb_commandes'] ?></td>
                                <td><?= formaterMontant($f['montant_total']) ?></td>
                            </tr>
                            <?php endforeach; ?>
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

<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

// Admin + Validateur peuvent voir les rapports
verifierRole(['admin', 'validateur']);