// Data Initialization for IndexedDB
class DataInitializer {
    constructor() {
        this.db = window.cupadDB;
        this.dataEndpoints = {
            clients: 'data/clients.json',
            disbursements: 'data/disbursements.json',
            savings: 'data/savings.json',
            collections: 'data/collections.json',
            payments: 'data/payments.json',
            registrations: 'data/registrations.json',
            groups: 'data/groups.json',
            settings: 'data/settings.json',
            notifications: 'data/notifications.json'
        };
    }

    async initializeData() {
        if (!navigator.onLine) {
            console.log('Offline - skipping data initialization');
            return;
        }

        try {
            console.log('Initializing IndexedDB with server data...');
            
            for (const [storeName, endpoint] of Object.entries(this.dataEndpoints)) {
                await this.loadStoreData(storeName, endpoint);
            }
            
            console.log('Data initialization complete');
            
            // Show success message
            if (typeof showToast === 'function') {
                showToast('📱 Offline data initialized', 'success');
            }
            
        } catch (error) {
            console.error('Data initialization error:', error);
        }
    }

    async loadStoreData(storeName, endpoint) {
        try {
            // Check if store already has data
            const existingData = await this.db.getAll(storeName);
            if (existingData.length > 0) {
                console.log(`${storeName} already has data, skipping...`);
                return;
            }

            // Fetch data from server
            const response = await fetch(endpoint);
            if (!response.ok) {
                console.log(`Failed to fetch ${endpoint}: ${response.status}`);
                return;
            }

            const data = await response.json();
            if (!Array.isArray(data)) {
                console.log(`${endpoint} did not return an array`);
                return;
            }

            // Clear existing data and bulk import
            await this.db.clearStore(storeName);
            const imported = await this.db.bulkImport(storeName, data);
            
            console.log(`Imported ${imported} records to ${storeName}`);
            
        } catch (error) {
            console.error(`Error loading ${storeName}:`, error);
        }
    }

    async refreshStoreData(storeName) {
        if (!navigator.onLine) {
            console.log('Offline - cannot refresh data');
            return;
        }

        const endpoint = this.dataEndpoints[storeName];
        if (!endpoint) {
            console.error(`No endpoint defined for ${storeName}`);
            return;
        }

        try {
            const response = await fetch(endpoint + '?t=' + Date.now()); // Cache bust
            if (!response.ok) return;

            const data = await response.json();
            if (!Array.isArray(data)) return;

            await this.db.clearStore(storeName);
            const imported = await this.db.bulkImport(storeName, data);
            
            console.log(`Refreshed ${imported} records in ${storeName}`);
            
            if (typeof showToast === 'function') {
                showToast(`✅ ${storeName} data refreshed`, 'success');
            }
            
        } catch (error) {
            console.error(`Error refreshing ${storeName}:`, error);
        }
    }

    async getDataStats() {
        const stats = {};
        
        for (const storeName of Object.keys(this.dataEndpoints)) {
            try {
                const data = await this.db.getAll(storeName);
                stats[storeName] = {
                    count: data.length,
                    lastUpdate: data.length > 0 ? Math.max(...data.map(item => item._timestamp || 0)) : 0
                };
            } catch (error) {
                stats[storeName] = { count: 0, lastUpdate: 0, error: error.message };
            }
        }
        
        return stats;
    }

    async exportData(storeName) {
        try {
            const data = await this.db.getAll(storeName);
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            
            const a = document.createElement('a');
            a.href = url;
            a.download = `${storeName}_export_${new Date().toISOString().split('T')[0]}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
        } catch (error) {
            console.error(`Export error for ${storeName}:`, error);
        }
    }

    async clearAllData() {
        if (!confirm('Are you sure you want to clear all offline data? This cannot be undone.')) {
            return;
        }

        try {
            for (const storeName of Object.keys(this.dataEndpoints)) {
                await this.db.clearStore(storeName);
            }
            
            // Also clear cache and queue
            await this.db.clearStore('data_cache');
            await this.db.clearStore('offline_queue');
            
            console.log('All offline data cleared');
            
            if (typeof showToast === 'function') {
                showToast('🗑️ All offline data cleared', 'info');
            }
            
        } catch (error) {
            console.error('Error clearing data:', error);
        }
    }
}

// Initialize data initializer
const dataInitializer = new DataInitializer();

// Auto-initialize on page load if online
window.addEventListener('load', () => {
    setTimeout(() => {
        dataInitializer.initializeData();
    }, 2000); // Wait 2 seconds after page load
});

// Global functions
window.dataInitializer = dataInitializer;
window.refreshOfflineData = (storeName) => dataInitializer.refreshStoreData(storeName);
window.exportOfflineData = (storeName) => dataInitializer.exportData(storeName);
window.clearOfflineData = () => dataInitializer.clearAllData();
window.getOfflineStats = () => dataInitializer.getDataStats();