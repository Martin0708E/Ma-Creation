<?php
// ============================================
// DÉMARRER SESSION
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo     = getConnexion();
$action  = $_POST['action'] ?? '';
$id_user = $_SESSION['id_utilisateur'];

try {
    switch ($action) {

        // ============================================
        // MODIFIER LE PROFIL
        // ============================================
        case 'modifier_profil':

            $nom         = strtoupper(trim($_POST['nom']         ?? ''));
            $prenom      = trim($_POST['prenom']                 ?? '');
            $email       = trim($_POST['email']                  ?? '');
            $telephone   = trim($_POST['telephone']              ?? '');
            $departement = trim($_POST['departement']            ?? '');

            // Validations
            if (empty($nom)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le nom est obligatoire !'
                ));
                exit();
            }

            if (empty($prenom)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le prénom est obligatoire !'
                ));
                exit();
            }

            if (empty($email)) {
                header('Location: index.php?erreur=' . urlencode(
                    'L\'email est obligatoire !'
                ));
                exit();
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Adresse email invalide !'
                ));
                exit();
            }

            // Vérifier email non utilisé par un autre
            $check = $pdo->prepare("
                SELECT COUNT(*) as nb
                FROM utilisateurs
                WHERE email = :email
                AND id_utilisateur != :id
            ");
            $check->execute([
                ':email' => $email,
                ':id'    => $id_user
            ]);

            if ($check->fetch()['nb'] > 0) {
                header('Location: index.php?erreur=' . urlencode(
                    'Cet email est déjà utilisé !'
                ));
                exit();
            }

            // Mettre à jour profil
            $pdo->prepare("
                UPDATE utilisateurs SET
                    nom         = :nom,
                    prenom      = :prenom,
                    email       = :email,
                    telephone   = :tel,
                    departement = :dept
                WHERE id_utilisateur = :id
            ")->execute([
                ':nom'    => $nom,
                ':prenom' => $prenom,
                ':email'  => $email,
                ':tel'    => !empty($telephone)
                             ? $telephone : null,
                ':dept'   => !empty($departement)
                             ? $departement : null,
                ':id'     => $id_user
            ]);

            // Mettre à jour la session
            $_SESSION['utilisateur']['nom']         = $nom;
            $_SESSION['utilisateur']['prenom']      = $prenom;
            $_SESSION['utilisateur']['email']       = $email;
            $_SESSION['utilisateur']['telephone']   = $telephone;
            $_SESSION['utilisateur']['departement'] = $departement;
            $_SESSION['nom_complet'] = $prenom . ' ' . $nom;

            // Log
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action, table_concernee)
                VALUES (:id, 'Modification profil', 'utilisateurs')
            ")->execute([':id' => $id_user]);

            header('Location: index.php?success=' . urlencode(
                'Votre profil a été mis à jour avec succès !'
            ));
            break;

        // ============================================
        // CHANGER MOT DE PASSE
        // ============================================
        case 'changer_mdp':

            $mdp_actuel    = $_POST['mdp_actuel']    ?? '';
            $nouveau_mdp   = $_POST['nouveau_mdp']   ?? '';
            $confirmer_mdp = $_POST['confirmer_mdp'] ?? '';

            // Validations
            if (empty($mdp_actuel)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le mot de passe actuel est obligatoire !'
                ));
                exit();
            }

            if (empty($nouveau_mdp)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le nouveau mot de passe est obligatoire !'
                ));
                exit();
            }

            if (strlen($nouveau_mdp) < 6) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le nouveau mot de passe doit avoir au moins 6 caractères !'
                ));
                exit();
            }

            if ($nouveau_mdp !== $confirmer_mdp) {
                header('Location: index.php?erreur=' . urlencode(
                    'Les nouveaux mots de passe ne correspondent pas !'
                ));
                exit();
            }

            // Récupérer mot de passe actuel
            $stmt = $pdo->prepare("
                SELECT mot_de_passe
                FROM utilisateurs
                WHERE id_utilisateur = :id
            ");
            $stmt->execute([':id' => $id_user]);
            $user = $stmt->fetch();

            if (!$user) {
                header('Location: index.php?erreur=' . urlencode(
                    'Utilisateur introuvable !'
                ));
                exit();
            }

            // Vérifier mot de passe actuel
            if (!password_verify($mdp_actuel,
                                  $user['mot_de_passe'])) {
                header('Location: index.php?erreur=' . urlencode(
                    'Mot de passe actuel incorrect !'
                ));
                exit();
            }

            // Vérifier que nouveau différent de l'ancien
            if (password_verify($nouveau_mdp,
                                 $user['mot_de_passe'])) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le nouveau mot de passe doit être différent de l\'ancien !'
                ));
                exit();
            }

            // Hasher et mettre à jour
            $hash = password_hash($nouveau_mdp, PASSWORD_DEFAULT);

            $pdo->prepare("
                UPDATE utilisateurs
                SET mot_de_passe = :hash
                WHERE id_utilisateur = :id
            ")->execute([
                ':hash' => $hash,
                ':id'   => $id_user
            ]);

            // Log
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action, table_concernee)
                VALUES (:id, 'Changement mot de passe', 'utilisateurs')
            ")->execute([':id' => $id_user]);

            // Déconnecter après changement
            $_SESSION = [];
            session_destroy();

            header('Location: ../auth/login.php?msg=' . urlencode(
                'Mot de passe changé avec succès ! Reconnectez-vous.'
            ));
            exit();

        default:
            header('Location: index.php');
            break;
    }

} catch (PDOException $e) {
    $msg = $e->getCode() == 23505
        ? 'Cet email est déjà utilisé !'
        : 'Erreur : ' . $e->getMessage();

    header('Location: index.php?erreur=' . urlencode($msg));

} catch (Exception $e) {
    header('Location: index.php?erreur=' . urlencode(
        'Erreur : ' . $e->getMessage()
    ));
}
exit();
?>