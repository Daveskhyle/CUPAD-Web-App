// IndexedDB Manager for CUPAD Offline Functionality
class CupadIndexedDB {
    constructor() {
        this.dbName = 'cupad_offline_db';
        this.version = 1;
        this.db = null;
        this.stores = {
            users: 'users',
            clients: 'clients',
            disbursements: 'disbursements',
            savings: 'savings',
            collections: 'collections',
            payments: 'payments',
            registrations: 'registrations',
            groups: 'groups',
            settings: 'settings',
            notifications: 'notifications',
            queue: 'offline_queue',
            cache: 'data_cache'
        };
    }

    async init() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.version);
            
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };
            
            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                
                // Create object stores
                Object.values(this.stores).forEach(storeName => {
                    if (!db.objectStoreNames.contains(storeName)) {
                        const store = db.createObjectStore(storeName, { keyPath: 'id', autoIncrement: true });
                        
                        // Add indexes for common queries
                        if (storeName === 'users') {
                            store.createIndex('username', 'username', { unique: false });
                            store.createIndex('role', 'role', { unique: false });
                        }
                        if (storeName === 'clients') {
                            store.createIndex('client_id', 'client_id', { unique: false });
                            store.createIndex('branch', 'branch', { unique: false });
                        }
                        if (storeName === 'offline_queue') {
                            store.createIndex('timestamp', 'timestamp', { unique: false });
                        }
                        if (storeName === 'data_cache') {
                            store.createIndex('key', 'key', { unique: true });
                            store.createIndex('timestamp', 'timestamp', { unique: false });
                        }
                    }
                });
            };
        });
    }

    // Generic CRUD operations
    async add(storeName, data) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.add({ ...data, _synced: false, _timestamp: Date.now() });
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async get(storeName, id) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.get(id);
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async getAll(storeName) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.getAll();
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async update(storeName, data) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.put({ ...data, _synced: false, _timestamp: Date.now() });
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    async delete(storeName, id) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.delete(id);
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    // Cache management
    async setCache(key, data, ttl = 3600000) { // 1 hour default TTL
        const cacheData = {
            key: key,
            data: data,
            timestamp: Date.now(),
            expires: Date.now() + ttl
        };
        return this.add(this.stores.cache, cacheData);
    }

    async getCache(key) {
        const allCache = await this.getAll(this.stores.cache);
        const cached = allCache.find(item => item.key === key);
        
        if (!cached) return null;
        if (cached.expires < Date.now()) {
            await this.delete(this.stores.cache, cached.id);
            return null;
        }
        
        return cached.data;
    }

    // Sync operations
    async addToSyncQueue(operation, storeName, data) {
        const queueItem = {
            operation: operation, // 'create', 'update', 'delete'
            storeName: storeName,
            data: data,
            timestamp: Date.now(),
            retries: 0
        };
        return this.add(this.stores.queue, queueItem);
    }

    async getSyncQueue() {
        return this.getAll(this.stores.queue);
    }

    async removeFromSyncQueue(id) {
        return this.delete(this.stores.queue, id);
    }

    // Data synchronization
    async syncWithServer() {
        if (!navigator.onLine) {
            console.log('Offline - sync skipped');
            return { success: false, message: 'No internet connection' };
        }

        const queue = await this.getSyncQueue();
        const results = { synced: 0, failed: 0, errors: [] };

        for (const item of queue) {
            try {
                const success = await this.syncItem(item);
                if (success) {
                    await this.removeFromSyncQueue(item.id);
                    results.synced++;
                } else {
                    results.failed++;
                    // Increment retry count
                    item.retries = (item.retries || 0) + 1;
                    if (item.retries < 3) {
                        await this.update(this.stores.queue, item);
                    } else {
                        await this.removeFromSyncQueue(item.id);
                        results.errors.push(`Max retries exceeded for ${item.operation} on ${item.storeName}`);
                    }
                }
            } catch (error) {
                results.failed++;
                results.errors.push(error.message);
            }
        }

        return results;
    }

    async syncItem(item) {
        const endpoint = this.getEndpointForStore(item.storeName);
        if (!endpoint) return false;

        try {
            const formData = new FormData();
            formData.append('action', item.operation);
            formData.append('data', JSON.stringify(item.data));

            const response = await fetch(endpoint, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            return response.ok;
        } catch (error) {
            console.error('Sync error:', error);
            return false;
        }
    }

    getEndpointForStore(storeName) {
        const endpoints = {
            clients: 'api/sync_clients.php',
            disbursements: 'api/sync_disbursements.php',
            savings: 'api/sync_savings.php',
            collections: 'api/sync_collections.php',
            payments: 'api/sync_payments.php',
            registrations: 'api/sync_registrations.php'
        };
        return endpoints[storeName] || null;
    }

    // Bulk data operations
    async bulkImport(storeName, dataArray) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readwrite');
            const store = transaction.objectStore(storeName);
            let completed = 0;
            const total = dataArray.length;

            if (total === 0) {
                resolve(0);
                return;
            }

            dataArray.forEach(data => {
                const request = store.add({ ...data, _synced: true, _timestamp: Date.now() });
                request.onsuccess = () => {
                    completed++;
                    if (completed === total) resolve(completed);
                };
                request.onerror = () => {
                    completed++;
                    if (completed === total) resolve(completed);
                };
            });
        });
    }

    async clearStore(storeName) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readwrite');
            const store = transaction.objectStore(storeName);
            const request = store.clear();
            
            request.onsuccess = () => resolve();
            request.onerror = () => reject(request.error);
        });
    }

    // Search operations
    async search(storeName, indexName, value) {
        await this.init();
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction([storeName], 'readonly');
            const store = transaction.objectStore(storeName);
            const index = store.index(indexName);
            const request = index.getAll(value);
            
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    // Statistics
    async getStorageStats() {
        const stats = {};
        for (const [key, storeName] of Object.entries(this.stores)) {
            const data = await this.getAll(storeName);
            stats[key] = {
                count: data.length,
                unsynced: data.filter(item => !item._synced).length
            };
        }
        return stats;
    }

    // Cleanup old cache entries
    async cleanupCache() {
        const allCache = await this.getAll(this.stores.cache);
        const now = Date.now();
        let cleaned = 0;

        for (const item of allCache) {
            if (item.expires < now) {
                await this.delete(this.stores.cache, item.id);
                cleaned++;
            }
        }

        return cleaned;
    }
}

// Initialize global instance
const cupadDB = new CupadIndexedDB();

// Auto-sync when online
window.addEventListener('online', async () => {
    console.log('Back online! Starting sync...');
    try {
        const results = await cupadDB.syncWithServer();
        if (results.synced > 0) {
            showToast(`✅ Synced ${results.synced} items`, 'success');
        }
        if (results.failed > 0) {
            showToast(`⚠️ ${results.failed} items failed to sync`, 'warning');
        }
    } catch (error) {
        console.error('Sync error:', error);
    }
});

// Periodic cleanup
setInterval(async () => {
    try {
        await cupadDB.cleanupCache();
    } catch (error) {
        console.error('Cache cleanup error:', error);
    }
}, 300000); // Every 5 minutes

// Export for global use
window.cupadDB = cupadDB;