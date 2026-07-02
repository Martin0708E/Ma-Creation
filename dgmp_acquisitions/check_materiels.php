<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Compter les matériels
    $count = $pdo->query("SELECT COUNT(*) FROM materiels")->fetchColumn();
    echo "✅ Total matériels : " . $count . "<br><br>";

    // Afficher tous les matériels
    $stmt = $pdo->query("
        SELECT m.id_materiel, m.nom_materiel, 
               m.prix_unitaire, c.nom_categorie 
        FROM materiels m
        LEFT JOIN categories_materiel c 
        ON m.id_categorie = c.id_categorie
        ORDER BY c.nom_categorie
    ");

    $materiels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($materiels)) {
        echo "❌ Aucun matériel trouvé dans la base !<br>";
    } else {
        echo "<h3>📋 Liste des matériels :</h3>";
        foreach ($materiels as $m) {
            echo "→ [" . $m['nom_categorie'] . "] ";
            echo $m['nom_materiel'] . " - ";
            echo number_format($m['prix_unitaire'], 0, ',', ' ') . " FCFA<br>";
        }
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>