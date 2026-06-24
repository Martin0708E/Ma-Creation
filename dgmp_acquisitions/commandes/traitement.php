<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo    = getConnexion();
$action = $_POST['action'] ?? '';

try {
    switch ($action) {

        // ============================================
        // RESPONSABLE : VALIDER OU REJETER COMMANDE
        // ============================================
        case 'valider_commande':

    // Vérifier rôle VALIDATEUR
    if ($_SESSION['role'] !== 'validateur') {
        header('Location: index.php?erreur=' . urlencode(
            '⛔ Seul le validateur peut valider les commandes !'
        ));
        exit();
    }
    // ... reste du code identique

            $id_commande = (int)($_POST['id_commande'] ?? 0);
            $decision    = $_POST['decision'] ?? '';
            $commentaire = trim($_POST['commentaire'] ?? '');

            if (!in_array($decision, ['validee', 'rejete'])) {
                header('Location: index.php?erreur=' .
                    urlencode('Décision invalide !'));
                exit();
            }

            if ($decision === 'rejete' && empty($commentaire)) {
                header('Location: index.php?erreur=' . urlencode(
                    '⚠️ Commentaire obligatoire pour rejeter !'
                ));
                exit();
            }

            $pdo->beginTransaction();

            // Récupérer infos commande
            $info = $pdo->prepare("
                SELECT c.*,
                       d.id_utilisateur as id_demandeur,
                       d.reference_demande,
                       u.nom, u.prenom
                FROM commandes c
                JOIN demandes_acquisition d ON c.id_demande = d.id_demande
                JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
                WHERE c.id_commande = :id
                AND c.statut = 'en_attente_validation'
            ");
            $info->execute([':id' => $id_commande]);
            $commande = $info->fetch();

            if (!$commande) {
                throw new Exception(
                    "Commande introuvable ou déjà traitée !"
                );
            }

            // Mettre à jour statut commande
            $pdo->prepare("
                UPDATE commandes SET
                    statut         = :statut,
                    id_responsable = :id_resp,
                    commentaire_responsable = :commentaire,
                    date_validation_responsable = NOW()
                WHERE id_commande = :id
            ")->execute([
                ':statut'      => $decision,
                ':id_resp'     => $_SESSION['id_utilisateur'],
                ':commentaire' => !empty($commentaire) ? $commentaire : null,
                ':id'          => $id_commande
            ]);

            // Mettre à jour statut demande
            $statut_demande = $decision === 'validee'
                ? 'commandee' : 'en_attente';

            $pdo->prepare("
                UPDATE demandes_acquisition
                SET statut = :statut
                WHERE id_demande = :id
            ")->execute([
                ':statut' => $statut_demande,
                ':id'     => $commande['id_demande']
            ]);

            // Notifier le demandeur
            $msg_notif = $decision === 'validee'
                ? "✅ Votre commande {$commande['reference_commande']} 
                   a été validée par le responsable !"
                : "❌ Votre commande {$commande['reference_commande']} 
                   a été rejetée. Motif : {$commentaire}";

            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, :type)
            ")->execute([
                ':id'   => $commande['id_demandeur'],
                ':msg'  => $msg_notif,
                ':type' => $decision === 'validee' ? 'succes' : 'alerte'
            ]);

            // Notifier les admins
            $admins = $pdo->query("
                SELECT id_utilisateur FROM utilisateurs
                WHERE role = 'admin' AND statut = TRUE
            ")->fetchAll();

            foreach ($admins as $a) {
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'info')
                ")->execute([
                    ':id'  => $a['id_utilisateur'],
                    ':msg' => "🛒 Commande {$commande['reference_commande']} 
                               {$decision} par le responsable 
                               {$_SESSION['nom_complet']}"
                ]);
            }

            // Log
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action, table_concernee, id_enregistrement)
                VALUES (:id, :action, 'commandes', :id_enreg)
            ")->execute([
                ':id'      => $_SESSION['id_utilisateur'],
                ':action'  => "Commande {$commande['reference_commande']} : {$decision}",
                ':id_enreg'=> $id_commande
            ]);

            $pdo->commit();

            $msg = $decision === 'validee'
                ? "✅ Commande {$commande['reference_commande']} validée avec succès !"
                : "❌ Commande {$commande['reference_commande']} rejetée.";

            header('Location: index.php?success=' . urlencode($msg));
            break;

        // ============================================
        // ADMIN : MODIFIER STATUT LIVRAISON
        // ============================================
        case 'modifier_statut':
            verifierRole(['admin']);

            $id     = (int)($_POST['id_commande'] ?? 0);
            $statut = $_POST['statut'] ?? '';

            $pdo->prepare("
                UPDATE commandes SET statut = :statut
                WHERE id_commande = :id
            ")->execute([':statut' => $statut, ':id' => $id]);

            if ($statut === 'livree') {
                $pdo->prepare("
                    UPDATE demandes_acquisition SET statut = 'livree'
                    WHERE id_demande = (
                        SELECT id_demande FROM commandes
                        WHERE id_commande = :id
                    )
                ")->execute([':id' => $id]);
            }

            header('Location: index.php?success=' .
                urlencode('Statut mis à jour !'));
            break;

        default:
            header('Location: index.php');
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Location: index.php?erreur=' .
        urlencode('Erreur : ' . $e->getMessage()));
}
exit();
?>