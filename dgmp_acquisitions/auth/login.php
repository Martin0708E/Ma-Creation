<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

// Rediriger si déjà connecté
if (isset($_SESSION['utilisateur'])) {
    header('Location: ../dashboard/index.php');
    exit();
}

$erreur_login        = '';
$erreur_inscription  = '';
$success_inscription = '';
$msg_login = isset($_GET['msg'])
             ? htmlspecialchars($_GET['msg']) : '';

// ============================================
// TRAITEMENT CONNEXION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'login') {

    $email        = trim($_POST['email']        ?? '');
    $mot_de_passe = $_POST['mot_de_passe']      ?? '';

    if (empty($email) || empty($mot_de_passe)) {
        $erreur_login = "Veuillez remplir tous les champs.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur_login = "Adresse email invalide.";

    } else {
        $pdo  = getConnexion();
        $stmt = $pdo->prepare("
            SELECT * FROM utilisateurs
            WHERE email = :email
        ");
        $stmt->execute([':email' => $email]);
        $utilisateur = $stmt->fetch();

        if ($utilisateur &&
            password_verify($mot_de_passe,
                            $utilisateur['mot_de_passe'])) {

            // ========================================
            // VÉRIFIER STATUT INSCRIPTION
            // ========================================

            // Compte en attente de validation
            if ($utilisateur['statut_inscription'] === 'en_attente') {

                $erreur_login =
                    "⏳ Votre compte est en attente de validation " .
                    "par l'administrateur. " .
                    "Vous serez informé dès que votre compte sera activé.";

            // Compte rejeté
            } elseif ($utilisateur['statut_inscription'] === 'rejete') {

                // Récupérer motif rejet dans notifications
                $stmt_notif = $pdo->prepare("
                    SELECT message FROM notifications
                    WHERE id_utilisateur = :id
                    AND type = 'alerte'
                    ORDER BY date_creation DESC
                    LIMIT 1
                ");
                $stmt_notif->execute([
                    ':id' => $utilisateur['id_utilisateur']
                ]);
                $notif_rejet = $stmt_notif->fetch();

                $motif = $notif_rejet
                         ? $notif_rejet['message']
                         : "Contactez l'administrateur pour plus d'informations.";

                $erreur_login = $motif;

            // Compte désactivé
            } elseif (!$utilisateur['statut']) {

                $erreur_login =
                    "🔒 Votre compte a été désactivé. " .
                    "Contactez l'administrateur.";

            // Connexion autorisée
            } else {

                $_SESSION['utilisateur']    = $utilisateur;
                $_SESSION['id_utilisateur'] = $utilisateur['id_utilisateur'];
                $_SESSION['role']           = $utilisateur['role'];
                $_SESSION['nom_complet']    = $utilisateur['prenom'] .
                                             ' ' . $utilisateur['nom'];

                // Log connexion
                // ✅ APRÈS
try {
    $pdo->prepare("
        INSERT INTO historique_actions
        (id_utilisateur, action, table_concernee)
        VALUES (:id, 'Connexion', 'utilisateurs')
    ")->execute([':id' => $utilisateur['id_utilisateur']]);
} catch (Exception $e) {
    // Ignorer l'erreur de log
    error_log("Erreur log connexion : " . $e->getMessage());
}

                header('Location: ../dashboard/index.php');
                exit();
            }

        } else {
            $erreur_login = "Email ou mot de passe incorrect.";
        }
    }
}

// ============================================
// TRAITEMENT INSCRIPTION
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'inscription') {

    $nom          = strtoupper(trim($_POST['nom']                ?? ''));
    $prenom       = trim($_POST['prenom']                        ?? '');
    $email        = trim($_POST['email_inscription']             ?? '');
    $telephone    = trim($_POST['telephone']                     ?? '');
    $departement  = trim($_POST['departement']                   ?? '');
    $role         = $_POST['role']                               ?? '';
    $mot_de_passe = $_POST['mot_de_passe_inscription']          ?? '';
    $confirmer    = $_POST['confirmer_mdp']                      ?? '';

    $roles_autorises = ['responsable', 'agent', 'validateur'];

    if (empty($nom)) {
        $erreur_inscription = "Le nom est obligatoire !";
    } elseif (empty($prenom)) {
        $erreur_inscription = "Le prénom est obligatoire !";
    } elseif (empty($email)) {
        $erreur_inscription = "L'email est obligatoire !";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur_inscription = "Email invalide !";
    } elseif (empty($role) ||
              !in_array($role, $roles_autorises)) {
        $erreur_inscription = "Veuillez choisir un rôle valide !";
    } elseif (strlen($mot_de_passe) < 6) {
        $erreur_inscription = "Mot de passe : minimum 6 caractères !";
    } elseif ($mot_de_passe !== $confirmer) {
        $erreur_inscription = "Les mots de passe ne correspondent pas !";
    } else {
        try {
            $pdo = getConnexion();

            // Vérifier si email existe
            $check = $pdo->prepare("
                SELECT COUNT(*) as nb
                FROM utilisateurs
                WHERE email = :email
            ");
            $check->execute([':email' => $email]);

            if ($check->fetch()['nb'] > 0) {
                $erreur_inscription = "Cet email est déjà utilisé !";
            } else {
                $hash = password_hash(
                    $mot_de_passe,
                    PASSWORD_DEFAULT
                );

                // Insérer avec statut en_attente
                $pdo->prepare("
                    INSERT INTO utilisateurs
                    (nom, prenom, email, mot_de_passe,
                     role, departement, telephone,
                     statut, statut_inscription)
                    VALUES
                    (:nom, :prenom, :email, :hash,
                     :role, :dept, :tel,
                     FALSE, 'en_attente')
                ")->execute([
                    ':nom'    => $nom,
                    ':prenom' => $prenom,
                    ':email'  => $email,
                    ':hash'   => $hash,
                    ':role'   => $role,
                    ':dept'   => !empty($departement)
                                 ? $departement : null,
                    ':tel'    => !empty($telephone)
                                 ? $telephone : null
                ]);

                // Notifier les admins
                $admins = $pdo->query("
                    SELECT id_utilisateur FROM utilisateurs
                    WHERE role = 'admin'
                    AND statut = TRUE
                    AND statut_inscription = 'approuve'
                ")->fetchAll();

                foreach ($admins as $a) {
                    $pdo->prepare("
                        INSERT INTO notifications
                        (id_utilisateur, message, type)
                        VALUES (:id, :msg, 'info')
                    ")->execute([
                        ':id'  => $a['id_utilisateur'],
                        ':msg' => "📝 Nouvelle inscription en attente : " .
                                  $prenom . " " . $nom .
                                  " (" . ucfirst($role) . ") — " .
                                  $email
                    ]);
                }

                $success_inscription =
                    "✅ Inscription soumise avec succès ! " .
                    "Votre compte est en attente de validation " .
                    "par l'administrateur.";
            }

        } catch (PDOException $e) {
            if ($e->getCode() == 23505) {
                $erreur_inscription = "Cet email est déjà utilisé !";
            } else {
                $erreur_inscription = "Erreur : " . $e->getMessage();
            }
        }
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
    <link rel="icon" type="image/png"
          href="../assets/images/logo_dgmp.png">
    <link rel="shortcut icon"
          href="../assets/images/logo_dgmp.png">
    <meta name="theme-color" content="#1a237e">
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        /* ===== TABS ===== */
        .auth-tabs {
            display: flex;
            margin-bottom: 24px;
            border-radius: 10px;
            overflow: hidden;
            border: 2px solid #e0e0e0;
        }
        .auth-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            background: #f5f5f5;
            color: #757575;
            border: none;
            transition: all 0.3s;
        }
        .auth-tab.active {
            background: linear-gradient(
                135deg, #1a237e, #283593
            );
            color: white;
        }
        .auth-tab:hover:not(.active) {
            background: #e8eaf6;
            color: #1a237e;
        }
        .auth-form { display: none; }
        .auth-form.active { display: block; }

        /* ===== RÔLES ===== */
        .role-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 8px;
        }
        .role-card {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
        }
        .role-card input[type="radio"] { display: none; }
        .role-card.selected {
            border-color: #1a237e;
            background: #e8eaf6;
        }
        .role-card:hover {
            border-color: #1a237e;
            background: #f3f4ff;
        }
        .role-icon {
            font-size: 24px;
            display: block;
            margin-bottom: 4px;
        }
        .role-name {
            font-size: 12px;
            font-weight: 700;
            color: #1a237e;
            display: block;
        }
        .role-desc {
            font-size: 10px;
            color: #757575;
            margin-top: 2px;
            display: block;
        }

        /* ===== ALERTES STATUT ===== */
        .alert-attente {
            background: #fff8e1;
            border-left: 4px solid #f57f17;
            padding: 14px 18px;
            border-radius: 8px;
            color: #e65100;
            font-weight: 500;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-rejete {
            background: #ffebee;
            border-left: 4px solid #c62828;
            padding: 14px 18px;
            border-radius: 8px;
            color: #c62828;
            font-weight: 500;
            margin-bottom: 16px;
            font-size: 14px;
        }
        .alert-success-inscription {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 14px 18px;
            border-radius: 8px;
            color: #2e7d32;
            font-weight: 600;
            margin-bottom: 16px;
            text-align: center;
            font-size: 14px;
        }

        /* ===== NOTE ADMIN ===== */
        .note-admin {
            background: #fff8e1;
            border-left: 4px solid #f57f17;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            color: #e65100;
            margin-top: 8px;
        }

        /* ===== FORCE MOT DE PASSE ===== */
        .force-barre {
            height: 5px;
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
            margin-top: 3px;
        }

        /* ===== LIEN STATUT ===== */
        .lien-statut {
            text-align: center;
            margin-top: 12px;
            font-size: 12px;
            color: #757575;
        }
        .lien-statut a {
            color: #f57f17;
            font-weight: 700;
            text-decoration: none;
        }
        .lien-statut a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body class="login-body">

<div class="login-wrapper">

    <!-- ============================= -->
    <!-- PANNEAU GAUCHE                -->
    <!-- ============================= -->
    <div class="login-left">
        <div class="login-left-content">
            <img src="..\assets\images\logo_dgmp.png" 
                alt="DGMP" 
                class="navbar-logo">
            <p>Direction Générale des Marchés Publics</p>
            </div>
        </div>
    </div>

    <!-- ============================= -->
    <!-- PANNEAU DROIT                 -->
    <!-- ============================= -->
    <div class="login-right">
        <div class="login-card">

            <!-- Header -->
            <div class="login-header">
                <img src="..\assets\images\logo_dgmp.png" 
                alt="DGMP" 
                class="navbar-logo">
                <h2>DGMP</h2>
                <p>Système d'Acquisitions Informatiques</p>
            </div>

            <!-- Message après changement mdp -->
            <?php if ($msg_login): ?>
            <div class="alert-success-inscription">
                ✅ <?= $msg_login ?>
            </div>
            <?php endif; ?>

            <!-- TABS -->
            <div class="auth-tabs">
                <button class="auth-tab active"
                        id="tab-connexion"
                        onclick="afficherTab('connexion')">
                    🔐 Connexion
                </button>
                <button class="auth-tab"
                        id="tab-inscription"
                        onclick="afficherTab('inscription')">
                    📝 Inscription
                </button>
            </div>

            <!-- ========================= -->
            <!-- FORMULAIRE CONNEXION      -->
            <!-- ========================= -->
            <div class="auth-form active"
                 id="form-connexion">

                <?php if ($erreur_login): ?>
                <?php
                // Choisir la classe d'alerte selon le message
                if (strpos($erreur_login, 'en attente') !== false) {
                    $classe = 'alert-attente';
                } elseif (strpos($erreur_login, 'rejetée') !== false ||
                          strpos($erreur_login, 'rejet') !== false) {
                    $classe = 'alert-rejete';
                } else {
                    $classe = 'alert alert-danger';
                }
                ?>
                <div class="<?= $classe ?>">
                    <?= htmlspecialchars($erreur_login) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="login">

                    <div class="form-group">
                        <label>📧 Adresse Email</label>
                        <input type="email"
                               name="email"
                               class="form-control"
                               placeholder="votre.email@dgmp.bf"
                               value="<?= htmlspecialchars(
                                   $_POST['email'] ?? ''
                               ) ?>"
                               required
                               autofocus>
                    </div>

                    <div class="form-group">
                        <label>🔒 Mot de Passe</label>
                        <div class="password-group">
                            <input type="password"
                                   id="mdp_login"
                                   name="mot_de_passe"
                                   class="form-control"
                                   placeholder="••••••••"
                                   required>
                            <button type="button"
                                    class="toggle-password"
                                    onclick="toggleMdp('mdp_login')">
                                👁️
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn-login">
                        🔐 Se Connecter
                    </button>

                </form>

                <!-- Lien vérifier statut -->
                <div class="lien-statut">
                    En attente de validation ?
                    <a href="statut_inscription.php">
                        🔍 Vérifier mon statut
                    </a>
                </div>

                <p style="text-align:center;margin-top:12px;
                           font-size:13px;color:#757575">
                    Pas encore de compte ?
                    <a href="#"
                       onclick="afficherTab('inscription')"
                       style="color:#1a237e;font-weight:700">
                        Créer un compte
                    </a>
                </p>

            </div>

            <!-- ========================= -->
            <!-- FORMULAIRE INSCRIPTION    -->
            <!-- ========================= -->
            <div class="auth-form"
                 id="form-inscription">

                <?php if ($erreur_inscription): ?>
                <div class="alert alert-danger">
                    ⚠️ <?= htmlspecialchars($erreur_inscription) ?>
                </div>
                <?php endif; ?>

                <?php if ($success_inscription): ?>
                <div class="alert-success-inscription">
                    <?= htmlspecialchars($success_inscription) ?>
                    <br><br>
                    <a href="#"
                       onclick="afficherTab('connexion')"
                       style="color:#2e7d32;font-weight:700">
                        → Retour à la connexion
                    </a>
                </div>
                <?php else: ?>

                <form method="POST" action=""
                      id="formInscription">
                    <input type="hidden"
                           name="action"
                           value="inscription">

                    <!-- Nom & Prénom -->
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>👤 Nom *</label>
                            <input type="text"
                                   name="nom"
                                   class="form-control"
                                   placeholder="OUEDRAOGO"
                                   style="text-transform:uppercase"
                                   value="<?= htmlspecialchars(
                                       $_POST['nom'] ?? ''
                                   ) ?>"
                                   required>
                        </div>
                        <div class="form-group col-6">
                            <label>👤 Prénom *</label>
                            <input type="text"
                                   name="prenom"
                                   class="form-control"
                                   placeholder="Jean"
                                   value="<?= htmlspecialchars(
                                       $_POST['prenom'] ?? ''
                                   ) ?>"
                                   required>
                        </div>
                    </div>

                    <!-- Email -->
                    <div class="form-group">
                        <label>📧 Email *</label>
                        <input type="email"
                               name="email_inscription"
                               class="form-control"
                               placeholder="votre.email@dgmp.bf"
                               value="<?= htmlspecialchars(
                                   $_POST['email_inscription'] ?? ''
                               ) ?>"
                               required>
                    </div>

                    <!-- Téléphone & Département -->
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>📞 Téléphone</label>
                            <input type="text"
                                   name="telephone"
                                   class="form-control"
                                   placeholder="+225 XX XX XX XX"
                                   value="<?= htmlspecialchars(
                                       $_POST['telephone'] ?? ''
                                   ) ?>">
                        </div>
                        <div class="form-group col-6">
                            <label>🏢 Département</label>
                            <input type="text"
                                   name="departement"
                                   class="form-control"
                                   placeholder="Ex: Informatique"
                                   value="<?= htmlspecialchars(
                                       $_POST['departement'] ?? ''
                                   ) ?>">
                        </div>
                    </div>

                    <!-- Rôle -->
                    <div class="form-group">
                        <label>🎭 Choisir votre Rôle *</label>
                        <div class="role-cards">

                            <label class="role-card <?= (($_POST['role'] ?? '') === 'responsable') ? 'selected' : '' ?>"
                                   onclick="selectRole(this)">
                                <input type="radio"
                                       name="role"
                                       value="responsable"
                                       <?= (($_POST['role'] ?? '') === 'responsable') ? 'checked' : '' ?>>
                                <span class="role-icon">🟠</span>
                                <span class="role-name">Responsable</span>
                            </label>

                            <label class="role-card <?= (($_POST['role'] ?? '') === 'agent') ? 'selected' : '' ?>"
                                   onclick="selectRole(this)">
                                <input type="radio"
                                       name="role"
                                       value="agent"
                                       <?= (($_POST['role'] ?? '') === 'agent') ? 'checked' : '' ?>>
                                <span class="role-icon">🟢</span>
                                <span class="role-name">Agent</span>
                            </label>

                            <label class="role-card <?= (($_POST['role'] ?? '') === 'validateur') ? 'selected' : '' ?>"
                                   onclick="selectRole(this)">
                                <input type="radio"
                                       name="role"
                                       value="validateur"
                                       <?= (($_POST['role'] ?? '') === 'validateur') ? 'checked' : '' ?>>
                                <span class="role-icon">🟣</span>
                                <span class="role-name">Validateur</span>
                            </label>

                        </div>
                        
                    </div>

                    <!-- Mot de passe -->
                    <div class="form-group">
                        <label>🔑 Mot de Passe *</label>
                        <div class="password-group">
                            <input type="password"
                                   id="mdp_inscription"
                                   name="mot_de_passe_inscription"
                                   class="form-control"
                                   placeholder="Minimum 6 caractères"
                                   onkeyup="verifierForce(this.value)"
                                   required minlength="6">
                            <button type="button"
                                    class="toggle-password"
                                    onclick="toggleMdp('mdp_inscription')">
                                👁️
                            </button>
                        </div>
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
                                   id="mdp_confirmer"
                                   name="confirmer_mdp"
                                   class="form-control"
                                   placeholder="Répétez le mot de passe"
                                   onkeyup="verifierCorrespondance()"
                                   required minlength="6">
                            <button type="button"
                                    class="toggle-password"
                                    onclick="toggleMdp('mdp_confirmer')">
                                👁️
                            </button>
                        </div>
                        <div id="msgCorrespondance"
                             style="font-size:11px;margin-top:4px">
                        </div>
                    </div>

                    <button type="submit"
                            class="btn-login"
                            style="background:linear-gradient(
                                135deg,#2e7d32,#388e3c)">
                        📝 Soumettre ma Demande d'Inscription
                    </button>

                </form>

                <?php endif; ?>

                <p style="text-align:center;margin-top:14px;
                           font-size:13px;color:#757575">
                    Déjà un compte ?
                    <a href="#"
                       onclick="afficherTab('connexion')"
                       style="color:#1a237e;font-weight:700">
                        Se connecter
                    </a>
                </p>

            </div>

            <!-- Footer -->
            <div class="login-footer">
                <p>🔒 Accès réservé au personnel autorisé</p>
                <p>© <?= date('Y') ?> DGMP — Tous droits réservés</p>
            </div>

        </div>
    </div>

</div>

<script>
// ============================================
// AFFICHER TAB
// ============================================
function afficherTab(tab) {
    document.querySelectorAll('.auth-form').forEach(function(f) {
        f.classList.remove('active');
    });
    document.querySelectorAll('.auth-tab').forEach(function(t) {
        t.classList.remove('active');
    });
    document.getElementById('form-' + tab).classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
}

// ============================================
// TOGGLE MOT DE PASSE
// ============================================
function toggleMdp(id) {
    var input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

// ============================================
// SÉLECTIONNER RÔLE
// ============================================
function selectRole(label) {
    document.querySelectorAll('.role-card').forEach(function(c) {
        c.classList.remove('selected');
    });
    label.classList.add('selected');
    label.querySelector('input').checked = true;
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
        { pct: '20%',  color: '#c62828', txt: '🔴 Très faible' },
        { pct: '40%',  color: '#e65100', txt: '🟠 Faible'      },
        { pct: '60%',  color: '#f57f17', txt: '🟡 Moyen'       },
        { pct: '80%',  color: '#2e7d32', txt: '🟢 Fort'        },
        { pct: '100%', color: '#1b5e20', txt: '💪 Très fort'   },
    ];

    var n = niveaux[Math.min(score - 1, 4)] || niveaux[0];
    niveau.style.width      = n.pct;
    niveau.style.background = n.color;
    texte.textContent       = n.txt;
    texte.style.color       = n.color;
}

// ============================================
// VÉRIFIER CORRESPONDANCE MDP
// ============================================
function verifierCorrespondance() {
    var mdp1 = document.getElementById('mdp_inscription').value;
    var mdp2 = document.getElementById('mdp_confirmer').value;
    var msg  = document.getElementById('msgCorrespondance');

    if (!mdp2 || mdp2.length === 0) {
        msg.textContent = '';
        return;
    }

    if (mdp1 === mdp2) {
        msg.textContent = '✅ Les mots de passe correspondent';
        msg.style.color = '#2e7d32';
    } else {
        msg.textContent = '❌ Les mots de passe ne correspondent pas';
        msg.style.color = '#c62828';
    }
}

// ============================================
// VALIDATION FORMULAIRE INSCRIPTION
// ============================================
var formInscription = document.getElementById('formInscription');
if (formInscription) {
    formInscription.addEventListener('submit', function(e) {

        var role = document.querySelector(
            'input[name="role"]:checked'
        );
        var mdp1 = document.getElementById('mdp_inscription').value;
        var mdp2 = document.getElementById('mdp_confirmer').value;

        if (!role) {
            e.preventDefault();
            alert('⚠️ Veuillez choisir un rôle !');
            return;
        }

        if (mdp1 !== mdp2) {
            e.preventDefault();
            alert('❌ Les mots de passe ne correspondent pas !');
            return;
        }

        if (mdp1.length < 6) {
            e.preventDefault();
            alert('⚠️ Le mot de passe doit avoir au moins 6 caractères !');
            return;
        }
    });
}

// ============================================
// AUTO : Afficher le bon tab si erreur
// ============================================
<?php if ($erreur_inscription || $success_inscription): ?>
afficherTab('inscription');
<?php endif; ?>

<?php if ($erreur_login): ?>
afficherTab('connexion');
<?php endif; ?>
</script>

</body>
</html>