<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

// ============================================
// RESTRICTION : RESPONSABLE SEULEMENT
// ============================================
if ($_SESSION['role'] !== 'responsable') {
    header('Location: ../dashboard/index.php?erreur=' . urlencode(
        '⛔ Accès refusé ! Seul le responsable peut valider les demandes.'
    ));
    exit();
}

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');

// Demandes en attente
$demandes_attente = $pdo->query("
    SELECT d.*,
           u.nom, u.prenom, u.email,
           f.nom_entreprise,
           COUNT(dd.id_detail) as nb_articles,
           COALESCE(SUM(dd.quantite * dd.prix_unitaire_estime), 0) as montant_total
    FROM demandes_acquisition d
    JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
    LEFT JOIN fournisseurs f ON d.id_fournisseur = f.id_fournisseur
    LEFT JOIN details_demande dd ON d.id_demande = dd.id_demande
    WHERE d.statut = 'en_attente'
    GROUP BY d.id_demande, u.nom, u.prenom,
             u.email, f.nom_entreprise
    ORDER BY
        CASE d.priorite
            WHEN 'urgente' THEN 1
            WHEN 'haute'   THEN 2
            WHEN 'normale' THEN 3
            ELSE 4
        END,
        d.date_demande ASC
")->fetchAll();

// ============================================
// TRAITEMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Vérifier rôle côté serveur
    if ($_SESSION['role'] !== 'responsable') {
        header('Location: ../dashboard/index.php');
        exit();
    }

    $id_demande  = (int)($_POST['id_demande'] ?? 0);
    $decision    = $_POST['decision'] ?? '';
    $commentaire = trim($_POST['commentaire'] ?? '');

    if (!in_array($decision, ['approuve', 'rejete'])) {
        header('Location: valider_demande.php?erreur=' .
            urlencode('Décision invalide !'));
        exit();
    }

    if ($decision === 'rejete' && empty($commentaire)) {
        header('Location: valider_demande.php?erreur=' . urlencode(
            '⚠️ Commentaire obligatoire pour rejeter !'
        ));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Récupérer infos demande
        $info = $pdo->prepare("
            SELECT d.*,
                   u.id_utilisateur as id_demandeur,
                   f.nom_entreprise
            FROM demandes_acquisition d
            JOIN utilisateurs u ON d.id_utilisateur = u.id_utilisateur
            LEFT JOIN fournisseurs f ON d.id_fournisseur = f.id_fournisseur
            WHERE d.id_demande = :id
            AND d.statut = 'en_attente'
        ");
        $info->execute([':id' => $id_demande]);
        $demande = $info->fetch();

        if (!$demande) {
            throw new Exception("Demande introuvable ou déjà traitée !");
        }

        // 1. ENREGISTRER LA VALIDATION
        $pdo->prepare("
            INSERT INTO validations
            (id_demande, id_validateur, niveau_validation,
             decision, commentaire)
            VALUES (:id_d, :id_v, 1, :decision, :commentaire)
        ")->execute([
            ':id_d'        => $id_demande,
            ':id_v'        => $_SESSION['id_utilisateur'],
            ':decision'    => $decision,
            ':commentaire' => !empty($commentaire) ? $commentaire : null
        ]);

        // 2. METTRE À JOUR STATUT DEMANDE
        $nouveau_statut = ($decision === 'approuve') ? 'validee' : 'rejetee';

        $pdo->prepare("
            UPDATE demandes_acquisition SET
                statut          = :statut,
                date_validation = NOW(),
                commentaire     = :commentaire
            WHERE id_demande    = :id
        ")->execute([
            ':statut'      => $nouveau_statut,
            ':commentaire' => !empty($commentaire) ? $commentaire : null,
            ':id'          => $id_demande
        ]);

        $ref_commande = '';

        // 3. SI APPROUVÉ → CRÉER COMMANDE
        if ($decision === 'approuve') {

            $ref_commande = 'CMD-' . date('Y') . '-' .
                str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Calculer montant
            $stmt_m = $pdo->prepare("
                SELECT COALESCE(
                    SUM(quantite * prix_unitaire_estime), 0
                ) as total
                FROM details_demande
                WHERE id_demande = :id
            ");
            $stmt_m->execute([':id' => $id_demande]);
            $montant_total = $stmt_m->fetch()['total'];

            // Créer commande
            // statut = en_attente_validation car le VALIDATEUR doit valider
            $pdo->prepare("
                INSERT INTO commandes
                (id_demande, id_fournisseur, reference_commande,
                 montant_total, date_commande,
                 date_livraison_prevue, statut)
                VALUES (:id_d, :id_f, :ref, :montant,
                        CURRENT_DATE, :date_liv,
                        'en_attente_validation')
            ")->execute([
                ':id_d'     => $id_demande,
                ':id_f'     => $demande['id_fournisseur'],
                ':ref'      => $ref_commande,
                ':montant'  => $montant_total,
                ':date_liv' => !empty($demande['date_livraison_prevue'])
                               ? $demande['date_livraison_prevue']
                               : null
            ]);

            // Notifier le demandeur
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'succes')
            ")->execute([
                ':id'  => $demande['id_demandeur'],
                ':msg' => "✅ Votre demande " .
                          $demande['reference_demande'] .
                          " a été approuvée par le responsable ! " .
                          "Commande " . $ref_commande .
                          " créée - En attente validation validateur."
            ]);

            // Notifier les VALIDATEURS
            $validateurs = $pdo->query("
                SELECT id_utilisateur FROM utilisateurs
                WHERE role = 'validateur' AND statut = TRUE
            ")->fetchAll();

            foreach ($validateurs as $v) {
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'info')
                ")->execute([
                    ':id'  => $v['id_utilisateur'],
                    ':msg' => "🛒 Nouvelle commande " .
                              $ref_commande .
                              " en attente de votre validation."
                ]);
            }

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
                    ':msg' => "📋 Demande " .
                              $demande['reference_demande'] .
                              " approuvée. Commande " .
                              $ref_commande . " créée."
                ]);
            }

        } else {
            // Notifier rejet
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'alerte')
            ")->execute([
                ':id'  => $demande['id_demandeur'],
                ':msg' => "❌ Votre demande " .
                          $demande['reference_demande'] .
                          " a été rejetée par le responsable. " .
                          "Motif : " . $commentaire
            ]);
        }

        // Log
        $pdo->prepare("
            INSERT INTO historique_actions
            (id_utilisateur, action, table_concernee, id_enregistrement)
            VALUES (:id, :action, 'demandes_acquisition', :id_enreg)
        ")->execute([
            ':id'      => $_SESSION['id_utilisateur'],
            ':action'  => "Demande " .
                          $demande['reference_demande'] .
                          " : " . $decision .
                          ($ref_commande
                              ? " → Commande " . $ref_commande
                              : ""),
            ':id_enreg'=> $id_demande
        ]);

        $pdo->commit();

        if ($decision === 'approuve') {
            $msg_s = "✅ Demande approuvée ! " .
                     "Commande " . $ref_commande .
                     " créée et envoyée au validateur !";
        } else {
            $msg_s = "❌ Demande rejetée avec succès.";
        }

        header('Location: valider_demande.php?success=' .
            urlencode($msg_s));
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: valider_demande.php?erreur=' .
            urlencode('Erreur : ' . $e->getMessage()));
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acquisitions Informatiques</title>
    <!-- FAVICON -->
    <?php include '../includes/head.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php include '../includes/pwa_head.php'; ?>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>✅ Validation des Demandes</h1>
                <p class="page-subtitle">
                    Approuvez ou rejetez les demandes en attente
                </p>
            </div>
            <div style="display:flex;gap:10px;align-items:center">
                <span class="badge bg-warning"
                      style="font-size:15px;padding:10px 16px">
                    ⏳ <?= count($demandes_attente) ?> en attente
                </span>
                <span class="badge bg-orange"
                      style="font-size:12px;padding:8px 12px;
                             background:#e65100;color:white">
                    🟠 Responsable uniquement
                </span>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- Info responsable -->
        <div class="validateur-info-box"
             style="border-color:#e65100;
                    background:linear-gradient(135deg,#fff3e0,#ffe0b2)">
            <div class="vib-icon">🟠</div>
            <div class="vib-content">
                <h3 style="color:#e65100">
                    Connecté en tant que Responsable
                </h3>
                <p>
                    👤 <strong><?= clean($_SESSION['nom_complet']) ?></strong>
                    | Seul vous pouvez valider ou rejeter les demandes.
                </p>
            </div>
        </div>

        <?php if (empty($demandes_attente)): ?>
        <div class="empty-state">
            <div class="empty-icon">🎉</div>
            <h3>Aucune demande en attente !</h3>
            <p>Toutes les demandes ont été traitées.</p>
            <a href="../dashboard/index.php"
               class="btn btn-primary"
               style="margin-top:16px">
                → Tableau de bord
            </a>
        </div>

        <?php else: ?>

        <?php foreach ($demandes_attente as $d): ?>
        <div class="validation-card"
             style="border-left-color:#e65100">

            <div class="validation-card-header"
                 style="background:#fff3e0;border-bottom-color:#ffcc80">
                <div>
                    <h3>
                        <?= clean($d['reference_demande']) ?>
                        &nbsp;<?= getBadgePriorite($d['priorite']) ?>
                    </h3>
                    <p>
                        👤 <strong>
                            <?= clean($d['prenom'] . ' ' . $d['nom']) ?>
                        </strong>
                        | 📧 <?= clean($d['email']) ?>
                        | 🏢 <?= clean($d['departement_demandeur']) ?>
                        | 📅 <?= date('d/m/Y H:i',
                            strtotime($d['date_demande'])) ?>
                    </p>
                </div>
                <a href="detail_demande.php?id=<?= $d['id_demande'] ?>"
                   class="btn btn-sm btn-info" target="_blank">
                    👁️ Voir détails
                </a>
            </div>

            <div class="validation-card-body">
                <div class="validation-info-grid">
                    <div class="vi-item">
                        <span>📝 Motif :</span>
                        <strong><?= clean($d['motif']) ?></strong>
                    </div>
                    <div class="vi-item">
                        <span>🏢 Fournisseur :</span>
                        <strong>
                            <?php if (!empty($d['nom_entreprise'])): ?>
                                <?= clean($d['nom_entreprise']) ?>
                            <?php else: ?>
                                <span style="color:var(--danger)">
                                    ⚠️ Non défini
                                </span>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="vi-item">
                        <span>🖥️ Articles :</span>
                        <strong>
                            <span class="badge bg-info">
                                <?= $d['nb_articles'] ?> article(s)
                            </span>
                        </strong>
                    </div>
                    <div class="vi-item">
                        <span>💰 Montant :</span>
                        <strong style="color:var(--primary);
                                       font-size:16px">
                            <?= formaterMontant($d['montant_total']) ?>
                        </strong>
                    </div>
                </div>
            </div>

            <div class="validation-card-footer">
                <form method="POST" action=""
                      onsubmit="return confirmerDecision(this)">
                    <input type="hidden" name="id_demande"
                           value="<?= $d['id_demande'] ?>">

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
                                value="approuve"
                                class="btn btn-success btn-lg"
                                onclick="decisionChoisie='approuve'">
                            ✅ Approuver la Demande
                        </button>
                        <button type="submit"
                                name="decision"
                                value="rejete"
                                class="btn btn-danger btn-lg"
                                onclick="decisionChoisie='rejete'">
                            ❌ Rejeter la Demande
                        </button>
                    </div>
                </form>
            </div>

        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
let decisionChoisie = '';

function confirmerDecision(form) {
    var commentaire = form.querySelector(
        'textarea[name="commentaire"]'
    ).value.trim();

    if (decisionChoisie === 'rejete' && commentaire === '') {
        alert('⚠️ Commentaire obligatoire pour rejeter !');
        form.querySelector('textarea').focus();
        return false;
    }

    var msg = decisionChoisie === 'approuve'
        ? '✅ Confirmer l\'approbation ?\n\n→ Une commande sera créée et envoyée au validateur !'
        : '❌ Confirmer le rejet ?\n\n→ Le demandeur sera notifié.';

    return confirm(msg);
}
</script>
</body>
</html>