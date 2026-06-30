<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin', 'responsable']);

$pdo    = getConnexion();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ---- AJOUTER ----
        case 'ajouter':
            $id_materiel  = (int)($_POST['id_materiel'] ?? 0);
            $id_livraison = !empty($_POST['id_livraison']) ? (int)$_POST['id_livraison'] : null;
            $numero_serie = trim($_POST['numero_serie'] ?? '');
            $etat         = $_POST['etat'] ?? 'neuf';
            $localisation = trim($_POST['localisation'] ?? '');
            $date_entree  = $_POST['date_entree'] ?? date('Y-m-d');

            if (!$id_materiel || empty($localisation)) {
                header('Location: index.php?erreur=' . urlencode('Matériel et localisation obligatoires !'));
                exit();
            }

            $stmt = $pdo->prepare("
                INSERT INTO inventaire
                (id_materiel, id_livraison, numero_serie, etat, localisation, date_entree)
                VALUES (:mat, :liv, :serie, :etat, :loc, :date)
            ");
            $stmt->execute([
                ':mat'   => $id_materiel,
                ':liv'   => $id_livraison,
                ':serie' => !empty($numero_serie) ? $numero_serie : null,
                ':etat'  => $etat,
                ':loc'   => $localisation,
                ':date'  => $date_entree
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                      "Ajout inventaire équipement ID:{$id_materiel}", 'inventaire');

            header('Location: index.php?success=' . urlencode('Équipement ajouté à l\'inventaire avec succès !'));
            break;

        // ---- MODIFIER ----
        case 'modifier':
            $id           = (int)($_POST['id_inventaire'] ?? 0);
            $etat         = $_POST['etat'] ?? 'bon';
            $localisation = trim($_POST['localisation'] ?? '');
            $numero_serie = trim($_POST['numero_serie'] ?? '');

            if (!$id || empty($localisation)) {
                header('Location: index.php?erreur=' . urlencode('Données invalides !'));
                exit();
            }

            $stmt = $pdo->prepare("
                UPDATE inventaire SET
                    etat         = :etat,
                    localisation = :loc,
                    numero_serie = :serie
                WHERE id_inventaire = :id
            ");
            $stmt->execute([
                ':etat'  => $etat,
                ':loc'   => $localisation,
                ':serie' => !empty($numero_serie) ? $numero_serie : null,
                ':id'    => $id
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                      "Modification inventaire ID:{$id}", 'inventaire', $id);

            header('Location: index.php?success=' . urlencode('Équipement modifié avec succès !'));
            break;

        // ---- SUPPRIMER ----
        case 'supprimer':
            verifierRole(['admin']);
            $id = (int)($_POST['id_inventaire'] ?? 0);

            if (!$id) {
                header('Location: index.php?erreur=' . urlencode('ID invalide !'));
                exit();
            }

            $pdo->prepare("DELETE FROM inventaire WHERE id_inventaire = :id")
                ->execute([':id' => $id]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                      "Suppression inventaire ID:{$id}", 'inventaire', $id);

            header('Location: index.php?success=' . urlencode('Équipement supprimé de l\'inventaire !'));
            break;

        default:
            header('Location: index.php');
    }

} catch (PDOException $e) {
    header('Location: index.php?erreur=' . urlencode('Erreur : ' . $e->getMessage()));
}
exit();
?>