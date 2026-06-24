<?php
// ============================================
// API AJAX : Récupérer articles d'une commande
// ============================================
require_once '../config/database.php';

header('Content-Type: application/json');

$id_commande = (int)($_GET['id_commande'] ?? 0);

if (!$id_commande) {
    echo json_encode([]);
    exit();
}

try {
    $pdo = getConnexion();

    $articles = $pdo->prepare("
        SELECT
            dd.id_materiel,
            dd.quantite,
            dd.prix_unitaire_estime,
            m.nom_materiel,
            m.specification_technique,
            c.nom_categorie
        FROM details_demande dd
        JOIN materiels m ON dd.id_materiel = m.id_materiel
        JOIN categories_materiel c ON m.id_categorie = c.id_categorie
        JOIN commandes cmd ON dd.id_demande = cmd.id_demande
        WHERE cmd.id_commande = :id
        ORDER BY c.nom_categorie, m.nom_materiel
    ");
    $articles->execute([':id' => $id_commande]);

    echo json_encode($articles->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    echo json_encode(['erreur' => $e->getMessage()]);
}
exit();
?>