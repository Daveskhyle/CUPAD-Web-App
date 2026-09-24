// Offline Data Manager for CUPAD
class OfflineDataManager {
    constructor() {
        this.db = window.cupadDB;
        this.isOnline = navigator.onLine;
        this.init();
    }

    init() {
        // Monitor online/offline status
        window.addEventListener('online', () => {
            this.isOnline = true;
            this.showConnectionStatus('online');
            this.autoSync();
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            this.showConnectionStatus('offline');
        });

        // Intercept form submissions
        this.interceptForms();
        
        // Show initial status
        this.showConnectionStatus(this.isOnline ? 'online' : 'offline');
    }

    showConnectionStatus(status) {
        const statusEl = document.getElementById('connection-status') || this.createStatusElement();
        
        if (status === 'online') {
            statusEl.innerHTML = '<i class="fas fa-wifi"></i> Online';
            statusEl.className = 'connection-status online';
        } else {
            statusEl.innerHTML = '<i class="fas fa-wifi-slash"></i> Offline Mode';
            statusEl.className = 'connection-status offline';
        }
    }

    createStatusElement() {
        const statusEl = document.createElement('div');
        statusEl.id = 'connection-status';
        statusEl.style.cssText = `
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 8px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            z-index: 10000;
            transition: all 0.3s ease;
        `;
        document.body.appendChild(statusEl);
        
        // Add CSS styles
        const style = document.createElement('style');
        style.textContent = `
            .connection-status.online {
                background: #22c55e;
                color: white;
            }
            .connection-status.offline {
                background: #ef4444;
                color: white;
            }
        `;
        document.head.appendChild(style);
        
        return statusEl;
    }

    interceptForms() {
        document.addEventListener('submit', async (e) => {
            const form = e.target;
            
            // Skip if form has data-no-offline attribute
            if (form.hasAttribute('data-no-offline')) return;
            
            // Skip login forms
            if (form.id === 'loginForm' || form.classList.contains('login-form')) return;
            
            if (!this.isOnline) {
                e.preventDefault();
                await this.handleOfflineSubmission(form);
            }
        });
    }

    async handleOfflineSubmission(form) {
        try {
            const formData = new FormData(form);
            const data = Object.fromEntries(formData.entries());
            const action = this.determineAction(form, data);
            const storeName = this.determineStore(form, data);
            
            if (!storeName) {
                showToast('Cannot save this form offline', 'error');
                return;
            }

            // Save to IndexedDB
            await this.db.add(storeName, data);
            
            // Add to sync queue
            await this.db.addToSyncQueue(action, storeName, data);
            
            showToast('📱 Saved offline! Will sync when online.', 'success');
            
            // Update UI to show pending status
            this.updatePendingCount();
            
            // Reset form if successful
            form.reset();
            
        } catch (error) {
            console.error('Offline save error:', error);
            showToast('Failed to save offline', 'error');
        }
    }

    determineAction(form, data) {
        const action = form.action || window.location.pathname;
        
        if (action.includes('registration') || data.action === 'register') return 'create';
        if (action.includes('disbursement') || data.action === 'disburse') return 'create';
        if (action.includes('collection') || data.action === 'collect') return 'create';
        if (action.includes('saving') || data.action === 'save') return 'create';
        if (data.id && data.action === 'update') return 'update';
        if (data.action === 'delete') return 'delete';
        
        return 'create'; // Default
    }

    determineStore(form, data) {
        const action = form.action || window.location.pathname;
        const formId = form.id || '';
        
        if (action.includes('registration') || formId.includes('registration')) return 'registrations';
        if (action.includes('disbursement') || formId.includes('disbursement')) return 'disbursements';
        if (action.includes('loan_collection') || formId.includes('loan')) return 'collections';
        if (action.includes('saving_collection') || formId.includes('saving')) return 'savings';
        if (action.includes('client') || formId.includes('client')) return 'clients';
        if (action.includes('payment') || formId.includes('payment')) return 'payments';
        
        return null;
    }

    async autoSync() {
        if (!this.isOnline) return;
        
        try {
            const results = await this.db.syncWithServer();
            
            if (results.synced > 0) {
                showToast(`✅ Synced ${results.synced} items`, 'success');
                this.updatePendingCount();
            }
            
            if (results.failed > 0) {
                showToast(`⚠️ ${results.failed} items failed to sync`, 'warning');
            }
            
        } catch (error) {
            console.error('Auto sync error:', error);
        }
    }

    async manualSync() {
        if (!this.isOnline) {
            showToast('❌ No internet connection', 'error');
            return;
        }
        
        showToast('🔄 Syncing...', 'info');
        
        try {
            const results = await this.db.syncWithServer();
            
            if (results.synced > 0) {
                showToast(`✅ Synced ${results.synced} items`, 'success');
            } else {
                showToast('✅ All data is up to date', 'success');
            }
            
            this.updatePendingCount();
            
        } catch (error) {
            console.error('Manual sync error:', error);
            showToast('❌ Sync failed', 'error');
        }
    }

    async updatePendingCount() {
        try {
            const queue = await this.db.getSyncQueue();
            const count = queue.length;
            
            let countEl = document.getElementById('pending-sync-count');
            if (!countEl) {
                countEl = this.createPendingCountElement();
            }
            
            if (count > 0) {
                countEl.textContent = count;
                countEl.style.display = 'inline-block';
            } else {
                countEl.style.display = 'none';
            }
            
        } catch (error) {
            console.error('Error updating pending count:', error);
        }
    }

    createPendingCountElement() {
        const countEl = document.createElement('div');
        countEl.id = 'pending-sync-count';
        countEl.style.cssText = `
            position: fixed;
            top: 50px;
            right: 10px;
            background: #f97316;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
            z-index: 10000;
            cursor: pointer;
        `;
        countEl.title = 'Click to sync pending items';
        countEl.addEventListener('click', () => this.manualSync());
        document.body.appendChild(countEl);
        return countEl;
    }

    // Data retrieval methods for offline use
    async getClients(branch = null) {
        try {
            let clients = await this.db.getAll('clients');
            if (branch) {
                clients = clients.filter(client => client.branch === branch);
            }
            return clients;
        } catch (error) {
            console.error('Error getting clients:', error);
            return [];
        }
    }

    async getClientById(id) {
        try {
            const clients = await this.db.getAll('clients');
            return clients.find(client => client.id === id || client.client_id === id);
        } catch (error) {
            console.error('Error getting client:', error);
            return null;
        }
    }

    async getDisbursements(filters = {}) {
        try {
            let disbursements = await this.db.getAll('disbursements');
            
            if (filters.branch) {
                disbursements = disbursements.filter(d => d.branch === filters.branch);
            }
            if (filters.date) {
                disbursements = disbursements.filter(d => d.date === filters.date);
            }
            
            return disbursements;
        } catch (error) {
            console.error('Error getting disbursements:', error);
            return [];
        }
    }

    async getSavings(filters = {}) {
        try {
            let savings = await this.db.getAll('savings');
            
            if (filters.client_id) {
                savings = savings.filter(s => s.client_id === filters.client_id);
            }
            if (filters.branch) {
                savings = savings.filter(s => s.branch === filters.branch);
            }
            
            return savings;
        } catch (error) {
            console.error('Error getting savings:', error);
            return [];
        }
    }

    // Cache management
    async cacheServerData(endpoint, data, ttl = 3600000) {
        try {
            await this.db.setCache(endpoint, data, ttl);
        } catch (error) {
            console.error('Cache error:', error);
        }
    }

    async getCachedData(endpoint) {
        try {
            return await this.db.getCache(endpoint);
        } catch (error) {
            console.error('Cache retrieval error:', error);
            return null;
        }
    }

    // Storage statistics
    async getStorageStats() {
        try {
            return await this.db.getStorageStats();
        } catch (error) {
            console.error('Stats error:', error);
            return {};
        }
    }
}

// Initialize offline data manager
const offlineManager = new OfflineDataManager();

// Global functions for easy access
window.offlineManager = offlineManager;
window.syncNow = () => offlineManager.manualSync();
window.getOfflineClients = (branch) => offlineManager.getClients(branch);
window.getOfflineClient = (id) => offlineManager.getClientById(id);