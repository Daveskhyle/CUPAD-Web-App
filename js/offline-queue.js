// Offline Queue Manager
class OfflineQueue {
    constructor() {
        this.dbName = 'cupad_offline_queue';
        this.storeName = 'pending_requests';
        this.db = null;
        this.init();
    }

    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, 1);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(this.storeName)) {
                    db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                }
            };
        });
    }

    async addToQueue(url, method, data) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            
            const request = store.add({
                url: url,
                method: method,
                data: data,
                timestamp: Date.now()
            });
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async getQueue() {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const request = store.getAll();
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async removeFromQueue(id) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.delete(id);
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    async syncQueue() {
        const queue = await this.getQueue();
        const results = [];
        
        for (const item of queue) {
            try {
                const response = await fetch(item.url, {
                    method: item.method,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: item.data
                });
                
                if (response.ok) {
                    await this.removeFromQueue(item.id);
                    results.push({ success: true, item });
                } else {
                    results.push({ success: false, item, error: 'Server error' });
                }
            } catch (error) {
                results.push({ success: false, item, error: error.message });
            }
        }
        
        return results;
    }

    async getQueueCount() {
        const queue = await this.getQueue();
        return queue.length;
    }
}

// Initialize queue
const offlineQueue = new OfflineQueue();

// Auto-sync when online
window.addEventListener('online', async () => {
    console.log('Back online! Syncing queued data...');
    const results = await offlineQueue.syncQueue();
    const synced = results.filter(r => r.success).length;
    const failed = results.filter(r => !r.success).length;
    
    if (synced > 0) {
        alert(`✅ Synced ${synced} offline transactions!`);
    }
    if (failed > 0) {
        alert(`⚠️ ${failed} transactions failed to sync. Will retry.`);
    }
});

// Show queue status
async function showQueueStatus() {
    const count = await offlineQueue.getQueueCount();
    const statusEl = document.getElementById('offline-queue-status');
    if (statusEl && count > 0) {
        statusEl.textContent = `${count} pending`;
        statusEl.style.display = 'block';
    }
}

// Intercept form submissions
document.addEventListener('submit', async (e) => {
    if (!navigator.onLine) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = new URLSearchParams(formData).toString();
        
        await offlineQueue.addToQueue(form.action, form.method, data);
        alert('📱 Saved offline! Will sync when back online.');
        showQueueStatus();
    }
});

// Manual sync button
async function manualSync() {
    if (!navigator.onLine) {
        alert('❌ No internet connection');
        return;
    }
    
    const results = await offlineQueue.syncQueue();
    const synced = results.filter(r => r.success).length;
    alert(`✅ Synced ${synced} transactions!`);
    showQueueStatus();
}
