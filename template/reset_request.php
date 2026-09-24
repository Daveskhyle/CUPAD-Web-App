<h2>Reset Password</h2>
<?php if($_GET['sent']): ?>Email sent (check spam).<?php endif; ?>
<form method="post" action="../reset_password.php">
  <input type="email" name="email" required placeholder="Registered email">
  <button type="submit">Send link</button>
</form>