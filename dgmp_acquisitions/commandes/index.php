<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');
$role    = $_SESSION['role'];

// Filtres
$filtre_statut = $_GET['statut']    ?? '';
$recherche     = $_GET['recherche'] ?? '';

$where  = "WHERE 1=1";
$params = [];

if (!empty($filtre_statut)) {
    $where .= " AND c.statut = :statut";
    $params[':statut'] = $filtre_statut;
}
if (!empty($recherche)) {
    $where .= " AND (
        c.reference_commande ILIKE :rech
        OR f.nom_entreprise  ILIKE :rech
        OR d.reference_demande ILIKE :rech
    )";
    $params[':rech'] = "%$recherche%";
}

// Récupérer commandes
$stmt_cmd = $pdo->prepare("
    SELECT c.*,
           f.nom_entreprise,
           f.telephone   as fournisseur_tel,
           f.email       as fournisseur_email,
           d.reference_demande,
           d.departement_demandeur,
           d.priorite,
           d.motif,
           u.nom         as demandeur_nom,
           u.prenom      as demandeur_prenom
    FROM commandes c
    JOIN fournisseurs f ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d ON c.id_demande = d.id_demande
    JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
    $where
    ORDER BY
        CASE c.statut
            WHEN 'en_attente_validation' THEN 1
            ELSE 2
        END,
        c.date_creation DESC
");
$stmt_cmd->execute($params);
$commandes = $stmt_cmd->fetchAll();

// Stats commandes
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'en_attente_validation'
            THEN 1 ELSE 0 END) as en_attente,
        SUM(CASE WHEN statut = 'validee'
            THEN 1 ELSE 0 END) as validees,
        SUM(CASE WHEN statut = 'rejetee'
            THEN 1 ELSE 0 END) as rejetees,
        SUM(CASE WHEN statut = 'livree'
            THEN 1 ELSE 0 END) as livrees,
        COALESCE(SUM(montant_total), 0) as budget_total
    FROM commandes
")->fetch();

// ============================================
// TRAITEMENT VALIDATION (VALIDATEUR SEULEMENT)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Vérifier rôle validateur
    if ($_SESSION['role'] !== 'validateur') {
        header('Location: index.php?erreur=' . urlencode(
            '⛔ Seul le validateur peut valider les commandes !'
        ));
        exit();
    }

    $id_commande = (int)($_POST['id_commande'] ?? 0);
    $decision    = $_POST['decision']          ?? '';
    $commentaire = trim($_POST['commentaire']  ?? '');

    // Validations
    if (!$id_commande) {
        header('Location: index.php?erreur=' .
            urlencode('ID commande invalide !'));
        exit();
    }

    if (!in_array($decision, ['validee', 'rejetee'])) {
        header('Location: index.php?erreur=' .
            urlencode('Décision invalide !'));
        exit();
    }

    if ($decision === 'rejetee' && empty($commentaire)) {
        header('Location: index.php?erreur=' . urlencode(
            '⚠️ Le commentaire est obligatoire pour rejeter !'
        ));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Récupérer infos commande
        $info = $pdo->prepare("
            SELECT c.*,
                   d.id_utilisateur as id_demandeur,
                   d.reference_demande,
                   u.nom, u.prenom
            FROM commandes c
            JOIN demandes_acquisition d
                ON c.id_demande = d.id_demande
            JOIN utilisateurs u
                ON d.id_utilisateur = u.id_utilisateur
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

        // 1. Mettre à jour statut commande
        $pdo->prepare("
            UPDATE commandes SET
                statut                      = :statut,
                commentaire_responsable     = :commentaire,
                date_validation_responsable = NOW()
            WHERE id_commande = :id
        ")->execute([
            ':statut'      => $decision,
            ':commentaire' => !empty($commentaire)
                              ? $commentaire : null,
            ':id'          => $id_commande
        ]);

        // 2. Mettre à jour statut demande
        $statut_demande = ($decision === 'validee')
                          ? 'commandee' : 'en_attente';

        $pdo->prepare("
            UPDATE demandes_acquisition
            SET statut = :statut
            WHERE id_demande = :id
        ")->execute([
            ':statut' => $statut_demande,
            ':id'     => $commande['id_demande']
        ]);

        // 3. Notifier le demandeur
        if ($decision === 'validee') {
            $msg_notif = "✅ Votre commande " .
                         $commande['reference_commande'] .
                         " a été validée par le validateur !" .
                         " Elle sera bientôt livrée.";
            $type_notif = 'succes';
        } else {
            $msg_notif = "❌ Votre commande " .
                         $commande['reference_commande'] .
                         " a été rejetée. Motif : " .
                         $commentaire;
            $type_notif = 'alerte';
        }

        $pdo->prepare("
            INSERT INTO notifications
            (id_utilisateur, message, type)
            VALUES (:id, :msg, :type)
        ")->execute([
            ':id'   => $commande['id_demandeur'],
            ':msg'  => $msg_notif,
            ':type' => $type_notif
        ]);

        // 4. Notifier les admins
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
                ':msg' => "🛒 Commande " .
                          $commande['reference_commande'] .
                          " " . $decision .
                          " par le validateur " .
                          clean($_SESSION['nom_complet'])
            ]);
        }

        // 5. Log
        $pdo->prepare("
            INSERT INTO historique_actions
            (id_utilisateur, action,
             table_concernee, id_enregistrement)
            VALUES (:id, :action, 'commandes', :id_enreg)
        ")->execute([
            ':id'      => $_SESSION['id_utilisateur'],
            ':action'  => "Commande " .
                          $commande['reference_commande'] .
                          " : " . $decision,
            ':id_enreg'=> $id_commande
        ]);

        $pdo->commit();

        if ($decision === 'validee') {
            $msg_s = "✅ Commande " .
                     $commande['reference_commande'] .
                     " validée avec succès !" .
                     " Elle est prête pour la livraison.";
        } else {
            $msg_s = "❌ Commande " .
                     $commande['reference_commande'] .
                     " rejetée avec succès.";
        }

        header('Location: index.php?success=' . urlencode($msg_s));
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: index.php?erreur=' . urlencode(
            'Erreur : ' . $e->getMessage()
        ));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- FAVICON -->
    <?php include '../includes/head.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php include '../includes/pwa_head.php'; ?>
    <title>Acquisitions Informatiques</title>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <!-- En-tête -->
        <div class="page-header">
            <div>
                <h1>🛒 Gestion des Commandes</h1>
                <p class="page-subtitle">
                    <?php if ($role === 'validateur'): ?>
                        Validez ou rejetez les commandes en attente
                    <?php else: ?>
                        Suivi de toutes les commandes
                    <?php endif; ?>
                </p>
            </div>
            <?php if ($role === 'validateur'
                      && $stats['en_attente'] > 0): ?>
            <span class="badge bg-warning"
                  style="font-size:15px;padding:10px 16px">
                ⏳ <?= $stats['en_attente'] ?> en attente
            </span>
            <?php endif; ?>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                ✅ <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($erreur): ?>
            <div class="alert alert-danger">
                ⚠️ <?= $erreur ?>
            </div>
        <?php endif; ?>

        <!-- Info validateur -->
        <?php if ($role === 'validateur'): ?>
        <div class="validateur-info-box">
            <div class="vib-icon">🟣</div>
            <div class="vib-content">
                <h3>Connecté en tant que Validateur</h3>
                <p>
                    👤 <strong>
                        <?= clean($_SESSION['nom_complet']) ?>
                    </strong>
                    | Vous pouvez valider ou rejeter
                    les commandes en attente.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alerte commandes en attente -->
        <?php if ($role === 'validateur'
                  && $stats['en_attente'] > 0): ?>
        <div class="alert alert-warning">
            ⚠️ <strong><?= $stats['en_attente'] ?></strong>
            commande(s) en attente de votre validation !
            Elles sont surlignées en jaune ci-dessous.
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid"
             style="grid-template-columns:repeat(6,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">🛒</div>
                <div class="stat-info">
                    <h3><?= $stats['total'] ?></h3>
                    <p>Total</p>
                </div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon">⏳</div>
                <div class="stat-info">
                    <h3><?= $stats['en_attente'] ?></h3>
                    <p>En Attente</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $stats['validees'] ?></h3>
                    <p>Validées</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">❌</div>
                <div class="stat-info">
                    <h3><?= $stats['rejetees'] ?></h3>
                    <p>Rejetées</p>
                </div>
            </div>
            <div class="stat-card teal">
                <div class="stat-icon">📦</div>
                <div class="stat-info">
                    <h3><?= $stats['livrees'] ?></h3>
                    <p>Livrées</p>
                </div>
            </div>
            <div class="stat-card gold">
                <div class="stat-icon">💰</div>
                <div class="stat-info">
                    <h3><?= formaterMontant($stats['budget_total']) ?></h3>
                    <p>Budget</p>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>🔍 Recherche</label>
                            <input type="text"
                                   name="recherche"
                                   class="form-control"
                                   placeholder="Réf. commande, fournisseur..."
                                   value="<?= clean($recherche) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label>📌 Statut</label>
                            <select name="statut" class="form-control">
                                <option value="">Tous les statuts</option>
                                <option value="en_attente_validation"
                                    <?= $filtre_statut === 'en_attente_validation'
                                        ? 'selected' : '' ?>>
                                    ⏳ En Attente Validation
                                </option>
                                <option value="validee"
                                    <?= $filtre_statut === 'validee'
                                        ? 'selected' : '' ?>>
                                    ✅ Validée
                                </option>
                                <option value="rejetee"
                                    <?= $filtre_statut === 'rejetee'
                                        ? 'selected' : '' ?>>
                                    ❌ Rejetée
                                </option>
                                <option value="livree"
                                    <?= $filtre_statut === 'livree'
                                        ? 'selected' : '' ?>>
                                    📦 Livrée
                                </option>
                            </select>
                        </div>
                        <div class="form-group col-4 align-end">
                            <label>&nbsp;</label>
                            <div class="btn-group">
                                <button type="submit"
                                        class="btn btn-primary">
                                    🔍 Filtrer
                                </button>
                                <a href="index.php"
                                   class="btn btn-secondary">
                                    🔄 Reset
                                </a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- ============================================ -->
        <!-- SECTION VALIDATEUR : CARDS COMMANDES         -->
        <!-- EN ATTENTE DE VALIDATION                     -->
        <!-- ============================================ -->
        <?php
        $commandes_attente = array_filter(
            $commandes,
            fn($c) => $c['statut'] === 'en_attente_validation'
        );
        ?>

        <?php if ($role === 'validateur'
                  && !empty($commandes_attente)): ?>
        <div class="card mb-3">
            <div class="card-header"
                 style="background:linear-gradient(135deg,#fff8e1,#fff3cd)">
                <h2 style="color:var(--warning)">
                    ⏳ Commandes en Attente de Validation
                </h2>
                <span class="badge bg-warning">
                    <?= count($commandes_attente) ?>
                </span>
            </div>
            <div class="card-body">

                <?php foreach ($commandes_attente as $c): ?>
                <div class="validation-card"
                     style="border-left-color:#6a1b9a">

                    <!-- En-tête carte -->
                    <div class="validation-card-header"
                         style="background:#f3e5f5;
                                border-bottom-color:#ce93d8">
                        <div>
                            <h3>
                                <?= clean($c['reference_commande']) ?>
                                &nbsp;
                                <?= getBadgePriorite($c['priorite']) ?>
                            </h3>
                            <p>
                                📋 Demande :
                                <strong>
                                    <?= clean($c['reference_demande']) ?>
                                </strong>
                                &nbsp;|&nbsp;
                                👤 Demandeur :
                                <strong>
                                    <?= clean(
                                        $c['demandeur_prenom'] . ' ' .
                                        $c['demandeur_nom']
                                    ) ?>
                                </strong>
                                &nbsp;|&nbsp;
                                🏢 <?= clean($c['departement_demandeur']) ?>
                                &nbsp;|&nbsp;
                                📅 <?= date('d/m/Y',
                                    strtotime($c['date_commande'])) ?>
                            </p>
                        </div>
                        <a href="detail.php?id=<?= $c['id_commande'] ?>"
                           class="btn btn-sm btn-info"
                           target="_blank">
                            👁️ Voir détails
                        </a>
                    </div>

                    <!-- Corps carte -->
                    <div class="validation-card-body">
                        <div class="validation-info-grid">
                            <div class="vi-item">
                                <span>🏢 Fournisseur :</span>
                                <strong>
                                    <?= clean($c['nom_entreprise']) ?>
                                </strong>
                            </div>
                            <div class="vi-item">
                                <span>📞 Téléphone :</span>
                                <strong>
                                    <?= clean($c['fournisseur_tel'] ?? '—') ?>
                                </strong>
                            </div>
                            <div class="vi-item">
                                <span>💰 Montant Total :</span>
                                <strong style="color:var(--primary);
                                               font-size:18px">
                                    <?= formaterMontant($c['montant_total']) ?>
                                </strong>
                            </div>
                            <div class="vi-item">
                                <span>📅 Date Commande :</span>
                                <strong>
                                    <?= date('d/m/Y',
                                        strtotime($c['date_commande'])) ?>
                                </strong>
                            </div>
                            <?php if ($c['date_livraison_prevue']): ?>
                            <div class="vi-item">
                                <span>📦 Livraison Prévue :</span>
                                <strong>
                                    <?= date('d/m/Y',
                                        strtotime($c['date_livraison_prevue'])) ?>
                                </strong>
                            </div>
                            <?php endif; ?>
                            <div class="vi-item">
                                <span>📝 Motif :</span>
                                <strong><?= clean($c['motif']) ?></strong>
                            </div>
                        </div>
                    </div>

                    <!-- Formulaire décision -->
                    <div class="validation-card-footer">
                        <form method="POST" action=""
                              onsubmit="return confirmerDecision(this)">
                            <input type="hidden"
                                   name="id_commande"
                                   value="<?= $c['id_commande'] ?>">

                            <div class="form-group">
                                <label>
                                    💬 Commentaire
                                    <small class="text-muted">
                                        (obligatoire pour rejet)
                                    </small>
                                </label>
                                <textarea name="commentaire"
                                          class="form-control"
                                          rows="2"
                                          placeholder="Ajoutez un commentaire...">
                                </textarea>
                            </div>

                            <div class="decision-btns">
                                <button type="submit"
                                        name="decision"
                                        value="validee"
                                        class="btn btn-success btn-lg"
                                        onclick="decisionChoisie='validee'">
                                    ✅ Valider la Commande
                                </button>
                                <button type="submit"
                                        name="decision"
                                        value="rejetee"
                                        class="btn btn-danger btn-lg"
                                        onclick="decisionChoisie='rejetee'">
                                    ❌ Rejeter la Commande
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
                <?php endforeach; ?>

            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- TABLEAU TOUTES LES COMMANDES                 -->
        <!-- ============================================ -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Liste des Commandes
                    <span class="badge bg-primary">
                        <?= count($commandes) ?>
                    </span>
                </h2>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Réf. Commande</th>
                                <th>Réf. Demande</th>
                                <th>Demandeur</th>
                                <th>Fournisseur</th>
                                <th>Montant</th>
                                <th>Date</th>
                                <th>Priorité</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($commandes)): ?>
                            <tr>
                                <td colspan="10"
                                    class="text-center py-4">
                                    😕 Aucune commande trouvée
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($commandes as $i => $c): ?>
                            <?php
                            $statut_badges = [
                                'en_attente_validation' =>
                                    ['warning', '⏳ En Attente'],
                                'validee' =>
                                    ['success', '✅ Validée'],
                                'rejetee' =>
                                    ['danger',  '❌ Rejetée'],
                                'en_cours' =>
                                    ['info',    '🚚 En cours'],
                                'livree' =>
                                    ['success', '📦 Livrée'],
                                'annulee' =>
                                    ['danger',  '🚫 Annulée'],
                                'partiellement_livree' =>
                                    ['warning', '📦 Partielle'],
                            ];
                            $sb = $statut_badges[$c['statut']]
                                  ?? ['secondary', $c['statut']];
                            ?>
                            <tr class="<?=
                                $c['statut'] === 'en_attente_validation'
                                ? 'row-attente' : ''
                            ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($c['reference_commande']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <a href="../demandes/detail_demande.php?id=<?= $c['id_demande'] ?>">
                                        <?= clean($c['reference_demande']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= clean(
                                        $c['demandeur_prenom'] . ' ' .
                                        $c['demandeur_nom']
                                    ) ?>
                                    <br>
                                    <small class="text-muted">
                                        <?= clean($c['departement_demandeur']) ?>
                                    </small>
                                </td>
                                <td>
                                    <strong>
                                        <?= clean($c['nom_entreprise']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <strong style="color:var(--primary)">
                                        <?= formaterMontant($c['montant_total']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= date('d/m/Y',
                                        strtotime($c['date_commande'])) ?>
                                </td>
                                <td>
                                    <?= getBadgePriorite($c['priorite']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $sb[0] ?>">
                                        <?= $sb[1] ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <!-- Voir détails -->
                                        <a href="detail.php?id=<?= $c['id_commande'] ?>"
                                           class="btn btn-sm btn-info"
                                           title="Voir détails">
                                            👁️
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
let decisionChoisie = '';

function confirmerDecision(form) {
    var commentaire = form.querySelector(
        'textarea[name="commentaire"]'
    ).value.trim();

    // Vérifier commentaire pour rejet
    if (decisionChoisie === 'rejetee' && commentaire === '') {
        alert('⚠️ Le commentaire est obligatoire pour rejeter !');
        form.querySelector('textarea').focus();
        return false;
    }

    var msg = '';
    if (decisionChoisie === 'validee') {
        msg = '✅ Confirmer la VALIDATION de cette commande ?\n\n' +
              '→ Elle sera transmise pour livraison.';
    } else {
        msg = '❌ Confirmer le REJET de cette commande ?\n\n' +
              '→ Le demandeur sera notifié du rejet.';
    }

    return confirm(msg);
}
</script>
</body>
</html>