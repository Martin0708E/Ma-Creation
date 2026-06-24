<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

// RESTRICTION : VALIDATEUR SEULEMENT
if ($_SESSION['role'] !== 'validateur') {
    header('Location: ../dashboard/index.php?erreur=' . urlencode(
        'Acces refuse ! Seul le validateur gere les livraisons.'
    ));
    exit();
}

$pdo           = getConnexion();
$success       = '';
$erreur        = '';
$cmd_preselect = (int)($_GET['cmd'] ?? 0);

// Commandes validées prêtes à livrer
$commandes = $pdo->query("
    SELECT c.*,
           f.nom_entreprise,
           f.telephone as fournisseur_tel,
           d.reference_demande,
           d.departement_demandeur,
           d.priorite
    FROM commandes c
    JOIN fournisseurs f
        ON c.id_fournisseur = f.id_fournisseur
    JOIN demandes_acquisition d
        ON c.id_demande = d.id_demande
    WHERE c.statut = 'validee'
    ORDER BY c.date_commande DESC
")->fetchAll();

// Réceptionnaires
$receptionnaires = $pdo->query("
    SELECT * FROM utilisateurs
    WHERE statut = TRUE
    ORDER BY nom, prenom
")->fetchAll();

// ============================================
// TRAITEMENT
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id_commande       = (int)($_POST['id_commande']       ?? 0);
    $id_receptionnaire = (int)($_POST['id_receptionnaire'] ?? 0);
    $date_livraison    = trim($_POST['date_livraison']      ?? '');
    $statut            = $_POST['statut']                   ?? '';
    $observation       = trim($_POST['observation']         ?? '');
    $articles_livres   = $_POST['articles']                 ?? [];

    // Validations
    if (!$id_commande) {
        $erreur = "Veuillez choisir une commande.";
    } elseif (!$id_receptionnaire) {
        $erreur = "Veuillez choisir un receptionnaire.";
    } elseif (empty($date_livraison)) {
        $erreur = "La date de livraison est obligatoire.";
    } elseif (empty($statut)) {
        $erreur = "Le statut est obligatoire.";
    } elseif (empty($articles_livres)) {
        $erreur = "Veuillez renseigner les articles livres.";
    } else {

        try {
            $pdo->beginTransaction();

            // 1. Créer la livraison
            $stmt = $pdo->prepare("
                INSERT INTO livraisons
                (id_commande, id_receptionnaire,
                 date_livraison, statut, observation)
                VALUES
                (:id_cmd, :id_recept,
                 :date, :statut, :obs)
                RETURNING id_livraison
            ");
            $stmt->execute([
                ':id_cmd'    => $id_commande,
                ':id_recept' => $id_receptionnaire,
                ':date'      => $date_livraison,
                ':statut'    => $statut,
                ':obs'       => !empty($observation)
                                ? $observation : null
            ]);
            $id_livraison = $stmt->fetch()['id_livraison'];

            // 2. Insérer détails livraison
            foreach ($articles_livres as $id_mat => $detail) {
                if (!empty($detail['qte_commandee'])) {

                    $qte_livree = (int)(
                        $detail['qte_livree']
                        ?? $detail['qte_commandee']
                    );

                    $pdo->prepare("
                        INSERT INTO details_livraison
                        (id_livraison, id_materiel,
                         quantite_commandee,
                         quantite_livree, observation)
                        VALUES
                        (:id_liv, :id_mat,
                         :qte_cmd, :qte_liv, :obs)
                    ")->execute([
                        ':id_liv'  => $id_livraison,
                        ':id_mat'  => (int)$id_mat,
                        ':qte_cmd' => (int)$detail['qte_commandee'],
                        ':qte_liv' => $qte_livree,
                        ':obs'     => $detail['obs'] ?? null
                    ]);

                    // Ajouter à l'inventaire
                    for ($k = 0; $k < $qte_livree; $k++) {
                        $num_serie = 'SN-' . date('Y') . '-' .
                                     $id_mat . '-' .
                                     str_pad(
                                         ($k + 1), 3, '0',
                                         STR_PAD_LEFT
                                     ) . '-' . rand(100, 999);

                        $pdo->prepare("
                            INSERT INTO inventaire
                            (id_materiel, id_livraison,
                             numero_serie, etat,
                             localisation, date_entree)
                            VALUES
                            (:id_mat, :id_liv,
                             :serie, 'neuf',
                             'A definir', CURRENT_DATE)
                        ")->execute([
                            ':id_mat' => (int)$id_mat,
                            ':id_liv' => $id_livraison,
                            ':serie'  => $num_serie
                        ]);
                    }
                }
            }

            // 3. Mettre à jour statut commande
            if ($statut === 'conforme') {
                $statut_cmd = 'livree';
            } elseif ($statut === 'non_conforme') {
                $statut_cmd = 'partiellement_livree';
            } else {
                $statut_cmd = 'partiellement_livree';
            }

            $pdo->prepare("
                UPDATE commandes SET statut = :statut
                WHERE id_commande = :id
            ")->execute([
                ':statut' => $statut_cmd,
                ':id'     => $id_commande
            ]);

            // 4. Mettre à jour statut demande
            if ($statut === 'conforme') {
                $statut_dem = 'livree';
            } else {
                $statut_dem = 'en_cours_traitement';
            }

            $pdo->prepare("
                UPDATE demandes_acquisition SET statut = :statut
                WHERE id_demande = (
                    SELECT id_demande FROM commandes
                    WHERE id_commande = :id
                )
            ")->execute([
                ':statut' => $statut_dem,
                ':id'     => $id_commande
            ]);

            // 5. Récupérer infos commande
            $info_cmd = $pdo->prepare("
                SELECT c.reference_commande,
                       d.id_utilisateur as id_demandeur,
                       d.reference_demande
                FROM commandes c
                JOIN demandes_acquisition d
                    ON c.id_demande = d.id_demande
                WHERE c.id_commande = :id
            ");
            $info_cmd->execute([':id' => $id_commande]);
            $info_cmd = $info_cmd->fetch();

            // 6. Notifier le demandeur
            if ($statut === 'conforme') {
                $msg_notif  = "Livraison conforme recue pour " .
                              $info_cmd['reference_commande'] . " !";
                $type_notif = 'succes';
            } elseif ($statut === 'non_conforme') {
                $msg_notif  = "Livraison non conforme pour " .
                              $info_cmd['reference_commande'] . ".";
                $type_notif = 'alerte';
            } else {
                $msg_notif  = "Livraison partielle pour " .
                              $info_cmd['reference_commande'] . ".";
                $type_notif = 'alerte';
            }

            $pdo->prepare("
                INSERT INTO notifications
                (id_utilisateur, message, type)
                VALUES (:id, :msg, :type)
            ")->execute([
                ':id'   => $info_cmd['id_demandeur'],
                ':msg'  => $msg_notif,
                ':type' => $type_notif
            ]);

            // 7. Notifier admins
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
                    ':msg' => "Livraison enregistree - Commande " .
                              $info_cmd['reference_commande'] .
                              " - Statut: " . $statut
                ]);
            }

            // 8. Log
            $pdo->prepare("
                INSERT INTO historique_actions
                (id_utilisateur, action,
                 table_concernee, id_enregistrement)
                VALUES (:id, :action, 'livraisons', :id_enreg)
            ")->execute([
                ':id'      => $_SESSION['id_utilisateur'],
                ':action'  => "Livraison enregistree - Commande " .
                              $info_cmd['reference_commande'],
                ':id_enreg'=> $id_livraison
            ]);

            $pdo->commit();

            header('Location: index.php?success=' . urlencode(
                "Livraison enregistree ! Inventaire mis a jour."
            ));
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $erreur = "Erreur : " . $e->getMessage();
        }

    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelle Livraison — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>📦 Enregistrer une Livraison</h1>
                <p class="page-subtitle">
                    Commandes validées prêtes à livrer
                </p>
            </div>
            <a href="index.php" class="btn btn-secondary">← Retour</a>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <?php if (empty($commandes)): ?>
        <div class="empty-state">
            <div class="empty-icon">📦</div>
            <h3>Aucune commande prête à livrer !</h3>
            <p>Les commandes doivent être validées avant livraison.</p>
            <a href="../commandes/index.php"
               class="btn btn-primary"
               style="margin-top:16px">
                → Voir les commandes
            </a>
        </div>

        <?php else: ?>

        <form method="POST" action="" id="formLivraison">

            <!-- SECTION 1 : Commande -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>🛒 Choisir la Commande *</h2>
                    <span class="badge bg-success">
                        <?= count($commandes) ?> disponible(s)
                    </span>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Commande à Livrer *</label>
                        <select name="id_commande"
                                id="selectCommande"
                                class="form-control"
                                onchange="chargerArticles(this)"
                                required>
                            <option value="">
                                -- Choisir une commande --
                            </option>
                            <?php foreach ($commandes as $c): ?>
                            <option value="<?= $c['id_commande'] ?>"
                                    data-ref="<?= clean($c['reference_commande']) ?>"
                                    data-dem="<?= clean($c['reference_demande']) ?>"
                                    data-fourn="<?= clean($c['nom_entreprise']) ?>"
                                    data-dept="<?= clean($c['departement_demandeur']) ?>"
                                    data-montant="<?= formaterMontant($c['montant_total']) ?>"
                                    <?= $cmd_preselect == $c['id_commande']
                                        ? 'selected' : '' ?>>
                                <?= clean($c['reference_commande']) ?> |
                                <?= clean($c['nom_entreprise']) ?> |
                                <?= formaterMontant($c['montant_total']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Fiche commande -->
                    <div id="ficheCommande"
                         class="fiche-commande"
                         style="display:none">
                        <div class="fiche-cmd-item">
                            <span>🛒 Commande :</span>
                            <strong id="fc_ref"></strong>
                        </div>
                        <div class="fiche-cmd-item">
                            <span>📋 Demande :</span>
                            <strong id="fc_dem"></strong>
                        </div>
                        <div class="fiche-cmd-item">
                            <span>🏢 Fournisseur :</span>
                            <strong id="fc_fourn"></strong>
                        </div>
                        <div class="fiche-cmd-item">
                            <span>🏢 Département :</span>
                            <strong id="fc_dept"></strong>
                        </div>
                        <div class="fiche-cmd-item">
                            <span>💰 Montant :</span>
                            <strong id="fc_montant"
                                    style="color:var(--primary)">
                            </strong>
                        </div>
                        <div class="fiche-cmd-item">
                            <span>📌 Statut :</span>
                            <span class="badge bg-success">
                                ✅ Validée
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2 : Infos livraison -->
            <div class="card mb-3">
                <div class="card-header">
                    <h2>📋 Informations Livraison *</h2>
                </div>
                <div class="card-body">
                    <div class="form-row">

                        <div class="form-group col-4">
                            <label>📅 Date Livraison *</label>
                            <input type="date"
                                   name="date_livraison"
                                   class="form-control"
                                   value="<?= date('Y-m-d') ?>"
                                   max="<?= date('Y-m-d') ?>"
                                   required>
                        </div>

                        <div class="form-group col-4">
                            <label>👤 Réceptionné par *</label>
                            <select name="id_receptionnaire"
                                    class="form-control"
                                    required>
                                <option value="">-- Choisir --</option>
                                <?php foreach ($receptionnaires as $r): ?>
                                <option value="<?= $r['id_utilisateur'] ?>"
                                    <?= $r['id_utilisateur'] ==
                                        $_SESSION['id_utilisateur']
                                        ? 'selected' : '' ?>>
                                    <?= clean($r['prenom'] . ' ' .
                                              $r['nom']) ?>
                                    (<?= ucfirst($r['role']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group col-4">
                            <label>📌 Statut *</label>
                            <select name="statut"
                                    id="selectStatut"
                                    class="form-control"
                                    onchange="afficherAlerte(this)"
                                    required>
                                <option value="">-- Choisir --</option>
                                <option value="conforme">
                                    ✅ Conforme
                                </option>
                                <option value="non_conforme">
                                    ❌ Non Conforme
                                </option>
                                <option value="partielle">
                                    📦 Partielle
                                </option>
                            </select>
                        </div>

                    </div>

                    <div id="alerteStatut" style="display:none"></div>

                    <div class="form-group">
                        <label>💬 Observation</label>
                        <textarea name="observation"
                                  class="form-control"
                                  rows="3"
                                  placeholder="Remarques...">
                        </textarea>
                    </div>
                </div>
            </div>

            <!-- SECTION 3 : Articles -->
            <div class="card mb-3"
                 id="sectionArticles"
                 style="display:none">
                <div class="card-header">
                    <h2>🖥️ Articles Livrés</h2>
                    <span class="badge bg-info"
                          id="nb_articles_badge">
                        0 article(s)
                    </span>
                </div>
                <div class="card-body p-0">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Matériel</th>
                                <th>Catégorie</th>
                                <th>Spécifications</th>
                                <th width="130">Qté Commandée</th>
                                <th width="130">Qté Livrée *</th>
                                <th>Observation</th>
                            </tr>
                        </thead>
                        <tbody id="corpsArticles">
                            <tr>
                                <td colspan="6"
                                    class="text-center py-3">
                                    Sélectionnez une commande
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Boutons -->
            <div class="form-actions-bottom">
                <a href="index.php" class="btn btn-secondary">
                    ← Annuler
                </a>
                <button type="submit"
                        class="btn btn-primary btn-lg">
                    💾 Enregistrer la Livraison
                </button>
            </div>

        </form>

        <?php endif; ?>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

<script>
function chargerArticles(select) {
    var id    = select.value;
    var fiche = document.getElementById('ficheCommande');

    if (!id) {
        fiche.style.display = 'none';
        document.getElementById('sectionArticles').style.display = 'none';
        return;
    }

    var opt = select.options[select.selectedIndex];
    document.getElementById('fc_ref').textContent     = opt.dataset.ref     || '';
    document.getElementById('fc_dem').textContent     = opt.dataset.dem     || '';
    document.getElementById('fc_fourn').textContent   = opt.dataset.fourn   || '';
    document.getElementById('fc_dept').textContent    = opt.dataset.dept    || '';
    document.getElementById('fc_montant').textContent = opt.dataset.montant || '';
    fiche.style.display = 'grid';

    fetch('../livraisons/get_articles.php?id_commande=' + id)
        .then(function(r) { return r.json(); })
        .then(function(articles) {
            var tbody   = document.getElementById('corpsArticles');
            var section = document.getElementById('sectionArticles');
            var badge   = document.getElementById('nb_articles_badge');

            section.style.display = 'block';
            badge.textContent     = articles.length + ' article(s)';

            if (articles.length === 0) {
                tbody.innerHTML =
                    '<tr><td colspan="6" class="text-center py-3">' +
                    'Aucun article</td></tr>';
                return;
            }

            var html = '';
            for (var i = 0; i < articles.length; i++) {
                var a = articles[i];
                html +=
                    '<tr>' +
                    '<td><strong>' + (a.nom_materiel || '') + '</strong></td>' +
                    '<td><span class="badge bg-info">' +
                        (a.nom_categorie || '') + '</span></td>' +
                    '<td><small>' + (a.specification_technique || '—') + '</small></td>' +
                    '<td>' +
                        '<input type="hidden" ' +
                            'name="articles[' + a.id_materiel + '][qte_commandee]" ' +
                            'value="' + a.quantite + '">' +
                        '<strong class="badge bg-primary">' + a.quantite + '</strong>' +
                    '</td>' +
                    '<td>' +
                        '<input type="number" ' +
                            'name="articles[' + a.id_materiel + '][qte_livree]" ' +
                            'class="form-control input-sm" ' +
                            'value="' + a.quantite + '" ' +
                            'min="0" max="' + a.quantite + '" required>' +
                    '</td>' +
                    '<td>' +
                        '<input type="text" ' +
                            'name="articles[' + a.id_materiel + '][obs]" ' +
                            'class="form-control input-sm" ' +
                            'placeholder="Remarque...">' +
                    '</td>' +
                    '</tr>';
            }
            tbody.innerHTML = html;
        })
        .catch(function(e) {
            console.error(e);
            document.getElementById('sectionArticles').style.display = 'block';
            document.getElementById('corpsArticles').innerHTML =
                '<tr><td colspan="6" class="text-center text-danger">' +
                'Erreur de chargement</td></tr>';
        });
}

function afficherAlerte(select) {
    var alerte = document.getElementById('alerteStatut');
    var msgs   = {
        'conforme'    : ['success', 'Conforme : Commande marquee Livree'],
        'non_conforme': ['danger',  'Non conforme : Partiellement livree'],
        'partielle'   : ['warning', 'Partielle : Partiellement livree']
    };
    if (select.value && msgs[select.value]) {
        var m = msgs[select.value];
        alerte.innerHTML     = '<div class="alert alert-' + m[0] + '">' + m[1] + '</div>';
        alerte.style.display = 'block';
    } else {
        alerte.style.display = 'none';
    }
}

window.addEventListener('load', function() {
    var sel = document.getElementById('selectCommande');
    if (sel && sel.value) chargerArticles(sel);
});

document.getElementById('formLivraison').addEventListener('submit', function(e) {
    var cmd    = document.getElementById('selectCommande').value;
    var statut = document.getElementById('selectStatut').value;
    if (!cmd) {
        e.preventDefault();
        alert('Choisissez une commande !');
        return;
    }
    if (!statut) {
        e.preventDefault();
        alert('Choisissez le statut !');
        return;
    }
    if (!confirm('Confirmer cette livraison ?')) {
        e.preventDefault();
    }
});
</script>
</body>
</html>