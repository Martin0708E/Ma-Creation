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

// Vérifier si la table livreurs existe
$table_livreurs_existe = false;
try {
    $check = $pdo->query("
        SELECT EXISTS (
            SELECT FROM information_schema.tables
            WHERE table_name = 'livreurs'
        ) as existe
    ");
    $table_livreurs_existe = $check->fetch()['existe'];
} catch (Exception $e) {
    $table_livreurs_existe = false;
}

// Construire la requête selon si livreurs existe
if ($table_livreurs_existe) {
    $sql = "
        SELECT c.*,
               f.nom_entreprise, f.email as fournisseur_email,
               f.telephone as fournisseur_tel,
               f.ville as fournisseur_ville,
               d.reference_demande, d.departement_demandeur,
               d.motif, d.priorite, d.date_demande,
               u.nom as demandeur_nom, u.prenom as demandeur_prenom,
               u.email as demandeur_email,
               liv.nom as livreur_nom, liv.prenom as livreur_prenom,
               liv.telephone as livreur_tel,
               liv.entreprise as livreur_entreprise
        FROM commandes c
        JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
        JOIN demandes_acquisition d ON c.id_demande = d.id_demande
        JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
        LEFT JOIN livreurs liv ON c.id_livreur = liv.id_livreur
        WHERE c.id_commande = :id
    ";
} else {
    $sql = "
        SELECT c.*,
               f.nom_entreprise, f.email as fournisseur_email,
               f.telephone as fournisseur_tel,
               f.ville as fournisseur_ville,
               d.reference_demande, d.departement_demandeur,
               d.motif, d.priorite, d.date_demande,
               u.nom as demandeur_nom, u.prenom as demandeur_prenom,
               u.email as demandeur_email,
               NULL as livreur_nom,
               NULL as livreur_prenom,
               NULL as livreur_tel,
               NULL as livreur_entreprise
        FROM commandes c
        JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
        JOIN demandes_acquisition d ON c.id_demande = d.id_demande
        JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
        WHERE c.id_commande = :id
    ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$commande = $stmt->fetch();

if (!$commande) {
    header('Location: index.php');
    exit();
}

// Articles de la demande
$articles = $pdo->prepare("
    SELECT dd.*,
           m.nom_materiel,
           m.specification_technique,
           c.nom_categorie
    FROM details_demande dd
    JOIN materiels m ON dd.id_materiel = m.id_materiel
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    WHERE dd.id_demande = :id
");
$articles->execute([':id' => $commande['id_demande']]);
$articles = $articles->fetchAll();

// Livraisons associées
$livraisons = $pdo->prepare("
    SELECT l.*,
           u.nom, u.prenom
    FROM livraisons l
    LEFT JOIN utilisateurs u ON l.id_receptionnaire = u.id_utilisateur
    WHERE l.id_commande = :id
    ORDER BY l.date_livraison DESC
");
$livraisons->execute([':id' => $id]);
$livraisons = $livraisons->fetchAll();

// Total articles
$total = 0;
foreach ($articles as $a) {
    $total += ($a['quantite'] * ($a['prix_unitaire_estime'] ?? 0));
}

// Badge statut
$statut_cmd = [
    'en_cours'             => ['warning', '⏳ En cours'],
    'livree'               => ['success', '✅ Livrée'],
    'annulee'              => ['danger',  '❌ Annulée'],
    'partiellement_livree' => ['info',    '📦 Partielle'],
];
$sc = $statut_cmd[$commande['statut']] ?? ['secondary', $commande['statut']];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Détail Commande — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>🛒 Détail de la Commande</h1>
                <p class="page-subtitle">
                    Référence :
                    <strong><?= clean($commande['reference_commande']) ?></strong>
                </p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn btn-secondary">← Retour</a>
                <button onclick="window.print()" class="btn btn-info">
                    🖨️ Imprimer
                </button>
            </div>
        </div>

        <!-- Grille infos -->
        <div class="detail-header-grid">

            <!-- Commande -->
            <div class="card">
                <div class="card-header">
                    <h2>🛒 Commande</h2>
                    <span class="badge bg-<?= $sc[0] ?>" style="font-size:14px">
                        <?= $sc[1] ?>
                    </span>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Réf. Commande</th>
                            <td>
                                <strong>
                                    <?= clean($commande['reference_commande']) ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Réf. Demande</th>
                            <td>
                                <a href="../demandes/detail_demande.php?id=<?= $commande['id_demande'] ?>">
                                    <?= clean($commande['reference_demande']) ?>
                                </a>
                            </td>
                        </tr>
                        <tr>
                            <th>Montant Total</th>
                            <td style="font-size:18px;font-weight:800;color:var(--primary)">
                                <?= formaterMontant($commande['montant_total']) ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Date Commande</th>
                            <td>
                                <?= date('d/m/Y', strtotime($commande['date_commande'])) ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Livraison Prévue</th>
                            <td>
                                <?= $commande['date_livraison_prevue']
                                    ? date('d/m/Y', strtotime($commande['date_livraison_prevue']))
                                    : '—' ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Priorité</th>
                            <td><?= getBadgePriorite($commande['priorite']) ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Demandeur -->
            <div class="card">
                <div class="card-header">
                    <h2>👤 Demandeur</h2>
                </div>
                <div class="card-body">
                    <table class="table-detail">
                        <tr>
                            <th>Nom</th>
                            <td>
                                <strong>
                                    <?= clean($commande['demandeur_prenom'] . ' ' .
                                              $commande['demandeur_nom']) ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= clean($commande['demandeur_email']) ?></td>
                        </tr>
                        <tr>
                            <th>Département</th>
                            <td><?= clean($commande['departement_demandeur']) ?></td>
                        </tr>
                        <tr>
                            <th>Motif</th>
                            <td><?= nl2br(clean($commande['motif'])) ?></td>
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
                            <td>
                                <strong><?= clean($commande['nom_entreprise']) ?></strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Email</th>
                            <td><?= clean($commande['fournisseur_email'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th>Téléphone</th>
                            <td><?= clean($commande['fournisseur_tel'] ?? '—') ?></td>
                        </tr>
                        <tr>
                            <th>Ville</th>
                            <td><?= clean($commande['fournisseur_ville'] ?? '—') ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Livreur -->
            <div class="card">
                <div class="card-header">
                    <h2>🚚 Livreur Assigné</h2>
                </div>
                <div class="card-body">
                    <?php if (!empty($commande['livreur_nom'])): ?>
                    <table class="table-detail">
                        <tr>
                            <th>Nom</th>
                            <td>
                                <strong>
                                    <?= clean($commande['livreur_prenom'] . ' ' .
                                              $commande['livreur_nom']) ?>
                                </strong>
                            </td>
                        </tr>
                        <tr>
                            <th>Entreprise</th>
                            <td>
                                <?= clean($commande['livreur_entreprise'] ?? '—') ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Téléphone</th>
                            <td><?= clean($commande['livreur_tel'] ?? '—') ?></td>
                        </tr>
                    </table>
                    <?php else: ?>
                    <div class="empty-state" style="padding:20px">
                        <p>🚚 Aucun livreur assigné</p>
                        <?php if (!$table_livreurs_existe): ?>
                        <small class="text-muted">
                            ⚠️ Exécutez le SQL pour créer la table livreurs
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Articles commandés -->
        <div class="card mb-3">
            <div class="card-header">
                <h2>🖥️ Articles Commandés</h2>
                <span class="badge bg-info"><?= count($articles) ?> article(s)</span>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Catégorie</th>
                            <th>Matériel</th>
                            <th>Spécifications</th>
                            <th>Quantité</th>
                            <th>Prix Unitaire</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($articles)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-3">
                                Aucun article trouvé
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($articles as $i => $a): ?>
                        <?php $sous_total = $a['quantite'] * ($a['prix_unitaire_estime'] ?? 0); ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <span class="badge bg-info">
                                    <?= clean($a['nom_categorie']) ?>
                                </span>
                            </td>
                            <td><strong><?= clean($a['nom_materiel']) ?></strong></td>
                            <td>
                                <small><?= clean($a['specification_technique'] ?? '—') ?></small>
                            </td>
                            <td><strong><?= $a['quantite'] ?></strong></td>
                            <td><?= formaterMontant($a['prix_unitaire_estime']) ?></td>
                            <td><strong><?= formaterMontant($sous_total) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <th colspan="6" class="text-right">
                                💰 MONTANT TOTAL
                            </th>
                            <th style="font-size:16px;color:var(--primary)">
                                <?= formaterMontant($total) ?>
                            </th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <!-- Livraisons -->
        <div class="card">
            <div class="card-header">
                <h2>📦 Livraisons Associées</h2>
                <span class="badge bg-info"><?= count($livraisons) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($livraisons)): ?>
                <div class="empty-state" style="padding:30px">
                    <div class="empty-icon">📦</div>
                    <h3>Aucune livraison enregistrée</h3>
                    <p>Cette commande n'a pas encore été livrée</p>
                    <?php if ($commande['statut'] === 'en_cours'
                              && in_array($_SESSION['role'], ['admin','responsable'])): ?>
                    <a href="../livraisons/nouvelle_livraison.php?cmd=<?= $id ?>"
                       class="btn btn-primary" style="margin-top:12px">
                        ➕ Enregistrer une Livraison
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date Livraison</th>
                            <th>Réceptionné par</th>
                            <th>Statut</th>
                            <th>Observation</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($livraisons as $i => $l): ?>
                        <?php
                        $bl = [
                            'conforme'     => ['success','✅ Conforme'],
                            'non_conforme' => ['danger', '❌ Non conforme'],
                            'partielle'    => ['warning','📦 Partielle'],
                        ];
                        $bls = $bl[$l['statut']] ?? ['secondary',$l['statut']];
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <?= date('d/m/Y', strtotime($l['date_livraison'])) ?>
                            </td>
                            <td>
                                <?= clean(($l['prenom'] ?? '') . ' ' . ($l['nom'] ?? '')) ?>
                            </td>
                            <td>
                                <span class="badge bg-<?= $bls[0] ?>">
                                    <?= $bls[1] ?>
                                </span>
                            </td>
                            <td><?= clean($l['observation'] ?? '—') ?></td>
                            <td>
                                <a href="../livraisons/detail_livraison.php?id=<?= $l['id_livraison'] ?>"
                                   class="btn btn-sm btn-info">
                                    👁️ Voir
                                </a>
                            </td>
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