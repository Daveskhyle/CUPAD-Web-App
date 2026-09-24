const CACHE_NAME = 'cupad-offline-v7';
const urlsToCache = [
    './',
    './index.php',
    './manifest.json',
    './css/styles.css',
    './js/main.js',
    './js/indexeddb-manager.js',
    './js/offline-manager.js',
    './js/offline-queue.js',
    './js/data-initializer.js',
    './offline-manager.html',
    './uploads/CUPAD LOGO.png',
    './uploads/cupad logo.jpg',
    './uploads/cupad-192.png',
    './uploads/cupad-512.png',
    './uploads/cupad-maskable.png',
    './uploads/default_avatar.png',
    './forgot_password.php',
    './reset_password.php',
    './reset_form.php',
    './logout.php',
    './passkey_setup.php',
    // Includes
    './includes/json_helpers.php',
    './includes/auth_functions.php',
    './includes/config.php',
    './includes/activity_tracker.php',
    './includes/lockout_helper.php',
    // Admin files (ALL)
    './admin/dashboard.php',
    './admin/analytics.php',
    './admin/charts.php',
    './admin/manage_users.php',
    './admin/manage_clients.php',
    './admin/manage_loans.php',
    './admin/client_management.php',
    './admin/client_financial_summary.php',
    './admin/create_account.php',
    './admin/assign_role.php',
    './admin/backup_restore.php',
    './admin/manage_zones_branches.php',
    './admin/manage_unions.php',
    './admin/unions_manage.php',
    './admin/transaction_manager.php',
    './admin/system_logs.php',
    './admin/location_tracker.php',
    './admin/location_map.php',
    './admin/active_loans.php',
    './admin/active_users_admin.php',
    './admin/branch_disbursements.php',
    './admin/branch_savings.php',
    './admin/manage_registrations.php',
    './admin/deleted_users_manager.php',
    './admin/deleted_transactions.php',
    './admin/get_deleted_savings.php',
    './admin/close_clients.php',
    './admin/restore_clients.php',
    './admin/transfer_client.php',
    './admin/manage_client_finances.php',
    './admin/manage_saving_balance.php',
    './admin/recent_activities.php',
    './admin/send_notification.php',
    './admin/show_pictures.php',
    './admin/regfee.php',
    './admin/disbursement_settings.php',
    './admin/loan_collection_settings.php',
    './admin/savings_collection_settings.php',
    './admin/withdrawal_settings.php',
    './admin/manage_lockout.php',
    './admin/manage_pin.php',
    './admin/clients_two_active_loans.php',
    './admin/js/main.js',
    './admin/js/charts.js',
    './admin/js/modals.js',
    './admin/js/navigation.js',
    './admin/js/theme.js',
    './admin/js/particles.js',
    // CO files (ALL)
    './co/dashboard.php',
    './co/clients.php',
    './co/registration.php',
    './co/disbursement.php',
    './co/loan_collection.php',
    './co/saving_collection.php',
    './co/saving_withdrawal.php',
    './co/combined_collection.php',
    './co/collections.php',
    './co/analytics.php',
    './co/client_info.php',
    './co/client_financial_summary.php',
    './co/client_union_summary.php',
    './co/profile.php',
    './co/settings.php',
    './co/union_groups.php',
    './co/assign_client_union.php',
    './co/history.php',
    './co/export_transactions.php',
    './co/save_location.php',
    './co/delete_profile_picture.php',
    './co/auto_repayment.php',
    // BM files (ALL)
    './bm/dashboard.php',
    './bm/clients.php',
    './bm/analytics.php',
    './bm/disbursement.php',
    './bm/loan_collection.php',
    './bm/saving_collection.php',
    './bm/saving_withdrawal.php',
    './bm/registration.php',
    './bm/client_info.php',
    './bm/profile.php',
    './bm/settings.php',
    './bm/manage_cos.php',
    './bm/union_groups.php',
    './bm/assign_client_union.php',
    './bm/save_location.php',
    './bm/track_transactions.php',
    './bm/realtime_data.php',
    './bm/api_loan_history.php',
    './bm/get_notifications.php',
    './bm/mark_notifications_read.php',
    './bm/js/ajax-utils.js',
    // TM files (ALL)
    './tm/dashboard.php',
    './tm/dashboard_data.php',
    './tm/analytics.php',
    './tm/analytics_data.php',
    './tm/charts.php',
    './tm/manage_users.php',
    './tm/manage_clients.php',
    './tm/manage_loans.php',
    './tm/client_management.php',
    './tm/client_financial_summary.php',
    './tm/create_account.php',
    './tm/assign_role.php',
    './tm/backup_restore.php',
    './tm/manage_zones_branches.php',
    './tm/manage_unions.php',
    './tm/transaction_manager.php',
    './tm/system_logs.php',
    './tm/location_tracker.php',
    './tm/location_map.php',
    './tm/active_loans.php',
    './tm/active_users_admin.php',
    './tm/branch_disbursements.php',
    './tm/branch_savings.php',
    './tm/manage_registrations.php',
    './tm/deleted_users_manager.php',
    './tm/deleted_transactions.php',
    './tm/close_clients.php',
    './tm/restore_clients.php',
    './tm/transfer_client.php',
    './tm/manage_client_finances.php',
    './tm/recent_activities.php',
    './tm/send_notification.php',
    './tm/regfee.php',
    './tm/profile.php',
    './tm/disbursement_settings.php',
    './tm/loan_collection_settings.php',
    './tm/savings_collection_settings.php',
    './tm/withdrawal_settings.php',
    './tm/manage_lockout.php',
    './tm/manage_pin.php',
    './tm/clients_two_active_loans.php',
    // ZM files (ALL)
    './zm/dashboard.php',
    './zm/analytics.php',
    './zm/profile.php',
    './zm/view_clients.php',
    './zm/view_users.php',
    './zm/client_financial_summary.php',
    // AM files (ALL)
    './am/dashboard.php',
    './am/get_notifications.php',
    './am/mark_notifications_read.php',
    // DZM files
    './dzm/dashboard.php',
    // Client files
    './client/dashboard.php',
    // Data JSON files (ALL)
    './users.json',
    './hierarchy.json',
    './activity_log.json',
    './user_activity.json',
    './assignments.json',
    './api_keys.json',
    './auto_sync.json',
    './data/clients.json',
    './data/disbursements.json',
    './data/savings.json',
    './data/Saving_Balance.json',
    './data/collections.json',
    './data/payments.json',
    './data/registrations.json',
    './data/groups.json',
    './data/settings.json',
    './data/notifications.json',
    './data/assignments.json',
    './data/audit_log.json',
    './data/maintenance.json',
    './data/deleted_users.json',
    './data/deleted_transactions.json',
    './data/closed_client.json',
    './data/user_locations.json',
    './data/email_schedules.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return Promise.all(
                urlsToCache.map(url => 
                    cache.add(url).catch(err => console.log('Failed to cache:', url))
                )
            );
        })
    );
    self.skipWaiting();
});

self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    
    if (event.request.url.length > 2048) {
        event.respondWith(new Response('URL too long', { status: 414 }));
        return;
    }

    // Skip caching for POST requests
    if (event.request.method !== 'GET') {
        event.respondWith(fetch(event.request));
        return;
    }

    // Cache-first for static assets (images, CSS, JS)
    if (url.pathname.match(/\.(jpg|jpeg|png|gif|svg|css|js|woff|woff2|ttf)$/)) {
        event.respondWith(
            caches.match(event.request).then(response => {
                return response || fetch(event.request).then(fetchResponse => {
                    return caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, fetchResponse.clone());
                        return fetchResponse;
                    });
                });
            })
        );
        return;
    }

    // Network-first for dynamic content (PHP, JSON)
    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(CACHE_NAME).then(cache => {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(() => {
                return caches.match(event.request).then(response => {
                    if (response) return response;
                    if (event.request.mode === 'navigate') {
                        return caches.match('./index.php');
                    }
                });
            })
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.filter(name => name !== CACHE_NAME).map(name => caches.delete(name))
            );
        })
    );
    self.clients.claim();
});