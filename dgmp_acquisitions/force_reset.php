<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Afficher TOUS les utilisateurs
    echo "<h3>📋 Utilisateurs dans la base :</h3>";
    $all = $pdo->query("SELECT id_utilisateur, nom, email, mot_de_passe, statut_inscription FROM utilisateurs");
    $users = $all->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        echo "❌ AUCUN utilisateur trouvé !<br>";
        echo "→ Vous devez relancer init_db.php<br>";
    } else {
        foreach ($users as $u) {
            echo "ID : " . $u['id_utilisateur'] . "<br>";
            echo "Nom : " . $u['nom'] . "<br>";
            echo "Email : " . $u['email'] . "<br>";
            echo "Hash : " . $u['mot_de_passe'] . "<br>";
            echo "Statut : " . $u['statut_inscription'] . "<br>";
            echo "---<br>";
        }
    }

    // Forcer nouveau mot de passe simple
    $nouveau_hash = password_hash('Admin123', PASSWORD_BCRYPT);

    $pdo->exec("UPDATE utilisateurs SET mot_de_passe = '$nouveau_hash'");

    echo "<br><h3>✅ Mot de passe réinitialisé pour TOUS les utilisateurs</h3>";
    echo "🔑 Nouveau mot de passe : <strong>Admin123</strong><br><br>";

    // Vérification immédiate
    echo "<h3>🔍 Vérification :</h3>";
    $all2 = $pdo->query("SELECT email, mot_de_passe FROM utilisateurs");
    foreach ($all2 as $u) {
        $ok = password_verify('Admin123', $u['mot_de_passe']);
        echo "→ " . $u['email'] . " : ";
        echo $ok ? "✅ OK" : "❌ ERREUR";
        echo "<br>";
    }

    echo "<br>========================================<br>";
    echo "🎉 Connectez-vous maintenant avec :<br>";
    echo "📧 Email : adminsysteme@gmail.com<br>";
    echo "🔑 Password : Admin123<br>";

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>