<?php
/**
 * One-time setup.
 *
 *   1. Edit includes/config.php with your database details.
 *   2. Open  https://yourdomain.com/install.php  in a browser.
 *   3. It checks the server, creates the tables and makes your admin account.
 *   4. DELETE THIS FILE afterwards. It refuses to run once an admin exists.
 */

require_once __DIR__ . '/includes/helpers.php';

$step   = 'check';
$errors = [];
$notes  = [];

// --------------------------------------------------------------- server check
$checks = [
    'PHP 7.4 or newer'      => [version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION],
    'PDO MySQL driver'      => [extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'loaded' : 'missing'],
    'GD (photo resizing)'   => [extension_loaded('gd'), extension_loaded('gd') ? 'loaded' : 'missing'],
    'mbstring'              => [extension_loaded('mbstring'), extension_loaded('mbstring') ? 'loaded' : 'missing'],
    'Sessions'              => [function_exists('session_start'), 'ok'],
    'uploads/avatars writable' => [
        is_dir(AVATAR_DIR) ? is_writable(AVATAR_DIR) : @mkdir(AVATAR_DIR, 0755, true),
        is_dir(AVATAR_DIR) && is_writable(AVATAR_DIR) ? 'writable' : 'not writable - chmod it to 755',
    ],
];

$serverOk = true;
foreach ($checks as [$pass, $_]) {
    if (!$pass) { $serverOk = false; }
}

// ------------------------------------------------------------ database check
$dbOk = false;
$dbMessage = '';
try {
    db();
    $dbOk = true;
    $dbMessage = 'Connected to "' . DB_NAME . '" on ' . (DB_SOCKET !== '' ? 'local socket' : DB_HOST) . '.';
} catch (Throwable $e) {
    $dbMessage = $e->getMessage();
}

// Already installed?
$installed = false;
if ($dbOk) {
    try {
        $installed = (bool)q_val("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    } catch (Throwable $e) {
        $installed = false;                    // tables not there yet
    }
}

if ($installed) {
    $step = 'done_already';
}

// -------------------------------------------------------------------- submit
if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $dbOk && $serverOk) {

    $name  = trim((string)($_POST['name'] ?? ''));
    $email = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    if (mb_strlen($name) < 2)                          $errors[] = 'Please enter the admin name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = 'Please enter a valid admin email address.';
    if (mb_strlen($pass) < 8)                          $errors[] = 'The admin password needs to be at least 8 characters.';
    if ($pass !== (string)($_POST['password2'] ?? '')) $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        $sqlFile = __DIR__ . '/../sql/schema.sql';
        if (!is_file($sqlFile)) {
            $sqlFile = __DIR__ . '/schema.sql';        // if the sql folder was not uploaded
        }
        if (!is_file($sqlFile)) {
            $errors[] = 'schema.sql not found. Upload the sql folder next to public_html, '
                      . 'or import sql/schema.sql through phpMyAdmin and reload this page.';
        } else {
            try {
                // Run the schema statement by statement. Comments are stripped
                // first so semicolons inside them cannot split a statement.
                $sql = (string)file_get_contents($sqlFile);
                $sql = preg_replace('/^\s*--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                    db()->exec($statement);
                }

                if (q_val('SELECT id FROM users WHERE email = ?', [$email])) {
                    $errors[] = 'A user with that email already exists.';
                } else {
                    q("INSERT INTO users (name, email, password_hash, role, status, created_at)
                       VALUES (?, ?, ?, 'admin', 'active', UTC_TIMESTAMP())",
                      [$name, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                    $step = 'success';
                }
            } catch (Throwable $e) {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap wrap--narrow page">

  <h1>Set up <?= e(SITE_NAME) ?></h1>

  <?php if ($step === 'done_already'): ?>
    <div class="alert alert--success">
      <strong>Already installed.</strong> This site has an admin account, so the installer will
      not run again.
    </div>
    <div class="card"><div class="card__body">
      <p>Please delete <code>install.php</code> from your server now, then log in.</p>
      <a class="btn" href="login.php">Go to the login page</a>
    </div></div>

  <?php elseif ($step === 'success'): ?>
    <div class="alert alert--success">
      <strong>All done.</strong> The tables are created and your admin account is ready.
    </div>
    <div class="card"><div class="card__body">
      <p><strong>One last thing:</strong> delete <code>install.php</code> from your server. Anyone
         who finds it could otherwise re-run setup on an empty database.</p>
      <a class="btn btn--lg" href="login.php">Log in as admin</a>
    </div></div>

  <?php else: ?>

    <h2>1. Server check</h2>
    <div class="card" style="margin-bottom:22px"><div class="card__body">
      <table class="data" style="margin:-6px 0">
        <?php foreach ($checks as $label => [$pass, $detail]): ?>
          <tr>
            <td><?= e($label) ?></td>
            <td class="muted small"><?= e($detail) ?></td>
            <td style="text-align:right">
              <span class="chip <?= $pass ? 'chip--accent' : 'chip--danger' ?>"><?= $pass ? 'ok' : 'problem' ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div></div>

    <h2>2. Database</h2>
    <div class="alert <?= $dbOk ? 'alert--success' : 'alert--error' ?>">
      <?= e($dbMessage) ?>
      <?php if (!$dbOk): ?><br>Edit <code>includes/config.php</code> and reload this page.<?php endif; ?>
    </div>

    <h2>3. Your admin account</h2>
    <?php if ($errors): ?>
      <div class="alert alert--error">
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    <?php endif; ?>

    <form class="card" method="post">
      <div class="card__body">
        <p class="muted small">This is the account that can see every member and every conversation.</p>

        <div class="field">
          <label for="name">Your name</label>
          <input type="text" id="name" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
        </div>
        <div class="field">
          <label for="email">Admin email</label>
          <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="row">
          <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required minlength="8">
          </div>
          <div class="field">
            <label for="password2">Repeat password</label>
            <input type="password" id="password2" name="password2" required>
          </div>
        </div>

        <button class="btn btn--lg btn--block" type="submit"
                <?= ($dbOk && $serverOk) ? '' : 'disabled' ?>>
          Create the tables and my admin account
        </button>
        <?php if (!$dbOk || !$serverOk): ?>
          <p class="small muted center" style="margin-top:12px">
            Fix the items marked "problem" above first.
          </p>
        <?php endif; ?>
      </div>
    </form>
  <?php endif; ?>

</div>
</body>
</html>
