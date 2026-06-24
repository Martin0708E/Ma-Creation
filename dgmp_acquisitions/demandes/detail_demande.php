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

// Récupérer la demande
$stmt = $pdo->prepare("
    SELECT d.*, u.nom, u.prenom, u.email, u.departement as dept_user
    FROM demandes_acquisition d
    JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
    WHERE d.id_demande = :id
");
$stmt->execute([':id' => $id]);
$demande = $stmt->fetch();

if (!$demande) {
    header('Location: index.php');
    exit();
}

// Détails des articles
$details = $pdo->prepare("
    SELECT dd.*, m.nom_materiel, m.specification_technique, c.nom_categorie
    FROM details_demande dd
    JOIN materiels m ON dd.id_materiel = m.id_materiel
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    WHERE dd.id_demande = :id
");
$details->execute([':id' => $id]);
$details = $details->fetchAll();

// Historique validations
$validations = $pdo->prepare("
    SELECT v.*, u.nom, u.prenom, u.role
    FROM validations v
    JOIN utilisateurs u ON v.id_validateur = u.id_utilisateur
    WHERE v.id_demande = :id
    ORDER BY v.date_validation DESC
");
$validations->execute([':id' => $id]);
$validations = $validations->fetchAll();

// Calculer total
$total = 0;
foreach ($details as $d) {
    $total += ($d['quantite'] * ($d['prix_unitaire_estime'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Demande — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>📋 Détail de la Demande</h1>
                <p class="page-subtitle">
                    Référence : <strong><?= clean($demande['reference_demande']) ?></strong>
                </p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-secondary">← Retour</a>
                <button onclick="window.print()" class="btn btn-info">🖨️ Imprimer</button>
            </div>
        </div>

        <div class="detail-grid">

            <!-- Informations demande -->
            <div class="card">
                <div class="card-header">
                    <h2>📌 Informations Générales</h2>
                    <?= getBadgeStatut($demande['statut']) ?>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Référence</th>
                            <td><?= clean($demande['reference_demande']) ?></td>
                        </tr>
                        <tr>
                            <th>Demandeur</th>
                            <td><?= clean($demande['prenom'] . ' ' . $demande['nom']) ?></td>
                        </tr>
                        <tr>
                            <th>Département</th>
                            <td><?= clean($demande['departement_demandeur']) ?></td>
                        </tr>
                        <tr>
                            <th>Priorité</th>
                            <td><?= getBadgePriorite($demande['priorite']) ?></td>
                        </tr>
                        <tr>
                            <th>Budget Estimé</th>
                            <td><?= formaterMontant($demande['budget_estime']) ?></td>
                        </tr>
                        <tr>
                            <th>Date Demande</th>
                            <td><?= date('d/m/Y à H:i', strtotime($demande['date_demande'])) ?></td>
                        </tr>
                        <?php if ($demande['date_validation']): ?>
                        <tr>
                            <th>Date Validation</th>
                            <td><?= date('d/m/Y à H:i', strtotime($demande['date_validation'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Motif</th>
                            <td><?= nl2br(clean($demande['motif'])) ?></td>
                        </tr>
                        <?php if ($demande['commentaire']): ?>
                        <tr>
                            <th>Commentaire</th>
                            <td><?= nl2br(clean($demande['commentaire'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <!-- Articles demandés -->
            <div class="card">
                <div class="card-header">
                    <h2>🖥️ Articles Demandés</h2>
                    <span class="badge bg-info"><?= count($details) ?> article(s)</span>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Catégorie</th>
                                <th>Matériel</th>
                                <th>Spécifications</th>
                                <th>Qté</th>
                                <th>Prix Unitaire</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($details as $i => $det): ?>
                            <?php $sous_total = $det['quantite'] * ($det['prix_unitaire_estime'] ?? 0); ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= clean($det['nom_categorie']) ?></td>
                                <td><strong><?= clean($det['nom_materiel']) ?></strong></td>
                                <td><small><?= clean($det['specification_technique'] ?? '—') ?></small></td>
                                <td><strong><?= $det['quantite'] ?></strong></td>
                                <td><?= formaterMontant($det['prix_unitaire_estime']) ?></td>
                                <td><?= formaterMontant($sous_total) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="total-row">
                                <th colspan="6" class="text-right">TOTAL ESTIMÉ</th>
                                <th><?= formaterMontant($total) ?></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- Historique validations -->
            <?php if (!empty($validations)): ?>
            <div class="card">
                <div class="card-header">
                    <h2>📜 Historique des Validations</h2>
                </div>
                <div class="card-body">
                    <?php foreach ($validations as $v): ?>
                    <div class="validation-item">
                        <div class="validation-header">
                            <strong><?= clean($v['prenom'] . ' ' . $v['nom']) ?></strong>
                            <span class="badge bg-<?= $v['decision']==='approuve' ? 'success' : 'danger' ?>">
                                <?= $v['decision'] === 'approuve' ? '✅ Approuvé' : '❌ Rejeté' ?>
                            </span>
                        </div>
                        <div class="validation-body">
                            <small>Niveau <?= $v['niveau_validation'] ?> | 
                                   <?= date('d/m/Y H:i', strtotime($v['date_validation'])) ?></small>
                            <?php if ($v['commentaire']): ?>
                                <p><?= clean($v['commentaire']) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Boutons d'action -->
        <?php if (in_array($_SESSION['role'], ['admin', 'validateur']) 
              && $demande['statut'] === 'en_attente'): ?>
        <div class="action-panel">
            <a href="valider_demande.php?id=<?= $id ?>" class="btn btn-success btn-lg">
                ✅ Valider / Rejeter cette Demande
            </a>
        </div>
        <?php endif; ?>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>