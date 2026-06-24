// ============================================
// SERVICE WORKER - DGMP ACQUISITIONS
// ============================================

var CACHE_NAME    = 'dgmp-cache-v1';
var OFFLINE_PAGE  = '/dgmp_acquisitions/offline.html';

var FILES_TO_CACHE = [
    '/dgmp_acquisitions/',
    '/dgmp_acquisitions/assets/css/style.css',
    '/dgmp_acquisitions/assets/images/logo_dgmp.png',
    '/dgmp_acquisitions/offline.html'
];

// ============================================
// INSTALLATION
// ============================================
self.addEventListener('install', function(evt) {
    console.log('DGMP SW : Installation...');

    evt.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            console.log('DGMP SW : Mise en cache des fichiers');
            return cache.addAll(FILES_TO_CACHE);
        })
    );
    self.skipWaiting();
});

// ============================================
// ACTIVATION
// ============================================
self.addEventListener('activate', function(evt) {
    console.log('DGMP SW : Activation...');

    evt.waitUntil(
        caches.keys().then(function(keyList) {
            return Promise.all(
                keyList.map(function(key) {
                    if (key !== CACHE_NAME) {
                        console.log('DGMP SW : Suppression ancien cache', key);
                        return caches.delete(key);
                    }
                })
            );
        })
    );
    self.clients.claim();
});

// ============================================
// FETCH - Network First Strategy
// ============================================
self.addEventListener('fetch', function(evt) {

    // Ignorer les requêtes non GET
    if (evt.request.method !== 'GET') return;

    // Ignorer les requêtes externes
    if (!evt.request.url.startsWith(self.location.origin)) return;

    evt.respondWith(
        fetch(evt.request)
            .then(function(response) {
                // Mettre en cache la réponse
                if (response && response.status === 200) {
                    var responseClone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(evt.request, responseClone);
                    });
                }
                return response;
            })
            .catch(function() {
                // Si pas de réseau → utiliser le cache
                return caches.match(evt.request)
                    .then(function(cachedResponse) {
                        if (cachedResponse) {
                            return cachedResponse;
                        }
                        // Page hors ligne
                        if (evt.request.destination === 'document') {
                            return caches.match(OFFLINE_PAGE);
                        }
                    });
            })
    );
});