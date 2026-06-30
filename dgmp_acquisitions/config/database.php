<?php
// ============================================
// CONFIGURATION BASE DE DONNÉES POSTGRESQL
// ============================================

define('DB_HOST', 'localhost');
define('DB_PORT', '5432');
define('DB_NAME', 'dgmp_acquisitions');
define('DB_USER', 'postgres');
define('DB_PASS', 'Ettien0708'); // ← Votre mot de passe

function getConnexion() {
    try {
        $dsn = "pgsql:host=" . DB_HOST .
               ";port=" . DB_PORT .
               ";dbname=" . DB_NAME;
        $pdo = new PDO($dsn, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch(PDOException $e) {
        die("<div style='color:red;padding:20px;font-family:Arial'>
            <h3>❌ Erreur de connexion PostgreSQL</h3>
            <p>" . $e->getMessage() . "</p>
        </div>");
    }
}

// ============================================
// DÉMARRAGE SESSION - TOUJOURS EN PREMIER !
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?>