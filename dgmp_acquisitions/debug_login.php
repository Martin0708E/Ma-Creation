<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Chercher l'utilisateur
    $stmt = $pdo->prepare("SELECT * FROM utilisateurs WHERE email = ?");
    $stmt->execute(['adminsysteme@gmail.com']);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        echo "✅ Utilisateur trouvé<br><br>";
        echo "📧 Email : " . $user['email'] . "<br>";
        echo "👤 Nom : " . $user['nom'] . "<br>";
        echo "🔑 Role : " . $user['role'] . "<br>";
        echo "📋 Statut : " . $user['statut_inscription'] . "<br>";
        echo "🔒 Mot de passe stocké : " . $user['mot_de_passe'] . "<br><br>";

        // Test password_verify
        $test1 = password_verify('AdminSys', $user['mot_de_passe']);
        echo "Test 'AdminSys' : " . ($test1 ? "✅ CORRECT" : "❌ INCORRECT") . "<br>";

        $test2 = password_verify('adminsys', $user['mot_de_passe']);
        echo "Test 'adminsys' : " . ($test2 ? "✅ CORRECT" : "❌ INCORRECT") . "<br>";

        $test3 = password_verify('Ettien0708', $user['mot_de_passe']);
        echo "Test 'Ettien0708' : " . ($test3 ? "✅ CORRECT" : "❌ INCORRECT") . "<br>";

    } else {
        echo "❌ Utilisateur NON trouvé avec cet email<br><br>";

        // Afficher tous les utilisateurs
        echo "📋 Liste de tous les utilisateurs :<br>";
        $all = $pdo->query("SELECT email, statut_inscription FROM utilisateurs");
        foreach ($all as $u) {
            echo "→ " . $u['email'] . " (" . $u['statut_inscription'] . ")<br>";
        }
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>