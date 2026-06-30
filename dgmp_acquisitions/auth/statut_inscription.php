<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

$statut  = '';
$message = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!empty($email) &&
        filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $pdo = getConnexion();
        $stmt = $pdo->prepare("
            SELECT nom, prenom, role,
                   statut, statut_inscription,
                   date_creation
            FROM utilisateurs
            WHERE email = :email
        ");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $statut = $user['statut_inscription'];

            if ($statut === 'en_attente') {
                $message = "⏳ Votre demande est en cours d'examen par l'administrateur.";
            } elseif ($statut === 'approuve') {
                $message = "✅ Votre compte a été approuvé ! Vous pouvez vous connecter.";
            } elseif ($statut === 'rejete') {
                $message = "❌ Votre demande a été rejetée. Contactez l'administrateur.";
            }
        } else {
            $message = "⚠️ Aucun compte trouvé avec cet email.";
            $statut  = 'inconnu';
        }
    } else {
        $message = "⚠️ Veuillez entrer un email valide.";
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
    <style>
        .statut-attente {
            background: #fff8e1;
            border-left: 4px solid #f57f17;
            padding: 20px;
            border-radius: 8px;
            color: #e65100;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }
        .statut-approuve {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 20px;
            border-radius: 8px;
            color: #2e7d32;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }
        .statut-rejete {
            background: #ffebee;
            border-left: 4px solid #c62828;
            padding: 20px;
            border-radius: 8px;
            color: #c62828;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }
        .statut-inconnu {
            background: #f5f5f5;
            border-left: 4px solid #757575;
            padding: 20px;
            border-radius: 8px;
            color: #757575;
            font-size: 16px;
            text-align: center;
        }
        .icone-statut {
            font-size: 48px;
            display: block;
            margin-bottom: 12px;
        }
    </style>
</head>
<body class="login-body">

<div class="login-wrapper">

    <!-- Panneau gauche -->
    <div class="login-left">
        <div class="login-left-content">
            <div class="login-left-logo">🏛️</div>
            <h1>DGMP</h1>
            <p>Direction Générale des Marchés Publics</p>
        </div>
    </div>

    <!-- Panneau droit -->
    <div class="login-right">
        <div class="login-card">

            <div class="login-header">
                <div class="login-icon">🔍</div>
                <h2>Vérifier mon Inscription</h2>
                <p>Entrez votre email pour voir le statut</p>
            </div>

            <!-- Résultat -->
            <?php if ($message): ?>
            <div class="statut-<?= $statut ?>">
                <?php if ($statut === 'en_attente'): ?>
                    <span class="icone-statut">⏳</span>
                <?php elseif ($statut === 'approuve'): ?>
                    <span class="icone-statut">✅</span>
                <?php elseif ($statut === 'rejete'): ?>
                    <span class="icone-statut">❌</span>
                <?php else: ?>
                    <span class="icone-statut">❓</span>
                <?php endif; ?>
                <?= htmlspecialchars($message) ?>

                <?php if ($statut === 'approuve'): ?>
                <br><br>
                <a href="login.php"
                   style="display:inline-block;padding:10px 20px;
                          background:#1a237e;color:white;
                          border-radius:8px;text-decoration:none;
                          font-weight:bold;margin-top:8px">
                    🔐 Se connecter
                </a>
                <?php endif; ?>
            </div>
            <br>
            <?php endif; ?>

            <!-- Formulaire -->
            <form method="POST" action="">
                <div class="form-group">
                    <label>📧 Votre Email</label>
                    <input type="email"
                           name="email"
                           class="form-control"
                           placeholder="votre.email@dgmp.bf"
                           value="<?= htmlspecialchars($email) ?>"
                           required autofocus>
                </div>

                <button type="submit" class="btn-login">
                    🔍 Vérifier mon Statut
                </button>
            </form>

            <div style="text-align:center;margin-top:16px">
                <a href="login.php"
                   style="color:#1a237e;font-size:13px;font-weight:700">
                    ← Retour à la connexion
                </a>
            </div>

            <div class="login-footer">
                <p>© <?= date('Y') ?> DGMP — Tous droits réservés</p>
            </div>

        </div>
    </div>
</div>

</body>
</html>