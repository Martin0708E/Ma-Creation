<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin']);

$pdo    = getConnexion();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ============================================
        // AJOUTER UTILISATEUR
        // ============================================
        case 'ajouter':

            $nom    = strtoupper(trim($_POST['nom']    ?? ''));
            $prenom = trim($_POST['prenom']            ?? '');
            $email  = trim($_POST['email']             ?? '');
            $role   = $_POST['role']                   ?? '';
            $dept   = trim($_POST['departement']       ?? '');
            $tel    = trim($_POST['telephone']         ?? '');
            $mdp    = $_POST['mot_de_passe']           ?? '';
            $conf   = $_POST['confirmer_mdp']          ?? '';

            if (empty($nom) || empty($prenom) ||
                empty($email) || empty($role) ||
                empty($mdp)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Tous les champs obligatoires doivent être remplis !'
                ));
                exit();
            }

            if ($mdp !== $conf) {
                header('Location: index.php?erreur=' . urlencode(
                    'Les mots de passe ne correspondent pas !'
                ));
                exit();
            }

            if (strlen($mdp) < 6) {
                header('Location: index.php?erreur=' . urlencode(
                    'Le mot de passe doit avoir au moins 6 caractères !'
                ));
                exit();
            }

            $hash = password_hash($mdp, PASSWORD_DEFAULT);

            $pdo->prepare("
                INSERT INTO utilisateurs
                (nom, prenom, email, mot_de_passe,
                 role, departement, telephone,
                 statut, statut_inscription)
                VALUES
                (:nom, :prenom, :email, :hash,
                 :role, :dept, :tel,
                 TRUE, 'approuve')
            ")->execute([
                ':nom'    => $nom,
                ':prenom' => $prenom,
                ':email'  => $email,
                ':hash'   => $hash,
                ':role'   => $role,
                ':dept'   => !empty($dept) ? $dept : null,
                ':tel'    => !empty($tel)  ? $tel  : null
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Creation utilisateur: {$prenom} {$nom}",
                'utilisateurs');

            header('Location: index.php?success=' . urlencode(
                "Utilisateur {$prenom} {$nom} cree avec succes !"
            ));
            break;

        // ============================================
        // MODIFIER UTILISATEUR
        // ============================================
        case 'modifier':

            $id     = (int)($_POST['id_utilisateur'] ?? 0);
            $nom    = strtoupper(trim($_POST['nom']   ?? ''));
            $prenom = trim($_POST['prenom']           ?? '');
            $email  = trim($_POST['email']            ?? '');
            $role   = $_POST['role']                  ?? '';
            $dept   = trim($_POST['departement']      ?? '');
            $tel    = trim($_POST['telephone']        ?? '');

            if (!$id || empty($nom) ||
                empty($prenom) || empty($email)) {
                header('Location: index.php?erreur=' . urlencode(
                    'Donnees invalides !'
                ));
                exit();
            }

            $pdo->prepare("
                UPDATE utilisateurs SET
                    nom         = :nom,
                    prenom      = :prenom,
                    email       = :email,
                    role        = :role,
                    departement = :dept,
                    telephone   = :tel
                WHERE id_utilisateur = :id
            ")->execute([
                ':nom'    => $nom,
                ':prenom' => $prenom,
                ':email'  => $email,
                ':role'   => $role,
                ':dept'   => !empty($dept) ? $dept : null,
                ':tel'    => !empty($tel)  ? $tel  : null,
                ':id'     => $id
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Modification utilisateur ID:{$id}",
                'utilisateurs', $id);

            header('Location: index.php?success=' . urlencode(
                "Utilisateur modifie avec succes !"
            ));
            break;

        // ============================================
        // TOGGLE STATUT
        // ============================================
        case 'toggle_statut':

            $id = (int)($_POST['id_utilisateur'] ?? 0);

            if (!$id ||
                $id == $_SESSION['id_utilisateur']) {
                header('Location: index.php?erreur=' . urlencode(
                    'Action non autorisee !'
                ));
                exit();
            }

            $current = $pdo->prepare("
                SELECT statut, nom, prenom
                FROM utilisateurs
                WHERE id_utilisateur = :id
            ");
            $current->execute([':id' => $id]);
            $user = $current->fetch();

            if (!$user) {
                header('Location: index.php?erreur=' . urlencode(
                    'Utilisateur introuvable !'
                ));
                exit();
            }

            $nouveau = $user['statut'] ? 'false' : 'true';

            $pdo->prepare("
                UPDATE utilisateurs
                SET statut = :statut
                WHERE id_utilisateur = :id
            ")->execute([
                ':statut' => $nouveau,
                ':id'     => $id
            ]);

            $action_log = $user['statut']
                ? 'Desactivation' : 'Activation';

            logAction($pdo, $_SESSION['id_utilisateur'],
                "{$action_log} utilisateur: " .
                "{$user['prenom']} {$user['nom']}",
                'utilisateurs', $id);

            $msg = $user['statut']
                ? "{$user['prenom']} {$user['nom']} desactive !"
                : "{$user['prenom']} {$user['nom']} active !";

            header('Location: index.php?success=' .
                urlencode($msg));
            break;

        // ============================================
        // RÉINITIALISER MOT DE PASSE
        // ============================================
        case 'reset_mdp':

            $id      = (int)($_POST['id_utilisateur'] ?? 0);
            $nouveau = $_POST['nouveau_mdp']           ?? '';

            if (!$id || strlen($nouveau) < 6) {
                header('Location: index.php?erreur=' . urlencode(
                    'Mot de passe trop court (min 6 caracteres) !'
                ));
                exit();
            }

            $hash = password_hash($nouveau, PASSWORD_DEFAULT);

            $pdo->prepare("
                UPDATE utilisateurs
                SET mot_de_passe = :hash
                WHERE id_utilisateur = :id
            ")->execute([
                ':hash' => $hash,
                ':id'   => $id
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                "Reinitialisation mot de passe ID:{$id}",
                'utilisateurs', $id);

            header('Location: index.php?success=' . urlencode(
                "Mot de passe reinitialise avec succes !"
            ));
            break;

        // ============================================
        // VALIDER INSCRIPTION
        // ============================================
        case 'valider_inscription':

            $id       = (int)($_POST['id_utilisateur'] ?? 0);
            $decision = $_POST['decision']             ?? '';
            $motif    = trim($_POST['motif_rejet']     ?? '');

            if (!$id ||
                !in_array($decision, ['approuve', 'rejete'])) {
                header('Location: index.php?erreur=' . urlencode(
                    'Donnees invalides !'
                ));
                exit();
            }

            // Récupérer infos utilisateur
            $stmt_user = $pdo->prepare("
                SELECT * FROM utilisateurs
                WHERE id_utilisateur = :id
            ");
            $stmt_user->execute([':id' => $id]);
            $user = $stmt_user->fetch();

            if (!$user) {
                header('Location: index.php?erreur=' . urlencode(
                    'Utilisateur introuvable !'
                ));
                exit();
            }

            $pdo->beginTransaction();

            if ($decision === 'approuve') {

                // Approuver et activer le compte
                $pdo->prepare("
                    UPDATE utilisateurs SET
                        statut_inscription = 'approuve',
                        statut = TRUE
                    WHERE id_utilisateur = :id
                ")->execute([':id' => $id]);

                // Notification dans le système
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'succes')
                ")->execute([
                    ':id'  => $id,
                    ':msg' => "Votre compte a ete approuve " .
                              "par l'administrateur ! " .
                              "Vous pouvez maintenant vous connecter."
                ]);

                logAction($pdo, $_SESSION['id_utilisateur'],
                    "Approbation inscription: " .
                    $user['prenom'] . " " . $user['nom'],
                    'utilisateurs', $id);

                $pdo->commit();

                header('Location: index.php?success=' . urlencode(
                    "Compte de " . $user['prenom'] .
                    " " . $user['nom'] .
                    " approuve avec succes !"
                ));

            } else {

                // Rejeter
                $motif_final = !empty($motif)
                    ? $motif
                    : "Votre demande ne repond pas aux criteres requis.";

                $pdo->prepare("
                    UPDATE utilisateurs SET
                        statut_inscription = 'rejete',
                        statut = FALSE
                    WHERE id_utilisateur = :id
                ")->execute([':id' => $id]);

                // Notification dans le système
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'alerte')
                ")->execute([
                    ':id'  => $id,
                    ':msg' => "Votre demande d'inscription a ete " .
                              "rejetee. Motif : " . $motif_final
                ]);

                logAction($pdo, $_SESSION['id_utilisateur'],
                    "Rejet inscription: " .
                    $user['prenom'] . " " . $user['nom'] .
                    " - Motif: " . $motif_final,
                    'utilisateurs', $id);

                $pdo->commit();

                header('Location: index.php?success=' . urlencode(
                    "Inscription de " . $user['prenom'] .
                    " " . $user['nom'] . " rejetee."
                ));
            }
            break;

        // ============================================
        // DEFAULT
        // ============================================
        default:
            header('Location: index.php');
            break;

    }

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $msg_erreur = $e->getCode() == 23505
        ? 'Cet email est deja utilise par un autre compte !'
        : 'Erreur : ' . $e->getMessage();

    header('Location: index.php?erreur=' .
        urlencode($msg_erreur));

} catch (Exception $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: index.php?erreur=' . urlencode(
        'Erreur : ' . $e->getMessage()
    ));
}

exit();
?>