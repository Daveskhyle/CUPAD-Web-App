/**
 * Online Status Heartbeat
 * Include this script in all authenticated pages
 * Add: <script src="/js/heartbeat.js"></script>
 */

(function() {
    // Send heartbeat every 2 minutes
    const HEARTBEAT_INTERVAL = 2 * 60 * 1000; // 2 minutes
    
    // Detect base path from current location
    const basePath = window.location.pathname.includes('/admin/') || window.location.pathname.includes('/co/') 
        ? '../includes/heartbeat.php' 
        : 'includes/heartbeat.php';
    
    function sendHeartbeat() {
        fetch(basePath, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                console.warn('Heartbeat failed:', data.message);
            }
        })
        .catch(error => {
            console.error('Heartbeat error:', error);
        });
    }
    
    // Send initial heartbeat
    sendHeartbeat();
    
    // Set up interval
    setInterval(sendHeartbeat, HEARTBEAT_INTERVAL);
    
    // Send heartbeat on user activity
    let activityTimeout;
    function onActivity() {
        clearTimeout(activityTimeout);
        activityTimeout = setTimeout(sendHeartbeat, 1000);
    }
    
    // Track user activity
    ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, onActivity, { passive: true });
    });
    
    // Send heartbeat before page unload
    window.addEventListener('beforeunload', function() {
        navigator.sendBeacon(basePath);
    });
})();
