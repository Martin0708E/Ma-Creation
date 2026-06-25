<?php
// ============================================
// ENVOI D'EMAIL - DGMP
// ============================================

function envoyerEmail($destinataire, $sujet, $message_html) {

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: DGMP Acquisitions <noreply@dgmp.bf>\r\n";
    $headers .= "Reply-To: admin@dgmp.bf\r\n";

    $resultat = mail($destinataire, $sujet, $message_html, $headers);
    return $resultat;
}

// ============================================
// EMAIL : APPROBATION INSCRIPTION
// ============================================
function envoyerEmailApprobation($email, $nom, $prenom, $role) {

    $sujet = "✅ Votre compte DGMP a été approuvé !";

    $message = "
    <!DOCTYPE html>
    <html>
    <head>
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DGMP — Page</title>

    <!-- FAVICON -->
    <?php include '../includes/head.php'; ?>

    <link rel="stylesheet" href="../assets/css/style.css">
</head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif;
                   background: #f0f2f5; margin:0; padding:20px; }
            .container { max-width:600px; margin:0 auto;
                         background:white; border-radius:12px;
                         overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1); }
            .header { background:linear-gradient(135deg,#1a237e,#283593);
                      padding:30px; text-align:center; color:white; }
            .header h1 { margin:0; font-size:24px; }
            .header p { margin:8px 0 0; opacity:0.8; }
            .body { padding:30px; }
            .success-box { background:#e8f5e9; border-left:4px solid #2e7d32;
                           padding:16px; border-radius:8px; margin:20px 0; }
            .info { background:#e8eaf6; border-radius:8px;
                    padding:16px; margin:16px 0; }
            .info p { margin:6px 0; font-size:14px; }
            .btn { display:inline-block; padding:14px 28px;
                   background:#1a237e; color:white;
                   text-decoration:none; border-radius:8px;
                   font-weight:bold; font-size:15px; margin-top:16px; }
            .footer { background:#f5f5f5; padding:16px;
                      text-align:center; color:#757575; font-size:12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏛️ DGMP</h1>
                <p>Direction Générale des Marchés Publics</p>
            </div>
            <div class='body'>
                <h2>Bonjour {$prenom} {$nom},</h2>

                <div class='success-box'>
                    ✅ <strong>Votre compte a été approuvé !</strong><br>
                    Vous pouvez maintenant accéder au système
                    d'acquisitions informatiques DGMP.
                </div>

                <div class='info'>
                    <p>📧 <strong>Email :</strong> {$email}</p>
                    <p>🎭 <strong>Rôle :</strong> " . ucfirst($role) . "</p>
                    <p>📅 <strong>Date :</strong> " . date('d/m/Y H:i') . "</p>
                </div>

                <p>Cliquez sur le bouton ci-dessous pour vous connecter :</p>

                <a href='http://localhost/dgmp_acquisitions/auth/login.php'
                   class='btn'>
                    🔐 Se Connecter
                </a>

                <p style='margin-top:20px;color:#757575;font-size:13px'>
                    Si vous n'êtes pas à l'origine de cette inscription,
                    veuillez contacter l'administrateur.
                </p>
            </div>
            <div class='footer'>
                © " . date('Y') . " DGMP — Système d'Acquisitions Informatiques
            </div>
        </div>
    </body>
    </html>";

    return envoyerEmail($email, $sujet, $message);
}

// ============================================
// EMAIL : REJET INSCRIPTION
// ============================================
function envoyerEmailRejet($email, $nom, $prenom, $motif) {

    $sujet = "❌ Votre demande d'inscription DGMP";

    $message = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif;
                   background: #f0f2f5; margin:0; padding:20px; }
            .container { max-width:600px; margin:0 auto;
                         background:white; border-radius:12px;
                         overflow:hidden; }
            .header { background:linear-gradient(135deg,#1a237e,#283593);
                      padding:30px; text-align:center; color:white; }
            .header h1 { margin:0; font-size:24px; }
            .body { padding:30px; }
            .error-box { background:#ffebee; border-left:4px solid #c62828;
                         padding:16px; border-radius:8px; margin:20px 0;
                         color:#c62828; }
            .motif-box { background:#f5f5f5; border-radius:8px;
                         padding:16px; margin:16px 0; }
            .footer { background:#f5f5f5; padding:16px;
                      text-align:center; color:#757575; font-size:12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🏛️ DGMP</h1>
                <p>Direction Générale des Marchés Publics</p>
            </div>
            <div class='body'>
                <h2>Bonjour {$prenom} {$nom},</h2>

                <div class='error-box'>
                    ❌ <strong>Votre demande d'inscription a été rejetée.</strong>
                </div>

                <div class='motif-box'>
                    <strong>Motif du rejet :</strong><br>
                    " . htmlspecialchars($motif) . "
                </div>

                <p>
                    Pour plus d'informations, veuillez contacter
                    l'administrateur système.
                </p>

                <p>
                    📧 <strong>Contact :</strong> admin@dgmp.bf
                </p>
            </div>
            <div class='footer'>
                © " . date('Y') . " DGMP — Système d'Acquisitions Informatiques
            </div>
        </div>
    </body>
    </html>";

    return envoyerEmail($email, $sujet, $message);
}
?>