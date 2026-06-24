<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo     = getConnexion();
$action  = $_POST['action'] ?? '';
$id_user = $_SESSION['id_utilisateur'];

try {
    switch ($action) {

        // ---- MARQUER TOUT COMME LU ----
        case 'marquer_tout_lu':
            $pdo->prepare("
                UPDATE notifications SET lu = TRUE
                WHERE id_utilisateur = :id
            ")->execute([':id' => $id_user]);

            header('Location: index.php?success=' . urlencode('Toutes les notifications marquées comme lues !'));
            break;

        // ---- SUPPRIMER UNE NOTIFICATION ----
        case 'supprimer_une':
            $id_notif = (int)($_POST['id_notification'] ?? 0);

            if (!$id_notif) {
                header('Location: index.php');
                exit();
            }

            // Vérifier que c'est bien la notification de cet utilisateur
            $check = $pdo->prepare("
                SELECT id_notification FROM notifications
                WHERE id_notification = :id AND id_utilisateur = :user
            ");
            $check->execute([':id' => $id_notif, ':user' => $id_user]);

            if ($check->fetch()) {
                $pdo->prepare("
                    DELETE FROM notifications WHERE id_notification = :id
                ")->execute([':id' => $id_notif]);
            }

            header('Location: index.php?success=' . urlencode('Notification supprimée !'));
            break;

        // ---- SUPPRIMER TOUTES (ADMIN) ----
        case 'supprimer_tout':
            if ($_SESSION['role'] !== 'admin') {
                header('Location: index.php');
                exit();
            }

            $pdo->prepare("
                DELETE FROM notifications WHERE id_utilisateur = :id
            ")->execute([':id' => $id_user]);

            header('Location: index.php?success=' . urlencode('Toutes les notifications supprimées !'));
            break;

        default:
            header('Location: index.php');
    }

} catch (PDOException $e) {
    header('Location: index.php?erreur=' . urlencode('Erreur : ' . $e->getMessage()));
}
exit();
?>