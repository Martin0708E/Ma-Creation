<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin']);

$pdo    = getConnexion();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        case 'ajouter':
            $nom   = trim($_POST['nom_materiel'] ?? '');
            $cat   = (int)($_POST['id_categorie'] ?? 0);
            $desc  = trim($_POST['description'] ?? '');
            $spec  = trim($_POST['specification_technique'] ?? '');
            $unite = trim($_POST['unite_mesure'] ?? 'Unite');
            $prix  = (float)($_POST['prix_unitaire'] ?? 0);

            if (empty($nom) || !$cat) {
                header('Location: index.php?erreur=' .
                    urlencode('Nom et catégorie obligatoires !'));
                exit();
            }

            $pdo->prepare("
                INSERT INTO materiels
                (id_categorie, nom_materiel, description,
                 specification_technique, unite_mesure, prix_unitaire)
                VALUES (:cat, :nom, :desc, :spec, :unite, :prix)
            ")->execute([
                ':cat'   => $cat,
                ':nom'   => $nom,
                ':desc'  => $desc,
                ':spec'  => $spec,
                ':unite' => $unite,
                ':prix'  => $prix
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Ajout matériel: {$nom} - Prix: {$prix} FCFA",
                'materiels');

            header('Location: index.php?success=' .
                urlencode("Matériel '{$nom}' ajouté avec succès !"));
            break;

        case 'modifier':
            $id    = (int)($_POST['id_materiel'] ?? 0);
            $nom   = trim($_POST['nom_materiel'] ?? '');
            $cat   = (int)($_POST['id_categorie'] ?? 0);
            $desc  = trim($_POST['description'] ?? '');
            $spec  = trim($_POST['specification_technique'] ?? '');
            $unite = trim($_POST['unite_mesure'] ?? 'Unite');
            $prix  = (float)($_POST['prix_unitaire'] ?? 0);

            $pdo->prepare("
                UPDATE materiels SET
                    id_categorie            = :cat,
                    nom_materiel            = :nom,
                    description             = :desc,
                    specification_technique = :spec,
                    unite_mesure            = :unite,
                    prix_unitaire           = :prix
                WHERE id_materiel = :id
            ")->execute([
                ':cat'   => $cat,
                ':nom'   => $nom,
                ':desc'  => $desc,
                ':spec'  => $spec,
                ':unite' => $unite,
                ':prix'  => $prix,
                ':id'    => $id
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Modification matériel ID:{$id} - Prix: {$prix} FCFA",
                'materiels', $id);

            header('Location: index.php?success=' .
                urlencode("Matériel modifié avec succès !"));
            break;

        case 'supprimer':
            $id = (int)($_POST['id_materiel'] ?? 0);

            $check = $pdo->prepare("
                SELECT COUNT(*) FROM details_demande
                WHERE id_materiel = :id
            ");
            $check->execute([':id' => $id]);

            if ($check->fetchColumn() > 0) {
                header('Location: index.php?erreur=' .
                    urlencode('Impossible : matériel utilisé dans des demandes !'));
                exit();
            }

            $pdo->prepare("DELETE FROM materiels WHERE id_materiel = :id")
                ->execute([':id' => $id]);

            header('Location: index.php?success=' .
                urlencode("Matériel supprimé !"));
            break;

        default:
            header('Location: index.php');
    }
} catch (PDOException $e) {
    header('Location: index.php?erreur=' .
        urlencode('Erreur : ' . $e->getMessage()));
}
exit();
?>