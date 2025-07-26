const CACHE_NAME = 'sgm-prefeitura-v1.0.0';
const urlsToCache = [
    './dashboard.php',
    './lista_usuarios.php',
    './perfil.php',
    './assistencia_habitacao.php',
    './assets/css/main.css',
    './assets/css/admin-style.css',
    './assets/js/main.js',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
    './assets/icons/icon-192x192.png',
    './assets/icons/icon-512x512.png'
];

// Install event - Cache dos recursos
self.addEventListener('install', event => {
    console.log('Service Worker: Install event');
    
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Service Worker: Caching files');
                return cache.addAll(urlsToCache);
            })
            .catch(error => {
                console.error('Service Worker: Cache failed', error);
            })
    );
    
    // Ativar imediatamente o novo service worker
    self.skipWaiting();
});

// Activate event - Limpar caches antigos
self.addEventListener('activate', event => {
    console.log('Service Worker: Activate event');
    
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.map(cacheName => {
                    if (cacheName !== CACHE_NAME) {
                        console.log('Service Worker: Deleting old cache:', cacheName);
                        return caches.delete(cacheName);
                    }
                })
            );
        })
    );
    
    // Assumir controle de todas as páginas imediatamente
    event.waitUntil(self.clients.claim());
});

// Fetch event - Interceptar requests
self.addEventListener('fetch', event => {
    const request = event.request;
    
    // Ignorar requests não-GET
    if (request.method !== 'GET') {
        return;
    }
    
    // Estratégia: Cache First para recursos estáticos
    if (isStaticResource(request.url)) {
        event.respondWith(
            caches.match(request)
                .then(response => {
                    if (response) {
                        return response;
                    }
                    return fetch(request).then(response => {
                        // Verificar se a resposta é válida
                        if (!response || response.status !== 200 || response.type !== 'basic') {
                            return response;
                        }
                        
                        // Clonar resposta para cache
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME)
                            .then(cache => {
                                cache.put(request, responseToCache);
                            });
                        
                        return response;
                    });
                })
                .catch(() => {
                    // Retornar página offline para navegação
                    if (request.destination === 'document') {
                        return caches.match('./offline.html');
                    }
                })
        );
    }
    // Estratégia: Network First para páginas PHP dinâmicas
    else if (isDynamicPage(request.url)) {
        event.respondWith(
            fetch(request)
                .then(response => {
                    // Se a resposta for válida, cache uma cópia
                    if (response && response.status === 200) {
                        const responseToCache = response.clone();
                        caches.open(CACHE_NAME)
                            .then(cache => {
                                cache.put(request, responseToCache);
                            });
                    }
                    return response;
                })
                .catch(() => {
                    // Se falhar, tentar buscar no cache
                    return caches.match(request)
                        .then(response => {
                            if (response) {
                                return response;
                            }
                            // Retornar página offline como fallback
                            if (request.destination === 'document') {
                                return caches.match('./offline.html');
                            }
                        });
                })
        );
    }
});

// Função para identificar recursos estáticos
function isStaticResource(url) {
    return url.includes('.css') || 
           url.includes('.js') || 
           url.includes('.png') || 
           url.includes('.jpg') || 
           url.includes('.jpeg') || 
           url.includes('.gif') || 
           url.includes('.svg') || 
           url.includes('.ico') ||
           url.includes('cdnjs.cloudflare.com') ||
           url.includes('fonts.googleapis.com');
}

// Função para identificar páginas dinâmicas
function isDynamicPage(url) {
    return url.includes('.php') || 
           url.endsWith('/') ||
           url.includes('dashboard') ||
           url.includes('lista_usuarios') ||
           url.includes('assistencia');
}

// Background Sync - Para funcionalidades offline
self.addEventListener('sync', event => {
    console.log('Service Worker: Background sync', event.tag);
    
    if (event.tag === 'sync-data') {
        event.waitUntil(
            syncOfflineData()
        );
    }
});

// Push notifications
self.addEventListener('push', event => {
    console.log('Service Worker: Push received');
    
    const options = {
        body: event.data ? event.data.text() : 'Nova notificação do sistema',
        icon: './assets/icons/icon-192x192.png',
        badge: './assets/icons/icon-72x72.png',
        vibrate: [200, 100, 200],
        tag: 'sgm-notification',
        actions: [
            {
                action: 'open',
                title: 'Abrir Sistema',
                icon: './assets/icons/icon-96x96.png'
            },
            {
                action: 'close',
                title: 'Fechar'
            }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification('Sistema Municipal', options)
    );
});

// Manipular cliques em notificações
self.addEventListener('notificationclick', event => {
    console.log('Service Worker: Notification click');
    
    event.notification.close();
    
    if (event.action === 'open') {
        event.waitUntil(
            clients.openWindow('./dashboard.php')
        );
    }
});

// Função para sincronizar dados offline (exemplo)
async function syncOfflineData() {
    try {
        const offlineData = await getOfflineData();
        if (offlineData && offlineData.length > 0) {
            await sendDataToServer(offlineData);
            await clearOfflineData();
        }
    } catch (error) {
        console.error('Service Worker: Sync failed', error);
    }
}

// Funções auxiliares para dados offline
async function getOfflineData() {
    // Implementar busca de dados salvos offline
    return [];
}

async function sendDataToServer(data) {
    // Implementar envio de dados para servidor
    return fetch('./api/sync-data.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    });
}

async function clearOfflineData() {
    // Implementar limpeza de dados offline
    console.log('Service Worker: Offline data cleared');
}

// Message handling para comunicação com o app
self.addEventListener('message', event => {
    console.log('Service Worker: Message received', event.data);
    
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    
    if (event.data && event.data.type === 'GET_VERSION') {
        event.ports[0].postMessage({version: CACHE_NAME});
    }
});