const CACHE_NAME = "laravel-pwa-v5";
const OFFLINE_URL = "/offline";

const FILES_TO_CACHE = [
    "/",
    OFFLINE_URL,
    "/manifest.json",
    "/background-sync.js",
    "/pwa-install.js",
];

const SYNC_STORE_NAME = "offline-requests";
const DB_NAME = "laravel-pwa-sync";
const DB_VERSION = 1;

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(SYNC_STORE_NAME)) {
                db.createObjectStore(SYNC_STORE_NAME, {
                    keyPath: "id",
                    autoIncrement: true,
                });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

function getAllRequests(store) {
    return new Promise((resolve, reject) => {
        const request = store.getAll();
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

self.addEventListener("install", (event) => {
    console.log("[PWA] Installing Service Worker...");
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => {
                console.log("[PWA] Pre-caching core offline assets");
                return Promise.allSettled(
                    FILES_TO_CACHE.map((url) => {
                        return fetch(url).then((response) => {
                            if (!response.ok)
                                throw new Error("Request failed for " + url);
                            return cache.put(url, response);
                        });
                    }),
                );
            })
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener("activate", (event) => {
    console.log("[PWA] Service Worker activated.");
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.map((key) => {
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                }),
            ),
        ),
    );
    self.clients.claim();
});

self.addEventListener("fetch", (event) => {
    const request = event.request;

    if (request.method !== "GET") {
        return;
    }

    // ✅ Fix: Skip caching Chrome Extension requests (like AdBlock, etc.)
    if (
        request.url.startsWith("chrome-extension://") ||
        request.url.startsWith("extension://")
    ) {
        return;
    }

    // Network-first with cache fallback
    event.respondWith(
        fetch(request)
            .then((response) => {
                if (
                    response &&
                    response.status === 200 &&
                    response.type === "basic"
                ) {
                    const responseToCache = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(request, responseToCache);
                    });
                }
                return response;
            })
            .catch(() => {
                console.log("[PWA] Network failed, looking for:", request.url);
                return caches.match(request).then((cachedResponse) => {
                    if (cachedResponse) {
                        return cachedResponse;
                    }
                    if (request.mode === "navigate") {
                        console.log("[PWA] Navigating to offline fallback");
                        return caches.match(OFFLINE_URL);
                    }
                });
            }),
    );
});

self.addEventListener("sync", (event) => {
    if (event.tag === "laravel-pwa-sync") {
        console.log("[PWA] Sync event triggered");
        event.waitUntil(syncRequests());
    }
});

async function syncRequests() {
    const db = await openDB();
    const tx = db.transaction(SYNC_STORE_NAME, "readonly");
    const store = tx.objectStore(SYNC_STORE_NAME);
    const requests = await getAllRequests(store);

    for (const req of requests) {
        try {
            const response = await fetch(req.url, {
                method: req.method,
                headers: req.headers,
                body: req.body,
            });

            if (response.ok) {
                const deleteTx = db.transaction(SYNC_STORE_NAME, "readwrite");
                await deleteTx.objectStore(SYNC_STORE_NAME).delete(req.id);
                console.log("[PWA] Synced successfully:", req.url);
            }
        } catch (err) {
            console.error("[PWA] Sync failed for:", req.url, err);
        }
    }
}
