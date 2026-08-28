<?php
/**
 * Setup wizard.
 *
 *   1. Upload everything to your web space.
 *   2. Open  https://yourdomain.com/install.php  in a browser.
 *   3. Fill in the form. It checks the server, finds your database, creates all
 *      the tables, saves your settings and makes your admin account.
 *   4. DELETE THIS FILE afterwards. It refuses to run once an admin exists.
 *
 * Nothing here needs a text editor - every setting is on the form.
 */

require_once __DIR__ . '/includes/helpers.php';

$errors  = [];
$done    = false;
$dbTried = [];

// ---------------------------------------------------------------------------
//  Server checks
// ---------------------------------------------------------------------------
$avatarReady = is_dir(AVATAR_DIR) ? is_writable(AVATAR_DIR) : @mkdir(AVATAR_DIR, 0755, true);
$configDir   = __DIR__ . '/includes';

$checks = [
    'PHP 7.4 or newer'    => [version_compare(PHP_VERSION, '7.4', '>='), 'you have ' . PHP_VERSION],
    'MySQL support'       => [extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'ready' : 'missing - ask your host to enable pdo_mysql'],
    'Photo resizing (GD)' => [extension_loaded('gd'), extension_loaded('gd') ? 'ready' : 'missing - ask your host to enable gd'],
    'Text handling'       => [extension_loaded('mbstring'), extension_loaded('mbstring') ? 'ready' : 'missing - ask your host to enable mbstring'],
    'Photo folder'        => [(bool)$avatarReady, $avatarReady ? 'ready to receive photos' : 'set uploads/avatars to 755'],
    'Settings folder'     => [is_writable($configDir), is_writable($configDir) ? 'ready' : 'set the includes folder to 755 so setup can save your settings'],
];

$serverOk = true;
foreach ($checks as [$pass, $_]) {
    if (!$pass) { $serverOk = false; }
}

// ---------------------------------------------------------------------------
//  Guesses for the database host, so nobody has to go hunting for it
// ---------------------------------------------------------------------------
function host_candidates(string $typed, string $dbName): array
{
    $site = preg_replace('/^www\./i', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
    $site = preg_replace('/:\d+$/', '', $site);

    $list = [];
    if ($typed !== '') {
        $list[] = $typed;
    }
    // one.com and several other hosts use "<yourdomain>.mysql"
    if ($site !== '') {
        $list[] = $site . '.mysql';
    }
    if ($dbName !== '') {
        $list[] = $dbName . '.mysql';
    }
    $list[] = 'localhost';
    $list[] = '127.0.0.1';
    $list[] = 'mysql';

    return array_values(array_unique(array_filter($list)));
}

/**
 * Try to connect. A host may carry a port as "server:3307" - rare, but some
 * hosts do it. Returns [true, PDO, host, port] or [false, null, '', 3306].
 */
function try_connect(array $hosts, string $name, string $user, string $pass, array &$tried): array
{
    foreach ($hosts as $host) {
        $port = 3306;
        if (preg_match('/^(.+):(\d+)$/', $host, $m)) {
            $host = $m[1];
            $port = (int)$m[2];
        }
        try {
            $pdo = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4',
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $tried[] = [$host . ':' . $port, 'connected'];
            return [true, $pdo, $host, $port];
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Wrong password or wrong database name is a real answer, not a
            // wrong hostname - stop guessing and tell them what is wrong.
            if (strpos($msg, 'Access denied') !== false || strpos($msg, 'Unknown database') !== false) {
                $tried[] = [$host . ':' . $port, $msg];
                return [false, null, '', 3306];
            }
            $tried[] = [$host . ':' . $port, 'no server there'];
        }
    }
    return [false, null, '', 3306];
}

// ---------------------------------------------------------------------------
//  Already installed?
// ---------------------------------------------------------------------------
// db_probe() rather than the normal connection: on a fresh site there are no
// database details yet, and this page still has to load so you can enter them.
$installed = false;
$probe = db_probe();
if ($probe) {
    try {
        $installed = (bool)$probe->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    } catch (Throwable $e) {
        $installed = false;          // tables are not there yet
    }
}

// ---------------------------------------------------------------------------
//  Run it
// ---------------------------------------------------------------------------
$in = [
    'site_name' => $_POST['site_name'] ?? 'Female Connects',
    'db_host'   => $_POST['db_host']   ?? '',
    'db_name'   => $_POST['db_name']   ?? '',
    'db_user'   => $_POST['db_user']   ?? '',
    'db_pass'   => $_POST['db_pass']   ?? '',
    'name'      => $_POST['name']      ?? '',
    'email'     => $_POST['email']     ?? '',
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST' && $serverOk) {

    $pass  = (string)($_POST['password'] ?? '');
    $pass2 = (string)($_POST['password2'] ?? '');

    if (trim($in['db_name']) === '') $errors[] = 'Please enter your database name.';
    if (trim($in['db_user']) === '') $errors[] = 'Please enter your database username.';
    if (mb_strlen(trim($in['site_name'])) < 2) $errors[] = 'Please give the site a name.';
    if (mb_strlen(trim($in['name'])) < 2)      $errors[] = 'Please enter your name.';
    if (!filter_var(trim($in['email']), FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address for the admin login.';
    if (mb_strlen($pass) < 8)  $errors[] = 'Your admin password needs to be at least 8 characters.';
    if ($pass !== $pass2)      $errors[] = 'The two admin passwords do not match.';

    if (!$errors) {
        [$ok, $pdo, $usedHost, $usedPort] = try_connect(
            host_candidates(trim($in['db_host']), trim($in['db_name'])),
            trim($in['db_name']), trim($in['db_user']), (string)$in['db_pass'], $dbTried
        );

        if (!$ok) {
            $last = end($dbTried);
            $errors[] = 'Could not reach the database. Last thing the server said: '
                      . ($last ? $last[1] : 'no response')
                      . '. Please check the database name, username and password in your hosting control panel.';
        } else {
            $pdo->exec("SET time_zone = '+00:00'");

            // --- tables ---------------------------------------------------
            $sqlFile = null;
            foreach ([__DIR__ . '/../sql/schema.sql', __DIR__ . '/sql/schema.sql', __DIR__ . '/schema.sql'] as $c) {
                if (is_file($c)) { $sqlFile = $c; break; }
            }
            if (!$sqlFile) {
                $errors[] = 'schema.sql is missing. Please re-upload the files, including the sql folder.';
            } else {
                try {
                    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($sqlFile));
                    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                        $pdo->exec($statement);
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not create the tables: ' . $e->getMessage();
                }
            }

            // --- admin account -------------------------------------------
            if (!$errors) {
                try {
                    $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
                    $check->execute([mb_strtolower(trim($in['email']))]);

                    if ($check->fetchColumn()) {
                        $errors[] = 'There is already an account with that email address in this database.';
                    } else {
                        $ins = $pdo->prepare(
                            "INSERT INTO users (name, email, password_hash, role, status, created_at)
                             VALUES (?, ?, ?, 'admin', 'active', UTC_TIMESTAMP())"
                        );
                        $ins->execute([
                            trim($in['name']),
                            mb_strtolower(trim($in['email'])),
                            password_hash($pass, PASSWORD_DEFAULT),
                        ]);
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not create the admin account: ' . $e->getMessage();
                }
            }

            // --- save the settings ---------------------------------------
            if (!$errors) {
                $settings = "<?php\n"
                    . "// Written by install.php. Safe to edit by hand later.\n"
                    . "define('DB_HOST', "   . var_export($usedHost, true) . ");\n"
                    . "define('DB_PORT', "   . var_export((int)$usedPort, true) . ");\n"
                    . "define('DB_NAME', "   . var_export(trim($in['db_name']), true) . ");\n"
                    . "define('DB_USER', "   . var_export(trim($in['db_user']), true) . ");\n"
                    . "define('DB_PASS', "   . var_export((string)$in['db_pass'], true) . ");\n"
                    . "define('SITE_NAME', " . var_export(trim($in['site_name']), true) . ");\n";

                if (@file_put_contents($configDir . '/config.local.php', $settings) === false) {
                    $errors[] = 'Everything is set up, but the settings file could not be saved. '
                              . 'Please set the includes folder to 755 and run this page again.';
                } else {
                    @chmod($configDir . '/config.local.php', 0640);
                    $done = true;
                }
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set up your site</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="wrap wrap--narrow page">

<?php if ($installed): ?>

  <h1>Already set up</h1>
  <div class="alert alert--success">
    This site already has an admin account, so setup will not run again.
  </div>
  <div class="card"><div class="card__body">
    <p><strong>Please delete <code>install.php</code> from your web space now</strong>, then log in.</p>
    <a class="btn btn--lg" href="login.php">Go to the login page</a>
  </div></div>

<?php elseif ($done): ?>

  <h1>All done</h1>
  <div class="alert alert--success">
    Your database is set up and your admin account is ready.
  </div>
  <div class="card"><div class="card__body">
    <p><strong>One last thing:</strong> delete <code>install.php</code> from your web space.
       Anyone who found it could otherwise re-run setup.</p>
    <p class="muted small">Your settings were saved automatically &mdash; there is no file to edit.</p>
    <a class="btn btn--lg" href="login.php">Log in as admin</a>
  </div></div>

<?php else: ?>

  <h1>Set up your site</h1>
  <p class="muted">One form, then you are finished. Nothing here needs a text editor.</p>

  <h2>Your server</h2>
  <div class="card" style="margin-bottom:24px"><div class="card__body">
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
    <?php if (!$serverOk): ?>
      <p class="small" style="margin-top:14px;color:var(--danger)">
        Fix the items marked "problem" before carrying on.
      </p>
    <?php endif; ?>
  </div></div>

  <?php if ($errors): ?>
    <div class="alert alert--error">
      <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
    <?php if ($dbTried): ?>
      <details class="card" style="margin-bottom:20px">
        <summary style="padding:14px 20px;cursor:pointer">What setup tried</summary>
        <div class="card__body" style="padding-top:0">
          <table class="data">
            <?php foreach ($dbTried as [$h, $why]): ?>
              <tr><td class="small"><?= e($h) ?></td><td class="muted small"><?= e($why) ?></td></tr>
            <?php endforeach; ?>
          </table>
        </div>
      </details>
    <?php endif; ?>
  <?php endif; ?>

  <form class="card" method="post">
    <div class="card__body">

      <h2 style="margin-top:0">Your database</h2>
      <p class="muted small">
        These come from your hosting control panel, under database settings. Leave the
        server box empty and setup will work it out for you.
      </p>

      <div class="field">
        <label for="db_name">Database name</label>
        <input type="text" id="db_name" name="db_name" required value="<?= e($in['db_name']) ?>">
      </div>
      <div class="row">
        <div class="field">
          <label for="db_user">Database username</label>
          <input type="text" id="db_user" name="db_user" required value="<?= e($in['db_user']) ?>">
          <div class="hint">On most hosts this is the same as the database name.</div>
        </div>
        <div class="field">
          <label for="db_pass">Database password</label>
          <input type="password" id="db_pass" name="db_pass" value="">
        </div>
      </div>
      <div class="field">
        <label for="db_host">Database server <span class="muted">(leave empty unless setup asks)</span></label>
        <input type="text" id="db_host" name="db_host" value="<?= e($in['db_host']) ?>"
               placeholder="setup will find this for you">
      </div>

      <hr style="border:0;border-top:1px solid var(--line);margin:24px 0">

      <h2>Your site</h2>
      <div class="field">
        <label for="site_name">Site name</label>
        <input type="text" id="site_name" name="site_name" required value="<?= e($in['site_name']) ?>">
        <div class="hint">Shown in the header and in the page titles.</div>
      </div>

      <hr style="border:0;border-top:1px solid var(--line);margin:24px 0">

      <h2>Your admin login</h2>
      <p class="muted small">This is the account that can see every member and every conversation.</p>

      <div class="field">
        <label for="name">Your name</label>
        <input type="text" id="name" name="name" required value="<?= e($in['name']) ?>">
      </div>
      <div class="field">
        <label for="email">Email address you will log in with</label>
        <input type="email" id="email" name="email" required value="<?= e($in['email']) ?>">
      </div>
      <div class="row">
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required minlength="8">
          <div class="hint">At least 8 characters.</div>
        </div>
        <div class="field">
          <label for="password2">Repeat password</label>
          <input type="password" id="password2" name="password2" required>
        </div>
      </div>

      <button class="btn btn--lg btn--block" type="submit" <?= $serverOk ? '' : 'disabled' ?>>
        Set up my site
      </button>
    </div>
  </form>

<?php endif; ?>

</div>
</body>
</html>
