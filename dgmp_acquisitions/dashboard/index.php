<?php
// ============================================
// AUGMENTER LE TEMPS D'EXÉCUTION
// ============================================
set_time_limit(300);
ini_set('max_execution_time', 300);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo  = getConnexion();
$role = $_SESSION['role']           ?? 'agent';
$id   = $_SESSION['id_utilisateur'] ?? 0;
$nom  = $_SESSION['nom_complet']    ?? 'Utilisateur';

// ============================================
// STATISTIQUES SIMPLES (UNE PAR UNE)
// ============================================

// Total demandes
$total_demandes = $pdo->query("
    SELECT COUNT(*) as t
    FROM demandes_acquisition
")->fetch()['t'];

// En attente
$demandes_en_attente = $pdo->query("
    SELECT COUNT(*) as t
    FROM demandes_acquisition
    WHERE statut = 'en_attente'
")->fetch()['t'];

// Validées
$demandes_validees = $pdo->query("
    SELECT COUNT(*) as t
    FROM demandes_acquisition
    WHERE statut = 'validee'
")->fetch()['t'];

// Rejetées
$demandes_rejetees = $pdo->query("
    SELECT COUNT(*) as t
    FROM demandes_acquisition
    WHERE statut = 'rejetee'
")->fetch()['t'];

// Fournisseurs actifs
$total_fournisseurs = $pdo->query("
    SELECT COUNT(*) as t
    FROM fournisseurs
    WHERE statut = 'actif'
")->fetch()['t'];

// Commandes en attente validation
$commandes_attente = $pdo->query("
    SELECT COUNT(*) as t
    FROM commandes
    WHERE statut = 'en_attente_validation'
")->fetch()['t'];

// Commandes prêtes à livrer
$commandes_livrer = $pdo->query("
    SELECT COUNT(*) as t
    FROM commandes
    WHERE statut = 'validee'
")->fetch()['t'];

// Budget total
$budget_total = $pdo->query("
    SELECT COALESCE(SUM(montant_total), 0) as t
    FROM commandes
")->fetch()['t'];

// Total livraisons
$total_livraisons = $pdo->query("
    SELECT COUNT(*) as t
    FROM livraisons
")->fetch()['t'];

// Mes demandes (agent/validateur)
$mes_demandes = 0;
if (in_array($role, ['agent', 'validateur'])) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as t
        FROM demandes_acquisition
        WHERE id_utilisateur = :id
    ");
    $stmt->execute([':id' => $id]);
    $mes_demandes = $stmt->fetch()['t'];
}

// ============================================
// DERNIÈRES DEMANDES (SIMPLE)
// ============================================
if ($role === 'agent') {
    $stmt_dem = $pdo->prepare("
        SELECT d.id_demande,
               d.reference_demande,
               d.departement_demandeur,
               d.priorite,
               d.statut,
               d.date_demande,
               u.nom,
               u.prenom
        FROM demandes_acquisition d
        JOIN utilisateurs u
            ON d.id_utilisateur = u.id_utilisateur
        WHERE d.id_utilisateur = :id
        ORDER BY d.date_demande DESC
        LIMIT 5
    ");
    $stmt_dem->execute([':id' => $id]);
} else {
    $stmt_dem = $pdo->query("
        SELECT d.id_demande,
               d.reference_demande,
               d.departement_demandeur,
               d.priorite,
               d.statut,
               d.date_demande,
               u.nom,
               u.prenom
        FROM demandes_acquisition d
        JOIN utilisateurs u
            ON d.id_utilisateur = u.id_utilisateur
        ORDER BY d.date_demande DESC
        LIMIT 5
    ");
}
$dernieres_demandes = $stmt_dem->fetchAll();

// ============================================
// DERNIÈRES COMMANDES
// ============================================
$dernieres_commandes = [];
if (in_array($role, ['admin', 'responsable', 'validateur'])) {
    $dernieres_commandes = $pdo->query("
        SELECT c.id_commande,
               c.reference_commande,
               c.montant_total,
               c.date_commande,
               c.statut,
               f.nom_entreprise,
               d.reference_demande
        FROM commandes c
        JOIN fournisseurs f
            ON c.id_fournisseur = f.id_fournisseur
        JOIN demandes_acquisition d
            ON c.id_demande = d.id_demande
        ORDER BY c.date_creation DESC
        LIMIT 5
    ")->fetchAll();
}

// Notifications
$nb_notif = compterNotifications($pdo, $id);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tableau de Bord — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <!-- En-tête -->
        <div class="page-header">
            <div>
                <h1>📊 Tableau de Bord</h1>
                <p class="page-subtitle">
                    Bienvenue,
                    <strong><?= clean($nom) ?></strong>
                    &nbsp;|&nbsp;
                    <span class="badge bg-<?php
                        $colors = [
                            'admin'       => 'danger',
                            'responsable' => 'warning',
                            'agent'       => 'success',
                            'validateur'  => 'purple',
                        ];
                        echo $colors[$role] ?? 'secondary';
                    ?>">
                        <?= ucfirst($role) ?>
                    </span>
                    &nbsp;|&nbsp;
                    📅 <?= date('d/m/Y H:i') ?>
                </p>
            </div>

            <!-- Boutons rapides -->
            <div class="btn-group">

                <!-- Nouvelle demande : tous -->
                <a href="../demandes/nouvelle_demande.php"
                   class="btn btn-primary">
                    ➕ Nouvelle Demande
                </a>

                <!-- Responsable -->
                <?php if ($role === 'responsable'
                          && $demandes_en_attente > 0): ?>
                <a href="../demandes/valider_demande.php"
                   class="btn btn-warning">
                    ✅ Valider
                    (<?= $demandes_en_attente ?>)
                </a>
                <?php endif; ?>

                <!-- Validateur -->
                <?php if ($role === 'validateur'
                          && $commandes_attente > 0): ?>
                <a href="../commandes/index.php"
                   class="btn btn-success">
                    🛒 Commandes
                    (<?= $commandes_attente ?>)
                </a>
                <?php endif; ?>

                <?php if ($role === 'validateur'
                          && $commandes_livrer > 0): ?>
                <a href="../livraisons/index.php"
                   class="btn btn-info">
                    📦 Livrer
                    (<?= $commandes_livrer ?>)
                </a>
                <?php endif; ?>

            </div>
        </div>

        <!-- ============================================ -->
        <!-- ALERTES                                      -->
        <!-- ============================================ -->

        <?php if ($role === 'responsable'
                  && $demandes_en_attente > 0): ?>
        <div class="alert alert-warning">
            ⚠️ <strong><?= $demandes_en_attente ?></strong>
            demande(s) en attente de validation !
            <a href="../demandes/valider_demande.php"
               class="btn btn-sm btn-warning"
               style="margin-left:12px">
                ✅ Valider maintenant
            </a>
        </div>
        <?php endif; ?>

        <?php if ($role === 'validateur'
                  && $commandes_attente > 0): ?>
        <div class="alert alert-info">
            🛒 <strong><?= $commandes_attente ?></strong>
            commande(s) en attente de validation !
            <a href="../commandes/index.php"
               class="btn btn-sm btn-info"
               style="margin-left:12px">
                → Voir les commandes
            </a>
        </div>
        <?php endif; ?>

        <?php if ($role === 'validateur'
                  && $commandes_livrer > 0): ?>
        <div class="alert alert-success">
            📦 <strong><?= $commandes_livrer ?></strong>
            commande(s) prête(s) à livrer !
            <a href="../livraisons/index.php"
               class="btn btn-sm btn-success"
               style="margin-left:12px">
                → Gérer livraisons
            </a>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- STATISTIQUES                                 -->
        <!-- ============================================ -->

        <?php if ($role === 'admin'): ?>
        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon">📋</div>
                <div class="stat-info">
                    <h3><?= $total_demandes ?></h3>
                    <p>Total Demandes</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⏳</div>
                <div class="stat-info">
                    <h3><?= $demandes_en_attente ?></h3>
                    <p>En Attente</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $demandes_validees ?></h3>
                    <p>Validées</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">❌</div>
                <div class="stat-info">
                    <h3><?= $demandes_rejetees ?></h3>
                    <p>Rejetées</p>
                </div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon">🏢</div>
                <div class="stat-info">
                    <h3><?= $total_fournisseurs ?></h3>
                    <p>Fournisseurs</p>
                </div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon">🛒</div>
                <div class="stat-info">
                    <h3><?= $commandes_attente ?></h3>
                    <p>Commandes</p>
                </div>
            </div>
            <div class="stat-card gold">
                <div class="stat-icon">💰</div>
                <div class="stat-info">
                    <h3><?= formaterMontant($budget_total) ?></h3>
                    <p>Budget Total</p>
                </div>
            </div>
            <div class="stat-card indigo">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <h3><?= $total_livraisons ?></h3>
                    <p>Livraisons</p>
                </div>
            </div>
        </div>

        <?php elseif ($role === 'responsable'): ?>
        <div class="stats-grid"
             style="grid-template-columns:repeat(4,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">📋</div>
                <div class="stat-info">
                    <h3><?= $total_demandes ?></h3>
                    <p>Total Demandes</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⏳</div>
                <div class="stat-info">
                    <h3><?= $demandes_en_attente ?></h3>
                    <p>À Valider</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $demandes_validees ?></h3>
                    <p>Approuvées</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">❌</div>
                <div class="stat-info">
                    <h3><?= $demandes_rejetees ?></h3>
                    <p>Rejetées</p>
                </div>
            </div>
        </div>

        <?php elseif ($role === 'validateur'): ?>
        <div class="stats-grid"
             style="grid-template-columns:repeat(4,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">📋</div>
                <div class="stat-info">
                    <h3><?= $mes_demandes ?></h3>
                    <p>Mes Demandes</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">🛒</div>
                <div class="stat-info">
                    <h3><?= $commandes_attente ?></h3>
                    <p>À Valider</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <h3><?= $commandes_livrer ?></h3>
                    <p>À Livrer</p>
                </div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $total_livraisons ?></h3>
                    <p>Livraisons</p>
                </div>
            </div>
        </div>

        <?php elseif ($role === 'agent'): ?>
        <div class="stats-grid"
             style="grid-template-columns:repeat(3,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">📋</div>
                <div class="stat-info">
                    <h3><?= $mes_demandes ?></h3>
                    <p>Mes Demandes</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⏳</div>
                <div class="stat-info">
                    <h3><?= $demandes_en_attente ?></h3>
                    <p>En Attente</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $demandes_validees ?></h3>
                    <p>Validées</p>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TABLEAU DEMANDES                             -->
        <!-- ============================================ -->
        <div class="card mb-3">
            <div class="card-header">
                <h2>
                    📋
                    <?php if ($role === 'agent'): ?>
                        Mes Dernières Demandes
                    <?php elseif ($role === 'responsable'): ?>
                        Demandes Récentes
                    <?php else: ?>
                        Dernières Demandes
                    <?php endif; ?>
                </h2>
                <a href="../demandes/index.php"
                   class="btn btn-sm btn-secondary">
                    Voir tout →
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Référence</th>
                                <?php if ($role !== 'agent'): ?>
                                <th>Demandeur</th>
                                <?php endif; ?>
                                <th>Département</th>
                                <th>Priorité</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dernieres_demandes)): ?>
                            <tr>
                                <td colspan="8"
                                    class="text-center py-4">
                                    😕 Aucune demande
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($dernieres_demandes
                                           as $i => $d): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($d['reference_demande']) ?>
                                    </strong>
                                </td>
                                <?php if ($role !== 'agent'): ?>
                                <td>
                                    <?= clean($d['prenom'] . ' ' .
                                              $d['nom']) ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?= clean($d['departement_demandeur']) ?>
                                </td>
                                <td>
                                    <?= getBadgePriorite($d['priorite']) ?>
                                </td>
                                <td>
                                    <?= getBadgeStatut($d['statut']) ?>
                                </td>
                                <td>
                                    <?= date('d/m/Y',
                                        strtotime($d['date_demande'])) ?>
                                </td>
                                <td>
                                    <a href="../demandes/detail_demande.php?id=<?= $d['id_demande'] ?>"
                                       class="btn btn-sm btn-info">
                                        👁️
                                    </a>
                                    <?php if ($role === 'responsable'
                                              && $d['statut'] === 'en_attente'): ?>
                                    <a href="../demandes/valider_demande.php"
                                       class="btn btn-sm btn-warning">
                                        ✅
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- TABLEAU COMMANDES                            -->
        <!-- ============================================ -->
        <?php if (!empty($dernieres_commandes)): ?>
        <div class="card">
            <div class="card-header">
                <h2>🛒 Dernières Commandes</h2>
                <a href="../commandes/index.php"
                   class="btn btn-sm btn-secondary">
                    Voir tout →
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Réf. Commande</th>
                                <th>Réf. Demande</th>
                                <th>Fournisseur</th>
                                <th>Montant</th>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dernieres_commandes
                                           as $i => $c): ?>
                            <?php
                            $sc = [
                                'en_attente_validation' =>
                                    ['warning', '⏳ En Attente'],
                                'validee' =>
                                    ['success', '✅ Validée'],
                                'rejetee' =>
                                    ['danger',  '❌ Rejetée'],
                                'en_cours' =>
                                    ['info',    '🚚 En cours'],
                                'livree' =>
                                    ['success', '📦 Livrée'],
                            ];
                            $sb = $sc[$c['statut']]
                                  ?? ['secondary', $c['statut']];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($c['reference_commande']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= clean($c['reference_demande']) ?>
                                </td>
                                <td>
                                    <?= clean($c['nom_entreprise']) ?>
                                </td>
                                <td>
                                    <strong style="color:var(--primary)">
                                        <?= formaterMontant($c['montant_total']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= date('d/m/Y',
                                        strtotime($c['date_commande'])) ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $sb[0] ?>">
                                        <?= $sb[1] ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="../commandes/detail.php?id=<?= $c['id_commande'] ?>"
                                       class="btn btn-sm btn-info">
                                        👁️
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>