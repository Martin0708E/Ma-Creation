<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

// RESTRICTION : RESPONSABLE SEULEMENT
if ($_SESSION['role'] !== 'responsable') {
    header('Location: ../dashboard/index.php?erreur=' . urlencode(
        'Acces refuse ! Seul le responsable peut valider les demandes.'
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
           COUNT(dd.id_detail) as nb_articles,
           COALESCE(SUM(dd.quantite), 0) as total_quantite
    FROM demandes_acquisition d
    JOIN utilisateurs u
        ON d.id_utilisateur = u.id_utilisateur
    LEFT JOIN details_demande dd
        ON d.id_demande = dd.id_demande
    WHERE d.statut = 'en_attente'
    GROUP BY d.id_demande, u.nom, u.prenom, u.email
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
// TRAITEMENT VALIDATION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_SESSION['role'] !== 'responsable') {
        header('Location: ../dashboard/index.php');
        exit();
    }

    $id_demande  = (int)($_POST['id_demande'] ?? 0);
    $decision    = $_POST['decision']         ?? '';
    $commentaire = trim($_POST['commentaire'] ?? '');

    if (!in_array($decision, ['approuve', 'rejete'])) {
        header('Location: valider_demande.php?erreur=' .
            urlencode('Decision invalide !'));
        exit();
    }

    if ($decision === 'rejete' && empty($commentaire)) {
        header('Location: valider_demande.php?erreur=' . urlencode(
            'Le commentaire est obligatoire pour rejeter !'
        ));
        exit();
    }

    try {
        $pdo->beginTransaction();

        // Récupérer infos demande
        $info = $pdo->prepare("
            SELECT d.*,
                   u.id_utilisateur as id_demandeur
            FROM demandes_acquisition d
            JOIN utilisateurs u
                ON d.id_utilisateur = u.id_utilisateur
            WHERE d.id_demande = :id
            AND d.statut = 'en_attente'
        ");
        $info->execute([':id' => $id_demande]);
        $demande = $info->fetch();

        if (!$demande) {
            throw new Exception("Demande introuvable !");
        }

        // 1. Enregistrer validation
        $pdo->prepare("
            INSERT INTO validations
            (id_demande, id_validateur,
             niveau_validation, decision, commentaire)
            VALUES (:id_d, :id_v, 1, :decision, :commentaire)
        ")->execute([
            ':id_d'        => $id_demande,
            ':id_v'        => $_SESSION['id_utilisateur'],
            ':decision'    => $decision,
            ':commentaire' => !empty($commentaire)
                              ? $commentaire : null
        ]);

        // 2. Mettre à jour statut demande
        $nouveau_statut = ($decision === 'approuve')
                          ? 'validee' : 'rejetee';

        $pdo->prepare("
            UPDATE demandes_acquisition SET
                statut          = :statut,
                date_validation = NOW(),
                commentaire     = :commentaire
            WHERE id_demande = :id
        ")->execute([
            ':statut'      => $nouveau_statut,
            ':commentaire' => !empty($commentaire)
                              ? $commentaire : null,
            ':id'          => $id_demande
        ]);

        $ref_commande = '';

        // 3. Si approuvé → Créer commande
        if ($decision === 'approuve') {

            $ref_commande = 'CMD-' . date('Y') . '-' .
                str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

            // Créer commande SANS fournisseur et SANS montant
            // Le validateur définira le fournisseur et les prix
            $pdo->prepare("
                INSERT INTO commandes
                (id_demande, reference_commande,
                 montant_total, date_commande, statut)
                VALUES
                (:id_d, :ref, 0, CURRENT_DATE,
                 'en_attente_validation')
                RETURNING id_commande
            ")->execute([
                ':id_d' => $id_demande,
                ':ref'  => $ref_commande
            ]);

            // Notifier les validateurs
            $validateurs = $pdo->query("
                SELECT id_utilisateur FROM utilisateurs
                WHERE role = 'validateur'
                AND statut = TRUE
            ")->fetchAll();

            foreach ($validateurs as $v) {
                $pdo->prepare("
                    INSERT INTO notifications
                    (id_utilisateur, message, type)
                    VALUES (:id, :msg, 'info')
                ")->execute([
                    ':id'  => $v['id_utilisateur'],
                    ':msg' => "Nouvelle commande " .
                              $ref_commande .
                              " en attente. " .
                              "Veuillez choisir le fournisseur " .
                              "et definir les prix."
                ]);
            }

            // Notifier le demandeur
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'succes')
            ")->execute([
                ':id'  => $demande['id_demandeur'],
                ':msg' => "Votre demande " .
                          $demande['reference_demande'] .
                          " a ete approuvee ! " .
                          "Commande " . $ref_commande .
                          " creee. En attente du validateur."
            ]);

        } else {
            // Notifier rejet
            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, 'alerte')
            ")->execute([
                ':id'  => $demande['id_demandeur'],
                ':msg' => "Votre demande " .
                          $demande['reference_demande'] .
                          " a ete rejetee. Motif : " .
                          $commentaire
            ]);
        }

        // Log
        $pdo->prepare("
            INSERT INTO historique_actions
            (id_utilisateur, action,
             table_concernee, id_enregistrement)
            VALUES (:id, :action,
                    'demandes_acquisition', :id_enreg)
        ")->execute([
            ':id'      => $_SESSION['id_utilisateur'],
            ':action'  => "Validation demande " .
                          $demande['reference_demande'] .
                          " : " . $decision .
                          ($ref_commande
                              ? " → Commande " . $ref_commande
                              : ""),
            ':id_enreg'=> $id_demande
        ]);

        $pdo->commit();

        if ($decision === 'approuve') {
            $msg_s = "Demande approuvee ! Commande " .
                     $ref_commande .
                     " creee. Le validateur va choisir " .
                     "le fournisseur et les prix.";
        } else {
            $msg_s = "Demande rejetee avec succes.";
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
    <title>Validation Demandes — DGMP</title>
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
                <span style="background:#e65100;color:white;
                             padding:8px 14px;border-radius:8px;
                             font-size:12px;font-weight:700">
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

        <!-- Info workflow -->
        <div class="alert alert-info">
            ℹ️ <strong>Workflow :</strong>
            Vous approuvez la demande →
            Commande créée (SANS fournisseur, SANS prix) →
            <strong>Le validateur choisira le fournisseur
            et définira les prix</strong>
        </div>

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

        <?php
        // Récupérer les articles de la demande
        $articles_dem = $pdo->prepare("
            SELECT dd.*, m.nom_materiel,
                   m.specification_technique,
                   c.nom_categorie
            FROM details_demande dd
            JOIN materiels m ON dd.id_materiel = m.id_materiel
            JOIN categories_materiel c
                ON m.id_categorie = c.id_categorie
            WHERE dd.id_demande = :id
        ");
        $articles_dem->execute([':id' => $d['id_demande']]);
        $articles_dem = $articles_dem->fetchAll();
        ?>

        <div class="validation-card"
             style="border-left-color:#e65100">

            <!-- En-tête -->
            <div class="validation-card-header"
                 style="background:#fff3e0;
                        border-bottom-color:#ffcc80">
                <div>
                    <h3>
                        <?= clean($d['reference_demande']) ?>
                        &nbsp;
                        <?= getBadgePriorite($d['priorite']) ?>
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
                   class="btn btn-sm btn-info"
                   target="_blank">
                    👁️ Voir détails
                </a>
            </div>

            <!-- Corps -->
            <div class="validation-card-body">

                <!-- Infos générales -->
                <div class="validation-info-grid"
                     style="margin-bottom:16px">
                    <div class="vi-item">
                        <span>📝 Motif :</span>
                        <strong><?= clean($d['motif']) ?></strong>
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
                        <span>📦 Quantité totale :</span>
                        <strong>
                            <?= $d['total_quantite'] ?> unité(s)
                        </strong>
                    </div>
                </div>

                <!-- Tableau des articles -->
                <?php if (!empty($articles_dem)): ?>
                <div style="border:1px solid #e0e0e0;
                            border-radius:8px;
                            overflow:hidden;
                            margin-bottom:12px">
                    <table class="table"
                           style="margin:0">
                        <thead>
                            <tr>
                                <th>Matériel</th>
                                <th>Catégorie</th>
                                <th>Spécifications</th>
                                <th>Quantité</th>
                                <th>Prix</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($articles_dem as $art): ?>
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
                                    <span style="background:#f3e5f5;
                                                 border:1px solid #6a1b9a;
                                                 border-radius:6px;
                                                 padding:4px 10px;
                                                 font-size:11px;
                                                 color:#6a1b9a;
                                                 font-weight:700">
                                        🟣 Défini par validateur
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

            </div>

            <!-- Formulaire décision -->
            <div class="validation-card-footer">
                <form method="POST" action=""
                      onsubmit="return confirmerDecision(this)">
                    <input type="hidden"
                           name="id_demande"
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
                            ❌ Rejeter
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
var decisionChoisie = '';

function confirmerDecision(form) {
    var commentaire = form.querySelector(
        'textarea[name="commentaire"]'
    ).value.trim();

    if (decisionChoisie === 'rejete' && commentaire === '') {
        alert('Commentaire obligatoire pour rejeter !');
        form.querySelector('textarea').focus();
        return false;
    }

    var msg = decisionChoisie === 'approuve'
        ? 'Confirmer l\'approbation ?\n\n' +
          'Une commande sera creee.\n' +
          'Le validateur choisira le fournisseur et les prix.'
        : 'Confirmer le rejet ?\n\n' +
          'Le demandeur sera notifie.';

    return confirm(msg);
}
</script>
</body>
</html>