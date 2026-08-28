<?php
/** Log in. The same form serves members and the admin. */

require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('members.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $email = trim((string)($_POST['email'] ?? ''));
    [$ok, $result] = attempt_login($email, (string)($_POST['password'] ?? ''));

    if ($ok) {
        start_session();
        $back = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);

        // Only ever follow a path on this site, never an outside URL.
        if ($back !== '' && $back[0] === '/' && strpos($back, '//') !== 0) {
            redirect($back);
        }
        redirect($result['role'] === 'admin' ? 'admin/index.php' : 'members.php');
    }
    $error = $result;
}

page_head('Log in', ['active' => 'login']);
?>

<div class="wrap wrap--narrow" style="padding:0">
  <h1>Welcome back</h1>
  <p class="muted">Log in to pick up your conversations.</p>

  <?php if ($error): ?>
    <div class="alert alert--error"><?= e($error) ?></div>
  <?php endif; ?>

  <form class="card" method="post" novalidate>
    <div class="card__body">
      <?= csrf_field() ?>

      <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" required autocomplete="username"
               value="<?= e($email) ?>" autofocus>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">
      </div>

      <button class="btn btn--block btn--lg" type="submit">Log in</button>

      <p class="center small muted" style="margin-top:14px">
        New here? <a href="register.php">Create a free profile</a>
      </p>
    </div>
  </form>
</div>

<?php page_foot(); ?>
