{{-- Консервативный service worker: кэшируются только версионированные
     статические файлы витрины. HTML, вход, API и любые данные не кэшируются. --}}
const ENABLED = @json($enabled);
const VERSION = 'corpms-sw-v1';
const CACHE = VERSION + '-assets';

const ASSET_PATH = /^\/assets\/(css|js|vendor|landing)\//;
const BUILD_PATH = /^\/build\//;
const VERSIONED_QUERY = /[?&](?:v=?\d+)$/;
const FINGERPRINTED = /[-.][A-Za-z0-9_-]{8,}\.(css|js|woff2?|svg|webp|png)$/;

self.addEventListener('install', function (event) {
    // Никакого precache HTML/ manifests: только ленивый кэш разрешённых файлов.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil((async function () {
        const keys = await caches.keys();

        if (!ENABLED) {
            // Kill switch: выключение снимет воркер и очистит только наши кэши.
            await Promise.all(
                keys
                    .filter(function (key) { return key.indexOf('corpms-sw-') === 0; })
                    .map(function (key) { return caches.delete(key); })
            );
            await self.registration.unregister();

            return;
        }

        await Promise.all(
            keys
                .filter(function (key) { return key.indexOf('corpms-sw-') === 0 && key !== CACHE; })
                .map(function (key) { return caches.delete(key); }),
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', function (event) {
    if (!ENABLED) {
        return;
    }

    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    const path = url.pathname;
    const isVersionedAsset = ASSET_PATH.test(path) && VERSIONED_QUERY.test(url.search);
    const isHashedBuild = BUILD_PATH.test(path) && FINGERPRINTED.test(path);

    if (!isVersionedAsset && !isHashedBuild) {
        return;
    }

    event.respondWith((async function () {
        const cache = await caches.open(CACHE);
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        const response = await fetch(request);
        if (response && response.ok) {
            await cache.put(request, response.clone());
        }

        return response;
    })());
});
