<?php
// ============================================
// FONCTIONS GLOBALES - DGMP
// ============================================

// Démarrer session si pas encore démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

// ============================================
// VÉRIFICATION CONNEXION
// ============================================
function verifierConnexion() {
    // Démarrer session si nécessaire
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['utilisateur']) || empty($_SESSION['utilisateur'])) {
        header('Location: ../auth/login.php');
        exit();
    }
}

// ============================================
// VÉRIFICATION RÔLE
// ============================================
function verifierRole($roles_autorises) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['role']) ||
        !in_array($_SESSION['role'], $roles_autorises)) {
        header('Location: ../dashboard/index.php');
        exit();
    }
}

// ============================================
// GÉNÉRATION RÉFÉRENCE
// ============================================
function genererReference($prefix) {
    return $prefix . '-' . date('Y') . '-' .
           str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

// ============================================
// ENVOI NOTIFICATION
// ============================================
function envoyerNotification($pdo, $id_utilisateur, $message, $type = 'info') {
    $stmt = $pdo->prepare("
        INSERT INTO notifications (id_utilisateur, message, type)
        VALUES (:id, :message, :type)
    ");
    $stmt->execute([
        ':id'      => $id_utilisateur,
        ':message' => $message,
        ':type'    => $type
    ]);
}

// ============================================
// BADGE STATUT DEMANDE
// ============================================
function getBadgeStatut($statut) {
    $badges = [
        'en_attente'          => ['warning',   '⏳ En Attente'],
        'en_cours_validation' => ['info',      '🔄 En Validation'],
        'validee'             => ['success',   '✅ Validée'],
        'rejetee'             => ['danger',    '❌ Rejetée'],
        'en_cours_traitement' => ['primary',   '⚙️ En Traitement'],
        'commandee'           => ['info',      '🛒 Commandée'],
        'livree'              => ['success',   '📦 Livrée'],
        'cloturee'            => ['secondary', '🔒 Clôturée'],
    ];
    $b = $badges[$statut] ?? ['secondary', ucfirst($statut)];
    return "<span class='badge bg-{$b[0]}'>{$b[1]}</span>";
}

// ============================================
// BADGE PRIORITÉ
// ============================================
function getBadgePriorite($priorite) {
    $badges = [
        'urgente' => ['danger',    '🔴 Urgente'],
        'haute'   => ['warning',   '🟠 Haute'],
        'normale' => ['success',   '🟢 Normale'],
        'basse'   => ['secondary', '⚪ Basse'],
    ];
    $b = $badges[$priorite] ?? ['secondary', ucfirst($priorite)];
    return "<span class='badge bg-{$b[0]}'>{$b[1]}</span>";
}

// ============================================
// FORMATER MONTANT
// ============================================
function formaterMontant($montant) {
    if (empty($montant) || $montant == 0) return '—';
    return number_format($montant, 0, ',', ' ') . ' FCFA';
}

// ============================================
// LOG ACTION
// ============================================
function logAction($pdo, $id_utilisateur, $action,
                   $table = null, $id_enreg = null) {
    $stmt = $pdo->prepare("
        INSERT INTO historique_actions
        (id_utilisateur, action, table_concernee, id_enregistrement)
        VALUES (:id, :action, :table, :id_enreg)
    ");
    $stmt->execute([
        ':id'       => $id_utilisateur,
        ':action'   => $action,
        ':table'    => $table,
        ':id_enreg' => $id_enreg
    ]);
}

// ============================================
// COMPTER NOTIFICATIONS
// ============================================
function compterNotifications($pdo, $id_utilisateur) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as nb FROM notifications
        WHERE id_utilisateur = :id AND lu = FALSE
    ");
    $stmt->execute([':id' => $id_utilisateur]);
    return $stmt->fetch()['nb'];
}

// ============================================
// NETTOYER DONNÉES
// ============================================
function clean($data) {
    if (is_null($data)) return '';
    return htmlspecialchars(strip_tags(trim($data)));
}
?>