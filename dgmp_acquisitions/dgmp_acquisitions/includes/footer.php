<footer class="footer">
    <div class="footer-content">
        <p>
            © <?= date('Y') ?> 
            <strong>DGMP</strong> — Direction Générale des Marchés Publics
        </p>
        <p class="footer-sub">
            Système d'Automatisation des Acquisitions Informatiques
        </p>
    </div>
</footer>

<!-- ============================================ -->
<!-- PWA SERVICE WORKER                           -->
<!-- ============================================ -->
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
        navigator.serviceWorker
            .register('/dgmp_acquisitions/service-worker.js')
            .then(function(registration) {
                console.log('✅ PWA DGMP enregistrée !',
                            registration.scope);
            })
            .catch(function(error) {
                console.log('❌ Erreur PWA : ', error);
            });
    });
}

// ============================================
// BOUTON INSTALLATION PWA
// ============================================
var deferredPrompt;

window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    deferredPrompt = e;

    // Afficher le bouton d'installation
    var btnInstall = document.getElementById('btnInstaller');
    if (btnInstall) {
        btnInstall.style.display = 'flex';

        btnInstall.addEventListener('click', function() {
            btnInstall.style.display = 'none';
            deferredPrompt.prompt();

            deferredPrompt.userChoice.then(function(choiceResult) {
                if (choiceResult.outcome === 'accepted') {
                    console.log('✅ Application installée !');
                    showNotification('Application DGMP installée !');
                }
                deferredPrompt = null;
            });
        });
    }
});

// Confirmation après installation
window.addEventListener('appinstalled', function() {
    console.log('✅ DGMP installée avec succès !');
    deferredPrompt = null;
});

// Notification installation
function showNotification(message) {
    var notif = document.createElement('div');
    notif.innerHTML = '📱 ' + message;
    notif.style.cssText =
        'position:fixed;bottom:20px;right:20px;' +
        'background:#2e7d32;color:white;' +
        'padding:14px 20px;border-radius:8px;' +
        'font-weight:bold;z-index:9999;' +
        'box-shadow:0 4px 12px rgba(0,0,0,0.3);' +
        'font-size:14px;';
    document.body.appendChild(notif);
    setTimeout(function() {
        notif.remove();
    }, 4000);
}
</script>