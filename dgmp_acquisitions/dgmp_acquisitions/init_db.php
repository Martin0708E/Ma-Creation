<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    
    // SQL pour créer la table
    $sql = "CREATE TABLE IF NOT EXISTS utilisateurs (
        id SERIAL PRIMARY KEY,
        nom VARCHAR(100),
        prenom VARCHAR(100),
        email VARCHAR(150) UNIQUE NOT NULL,
        mot_de_passe VARCHAR(255) NOT NULL,
        role VARCHAR(50) DEFAULT 'user'
    );";

    $pdo->exec($sql);
    echo "✅ Table 'utilisateurs' créée avec succès !<br>";

    // SQL pour ajouter l'utilisateur (on vérifie s'il existe déjà d'abord)
    $email = 'votre@email.com'; // <--- CHANGEZ CECI
    $pass = password_hash('password', PASSWORD_BCRYPT);
    
    $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE email = ?");
    $check->execute([$email]);
    
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe, role) VALUES (?, ?, ?, ?, ?)");
        $ins->execute(['Admin', 'DGMP', $email, $pass, 'admin']);
        echo "✅ Utilisateur admin créé (Mot de passe: password)";
    } else {
        echo "ℹ️ L'utilisateur existe déjà.";
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}