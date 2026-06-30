<?php
$role     = $_SESSION['role'];
$pdo_side = getConnexion();

function isActive($path) {
    return strpos($_SERVER['REQUEST_URI'], $path) !== false
           ? 'active' : '';
}
?>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <img src="..\assets\images\logo_dgmp.png" 
                alt="DGMP" 
                class="navbar-logo">
        <small>DGMP</small>
    </div>

    <nav class="sidebar-menu">

        <!-- ===== TABLEAU DE BORD ===== -->
        <a href="../dashboard/index.php"
           class="menu-item <?= isActive('dashboard') ?>">
            <span class="menu-icon">📊</span>
            <span class="menu-text">Tableau de Bord</span>
        </a>

        <!-- ===== ACQUISITIONS ===== -->
        <div class="menu-section">ACQUISITIONS</div>

        <!-- Demandes : TOUT LE MONDE -->
        <a href="../demandes/index.php"
           class="menu-item <?= isActive('/demandes/index') ?>">
            <span class="menu-icon">📋</span>
            <span class="menu-text">Demandes</span>
        </a>

        <!-- Nouvelle demande : TOUT LE MONDE -->
        <a href="../demandes/nouvelle_demande.php"
           class="menu-item <?= isActive('nouvelle_demande') ?>">
            <span class="menu-icon">➕</span>
            <span class="menu-text">Nouvelle Demande</span>
        </a>

        <!-- ===== VALIDATION : RESPONSABLE SEULEMENT ===== -->
        <?php if ($role === 'responsable'): ?>
        <div class="menu-section">VALIDATION</div>
        <a href="../demandes/valider_demande.php"
           class="menu-item menu-item-responsable
                  <?= isActive('valider_demande') ?>">
            <span class="menu-icon">✅</span>
            <span class="menu-text">
                Valider Demandes
                <?php
                $nb_dem = $pdo_side->query("
                    SELECT COUNT(*) as nb
                    FROM demandes_acquisition
                    WHERE statut = 'en_attente'
                ")->fetch()['nb'];
                if ($nb_dem > 0): ?>
                <span class="menu-badge"><?= $nb_dem ?></span>
                <?php endif; ?>
            </span>
        </a>
        <?php endif; ?>

        <!-- ===== GESTION ===== -->
        <div class="menu-section">GESTION</div>

        <!-- Fournisseurs : admin, responsable -->
        <?php if (in_array($role, ['admin', 'responsable'])): ?>
        <a href="../fournisseurs/index.php"
           class="menu-item <?= isActive('fournisseurs') ?>">
            <span class="menu-icon">🏢</span>
            <span class="menu-text">Fournisseurs</span>
        </a>
        <?php endif; ?>

        <!-- Commandes : admin, responsable, validateur -->
        <?php if (in_array($role, ['admin', 'responsable', 'validateur'])): ?>
        <a href="../commandes/index.php"
           class="menu-item <?= isActive('commandes') ?>">
            <span class="menu-icon">🛒</span>
            <span class="menu-text">
                Commandes
                <?php if ($role === 'validateur'):
                    $nb_cmd = $pdo_side->query("
                        SELECT COUNT(*) as nb
                        FROM commandes
                        WHERE statut = 'en_attente_validation'
                    ")->fetch()['nb'];
                    if ($nb_cmd > 0): ?>
                    <span class="menu-badge"><?= $nb_cmd ?></span>
                    <?php endif;
                endif; ?>
            </span>
        </a>
        <?php endif; ?>

        <!-- ===== LIVRAISONS : VALIDATEUR SEULEMENT ===== -->
        <?php if ($role === 'validateur'): ?>
        <a href="../livraisons/index.php"
           class="menu-item menu-item-validateur
                  <?= isActive('livraisons') ?>">
            <span class="menu-icon">📦</span>
            <span class="menu-text">
                Livraisons
                <?php
                $nb_liv = $pdo_side->query("
                    SELECT COUNT(*) as nb
                    FROM commandes
                    WHERE statut = 'validee'
                ")->fetch()['nb'];
                if ($nb_liv > 0): ?>
                <span class="menu-badge"><?= $nb_liv ?></span>
                <?php endif; ?>
            </span>
        </a>
        <?php endif; ?>

        <!-- Inventaire : tous -->
        <a href="../inventaire/index.php"
           class="menu-item <?= isActive('inventaire') ?>">
            <span class="menu-icon">🗃️</span>
            <span class="menu-text">Inventaire</span>
        </a>

        <!-- ===== RAPPORTS : admin + validateur ===== -->
        <?php if (in_array($role, ['admin', 'validateur'])): ?>
        <div class="menu-section">RAPPORTS</div>
        <a href="../rapports/index.php"
           class="menu-item <?= isActive('rapports') ?>">
            <span class="menu-icon">📈</span>
            <span class="menu-text">Rapports</span>
        </a>
        <?php endif; ?>

        <!-- ===== ADMINISTRATION : ADMIN SEULEMENT ===== -->
        <?php if ($role === 'admin'): ?>
        <div class="menu-section">ADMINISTRATION</div>

        <a href="../utilisateurs/index.php"
           class="menu-item <?= isActive('utilisateurs') ?>">
            <span class="menu-icon">👥</span>
            <span class="menu-text">Utilisateurs</span>
        </a>

        <a href="../materiels/index.php"
           class="menu-item <?= isActive('materiels') ?>">
            <span class="menu-icon">🖥️</span>
            <span class="menu-text">Matériels</span>
        </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <small>© <?= date('Y') ?> DGMP</small>
    </div>
</aside>