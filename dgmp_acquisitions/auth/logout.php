<?php
// ============================================
// LOGOUT SÉCURISÉ - SANS ERREUR BD
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Récupérer id avant destruction
$id_user = $_SESSION['id_utilisateur'] ?? null;

// Détruire la session EN PREMIER
$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

// Enregistrer log APRÈS destruction session
// Dans un try-catch pour ne jamais bloquer
if ($id_user) {
    try {
        require_once '../config/database.php';
        $pdo = getConnexion();

        // Double vérification existence utilisateur
        $stmt = $pdo->prepare("
            SELECT id_utilisateur
            FROM utilisateurs
            WHERE id_utilisateur = :id
            AND statut = TRUE
            LIMIT 1
        ");
        $stmt->execute([':id' => $id_user]);

        if ($stmt->fetch()) {
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action, table_concernee)
                VALUES (:id, 'Deconnexion', 'utilisateurs')
            ")->execute([':id' => $id_user]);
        }
    } catch (Exception $e) {
        // Ignorer toute erreur
    }
}

// Toujours rediriger
header('Location: ../auth/login.php');
exit();
?>