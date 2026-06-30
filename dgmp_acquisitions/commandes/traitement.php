<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo    = getConnexion();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ============================================
        // MODIFIER STATUT COMMANDE
        // ============================================
        case 'modifier_statut':
            verifierRole(['admin']);

            $id     = (int)($_POST['id_commande'] ?? 0);
            $statut = $_POST['statut']             ?? '';

            if (!$id || empty($statut)) {
                header('Location: index.php?erreur=' .
                    urlencode('Donnees invalides !'));
                exit();
            }

            $pdo->prepare("
                UPDATE commandes SET statut = :statut
                WHERE id_commande = :id
            ")->execute([
                ':statut' => $statut,
                ':id'     => $id
            ]);

            // Si livrée → mettre à jour demande
            if ($statut === 'livree') {
                $pdo->prepare("
                    UPDATE demandes_acquisition SET statut = 'livree'
                    WHERE id_demande = (
                        SELECT id_demande FROM commandes
                        WHERE id_commande = :id
                    )
                ")->execute([':id' => $id]);
            }

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Modification statut commande ID:" . $id .
                " → " . $statut,
                'commandes', $id);

            header('Location: index.php?success=' . urlencode(
                'Statut mis a jour !'
            ));
            break;

        // ============================================
        // DEFAULT
        // ============================================
        default:
            header('Location: index.php');
            break;
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?erreur=' . urlencode(
        'Erreur : ' . $e->getMessage()
    ));
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: index.php?erreur=' . urlencode(
        'Erreur : ' . $e->getMessage()
    ));
}
exit();
?>