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
$id_user = $_SESSION['id_utilisateur'];
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');

// Récupérer infos utilisateur
$stmt = $pdo->prepare("
    SELECT * FROM utilisateurs
    WHERE id_utilisateur = :id
");
$stmt->execute([':id' => $id_user]);
$user = $stmt->fetch();

if (!$user) {
    header('Location: ../auth/logout.php');
    exit();
}

// Statistiques
$nb_demandes = $pdo->prepare("
    SELECT COUNT(*) as nb
    FROM demandes_acquisition
    WHERE id_utilisateur = :id
");
$nb_demandes->execute([':id' => $id_user]);
$nb_demandes = $nb_demandes->fetch()['nb'];

$nb_notifications = $pdo->prepare("
    SELECT COUNT(*) as nb
    FROM notifications
    WHERE id_utilisateur = :id AND lu = FALSE
");
$nb_notifications->execute([':id' => $id_user]);
$nb_notifications = $nb_notifications->fetch()['nb'];

// Historique récent
$historique = $pdo->prepare("
    SELECT * FROM historique_actions
    WHERE id_utilisateur = :id
    ORDER BY date_action DESC
    LIMIT 8
");
$historique->execute([':id' => $id_user]);
$historique = $historique->fetchAll();

// Dernières demandes
$dernieres = $pdo->prepare("
    SELECT * FROM demandes_acquisition
    WHERE id_utilisateur = :id
    ORDER BY date_demande DESC
    LIMIT 5
");
$dernieres->execute([':id' => $id_user]);
$dernieres = $dernieres->fetchAll();

// Initiales et couleur rôle
$initiales = strtoupper(
    substr($user['prenom'], 0, 1) .
    substr($user['nom'], 0, 1)
);

$role_colors = [
    'admin'       => ['#c62828', '🔴'],
    'responsable' => ['#e65100', '🟠'],
    'agent'       => ['#2e7d32', '🟢'],
    'validateur'  => ['#6a1b9a', '🟣'],
];
$rc = $role_colors[$user['role']] ?? ['#1a237e', '🔵'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* Force MDP */
        .force-barre {
            height: 6px;
            background: #e0e0e0;
            border-radius: 10px;
            margin-top: 6px;
            overflow: hidden;
        }
        .force-niveau {
            height: 100%;
            border-radius: 10px;
            transition: all 0.4s;
            width: 0%;
        }
        .force-texte {
            font-size: 11px;
            margin-top: 4px;
        }

        /* Validation MDP */
        .mdp-check {
            font-size: 12px;
            margin-top: 4px;
            padding: 6px 10px;
            border-radius: 4px;
        }
        .mdp-ok  { color: #2e7d32; background: #e8f5e9; }
        .mdp-err { color: #c62828; background: #ffebee; }
    </style>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <!-- En-tête -->
        <div class="page-header">
            <div>
                <h1>👤 Mon Profil</h1>
                <p class="page-subtitle">
                    Gérez vos informations personnelles
                </p>
            </div>
            <a href="../dashboard/index.php"
               class="btn btn-secondary">
                ← Retour au tableau de bord
            </a>
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

        <div class="profile-grid">

            <!-- ============================= -->
            <!-- CARTE PROFIL GAUCHE           -->
            <!-- ============================= -->
            <div class="profile-left">
                <div class="card profile-card">

                    <!-- Bannière -->
                    <div class="profile-banner"
                         style="background:linear-gradient(135deg,
                                <?= $rc[0] ?>, #1a237e)">
                    </div>

                    <!-- Avatar -->
                    <div class="profile-avatar-wrapper">
                        <div class="profile-avatar"
                             style="background:linear-gradient(135deg,
                                    <?= $rc[0] ?>, #283593)">
                            <?= $initiales ?>
                        </div>
                    </div>

                    <!-- Identité -->
                    <div class="profile-identity">
                        <h2>
                            <?= clean($user['prenom'] . ' ' .
                                      $user['nom']) ?>
                        </h2>
                        <p class="profile-role">
                            <?= $rc[1] ?> <?= ucfirst($user['role']) ?>
                        </p>
                        <p class="profile-dept">
                            🏢 <?= clean($user['departement'] ?? 'Non défini') ?>
                        </p>
                        <div class="profile-status">
                            <?php if ($user['statut']): ?>
                            <span class="badge bg-success">
                                ✅ Compte Actif
                            </span>
                            <?php else: ?>
                            <span class="badge bg-danger">
                                ❌ Compte Inactif
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Stats rapides -->
                    <div class="profile-quick-stats">
                        <div class="quick-stat">
                            <span class="qs-value">
                                <?= $nb_demandes ?>
                            </span>
                            <span class="qs-label">Demandes</span>
                        </div>
                        <div class="quick-stat">
                            <span class="qs-value">
                                <?= $nb_notifications ?>
                            </span>
                            <span class="qs-label">Notifications</span>
                        </div>
                        <div class="quick-stat">
                            <span class="qs-value">
                                <?= count($historique) ?>
                            </span>
                            <span class="qs-label">Actions</span>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="profile-contact">
                        <div class="contact-item">
                            <span class="contact-icon">📧</span>
                            <span><?= clean($user['email']) ?></span>
                        </div>
                        <div class="contact-item">
                            <span class="contact-icon">📞</span>
                            <span>
                                <?= clean($user['telephone'] ?? 'Non renseigné') ?>
                            </span>
                        </div>
                        <div class="contact-item">
                            <span class="contact-icon">📅</span>
                            <span>
                                Membre depuis le
                                <?= date('d/m/Y',
                                    strtotime($user['date_creation'])) ?>
                            </span>
                        </div>
                    </div>

                    <!-- Boutons action -->
                    <div class="profile-actions">
                        <button onclick="ouvrirModal('modalModifierProfil')"
                                class="btn btn-primary btn-block">
                            ✏️ Modifier mon Profil
                        </button>
                        <button onclick="ouvrirModal('modalChangerMdp')"
                                class="btn btn-warning btn-block">
                            🔑 Changer mon Mot de Passe
                        </button>
                    </div>

                </div>
            </div>

            <!-- ============================= -->
            <!-- PARTIE DROITE                 -->
            <!-- ============================= -->
            <div class="profile-right">

                <!-- Informations détaillées -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h2>📋 Informations Personnelles</h2>
                        <button onclick="ouvrirModal('modalModifierProfil')"
                                class="btn btn-sm btn-primary">
                            ✏️ Modifier
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="info-grid">
                            <div class="info-item">
                                <label>👤 Nom</label>
                                <span><?= clean($user['nom']) ?></span>
                            </div>
                            <div class="info-item">
                                <label>👤 Prénom</label>
                                <span><?= clean($user['prenom']) ?></span>
                            </div>
                            <div class="info-item">
                                <label>📧 Email</label>
                                <span><?= clean($user['email']) ?></span>
                            </div>
                            <div class="info-item">
                                <label>📞 Téléphone</label>
                                <span>
                                    <?= clean($user['telephone'] ?? '—') ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>🏢 Département</label>
                                <span>
                                    <?= clean($user['departement'] ?? '—') ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>🎭 Rôle</label>
                                <span>
                                    <?= $rc[1] ?> <?= ucfirst($user['role']) ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>📅 Date Création</label>
                                <span>
                                    <?= date('d/m/Y H:i',
                                        strtotime($user['date_creation'])) ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <label>🔐 Statut</label>
                                <span>
                                    <?= $user['statut']
                                        ? '<span class="badge bg-success">✅ Actif</span>'
                                        : '<span class="badge bg-danger">❌ Inactif</span>' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Dernières demandes -->
                <?php if (!empty($dernieres)): ?>
                <div class="card mb-3">
                    <div class="card-header">
                        <h2>📋 Mes Dernières Demandes</h2>
                        <a href="../demandes/index.php"
                           class="btn btn-sm btn-secondary">
                            Voir tout →
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Référence</th>
                                    <th>Département</th>
                                    <th>Priorité</th>
                                    <th>Statut</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dernieres as $d): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= clean($d['reference_demande']) ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <?= clean($d['departement_demandeur']) ?>
                                    </td>
                                    <td>
                                        <?= getBadgePriorite($d['priorite']) ?>
                                    </td>
                                    <td>
                                        <?= getBadgeStatut($d['statut']) ?>
                                    </td>
                                    <td>
                                        <?= date('d/m/Y',
                                            strtotime($d['date_demande'])) ?>
                                    </td>
                                    <td>
                                        <a href="../demandes/detail_demande.php?id=<?= $d['id_demande'] ?>"
                                           class="btn btn-sm btn-info">
                                            👁️
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Historique -->
                <div class="card">
                    <div class="card-header">
                        <h2>📜 Historique de mes Actions</h2>
                        <span class="badge bg-info">
                            <?= count($historique) ?> actions
                        </span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($historique)): ?>
                        <div class="empty-state" style="padding:30px">
                            <p>Aucune action enregistrée</p>
                        </div>
                        <?php else: ?>
                        <div class="historique-list">
                            <?php foreach ($historique as $h): ?>
                            <div class="historique-item">
                                <div class="historique-icon">⚡</div>
                                <div class="historique-content">
                                    <p class="historique-action">
                                        <?= clean($h['action']) ?>
                                    </p>
                                    <?php if ($h['table_concernee']): ?>
                                    <small class="text-muted">
                                        📁 <?= clean($h['table_concernee']) ?>
                                    </small>
                                    <?php endif; ?>
                                </div>
                                <div class="historique-date">
                                    🕐 <?= date('d/m/Y H:i',
                                        strtotime($h['date_action'])) ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </div>

    </main>
</div>

<!-- ============================================ -->
<!-- MODAL : MODIFIER PROFIL                      -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalModifierProfil">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Modifier mon Profil</h3>
            <button onclick="fermerModal('modalModifierProfil')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="modifier_profil">
            <div class="modal-body">

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>👤 Nom *</label>
                        <input type="text"
                               name="nom"
                               class="form-control"
                               value="<?= clean($user['nom']) ?>"
                               style="text-transform:uppercase"
                               required>
                    </div>
                    <div class="form-group col-6">
                        <label>👤 Prénom *</label>
                        <input type="text"
                               name="prenom"
                               class="form-control"
                               value="<?= clean($user['prenom']) ?>"
                               required>
                    </div>
                </div>

                <div class="form-group">
                    <label>📧 Email *</label>
                    <input type="email"
                           name="email"
                           class="form-control"
                           value="<?= clean($user['email']) ?>"
                           required>
                </div>

                <div class="form-group">
                    <label>📞 Téléphone</label>
                    <input type="text"
                           name="telephone"
                           class="form-control"
                           value="<?= clean($user['telephone'] ?? '') ?>"
                           placeholder="+225 XX XX XX XX">
                </div>

                <div class="form-group">
                    <label>🏢 Département</label>
                    <input type="text"
                           name="departement"
                           class="form-control"
                           value="<?= clean($user['departement'] ?? '') ?>"
                           placeholder="Ex: Direction Informatique">
                </div>

                <div class="alert alert-info">
                    ℹ️ Votre rôle ne peut être modifié
                    que par un administrateur.
                </div>

            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalModifierProfil')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    💾 Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL : CHANGER MOT DE PASSE                 -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalChangerMdp">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>🔑 Changer mon Mot de Passe</h3>
            <button onclick="fermerModal('modalChangerMdp')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST"
              action="traitement.php"
              id="formChangerMdp">
            <input type="hidden" name="action" value="changer_mdp">
            <div class="modal-body">

                <!-- Mot de passe actuel -->
                <div class="form-group">
                    <label>🔒 Mot de Passe Actuel *</label>
                    <div class="password-group">
                        <input type="password"
                               id="mdp_actuel"
                               name="mdp_actuel"
                               class="form-control"
                               placeholder="Votre mot de passe actuel"
                               required>
                        <button type="button"
                                class="toggle-password"
                                onclick="toggleMdp('mdp_actuel')">
                            👁️
                        </button>
                    </div>
                </div>

                <!-- Nouveau mot de passe -->
                <div class="form-group">
                    <label>🔑 Nouveau Mot de Passe *</label>
                    <div class="password-group">
                        <input type="password"
                               id="nouveau_mdp"
                               name="nouveau_mdp"
                               class="form-control"
                               placeholder="Minimum 6 caractères"
                               onkeyup="verifierForce(this.value)"
                               required minlength="6">
                        <button type="button"
                                class="toggle-password"
                                onclick="toggleMdp('nouveau_mdp')">
                            👁️
                        </button>
                    </div>
                    <!-- Indicateur force -->
                    <div class="force-barre">
                        <div class="force-niveau"
                             id="forceNiveau"></div>
                    </div>
                    <div class="force-texte"
                         id="forceTexte"></div>
                </div>

                <!-- Confirmer mot de passe -->
                <div class="form-group">
                    <label>🔑 Confirmer Mot de Passe *</label>
                    <div class="password-group">
                        <input type="password"
                               id="confirmer_mdp"
                               name="confirmer_mdp"
                               class="form-control"
                               placeholder="Répétez le nouveau MDP"
                               onkeyup="verifierCorrespondance()"
                               required minlength="6">
                        <button type="button"
                                class="toggle-password"
                                onclick="toggleMdp('confirmer_mdp')">
                            👁️
                        </button>
                    </div>
                    <!-- Message correspondance -->
                    <div id="msgCorrespondance"
                         class="mdp-check"
                         style="display:none">
                    </div>
                </div>

                <div class="alert alert-warning">
                    ⚠️ Après le changement, vous serez
                    déconnecté automatiquement.
                </div>

            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalChangerMdp')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit"
                        class="btn btn-warning">
                    🔑 Changer le Mot de Passe
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
// ============================================
// MODALS
// ============================================
function ouvrirModal(id) {
    document.getElementById(id).classList.add('show');
}

function fermerModal(id) {
    document.getElementById(id).classList.remove('show');
}

document.querySelectorAll('.modal-overlay').forEach(function(m) {
    m.addEventListener('click', function(e) {
        if (e.target === this) fermerModal(this.id);
    });
});

// ============================================
// TOGGLE MOT DE PASSE
// ============================================
function toggleMdp(id) {
    var input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

// ============================================
// FORCE DU MOT DE PASSE
// ============================================
function verifierForce(mdp) {
    var niveau = document.getElementById('forceNiveau');
    var texte  = document.getElementById('forceTexte');

    if (!mdp || mdp.length === 0) {
        niveau.style.width = '0%';
        texte.textContent  = '';
        return;
    }

    var score = 0;
    if (mdp.length >= 6)          score++;
    if (mdp.length >= 10)         score++;
    if (/[A-Z]/.test(mdp))        score++;
    if (/[0-9]/.test(mdp))        score++;
    if (/[^A-Za-z0-9]/.test(mdp)) score++;

    var niveaux = [
        { pct:'20%',  color:'#c62828', txt:'🔴 Très faible' },
        { pct:'40%',  color:'#e65100', txt:'🟠 Faible'      },
        { pct:'60%',  color:'#f57f17', txt:'🟡 Moyen'       },
        { pct:'80%',  color:'#2e7d32', txt:'🟢 Fort'        },
        { pct:'100%', color:'#1b5e20', txt:'💪 Très fort'   },
    ];

    var n = niveaux[Math.min(score - 1, 4)] || niveaux[0];
    niveau.style.width      = n.pct;
    niveau.style.background = n.color;
    texte.textContent       = n.txt;
    texte.style.color       = n.color;
}

// ============================================
// VÉRIFIER CORRESPONDANCE
// ============================================
function verifierCorrespondance() {
    var mdp1 = document.getElementById('nouveau_mdp').value;
    var mdp2 = document.getElementById('confirmer_mdp').value;
    var msg  = document.getElementById('msgCorrespondance');

    if (!mdp2 || mdp2.length === 0) {
        msg.style.display = 'none';
        return;
    }

    msg.style.display = 'block';

    if (mdp1 === mdp2) {
        msg.className    = 'mdp-check mdp-ok';
        msg.textContent  = '✅ Les mots de passe correspondent';
    } else {
        msg.className    = 'mdp-check mdp-err';
        msg.textContent  = '❌ Les mots de passe ne correspondent pas';
    }
}

// ============================================
// VALIDATION FORMULAIRE
// ============================================
document.getElementById('formChangerMdp')
        .addEventListener('submit', function(e) {

    var actuel   = document.getElementById('mdp_actuel').value;
    var nouveau  = document.getElementById('nouveau_mdp').value;
    var confirmer = document.getElementById('confirmer_mdp').value;

    if (!actuel) {
        e.preventDefault();
        alert('⚠️ Entrez votre mot de passe actuel !');
        return;
    }

    if (nouveau.length < 6) {
        e.preventDefault();
        alert('⚠️ Minimum 6 caractères !');
        return;
    }

    if (nouveau !== confirmer) {
        e.preventDefault();
        alert('❌ Les mots de passe ne correspondent pas !');
        return;
    }

    if (!confirm(
        '🔑 Confirmer le changement de mot de passe ?\n\n' +
        '⚠️ Vous serez déconnecté après le changement.'
    )) {
        e.preventDefault();
    }
});

// Ouvrir modal si erreur
<?php if ($erreur): ?>
ouvrirModal('modalChangerMdp');
<?php endif; ?>
</script>
</body>
</html>