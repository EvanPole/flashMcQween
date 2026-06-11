const cacheName = 'flashmcqween-offline-auth-v3';
const shellUrls = [
    '/',
    '/js/offline-auth.js',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(cacheName)
            .then((cache) => cache.addAll(shellUrls))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    caches.open(cacheName).then((cache) => cache.put(request, response.clone()));

                    return response;
                })
                .catch(async () => {
                    const cache = await caches.open(cacheName);

                    return cache.match(request)
                        || cache.match('/');
                }),
        );
        return;
    }

    if (url.pathname === '/js/offline-auth.js') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    caches.open(cacheName).then((cache) => cache.put(request, response.clone()));

                    return response;
                })
                .catch(() => caches.match(request)),
        );
    }
});
