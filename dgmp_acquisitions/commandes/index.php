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
        OR d.reference_demande ILIKE :rech
    )";
    $params[':rech'] = "%" . $recherche . "%";
}

// Récupérer commandes
$stmt_cmd = $pdo->prepare("
    SELECT c.*,
           f.nom_entreprise,
           d.reference_demande,
           d.departement_demandeur,
           d.priorite,
           d.motif,
           d.id_demande as id_dem,
           u.nom         as demandeur_nom,
           u.prenom      as demandeur_prenom
    FROM commandes c
    LEFT JOIN fournisseurs f
        ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d
        ON c.id_demande = d.id_demande
    JOIN utilisateurs u
        ON d.id_utilisateur = u.id_utilisateur
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

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN statut='en_attente_validation'
            THEN 1 ELSE 0 END) as en_attente,
        SUM(CASE WHEN statut='validee'
            THEN 1 ELSE 0 END) as validees,
        SUM(CASE WHEN statut='rejetee'
            THEN 1 ELSE 0 END) as rejetees,
        SUM(CASE WHEN statut='livree'
            THEN 1 ELSE 0 END) as livrees,
        COALESCE(SUM(montant_total), 0) as budget_total
    FROM commandes
")->fetch();

// Fournisseurs actifs (pour validateur)
$fournisseurs_actifs = $pdo->query("
    SELECT * FROM fournisseurs
    WHERE statut = 'actif'
    ORDER BY nom_entreprise
")->fetchAll();

// ============================================
// TRAITEMENT VALIDATION (VALIDATEUR)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_SESSION['role'] !== 'validateur') {
        header('Location: index.php?erreur=' . urlencode(
            'Seul le validateur peut valider !'
        ));
        exit();
    }

    $id_commande    = (int)($_POST['id_commande']    ?? 0);
    $decision       = $_POST['decision']             ?? '';
    $commentaire    = trim($_POST['commentaire']     ?? '');
    $id_fournisseur = (int)($_POST['id_fournisseur'] ?? 0);
    $prix_articles  = $_POST['prix_articles']        ?? [];

    if (!in_array($decision, ['validee', 'rejetee'])) {
        header('Location: index.php?erreur=' .
            urlencode('Decision invalide !'));
        exit();
    }

    if ($decision === 'validee' && !$id_fournisseur) {
        header('Location: index.php?erreur=' . urlencode(
            'Vous devez choisir un fournisseur !'
        ));
        exit();
    }

    if ($decision === 'validee' && empty($prix_articles)) {
        header('Location: index.php?erreur=' . urlencode(
            'Vous devez saisir les prix unitaires !'
        ));
        exit();
    }

    if ($decision === 'rejetee' && empty($commentaire)) {
        header('Location: index.php?erreur=' . urlencode(
            'Commentaire obligatoire pour rejeter !'
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
                   d.id_demande
            FROM commandes c
            JOIN demandes_acquisition d
                ON c.id_demande = d.id_demande
            WHERE c.id_commande = :id
            AND c.statut = 'en_attente_validation'
        ");
        $info->execute([':id' => $id_commande]);
        $commande_info = $info->fetch();

        if (!$commande_info) {
            throw new Exception(
                "Commande introuvable ou deja traitee !"
            );
        }

        if ($decision === 'validee') {

            // Calculer montant total avec prix saisis
            $montant_total = 0;

            foreach ($prix_articles as $id_detail => $prix) {
                $prix_val = (float)$prix;
                if ($prix_val > 0) {
                    // Récupérer quantité
                    $stmt_q = $pdo->prepare("
                        SELECT quantite FROM details_demande
                        WHERE id_detail = :id
                        AND id_demande = :id_dem
                    ");
                    $stmt_q->execute([
                        ':id'     => (int)$id_detail,
                        ':id_dem' => $commande_info['id_demande']
                    ]);
                    $row = $stmt_q->fetch();

                    if ($row) {
                        // Mettre à jour prix
                        $pdo->prepare("
                            UPDATE details_demande
                            SET prix_unitaire_estime = :prix
                            WHERE id_detail = :id
                        ")->execute([
                            ':prix' => $prix_val,
                            ':id'   => (int)$id_detail
                        ]);
                        $montant_total += $row['quantite'] * $prix_val;
                    }
                }
            }

            // Mettre à jour commande
            $pdo->prepare("
                UPDATE commandes SET
                    statut                      = 'validee',
                    id_fournisseur              = :id_fourn,
                    montant_total               = :montant,
                    commentaire_responsable     = :commentaire,
                    date_validation_responsable = NOW()
                WHERE id_commande = :id
            ")->execute([
                ':id_fourn'    => $id_fournisseur,
                ':montant'     => $montant_total,
                ':commentaire' => !empty($commentaire)
                                  ? $commentaire : null,
                ':id'          => $id_commande
            ]);

            // Mettre à jour demande
            $pdo->prepare("
                UPDATE demandes_acquisition SET
                    statut        = 'commandee',
                    budget_estime = :budget
                WHERE id_demande = :id
            ")->execute([
                ':budget' => $montant_total,
                ':id'     => $commande_info['id_demande']
            ]);

            // Notifier demandeur
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'succes')
            ")->execute([
                ':id'  => $commande_info['id_demandeur'],
                ':msg' => "Commande " .
                          $commande_info['reference_commande'] .
                          " validee ! Montant : " .
                          number_format($montant_total, 0, ',', ' ') .
                          " FCFA. Prête pour livraison."
            ]);

            // Notifier admins
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
                    ':msg' => "Commande " .
                              $commande_info['reference_commande'] .
                              " validee par le validateur. " .
                              "Montant : " .
                              number_format($montant_total, 0, ',', ' ') .
                              " FCFA"
                ]);
            }

        } else {

            // Rejeter
            $pdo->prepare("
                UPDATE commandes SET
                    statut                      = 'rejetee',
                    commentaire_responsable     = :commentaire,
                    date_validation_responsable = NOW()
                WHERE id_commande = :id
            ")->execute([
                ':commentaire' => $commentaire,
                ':id'          => $id_commande
            ]);

            $pdo->prepare("
                UPDATE demandes_acquisition
                SET statut = 'en_attente'
                WHERE id_demande = :id
            ")->execute([
                ':id' => $commande_info['id_demande']
            ]);

            // Notifier demandeur
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'alerte')
            ")->execute([
                ':id'  => $commande_info['id_demandeur'],
                ':msg' => "Commande " .
                          $commande_info['reference_commande'] .
                          " rejetee. Motif : " . $commentaire
            ]);
        }

        // Log
        $pdo->prepare("
            INSERT INTO historique_actions
            (id_utilisateur, action,
             table_concernee, id_enregistrement)
            VALUES (:id, :action, 'commandes', :id_enreg)
        ")->execute([
            ':id'      => $_SESSION['id_utilisateur'],
            ':action'  => "Commande " .
                          $commande_info['reference_commande'] .
                          " : " . $decision,
            ':id_enreg'=> $id_commande
        ]);

        $pdo->commit();

        $msg = ($decision === 'validee')
            ? "Commande validee avec fournisseur et prix !"
            : "Commande rejetee.";

        header('Location: index.php?success=' . urlencode($msg));
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
    <title>Commandes — DGMP</title>
    <link rel="icon" href="../assets/images/logo_dgmp.png">
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>🛒 Gestion des Commandes</h1>
                <p class="page-subtitle">
                    <?php if ($role === 'validateur'): ?>
                        Choisissez fournisseur et définissez les prix
                    <?php else: ?>
                        Suivi des commandes
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
        <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
        <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
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
                    | Vous devez choisir le fournisseur
                    et définir les prix unitaires
                    de chaque matériel.
                </p>
            </div>
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
                                   placeholder="Réf. commande..."
                                   value="<?= clean($recherche) ?>">
                        </div>
                        <div class="form-group col-4">
                            <label>📌 Statut</label>
                            <select name="statut" class="form-control">
                                <option value="">Tous</option>
                                <option value="en_attente_validation"
                                    <?= $filtre_statut === 'en_attente_validation'
                                        ? 'selected' : '' ?>>
                                    ⏳ En Attente
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
        <!-- COMMANDES EN ATTENTE - VALIDATEUR            -->
        <!-- ============================================ -->
        <?php
        $commandes_attente = array_filter(
            $commandes,
            function($c) {
                return $c['statut'] === 'en_attente_validation';
            }
        );
        ?>

        <?php if ($role === 'validateur'
                  && !empty($commandes_attente)): ?>
        <div class="card mb-3">
            <div class="card-header"
                 style="background:linear-gradient(
                            135deg,#fff8e1,#fff3cd)">
                <h2 style="color:var(--warning)">
                    ⏳ Commandes — Choisir Fournisseur et Prix
                </h2>
                <span class="badge bg-warning">
                    <?= count($commandes_attente) ?>
                </span>
            </div>
            <div class="card-body">

                <?php foreach ($commandes_attente as $c): ?>

                <?php
                // Articles de la demande
                $articles = $pdo->prepare("
                    SELECT dd.*,
                           m.nom_materiel,
                           m.specification_technique,
                           cat.nom_categorie
                    FROM details_demande dd
                    JOIN materiels m
                        ON dd.id_materiel = m.id_materiel
                    JOIN categories_materiel cat
                        ON m.id_categorie = cat.id_categorie
                    WHERE dd.id_demande = :id
                    ORDER BY cat.nom_categorie, m.nom_materiel
                ");
                $articles->execute([':id' => $c['id_demande']]);
                $articles_list = $articles->fetchAll();
                ?>

                <div class="validation-card"
                     style="border-left-color:#6a1b9a;
                            margin-bottom:24px">

                    <!-- En-tête -->
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
                                📋
                                <strong>
                                    <?= clean($c['reference_demande']) ?>
                                </strong>
                                | 👤
                                <strong>
                                    <?= clean(
                                        $c['demandeur_prenom'] . ' ' .
                                        $c['demandeur_nom']
                                    ) ?>
                                </strong>
                                | 🏢 <?= clean($c['departement_demandeur']) ?>
                                | 📅 <?= date('d/m/Y',
                                    strtotime($c['date_commande'])) ?>
                            </p>
                        </div>
                        <a href="detail.php?id=<?= $c['id_commande'] ?>"
                           class="btn btn-sm btn-info"
                           target="_blank">
                            👁️ Voir
                        </a>
                    </div>

                    <!-- Formulaire -->
                    <div class="validation-card-body">
                        <form method="POST" action=""
                              id="form_<?= $c['id_commande'] ?>"
                              onsubmit="return confirmerDecision(
                                  this, <?= $c['id_commande'] ?>
                              )">
                            <input type="hidden"
                                   name="id_commande"
                                   value="<?= $c['id_commande'] ?>">

                            <!-- ÉTAPE 1 : Fournisseur -->
                            <div style="background:#e8eaf6;
                                        border-radius:10px;
                                        padding:16px;
                                        margin-bottom:16px">
                                <h4 style="color:var(--primary);
                                           margin-bottom:12px;
                                           font-size:15px">
                                    🏢 Étape 1 : Choisir le Fournisseur *
                                </h4>
                                <select name="id_fournisseur"
                                        class="form-control"
                                        id="fourn_<?= $c['id_commande'] ?>"
                                        onchange="afficherFournisseur(
                                            this, <?= $c['id_commande'] ?>
                                        )">
                                    <option value="">
                                        -- Choisir un fournisseur --
                                    </option>
                                    <?php foreach ($fournisseurs_actifs as $f): ?>
                                    <option value="<?= $f['id_fournisseur'] ?>"
                                            data-tel="<?= clean($f['telephone'] ?? '') ?>"
                                            data-email="<?= clean($f['email'] ?? '') ?>"
                                            data-ville="<?= clean($f['ville'] ?? '') ?>">
                                        <?= clean($f['nom_entreprise']) ?>
                                        <?php if ($f['ville']): ?>
                                            — <?= clean($f['ville']) ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Info fournisseur -->
                                <div id="info_fourn_<?= $c['id_commande'] ?>"
                                     style="display:none;
                                            margin-top:10px;
                                            background:white;
                                            border-radius:8px;
                                            padding:12px;
                                            font-size:13px;
                                            display:none">
                                </div>
                            </div>

                            <!-- ÉTAPE 2 : Prix unitaires -->
                            <div style="background:#f3e5f5;
                                        border-radius:10px;
                                        padding:16px;
                                        margin-bottom:16px">
                                <h4 style="color:#6a1b9a;
                                           margin-bottom:12px;
                                           font-size:15px">
                                    💰 Étape 2 : Définir les Prix Unitaires *
                                    <small style="font-weight:400;
                                                  font-size:12px;
                                                  color:#757575">
                                        (selon le fournisseur choisi)
                                    </small>
                                </h4>

                                <div class="table-responsive">
                                    <table class="table">
                                        <thead>
                                            <tr>
                                                <th>Matériel</th>
                                                <th>Catégorie</th>
                                                <th>Spécifications</th>
                                                <th width="90">
                                                    Quantité
                                                </th>
                                                <th width="190">
                                                    Prix Unitaire (FCFA) *
                                                </th>
                                                <th width="150">
                                                    Sous-Total
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($articles_list as $art): ?>
                                            <tr>
                                                <td>
                                                    <strong>
                                                        <?= clean($art['nom_materiel']) ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info">
                                                        <?= clean($art['nom_categorie']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <small>
                                                        <?= clean($art['specification_technique'] ?? '—') ?>
                                                    </small>
                                                </td>
                                                <td>
                                                    <strong class="badge bg-primary">
                                                        <?= $art['quantite'] ?>
                                                    </strong>
                                                </td>
                                                <td>
                                                    <input type="number"
                                                           name="prix_articles[<?= $art['id_detail'] ?>]"
                                                           id="prix_<?= $art['id_detail'] ?>_<?= $c['id_commande'] ?>"
                                                           class="form-control input-sm prix-input"
                                                           data-qte="<?= $art['quantite'] ?>"
                                                           data-cmd="<?= $c['id_commande'] ?>"
                                                           data-detail="<?= $art['id_detail'] ?>"
                                                           placeholder="Ex: 350000"
                                                           min="0"
                                                           step="100"
                                                           onchange="calculerSousTotal(
                                                               <?= $art['id_detail'] ?>,
                                                               <?= $c['id_commande'] ?>
                                                           )"
                                                           onkeyup="calculerSousTotal(
                                                               <?= $art['id_detail'] ?>,
                                                               <?= $c['id_commande'] ?>
                                                           )">
                                                </td>
                                                <td>
                                                    <span id="st_<?= $art['id_detail'] ?>_<?= $c['id_commande'] ?>"
                                                          style="font-weight:700;
                                                                 color:var(--primary);
                                                                 font-size:14px">
                                                        0 FCFA
                                                    </span>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background:#f3e5f5">
                                                <th colspan="5"
                                                    style="padding:14px;
                                                           font-size:15px;
                                                           text-align:right">
                                                    💰 MONTANT TOTAL :
                                                </th>
                                                <th style="padding:14px;
                                                           font-size:18px;
                                                           color:#6a1b9a">
                                                    <span id="total_<?= $c['id_commande'] ?>">
                                                        0 FCFA
                                                    </span>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>

                            <!-- ÉTAPE 3 : Commentaire -->
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

                            <!-- Boutons -->
                            <div class="decision-btns">
                                <button type="submit"
                                        name="decision"
                                        value="validee"
                                        class="btn btn-success btn-lg"
                                        onclick="decisionChoisie='validee'">
                                    ✅ Valider avec ce Fournisseur et ces Prix
                                </button>
                                <button type="submit"
                                        name="decision"
                                        value="rejetee"
                                        class="btn btn-danger btn-lg"
                                        onclick="decisionChoisie='rejetee'">
                                    ❌ Rejeter
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
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($commandes)): ?>
                            <tr>
                                <td colspan="10"
                                    class="text-center py-4">
                                    😕 Aucune commande
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($commandes as $i => $c): ?>
                            <?php
                            $sb_list = [
                                'en_attente_validation' =>
                                    ['warning', '⏳ En Attente'],
                                'validee'  =>
                                    ['success', '✅ Validée'],
                                'rejetee'  =>
                                    ['danger',  '❌ Rejetée'],
                                'livree'   =>
                                    ['success', '📦 Livrée'],
                                'partiellement_livree' =>
                                    ['warning', '📦 Partielle'],
                            ];
                            $sb = $sb_list[$c['statut']]
                                  ?? ['secondary', $c['statut']];
                            ?>
                            <tr class="<?= $c['statut'] === 'en_attente_validation'
                                            ? 'row-attente' : '' ?>">
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($c['reference_commande']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= clean($c['reference_demande']) ?>
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
                                    <?php if ($c['nom_entreprise']): ?>
                                    <strong>
                                        <?= clean($c['nom_entreprise']) ?>
                                    </strong>
                                    <?php else: ?>
                                    <span class="badge bg-warning">
                                        ⏳ À choisir
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($c['montant_total'] > 0): ?>
                                    <strong style="color:var(--primary)">
                                        <?= formaterMontant($c['montant_total']) ?>
                                    </strong>
                                    <?php else: ?>
                                    <span class="badge bg-warning">
                                        ⏳ À définir
                                    </span>
                                    <?php endif; ?>
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
                                    <a href="detail.php?id=<?= $c['id_commande'] ?>"
                                       class="btn btn-sm btn-info">
                                        👁️
                                    </a>
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
var decisionChoisie = '';

// ============================================
// AFFICHER INFO FOURNISSEUR
// ============================================
function afficherFournisseur(select, cmd_id) {
    var opt = select.options[select.selectedIndex];
    var div = document.getElementById('info_fourn_' + cmd_id);

    if (!select.value) {
        div.style.display = 'none';
        return;
    }

    div.innerHTML =
        '<div style="display:grid;' +
        'grid-template-columns:1fr 1fr 1fr;gap:10px">' +

        '<div>' +
        '<span style="color:#757575;font-size:12px">🏢 Fournisseur</span>' +
        '<br><strong>' + opt.text + '</strong>' +
        '</div>' +

        '<div>' +
        '<span style="color:#757575;font-size:12px">📞 Téléphone</span>' +
        '<br><strong>' + (opt.dataset.tel || '—') + '</strong>' +
        '</div>' +

        '<div>' +
        '<span style="color:#757575;font-size:12px">📧 Email</span>' +
        '<br><strong>' + (opt.dataset.email || '—') + '</strong>' +
        '</div>' +

        '</div>';

    div.style.display = 'block';
}

// ============================================
// CALCULER SOUS-TOTAL PAR ARTICLE
// ============================================
function calculerSousTotal(id_detail, cmd_id) {
    var input = document.getElementById(
        'prix_' + id_detail + '_' + cmd_id
    );
    var st_elem = document.getElementById(
        'st_' + id_detail + '_' + cmd_id
    );

    if (!input || !st_elem) return;

    var prix = parseFloat(input.value) || 0;
    var qte  = parseFloat(input.dataset.qte) || 0;
    var st   = qte * prix;

    st_elem.textContent = st.toLocaleString('fr-FR') + ' FCFA';
    st_elem.style.color = prix > 0
                          ? 'var(--primary)'
                          : 'var(--danger)';

    // Recalculer total
    calculerTotal(cmd_id);
}

// ============================================
// CALCULER TOTAL GÉNÉRAL
// ============================================
function calculerTotal(cmd_id) {
    var total  = 0;
    var inputs = document.querySelectorAll(
        '.prix-input[data-cmd="' + cmd_id + '"]'
    );

    inputs.forEach(function(input) {
        var prix = parseFloat(input.value) || 0;
        var qte  = parseFloat(input.dataset.qte) || 0;
        total   += qte * prix;
    });

    var total_elem = document.getElementById('total_' + cmd_id);
    if (total_elem) {
        total_elem.textContent =
            total.toLocaleString('fr-FR') + ' FCFA';
        total_elem.style.color = total > 0
                                 ? '#6a1b9a'
                                 : 'var(--danger)';
    }
}

// ============================================
// CONFIRMER DÉCISION
// ============================================
function confirmerDecision(form, cmd_id) {
    var commentaire = form.querySelector(
        'textarea[name="commentaire"]'
    ).value.trim();

    var fournisseur = form.querySelector(
        'select[name="id_fournisseur"]'
    ).value;

    if (decisionChoisie === 'validee') {

        // Vérifier fournisseur
        if (!fournisseur) {
            alert('Vous devez choisir un fournisseur !');
            form.querySelector('select').focus();
            return false;
        }

        // Vérifier que tous les prix sont remplis
        var inputs = document.querySelectorAll(
            '.prix-input[data-cmd="' + cmd_id + '"]'
        );
        var tous_ok = true;

        inputs.forEach(function(input) {
            var val = parseFloat(input.value);
            if (!input.value || val <= 0) {
                tous_ok = false;
                input.style.borderColor = 'red';
            } else {
                input.style.borderColor = '';
            }
        });

        if (!tous_ok) {
            alert(
                'Vous devez saisir tous les prix unitaires !\n' +
                'Les champs en rouge sont vides ou invalides.'
            );
            return false;
        }

        // Récupérer total
        var total_elem = document.getElementById(
            'total_' + cmd_id
        );
        var total_text = total_elem
                         ? total_elem.textContent
                         : '?';

        // Nom fournisseur
        var fourn_select = form.querySelector(
            'select[name="id_fournisseur"]'
        );
        var fourn_nom = fourn_select.options[
            fourn_select.selectedIndex
        ].text;

        return confirm(
            'Confirmer la VALIDATION ?\n\n' +
            '🏢 Fournisseur : ' + fourn_nom + '\n' +
            '💰 Montant total : ' + total_text + '\n\n' +
            'Cette action est définitive !'
        );
    }

    if (decisionChoisie === 'rejetee') {
        if (!commentaire) {
            alert('Commentaire obligatoire pour rejeter !');
            form.querySelector('textarea').focus();
            return false;
        }
        return confirm(
            'Confirmer le REJET ?\n\n' +
            'Le demandeur sera notifié.'
        );
    }

    return true;
}
</script>
</body>
</html>