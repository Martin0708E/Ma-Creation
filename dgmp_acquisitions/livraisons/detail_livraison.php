<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo = getConnexion();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) {
    header('Location: index.php');
    exit();
}

// Récupérer la livraison
$stmt = $pdo->prepare("
    SELECT l.*,
           c.reference_commande, c.montant_total,
           f.nom_entreprise, f.telephone as fournisseur_tel,
           d.reference_demande, d.departement_demandeur, d.motif,
           u.nom as recept_nom, u.prenom as recept_prenom,
           u.email as recept_email
    FROM livraisons l
    JOIN commandes c ON l.id_commande = c.id_commande
    JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d ON c.id_demande = d.id_demande
    LEFT JOIN utilisateurs u ON l.id_receptionnaire = u.id_utilisateur
    WHERE l.id_livraison = :id
");
$stmt->execute([':id' => $id]);
$livraison = $stmt->fetch();

if (!$livraison) {
    header('Location: index.php');
    exit();
}

// Articles livrés
$articles = $pdo->prepare("
    SELECT dl.*,
           m.nom_materiel,
           m.specification_technique,
           c.nom_categorie
    FROM details_livraison dl
    JOIN materiels m ON dl.id_materiel = m.id_materiel
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    WHERE dl.id_livraison = :id
");
$articles->execute([':id' => $id]);
$articles = $articles->fetchAll();

$bl = [
    'conforme'     => ['success', '✅ Conforme'],
    'non_conforme' => ['danger',  '❌ Non Conforme'],
    'partielle'    => ['warning', '📦 Partielle'],
];
$bls = $bl[$livraison['statut']] ?? ['secondary', $livraison['statut']];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Livraison — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>📦 Détail de la Livraison</h1>
                <p class="page-subtitle">
                    Commande : <strong><?= clean($livraison['reference_commande']) ?></strong>
                </p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-secondary">← Retour</a>
                <button onclick="window.print()" class="btn btn-info">
                    🖨️ Imprimer
                </button>
            </div>
        </div>

        <!-- Infos principales -->
        <div class="detail-header-grid">

            <!-- Info livraison -->
            <div class="card">
                <div class="card-header">
                    <h2>📦 Livraison</h2>
                    <span class="badge bg-<?= $bls[0] ?>" style="font-size:14px">
                        <?= $bls[1] ?>
                    </span>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Réf. Commande</th>
                            <td><strong><?= clean($livraison['reference_commande']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Réf. Demande</th>
                            <td><?= clean($livraison['reference_demande']) ?></td>
                        </tr>
                        <tr>
                            <th>Date Livraison</th>
                            <td>
                                <strong>
                                    <?= date('d/m/Y', strtotime($livraison['date_livraison'])) ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Statut</th>
                            <td>
                                <span class="badge bg-<?= $bls[0] ?>"><?= $bls[1] ?></span>
                            </td>
                        </tr>
                        <?php if ($livraison['observation']): ?>
                        <tr>
                            <th>Observation</th>
                            <td><?= nl2br(clean($livraison['observation'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Réceptionnaire -->
            <div class="card">
                <div class="card-header">
                    <h2>👤 Réceptionné par</h2>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Nom</th>
                            <td>
                                <strong>
                                    <?= clean(($livraison['recept_prenom'] ?? '') . ' ' .
                                              ($livraison['recept_nom'] ?? '')) ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= clean($livraison['recept_email'] ?? '—') ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Fournisseur -->
            <div class="card">
                <div class="card-header">
                    <h2>🏢 Fournisseur</h2>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Entreprise</th>
                            <td><strong><?= clean($livraison['nom_entreprise']) ?></strong></td>
                        </tr>
                        <tr>
                            <th>Téléphone</th>
                            <td><?= clean($livraison['fournisseur_tel'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th>Département</th>
                            <td><?= clean($livraison['departement_demandeur']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

        </div>

        <!-- Articles livrés -->
        <div class="card">
            <div class="card-header">
                <h2>🖥️ Articles Livrés</h2>
                <span class="badge bg-info"><?= count($articles) ?> article(s)</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($articles)): ?>
                <div class="empty-state" style="padding:30px">
                    <p>Aucun article enregistré pour cette livraison</p>
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Catégorie</th>
                            <th>Matériel</th>
                            <th>Spécifications</th>
                            <th>Qté Commandée</th>
                            <th>Qté Livrée</th>
                            <th>Différence</th>
                            <th>Observation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($articles as $i => $a): ?>
                        <?php $diff = $a['quantite_livree'] - $a['quantite_commandee']; ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <span class="badge bg-info">
                                    <?= clean($a['nom_categorie']) ?>
                                </span>
                            </td>
                            <td><strong><?= clean($a['nom_materiel']) ?></strong></td>
                            <td><small><?= clean($a['specification_technique'] ?? '—') ?></small></td>
                            <td><?= $a['quantite_commandee'] ?></td>
                            <td><strong><?= $a['quantite_livree'] ?></strong></td>
                            <td>
                                <?php if ($diff == 0): ?>
                                    <span class="badge bg-success">✅ OK</span>
                                <?php elseif ($diff < 0): ?>
                                    <span class="badge bg-danger">⚠️ <?= $diff ?></span>
                                <?php else: ?>
                                    <span class="badge bg-info">+<?= $diff ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= clean($a['observation'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>