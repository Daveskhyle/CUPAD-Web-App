
<?php
if (file_exists(__DIR__ . '/../maintenance.flag')) {
    include_once __DIR__ . '/../maintenance.php';
    exit();
}
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'client') {
    header('Location: ../index.php');
    exit();
}
include '../includes/header.php';
?>
<main>
    <h2>Welcome, Client!</h2>
    <p>This is your dashboard. Here you can view your account details, loan status, and recent transactions.</p>
</main>
<body oncopy="return false;" onselectstart="return false;" oncontextmenu="return false;">
<script>
    // DevTools detection
    const devtools = /./;
    devtools.toString = function() {
        this.opened = true;
    }

    setInterval(() => {
        console.profile(devtools);
        console.profileEnd(devtools);
        if (devtools.opened) {
            document.body.innerHTML = '';
        }
    }, 1000);
</script>
</html>
