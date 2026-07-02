<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Nouveau mot de passe
    $nouveau_mdp = password_hash('AdminSys', PASSWORD_BCRYPT);

    // Mettre à jour le mot de passe admin
    $stmt = $pdo->prepare("
        UPDATE utilisateurs 
        SET mot_de_passe = ? 
        WHERE email = ?
    ");

    $stmt->execute([
        $nouveau_mdp,
        'adminsysteme@gmail.com'
    ]);

    echo "✅ Mot de passe mis à jour avec succès !<br><br>";
    echo "📧 Email    : adminsysteme@gmail.com<br>";
    echo "🔑 Password : AdminSys<br><br>";

    // Vérification
    $check = $pdo->prepare("
        SELECT email, mot_de_passe, statut_inscription 
        FROM utilisateurs 
        WHERE email = ?
    ");
    $check->execute(['adminsysteme@gmail.com']);
    $user = $check->fetch();

    if ($user) {
        echo "✅ Utilisateur trouvé<br>";
        echo "✅ Statut : " . $user['statut_inscription'] . "<br>";

        if (password_verify('AdminSys', $user['mot_de_passe'])) {
            echo "✅ Mot de passe vérifié avec succès !<br>";
            echo "<br>🎉 Vous pouvez maintenant vous connecter !";
        } else {
            echo "❌ Erreur de vérification du mot de passe";
        }
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>