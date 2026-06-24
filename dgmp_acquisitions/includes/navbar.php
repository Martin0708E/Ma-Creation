<?php
$user    = $_SESSION['utilisateur'];
$initiales = strtoupper(
    substr($user['prenom'], 0, 1) . 
    substr($user['nom'], 0, 1)
);
$pdo_nav = getConnexion();
$nb_notif = compterNotifications($pdo_nav, $_SESSION['id_utilisateur']);
?>

<nav class="navbar">
    <div class="navbar-left">
        <button class="burger-btn" onclick="toggleSidebar()">☰</button>
        <a href="../dashboard/index.php" class="navbar-brand">
            <img src="..\assets\images\logo_dgmp.png" 
                alt="DGMP" 
                class="navbar-logo">
            <span class="brand-sub">| Acquisitions Informatiques</span>
        </a>
    </div>

    <div class="navbar-right">
        <!-- Notifications -->
        <a href="../notifications/index.php" class="notif-btn">
            🔔
            <?php if ($nb_notif > 0): ?>
                <span class="notif-badge"><?= $nb_notif ?></span>
            <?php endif; ?>
        </a>

        <!-- Utilisateur -->
        <div class="user-dropdown">
            <div class="user-info" onclick="toggleDropdown()">
                <div class="user-avatar"><?= $initiales ?></div>
                <div class="user-details">
                    <div class="user-name">
                        <?= clean($user['prenom'] . ' ' . $user['nom']) ?>
                    </div>
                    <div class="user-role"><?= ucfirst($user['role']) ?></div>
                </div>
                <span>▾</span>
            </div>
            <div class="dropdown-menu" id="dropdownMenu">
                <a href="../profile/index.php" class="dropdown-item">
                    👤 Mon Profil
                </a>
                <div class="dropdown-divider"></div>
                <a href="../auth/logout.php" class="dropdown-item text-danger">
                    🚪 Déconnexion
                </a>
            </div>
        </div>
    </div>
</nav>

<script>
function toggleDropdown() {
    document.getElementById('dropdownMenu').classList.toggle('show');
}
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('open');
}
window.onclick = function(e) {
    if (!e.target.closest('.user-dropdown')) {
        const d = document.getElementById('dropdownMenu');
        if (d) d.classList.remove('show');
    }
}
</script>