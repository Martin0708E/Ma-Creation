<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo    = getConnexion();
$id_user = $_SESSION['id_utilisateur'];

// Marquer toutes comme lues automatiquement
$pdo->prepare("
    UPDATE notifications SET lu = TRUE
    WHERE id_utilisateur = :id AND lu = FALSE
")->execute([':id' => $id_user]);

// Filtre
$filtre_type = $_GET['type'] ?? '';
$filtre_lu   = $_GET['lu']   ?? '';

$where  = "WHERE n.id_utilisateur = :id";
$params = [':id' => $id_user];

if (!empty($filtre_type)) {
    $where .= " AND n.type = :type";
    $params[':type'] = $filtre_type;
}
if ($filtre_lu !== '') {
    $where .= " AND n.lu = :lu";
    $params[':lu'] = $filtre_lu === '1' ? 'true' : 'false';
}

// Récupérer notifications
$notifications = $pdo->prepare("
    SELECT n.*
    FROM notifications n
    $where
    ORDER BY n.date_creation DESC
");
$notifications->execute($params);
$notifications = $notifications->fetchAll();

// Stats notifications
$stats_notif = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN lu = FALSE THEN 1 ELSE 0 END) as non_lues,
        SUM(CASE WHEN type = 'info'    THEN 1 ELSE 0 END) as info,
        SUM(CASE WHEN type = 'succes'  THEN 1 ELSE 0 END) as succes,
        SUM(CASE WHEN type = 'alerte'  THEN 1 ELSE 0 END) as alerte,
        SUM(CASE WHEN type = 'erreur'  THEN 1 ELSE 0 END) as erreur
    FROM notifications
    WHERE id_utilisateur = :id
");
$stats_notif->execute([':id' => $id_user]);
$stats_notif = $stats_notif->fetch();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — DGMP</title>
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
                <h1>🔔 Notifications</h1>
                <p class="page-subtitle">
                    Toutes vos notifications et alertes système
                </p>
            </div>
            <div class="btn-group">
                <!-- Marquer tout comme lu -->
                <form method="POST" action="traitement.php" style="display:inline">
                    <input type="hidden" name="action" value="marquer_tout_lu">
                    <button type="submit" class="btn btn-secondary">
                        ✅ Tout marquer comme lu
                    </button>
                </form>
                <!-- Supprimer tout -->
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <form method="POST" action="traitement.php" style="display:inline"
                      onsubmit="return confirm('Supprimer toutes les notifications ?')">
                    <input type="hidden" name="action" value="supprimer_tout">
                    <button type="submit" class="btn btn-danger">
                        🗑️ Tout supprimer
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Statistiques -->
        <div class="stats-grid" style="grid-template-columns:repeat(5,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">🔔</div>
                <div class="stat-info">
                    <h3><?= $stats_notif['total'] ?></h3>
                    <p>Total</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">📬</div>
                <div class="stat-info">
                    <h3><?= $stats_notif['non_lues'] ?></h3>
                    <p>Non Lues</p>
                </div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon">ℹ️</div>
                <div class="stat-info">
                    <h3><?= $stats_notif['info'] ?></h3>
                    <p>Informations</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $stats_notif['succes'] ?></h3>
                    <p>Succès</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">⚠️</div>
                <div class="stat-info">
                    <h3><?= $stats_notif['alerte'] ?></h3>
                    <p>Alertes</p>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>📌 Type</label>
                            <select name="type" class="form-control">
                                <option value="">Tous les types</option>
                                <option value="info"   <?= $filtre_type=='info'  ?'selected':'' ?>>ℹ️ Information</option>
                                <option value="succes" <?= $filtre_type=='succes'?'selected':'' ?>>✅ Succès</option>
                                <option value="alerte" <?= $filtre_type=='alerte'?'selected':'' ?>>⚠️ Alerte</option>
                                <option value="erreur" <?= $filtre_type=='erreur'?'selected':'' ?>>❌ Erreur</option>
                            </select>
                        </div>
                        <div class="form-group col-4">
                            <label>📖 Statut</label>
                            <select name="lu" class="form-control">
                                <option value="">Toutes</option>
                                <option value="0" <?= $filtre_lu==='0'?'selected':'' ?>>📬 Non lues</option>
                                <option value="1" <?= $filtre_lu==='1'?'selected':'' ?>>📭 Lues</option>
                            </select>
                        </div>
                        <div class="form-group col-4 align-end">
                            <label>&nbsp;</label>
                            <div class="btn-group">
                                <button type="submit" class="btn btn-primary">🔍 Filtrer</button>
                                <a href="index.php" class="btn btn-secondary">🔄 Reset</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Liste des notifications -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Mes Notifications
                    <span class="badge bg-primary"><?= count($notifications) ?></span>
                </h2>
            </div>
            <div class="card-body p-0">

                <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🎉</div>
                    <h3>Aucune notification !</h3>
                    <p>Vous êtes à jour, aucune notification à afficher.</p>
                </div>

                <?php else: ?>

                <div class="notif-list">
                    <?php foreach ($notifications as $notif): ?>
                    <?php
                    $type_config = [
                        'info'   => ['info',    'ℹ️', 'Information'],
                        'succes' => ['success', '✅', 'Succès'],
                        'alerte' => ['warning', '⚠️', 'Alerte'],
                        'erreur' => ['danger',  '❌', 'Erreur'],
                    ];
                    $tc = $type_config[$notif['type']] ?? ['secondary', '🔔', 'Notification'];
                    ?>
                    <div class="notif-item <?= $notif['lu'] ? 'notif-lue' : 'notif-non-lue' ?>">
                        <div class="notif-icone notif-icone-<?= $tc[0] ?>">
                            <?= $tc[1] ?>
                        </div>
                        <div class="notif-contenu">
                            <div class="notif-header-item">
                                <span class="badge bg-<?= $tc[0] ?>"><?= $tc[2] ?></span>
                                <?php if (!$notif['lu']): ?>
                                    <span class="badge bg-warning">🆕 Nouveau</span>
                                <?php endif; ?>
                                <span class="notif-date">
                                    🕐 <?= date('d/m/Y à H:i', strtotime($notif['date_creation'])) ?>
                                </span>
                            </div>
                            <p class="notif-message"><?= clean($notif['message']) ?></p>
                        </div>
                        <div class="notif-actions">
                            <form method="POST" action="traitement.php">
                                <input type="hidden" name="action" value="supprimer_une">
                                <input type="hidden" name="id_notification"
                                       value="<?= $notif['id_notification'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger"
                                        title="Supprimer"
                                        onclick="return confirm('Supprimer cette notification ?')">
                                    🗑️
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php endif; ?>

            </div>
        </div>

    </main>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>