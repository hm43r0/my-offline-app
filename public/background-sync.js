const SYNC_STORE_NAME = 'offline-requests';
const DB_NAME = 'laravel-pwa-sync';
const DB_VERSION = 1;

function openDB() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, DB_VERSION);
        request.onupgradeneeded = (event) => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains(SYNC_STORE_NAME)) {
                db.createObjectStore(SYNC_STORE_NAME, { keyPath: 'id', autoIncrement: true });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function queueRequest(request) {
    let body;
    let headers = Object.fromEntries(request.headers.entries());

    if (request.headers.get('content-type') && request.headers.get('content-type').includes('multipart/form-data')) {
        const formData = await request.clone().formData();
        body = JSON.stringify(Object.fromEntries(formData.entries()));
        headers['content-type'] = 'application/json';
    } else {
        body = await request.clone().text();
    }

    const db = await openDB();
    const tx = db.transaction(SYNC_STORE_NAME, 'readwrite');
    const store = tx.objectStore(SYNC_STORE_NAME);

    const serializedRequest = {
        url: request.url,
        method: request.method,
        headers: headers,
        body: body,
        timestamp: Date.now()
    };

    const requestAdd = store.add(serializedRequest);
    
    return new Promise((resolve, reject) => {
        requestAdd.onsuccess = async () => {
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                try {
                    const registration = await navigator.serviceWorker.ready;
                    await registration.sync.register('laravel-pwa-sync');
                } catch (e) {
                    console.error('Sync registration failed:', e);
                }
            }
            resolve();
        };
        requestAdd.onerror = () => reject(requestAdd.error);
    });
}

window.queueRequest = queueRequest;