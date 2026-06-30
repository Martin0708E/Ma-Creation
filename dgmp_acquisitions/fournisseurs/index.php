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

// ============================================
// TRAITEMENT FORMULAIRE
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // ---- AJOUTER ----
    if ($action === 'ajouter') {

        $nom_entreprise = strtoupper(trim($_POST['nom_entreprise'] ?? ''));
        $nom            = strtoupper(trim($_POST['nom']            ?? ''));
        $prenom         = trim($_POST['prenom']                    ?? '');
        $email          = trim($_POST['email']                     ?? '');
        $tel            = trim($_POST['telephone']                 ?? '');
        $adresse        = trim($_POST['adresse']                   ?? '');
        $ville          = trim($_POST['ville']                     ?? '');
        $pays           = trim($_POST['pays']                      ?? '');
        $registre       = trim($_POST['numero_registre']           ?? '');

        if (empty($nom_entreprise)) {
            $erreur = "Le nom de l'entreprise est obligatoire !";
        } elseif (empty($email)) {
            $erreur = "L'email est obligatoire !";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Email invalide !";
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO fournisseurs
                    (nom_entreprise, nom, prenom,
                     email, telephone, adresse,
                     ville, pays, numero_registre)
                    VALUES
                    (:nom_e, :nom, :prenom,
                     :email, :tel, :adresse,
                     :ville, :pays, :registre)
                ")->execute([
                    ':nom_e'   => $nom_entreprise,
                    ':nom'     => !empty($nom)     ? $nom     : null,
                    ':prenom'  => !empty($prenom)  ? $prenom  : null,
                    ':email'   => $email,
                    ':tel'     => !empty($tel)     ? $tel     : null,
                    ':adresse' => !empty($adresse) ? $adresse : null,
                    ':ville'   => !empty($ville)   ? $ville   : null,
                    ':pays'    => !empty($pays)    ? $pays    : null,
                    ':registre'=> !empty($registre)? $registre: null
                ]);

                logAction($pdo, $_SESSION['id_utilisateur'],
                    "Ajout fournisseur : " . $nom_entreprise,
                    'fournisseurs');

                header('Location: index.php?success=' . urlencode(
                    "Fournisseur " . $nom_entreprise . " ajoute !"
                ));
                exit();

            } catch (PDOException $e) {
                if ($e->getCode() == 23505) {
                    $erreur = "Cet email est deja utilise !";
                } else {
                    $erreur = "Erreur : " . $e->getMessage();
                }
            }
        }
    }

    // ---- MODIFIER ----
    if ($action === 'modifier') {

        $id             = (int)($_POST['id_fournisseur'] ?? 0);
        $nom_entreprise = strtoupper(trim($_POST['nom_entreprise'] ?? ''));
        $nom            = strtoupper(trim($_POST['nom']            ?? ''));
        $prenom         = trim($_POST['prenom']                    ?? '');
        $email          = trim($_POST['email']                     ?? '');
        $tel            = trim($_POST['telephone']                 ?? '');
        $adresse        = trim($_POST['adresse']                   ?? '');
        $ville          = trim($_POST['ville']                     ?? '');
        $pays           = trim($_POST['pays']                      ?? '');
        $registre       = trim($_POST['numero_registre']           ?? '');

        if (!$id || empty($nom_entreprise) || empty($email)) {
            $erreur = "Donnees invalides !";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreur = "Email invalide !";
        } else {
            try {
                $pdo->prepare("
                    UPDATE fournisseurs SET
                        nom_entreprise  = :nom_e,
                        nom             = :nom,
                        prenom          = :prenom,
                        email           = :email,
                        telephone       = :tel,
                        adresse         = :adresse,
                        ville           = :ville,
                        pays            = :pays,
                        numero_registre = :registre
                    WHERE id_fournisseur = :id
                ")->execute([
                    ':nom_e'   => $nom_entreprise,
                    ':nom'     => !empty($nom)     ? $nom     : null,
                    ':prenom'  => !empty($prenom)  ? $prenom  : null,
                    ':email'   => $email,
                    ':tel'     => !empty($tel)     ? $tel     : null,
                    ':adresse' => !empty($adresse) ? $adresse : null,
                    ':ville'   => !empty($ville)   ? $ville   : null,
                    ':pays'    => !empty($pays)    ? $pays    : null,
                    ':registre'=> !empty($registre)? $registre: null,
                    ':id'      => $id
                ]);

                logAction($pdo, $_SESSION['id_utilisateur'],
                    "Modification fournisseur ID:" . $id,
                    'fournisseurs', $id);

                header('Location: index.php?success=' . urlencode(
                    "Fournisseur modifie !"
                ));
                exit();

            } catch (PDOException $e) {
                if ($e->getCode() == 23505) {
                    $erreur = "Cet email est deja utilise !";
                } else {
                    $erreur = "Erreur : " . $e->getMessage();
                }
            }
        }
    }

    // ---- TOGGLE STATUT ----
    if ($action === 'toggle_statut') {

        $id = (int)($_POST['id_fournisseur'] ?? 0);

        if ($id) {
            try {
                $current = $pdo->prepare("
                    SELECT statut, nom_entreprise
                    FROM fournisseurs
                    WHERE id_fournisseur = :id
                ");
                $current->execute([':id' => $id]);
                $fourn = $current->fetch();

                if ($fourn) {
                    $nouveau = ($fourn['statut'] === 'actif')
                               ? 'inactif' : 'actif';

                    $pdo->prepare("
                        UPDATE fournisseurs
                        SET statut = :statut
                        WHERE id_fournisseur = :id
                    ")->execute([
                        ':statut' => $nouveau,
                        ':id'     => $id
                    ]);

                    $msg = ($nouveau === 'actif')
                        ? "Fournisseur active !"
                        : "Fournisseur desactive !";

                    header('Location: index.php?success=' .
                        urlencode($msg));
                    exit();
                }
            } catch (PDOException $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }

    // ---- SUPPRIMER ----
    if ($action === 'supprimer' && $role === 'admin') {

        $id = (int)($_POST['id_fournisseur'] ?? 0);

        if ($id) {
            try {
                $check = $pdo->prepare("
                    SELECT COUNT(*) as nb FROM commandes
                    WHERE id_fournisseur = :id
                ");
                $check->execute([':id' => $id]);

                if ($check->fetch()['nb'] > 0) {
                    $erreur = "Ce fournisseur a des commandes !";
                } else {
                    $pdo->prepare("
                        DELETE FROM fournisseurs
                        WHERE id_fournisseur = :id
                    ")->execute([':id' => $id]);

                    header('Location: index.php?success=' .
                        urlencode("Fournisseur supprime !"));
                    exit();
                }
            } catch (PDOException $e) {
                $erreur = "Erreur : " . $e->getMessage();
            }
        }
    }
}

// ============================================
// RÉCUPÉRER FOURNISSEURS
// ============================================
$fournisseurs = $pdo->query("
    SELECT f.*,
           COUNT(DISTINCT c.id_commande) as nb_commandes
    FROM fournisseurs f
    LEFT JOIN commandes c
        ON f.id_fournisseur = c.id_fournisseur
    GROUP BY f.id_fournisseur
    ORDER BY f.nom_entreprise ASC
")->fetchAll();

$total_actifs   = count(array_filter(
    $fournisseurs,
    function($f) { return $f['statut'] === 'actif'; }
));
$total_inactifs = count($fournisseurs) - $total_actifs;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
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

        <!-- En-tête -->
        <div class="page-header">
            <div>
                <h1>🏢 Gestion des Fournisseurs</h1>
                <p class="page-subtitle">
                    Liste de tous les fournisseurs
                </p>
            </div>
            <?php if (in_array($role, ['admin', 'responsable'])): ?>
            <button onclick="ouvrirModal('modalAjouter')"
                    class="btn btn-primary">
                ➕ Ajouter Fournisseur
            </button>
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

        <!-- Stats -->
        <div class="stats-grid"
             style="grid-template-columns:repeat(3,1fr)">
            <div class="stat-card blue">
                <div class="stat-icon">🏢</div>
                <div class="stat-info">
                    <h3><?= count($fournisseurs) ?></h3>
                    <p>Total Fournisseurs</p>
                </div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon">✅</div>
                <div class="stat-info">
                    <h3><?= $total_actifs ?></h3>
                    <p>Actifs</p>
                </div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon">🔒</div>
                <div class="stat-info">
                    <h3><?= $total_inactifs ?></h3>
                    <p>Inactifs</p>
                </div>
            </div>
        </div>

        <!-- Tableau -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Liste des Fournisseurs
                    <span class="badge bg-primary">
                        <?= count($fournisseurs) ?>
                    </span>
                </h2>
                <input type="text"
                       id="rechercheFourn"
                       class="form-control"
                       style="width:250px"
                       placeholder="🔍 Rechercher..."
                       onkeyup="filtrerTableau()">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table" id="tableFourn">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Entreprise</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Email</th>
                                <th>Téléphone</th>
                                <th>Ville</th>
                                <th>Commandes</th>
                                <th>Statut</th>
                                <?php if (in_array($role,
                                    ['admin', 'responsable'])): ?>
                                <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($fournisseurs)): ?>
                            <tr>
                                <td colspan="10"
                                    class="text-center py-4">
                                    😕 Aucun fournisseur
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($fournisseurs as $i => $f): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong>
                                        <?= clean($f['nom_entreprise']) ?>
                                    </strong>
                                </td>
                                <td>
                                    <?= clean($f['nom'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= clean($f['prenom'] ?? '—') ?>
                                </td>
                                <td>
                                    <a href="mailto:<?= clean($f['email']) ?>">
                                        <?= clean($f['email']) ?>
                                    </a>
                                </td>
                                <td>
                                    <?= clean($f['telephone'] ?? '—') ?>
                                </td>
                                <td>
                                    <?= clean($f['ville'] ?? '—') ?>
                                </td>
                                <td>
                                    <span class="badge bg-info">
                                        <?= $f['nb_commandes'] ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($f['statut'] === 'actif'): ?>
                                    <span class="badge bg-success">
                                        ✅ Actif
                                    </span>
                                    <?php else: ?>
                                    <span class="badge bg-danger">
                                        🔒 Inactif
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <?php if (in_array($role,
                                    ['admin', 'responsable'])): ?>
                                <td>
                                    <div class="action-btns">

                                        <!-- Modifier -->
                                        <button onclick="ouvrirModifier(
                                            <?= $f['id_fournisseur'] ?>,
                                            '<?= addslashes($f['nom_entreprise']) ?>',
                                            '<?= addslashes($f['nom'] ?? '') ?>',
                                            '<?= addslashes($f['prenom'] ?? '') ?>',
                                            '<?= addslashes($f['email']) ?>',
                                            '<?= addslashes($f['telephone'] ?? '') ?>',
                                            '<?= addslashes($f['adresse'] ?? '') ?>',
                                            '<?= addslashes($f['ville'] ?? '') ?>',
                                            '<?= addslashes($f['pays'] ?? '') ?>',
                                            '<?= addslashes($f['numero_registre'] ?? '') ?>'
                                        )" class="btn btn-sm btn-warning"
                                           title="Modifier">
                                            ✏️
                                        </button>

                                        <!-- Toggle statut -->
                                        <form method="POST"
                                              action=""
                                              style="display:inline">
                                            <input type="hidden"
                                                   name="action"
                                                   value="toggle_statut">
                                            <input type="hidden"
                                                   name="id_fournisseur"
                                                   value="<?= $f['id_fournisseur'] ?>">
                                            <button type="submit"
                                                    onclick="return confirm('Confirmer ?')"
                                                    class="btn btn-sm <?= $f['statut'] === 'actif'
                                                        ? 'btn-danger'
                                                        : 'btn-success' ?>">
                                                <?= $f['statut'] === 'actif'
                                                    ? '🔒' : '🔓' ?>
                                            </button>
                                        </form>

                                        <!-- Supprimer admin -->
                                        <?php if ($role === 'admin'): ?>
                                        <form method="POST"
                                              action=""
                                              style="display:inline">
                                            <input type="hidden"
                                                   name="action"
                                                   value="supprimer">
                                            <input type="hidden"
                                                   name="id_fournisseur"
                                                   value="<?= $f['id_fournisseur'] ?>">
                                            <button type="submit"
                                                    onclick="return confirm('Supprimer ?')"
                                                    class="btn btn-sm btn-danger">
                                                🗑️
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                    </div>
                                </td>
                                <?php endif; ?>
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
<!-- MODAL AJOUTER                                -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalAjouter">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Ajouter un Fournisseur</h3>
            <button onclick="fermerModal('modalAjouter')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-body">

                <div class="form-group">
                    <label>🏢 Nom Entreprise *</label>
                    <input type="text"
                           name="nom_entreprise"
                           class="form-control"
                           placeholder="Ex: TECH SOLUTIONS CI"
                           style="text-transform:uppercase"
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>👤 Nom</label>
                        <input type="text"
                               name="nom"
                               class="form-control"
                               placeholder="Ex: OUEDRAOGO"
                               style="text-transform:uppercase">
                    </div>
                    <div class="form-group col-6">
                        <label>👤 Prénom</label>
                        <input type="text"
                               name="prenom"
                               class="form-control"
                               placeholder="Ex: Jean">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>📧 Email *</label>
                        <input type="email"
                               name="email"
                               class="form-control"
                               placeholder="contact@entreprise.ci"
                               required>
                    </div>
                    <div class="form-group col-6">
                        <label>📞 Téléphone</label>
                        <input type="text"
                               name="telephone"
                               class="form-control"
                               placeholder="+225 XX XX XX XX">
                    </div>
                </div>

                <div class="form-group">
                    <label>📍 Adresse</label>
                    <textarea name="adresse"
                              class="form-control"
                              rows="2"
                              placeholder="Adresse...">
                    </textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-4">
                        <label>🏙️ Ville</label>
                        <input type="text"
                               name="ville"
                               class="form-control"
                               value="Abidjan">
                    </div>
                    <div class="form-group col-4">
                        <label>🌍 Pays</label>
                        <input type="text"
                               name="pays"
                               class="form-control"
                               value="Côte d'Ivoire">
                    </div>
                    <div class="form-group col-4">
                        <label>📄 N° Registre</label>
                        <input type="text"
                               name="numero_registre"
                               class="form-control"
                               placeholder="CI-2024-001">
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalAjouter')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit"
                        class="btn btn-primary">
                    💾 Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ============================================ -->
<!-- MODAL MODIFIER                               -->
<!-- ============================================ -->
<div class="modal-overlay" id="modalModifier">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Modifier le Fournisseur</h3>
            <button onclick="fermerModal('modalModifier')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id_fournisseur" id="mod_id">
            <div class="modal-body">

                <div class="form-group">
                    <label>🏢 Nom Entreprise *</label>
                    <input type="text"
                           name="nom_entreprise"
                           id="mod_nom_entreprise"
                           class="form-control"
                           style="text-transform:uppercase"
                           required>
                </div>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>👤 Nom</label>
                        <input type="text"
                               name="nom"
                               id="mod_nom"
                               class="form-control"
                               style="text-transform:uppercase">
                    </div>
                    <div class="form-group col-6">
                        <label>👤 Prénom</label>
                        <input type="text"
                               name="prenom"
                               id="mod_prenom"
                               class="form-control">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-6">
                        <label>📧 Email *</label>
                        <input type="email"
                               name="email"
                               id="mod_email"
                               class="form-control"
                               required>
                    </div>
                    <div class="form-group col-6">
                        <label>📞 Téléphone</label>
                        <input type="text"
                               name="telephone"
                               id="mod_tel"
                               class="form-control">
                    </div>
                </div>

                <div class="form-group">
                    <label>📍 Adresse</label>
                    <textarea name="adresse"
                              id="mod_adresse"
                              class="form-control"
                              rows="2">
                    </textarea>
                </div>

                <div class="form-row">
                    <div class="form-group col-4">
                        <label>🏙️ Ville</label>
                        <input type="text"
                               name="ville"
                               id="mod_ville"
                               class="form-control">
                    </div>
                    <div class="form-group col-4">
                        <label>🌍 Pays</label>
                        <input type="text"
                               name="pays"
                               id="mod_pays"
                               class="form-control">
                    </div>
                    <div class="form-group col-4">
                        <label>📄 N° Registre</label>
                        <input type="text"
                               name="numero_registre"
                               id="mod_registre"
                               class="form-control">
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalModifier')"
                        class="btn btn-secondary">
                    Annuler
                </button>
                <button type="submit"
                        class="btn btn-warning">
                    ✏️ Modifier
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

function ouvrirModifier(id, nom_e, nom, prenom,
                         email, tel, adresse,
                         ville, pays, registre) {
    document.getElementById('mod_id').value             = id;
    document.getElementById('mod_nom_entreprise').value = nom_e;
    document.getElementById('mod_nom').value            = nom;
    document.getElementById('mod_prenom').value         = prenom;
    document.getElementById('mod_email').value          = email;
    document.getElementById('mod_tel').value            = tel;
    document.getElementById('mod_adresse').value        = adresse;
    document.getElementById('mod_ville').value          = ville;
    document.getElementById('mod_pays').value           = pays;
    document.getElementById('mod_registre').value       = registre;
    ouvrirModal('modalModifier');
}

function filtrerTableau() {
    var val = document.getElementById('rechercheFourn')
                      .value.toLowerCase();
    document.querySelectorAll('#tableFourn tbody tr')
            .forEach(function(r) {
        r.style.display =
            r.textContent.toLowerCase().includes(val)
            ? '' : 'none';
    });
}
</script>
</body>
</html>