<?php
// ============================================
// CRÉER LES UTILISATEURS AVEC MOT DE PASSE HASHÉ
// SUPPRIMEZ CE FICHIER APRÈS UTILISATION !
// ============================================

$host = 'localhost';
$port = '5432';
$dbname = 'dgmp_acquisitions';
$user = 'postgres';
$pass = 'Ettien0708'; // ← Changez ici

try {
    $pdo = new PDO(
        "pgsql:host=$host;port=$port;dbname=$dbname",
        $user, $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Mot de passe pour tous les utilisateurs
    $mot_de_passe = 'Admin@123';
    $hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

    $utilisateurs = [
        [
            'nom'         => 'ADMIN',
            'prenom'      => 'Systeme',
            'email'       => 'admin@dgmp.bf',
            'role'        => 'admin',
            'departement' => 'Direction Generale'
        ],
        [
            'nom'         => 'OUEDRAOGO',
            'prenom'      => 'Jean',
            'email'       => 'jean.ouedraogo@dgmp.bf',
            'role'        => 'responsable',
            'departement' => 'Direction Informatique'
        ],
        [
            'nom'         => 'KABORE',
            'prenom'      => 'Marie',
            'email'       => 'marie.kabore@dgmp.bf',
            'role'        => 'agent',
            'departement' => 'Comptabilite'
        ],
        [
            'nom'         => 'SOME',
            'prenom'      => 'Paul',
            'email'       => 'paul.some@dgmp.bf',
            'role'        => 'validateur',
            'departement' => 'Direction Generale'
        ],
    ];

    echo "<h2>👥 Création des Utilisateurs</h2>";

    foreach ($utilisateurs as $u) {
        // Vérifier si l'utilisateur existe déjà
        $check = $pdo->prepare("SELECT COUNT(*) FROM utilisateurs WHERE email = :email");
        $check->execute([':email' => $u['email']]);
        
        if ($check->fetchColumn() > 0) {
            // Mettre à jour le mot de passe
            $stmt = $pdo->prepare("
                UPDATE utilisateurs 
                SET mot_de_passe = :hash 
                WHERE email = :email
            ");
            $stmt->execute([':hash' => $hash, ':email' => $u['email']]);
            echo "<p style='color:orange'>🔄 Mis à jour : <strong>{$u['prenom']} {$u['nom']}</strong> ({$u['email']})</p>";
        } else {
            // Insérer nouvel utilisateur
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs 
                (nom, prenom, email, mot_de_passe, role, departement)
                VALUES (:nom, :prenom, :email, :hash, :role, :dept)
            ");
            $stmt->execute([
                ':nom'    => $u['nom'],
                ':prenom' => $u['prenom'],
                ':email'  => $u['email'],
                ':hash'   => $hash,
                ':role'   => $u['role'],
                ':dept'   => $u['departement']
            ]);
            echo "<p style='color:green'>✅ Créé : <strong>{$u['prenom']} {$u['nom']}</strong> ({$u['email']})</p>";
        }
    }

    echo "<hr>";
    echo "<h3>🔑 Identifiants de connexion :</h3>";
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse'>";
    echo "<tr style='background:#1a237e;color:white'>
            <th>Nom</th><th>Email</th><th>Mot de passe</th><th>Rôle</th>
          </tr>";
    foreach ($utilisateurs as $u) {
        echo "<tr>
                <td>{$u['prenom']} {$u['nom']}</td>
                <td>{$u['email']}</td>
                <td><strong>Admin@123</strong></td>
                <td>{$u['role']}</td>
              </tr>";
    }
    echo "</table>";
    echo "<br>";
    echo "<p style='color:red;font-weight:bold'>
          ⚠️ SUPPRIMEZ CE FICHIER MAINTENANT : creer_utilisateurs.php
          </p>";
    echo "<a href='auth/login.php' style='
            display:inline-block;padding:14px 28px;
            background:#1a237e;color:white;
            border-radius:8px;text-decoration:none;
            font-size:16px;font-weight:bold;margin-top:10px
          '>🔐 Aller à la Connexion</a>";

} catch (PDOException $e) {
    echo "<p style='color:red'>❌ Erreur : " . $e->getMessage() . "</p>";
    echo "<p>Vérifiez votre mot de passe PostgreSQL dans ce fichier</p>";
}
?>