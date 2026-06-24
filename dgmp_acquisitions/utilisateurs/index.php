<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin']);

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');

// Inscriptions en attente
$inscriptions_attente = $pdo->query("
    SELECT * FROM utilisateurs
    WHERE statut_inscription = 'en_attente'
    ORDER BY date_creation DESC
")->fetchAll();

// Tous les utilisateurs
$utilisateurs = $pdo->query("
    SELECT u.*,
           COUNT(DISTINCT d.id_demande) as nb_demandes
    FROM utilisateurs u
    LEFT JOIN demandes_acquisition d
        ON u.id_utilisateur = d.id_utilisateur
    WHERE u.statut_inscription = 'approuve'
    GROUP BY u.id_utilisateur
    ORDER BY u.role, u.nom, u.prenom
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Utilisateurs — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">

        <div class="page-header">
            <div>
                <h1>👥 Gestion des Utilisateurs</h1>
                <p class="page-subtitle">
                    Administration des comptes utilisateurs
                </p>
            </div>
            <button onclick="ouvrirModal('modalAjouter')"
                    class="btn btn-primary">
                ➕ Ajouter Utilisateur
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- INSCRIPTIONS EN ATTENTE                      -->
        <!-- ============================================ -->
        <?php if (!empty($inscriptions_attente)): ?>
        <div class="card mb-3"
             style="border-left:5px solid #f57f17">
            <div class="card-header"
                 style="background:#fff8e1">
                <h2 style="color:#e65100">
                    ⏳ Inscriptions en Attente de Validation
                </h2>
                <span class="badge bg-warning"
                      style="font-size:14px;padding:8px 14px">
                    <?= count($inscriptions_attente) ?>
                    en attente
                </span>
            </div>
            <div class="card-body p-0">
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Nom Complet</th>
                            <th>Email</th>
                            <th>Rôle Demandé</th>
                            <th>Département</th>
                            <th>Téléphone</th>
                            <th>Date Demande</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inscriptions_attente
                                       as $i => $u): ?>
                        <tr style="background:#fffde7">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <strong>
                                    <?= clean($u['prenom'] . ' ' .
                                              $u['nom']) ?>
                                </strong>
                            </td>
                            <td><?= clean($u['email']) ?></td>
                            <td>
                                <?php
                                $role_badges = [
                                    'responsable' =>
                                        ['warning', '🟠 Responsable'],
                                    'agent'       =>
                                        ['success', '🟢 Agent'],
                                    'validateur'  =>
                                        ['purple',  '🟣 Validateur'],
                                ];
                                $rb = $role_badges[$u['role']]
                                      ?? ['secondary', $u['role']];
                                ?>
                                <span class="badge bg-<?= $rb[0] ?>">
                                    <?= $rb[1] ?>
                                </span>
                            </td>
                            <td>
                                <?= clean($u['departement'] ?? '—') ?>
                            </td>
                            <td>
                                <?= clean($u['telephone'] ?? '—') ?>
                            </td>
                            <td>
                                <?= date('d/m/Y H:i',
                                    strtotime($u['date_creation'])) ?>
                            </td>
                            <td>
                                <div class="action-btns">

                                    <!-- Approuver -->
                                    <form method="POST"
                                          action="traitement.php"
                                          style="display:inline"
                                          onsubmit="return confirm(
                                            'Approuver le compte de ' +
                                            '<?= addslashes($u['prenom'] . ' ' . $u['nom']) ?>' +
                                            ' ?')">
                                        <input type="hidden"
                                               name="action"
                                               value="valider_inscription">
                                        <input type="hidden"
                                               name="id_utilisateur"
                                               value="<?= $u['id_utilisateur'] ?>">
                                        <input type="hidden"
                                               name="decision"
                                               value="approuve">
                                        <button type="submit"
                                                class="btn btn-sm btn-success">
                                            ✅ Approuver
                                        </button>
                                    </form>

                                    <!-- Rejeter -->
                                    <button onclick="ouvrirRejet(
                                        <?= $u['id_utilisateur'] ?>,
                                        '<?= addslashes($u['prenom'] . ' ' . $u['nom']) ?>'
                                    )" class="btn btn-sm btn-danger">
                                        ❌ Rejeter
                                    </button>

                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- ============================================ -->
        <!-- STATS PAR RÔLE                              -->
        <!-- ============================================ -->
        <div class="stats-grid"
             style="grid-template-columns:repeat(4,1fr)">
            <?php
            $roles = [
                'admin'       => ['🔴', 'Administrateurs', 'red'],
                'responsable' => ['🟠', 'Responsables',    'orange'],
                'validateur'  => ['🟣', 'Validateurs',     'purple'],
                'agent'       => ['🟢', 'Agents',          'green'],
            ];
            foreach ($roles as $role => list($icon, $label, $color)):
                $nb = count(array_filter(
                    $utilisateurs,
                    function($u) use ($role) {
                        return $u['role'] === $role;
                    }
                ));
            ?>
            <div class="stat-card <?= $color ?>">
                <div class="stat-icon"><?= $icon ?></div>
                <div class="stat-info">
                    <h3><?= $nb ?></h3>
                    <p><?= $label ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ============================================ -->
        <!-- TABLEAU UTILISATEURS                         -->
        <!-- ============================================ -->
        <div class="card">
            <div class="card-header">
                <h2>👥 Liste des Utilisateurs
                    <span class="badge bg-primary">
                        <?= count($utilisateurs) ?>
                    </span>
                </h2>
                <input type="text"
                       id="rechercheUser"
                       class="form-control"
                       style="width:250px"
                       placeholder="🔍 Rechercher..."
                       onkeyup="filtrerUsers()">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table" id="tableUsers">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Avatar</th>
                                <th>Nom Complet</th>
                                <th>Email</th>
                                <th>Rôle</th>
                                <th>Département</th>
                                <th>Téléphone</th>
                                <th>Demandes</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($utilisateurs)): ?>
                            <tr>
                                <td colspan="10"
                                    class="text-center py-4">
                                    😕 Aucun utilisateur
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($utilisateurs as $i => $u): ?>
                            <?php
                            $initiales = strtoupper(
                                substr($u['prenom'], 0, 1) .
                                substr($u['nom'], 0, 1)
                            );
                            $role_badges = [
                                'admin'       =>
                                    ['danger',  '🔴 Admin'],
                                'responsable' =>
                                    ['warning', '🟠 Responsable'],
                                'validateur'  =>
                                    ['purple',  '🟣 Validateur'],
                                'agent'       =>
                                    ['success', '🟢 Agent'],
                            ];
                            $rb = $role_badges[$u['role']]
                                  ?? ['secondary', $u['role']];
                            ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <div class="user-avatar-table">
                                        <?= $initiales ?>
                                    </div>
                                </td>
                                <td>
                                    <strong>
                                        <?= clean($u['prenom'] . ' ' .
                                                  $u['nom']) ?>
                                    </strong>
                                </td>
                                <td><?= clean($u['email']) ?></td>
                                <td>
                                    <span class="badge bg-<?= $rb[0] ?>">
                                        <?= $rb[1] ?>
                                    </span>
                                </td>
                                <td>
                                    <?= clean($u['departement'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= clean($u['telephone'] ?? '—') ?>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= $u['nb_demandes'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($u['statut']): ?>
                                    <span class="badge bg-success">
                                        ✅ Actif
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">
                                        ❌ Inactif
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-btns">

                                        <!-- Modifier -->
                                        <button onclick="ouvrirModifier(
                                            <?= $u['id_utilisateur'] ?>,
                                            '<?= addslashes($u['nom']) ?>',
                                            '<?= addslashes($u['prenom']) ?>',
                                            '<?= addslashes($u['email']) ?>',
                                            '<?= $u['role'] ?>',
                                            '<?= addslashes($u['departement'] ?? '') ?>',
                                            '<?= addslashes($u['telephone'] ?? '') ?>'
                                        )" class="btn btn-sm btn-warning">
                                            ✏️
                                        </button>

                                        <!-- Toggle statut -->
                                        <?php if ($u['id_utilisateur'] !=
                                                  $_SESSION['id_utilisateur']): ?>
                                        <form method="POST"
                                              action="traitement.php"
                                              style="display:inline">
                                            <input type="hidden"
                                                   name="action"
                                                   value="toggle_statut">
                                            <input type="hidden"
                                                   name="id_utilisateur"
                                                   value="<?= $u['id_utilisateur'] ?>">
                                            <button type="submit"
                                                    class="btn btn-sm <?= $u['statut'] ? 'btn-danger' : 'btn-success' ?>"
                                                    onclick="return confirm('Confirmer cette action ?')">
                                                <?= $u['statut'] ? '🔒' : '🔓' ?>
                                            </button>
                                        </form>

                                        <!-- Reset MDP -->
                                        <button onclick="ouvrirResetMdp(
                                            <?= $u['id_utilisateur'] ?>,
                                            '<?= addslashes($u['prenom'] . ' ' . $u['nom']) ?>'
                                        )" class="btn btn-sm btn-info">
                                            🔑
                                        </button>
                                        <?php endif; ?>

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

<!-- ============================================ -->
<!-- MODAL : AJOUTER                              -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalAjouter">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Ajouter un Utilisateur</h3>
            <button onclick="fermerModal('modalAjouter')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Nom *</label>
                        <input type="text" name="nom"
                               class="form-control"
                               placeholder="Ex: OUEDRAOGO"
                               style="text-transform:uppercase"
                               required>
                    </div>
                    <div class="form-group col-6">
                        <label>Prénom *</label>
                        <input type="text" name="prenom"
                               class="form-control"
                               placeholder="Ex: Jean"
                               required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email"
                           class="form-control"
                           placeholder="prenom.nom@dgmp.bf"
                           required>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Rôle *</label>
                        <select name="role"
                                class="form-control" required>
                            <option value="">-- Choisir --</option>
                            <option value="admin">🔴 Admin</option>
                            <option value="responsable">🟠 Responsable</option>
                            <option value="validateur">🟣 Validateur</option>
                            <option value="agent">🟢 Agent</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>Département</label>
                        <input type="text" name="departement"
                               class="form-control"
                               placeholder="Ex: Informatique">
                    </div>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" name="telephone"
                           class="form-control"
                           placeholder="+225 XX XX XX XX">
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Mot de Passe *</label>
                        <input type="password"
                               name="mot_de_passe"
                               class="form-control"
                               required minlength="6"
                               placeholder="Min 6 caractères">
                    </div>
                    <div class="form-group col-6">
                        <label>Confirmer MDP *</label>
                        <input type="password"
                               name="confirmer_mdp"
                               class="form-control"
                               required minlength="6"
                               placeholder="Répéter MDP">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalAjouter')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-primary">
                    💾 Créer le Compte
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL : MODIFIER                             -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalModifier">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Modifier l'Utilisateur</h3>
            <button onclick="fermerModal('modalModifier')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id_utilisateur" id="mod_id">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Nom *</label>
                        <input type="text" name="nom"
                               id="mod_nom"
                               class="form-control"
                               style="text-transform:uppercase"
                               required>
                    </div>
                    <div class="form-group col-6">
                        <label>Prénom *</label>
                        <input type="text" name="prenom"
                               id="mod_prenom"
                               class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Email *</label>
                    <input type="email" name="email"
                           id="mod_email"
                           class="form-control" required>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Rôle *</label>
                        <select name="role" id="mod_role"
                                class="form-control" required>
                            <option value="admin">🔴 Admin</option>
                            <option value="responsable">🟠 Responsable</option>
                            <option value="validateur">🟣 Validateur</option>
                            <option value="agent">🟢 Agent</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>Département</label>
                        <input type="text" name="departement"
                               id="mod_departement"
                               class="form-control">
                    </div>
                </div>
                <div class="form-group">
                    <label>Téléphone</label>
                    <input type="text" name="telephone"
                           id="mod_telephone"
                           class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalModifier')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-warning">
                    ✏️ Modifier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL : RESET MDP                            -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalResetMdp">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>🔑 Réinitialiser Mot de Passe</h3>
            <button onclick="fermerModal('modalResetMdp')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="reset_mdp">
            <input type="hidden" name="id_utilisateur" id="reset_id">
            <div class="modal-body">
                <p>Réinitialiser le MDP de :</p>
                <p><strong id="reset_nom"
                           style="color:var(--primary)">
                </strong></p>
                <div class="form-group" style="margin-top:14px">
                    <label>Nouveau Mot de Passe *</label>
                    <input type="password"
                           name="nouveau_mdp"
                           class="form-control"
                           required minlength="6"
                           placeholder="Minimum 6 caractères">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalResetMdp')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-info">
                    🔑 Réinitialiser
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL : REJET INSCRIPTION                    -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalRejet">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>❌ Rejeter l'Inscription</h3>
            <button onclick="fermerModal('modalRejet')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action"
                   value="valider_inscription">
            <input type="hidden" name="id_utilisateur"
                   id="rejet_id">
            <input type="hidden" name="decision"
                   value="rejete">
            <div class="modal-body">
                <p>Rejeter la demande de :</p>
                <p>
                    <strong id="rejet_nom"
                            style="color:var(--danger)">
                    </strong>
                </p>
                <div class="form-group" style="margin-top:14px">
                    <label>Motif du rejet *</label>
                    <textarea name="motif_rejet"
                              class="form-control"
                              rows="3"
                              placeholder="Expliquez le motif..."
                              required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalRejet')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit" class="btn btn-danger">
                    ❌ Confirmer le Rejet
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
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

function ouvrirModifier(id, nom, prenom, email,
                         role, dept, tel) {
    document.getElementById('mod_id').value          = id;
    document.getElementById('mod_nom').value         = nom;
    document.getElementById('mod_prenom').value      = prenom;
    document.getElementById('mod_email').value       = email;
    document.getElementById('mod_role').value        = role;
    document.getElementById('mod_departement').value = dept;
    document.getElementById('mod_telephone').value   = tel;
    ouvrirModal('modalModifier');
}

function ouvrirResetMdp(id, nom) {
    document.getElementById('reset_id').value          = id;
    document.getElementById('reset_nom').textContent   = nom;
    ouvrirModal('modalResetMdp');
}

function ouvrirRejet(id, nom) {
    document.getElementById('rejet_id').value         = id;
    document.getElementById('rejet_nom').textContent  = nom;
    ouvrirModal('modalRejet');
}

function filtrerUsers() {
    var val  = document.getElementById('rechercheUser')
                       .value.toLowerCase();
    var rows = document.querySelectorAll('#tableUsers tbody tr');
    rows.forEach(function(row) {
        row.style.display =
            row.textContent.toLowerCase().includes(val)
            ? '' : 'none';
    });
}
</script>
</body>
</html>