<?php
// Fichier racine - Redirection automatique
require_once 'config/database.php';

if (isset($_SESSION['utilisateur'])) {
    header('Location: dashboard/index.php');
} else {
    header('Location: auth/login.php');
}
exit();
?>


<?php
// Redirection automatique vers la page de connexion
header("Location: /auth/login.php");
exit();
?>