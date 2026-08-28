<?php
/**
 * Shared page chrome. Every page calls page_head(...) then page_foot().
 *
 * Pages inside a sub-folder (admin/, api/) define GC_BASE = '../' before
 * including this file so that links and asset paths still resolve.
 */

require_once __DIR__ . '/chat.php';

if (!defined('GC_BASE')) {
    define('GC_BASE', '');
}

/** Inline SVG icons - no icon font, no emoji, nothing external to load. */
function icon(string $name, int $size = 20): string
{
    $paths = [
        'chat'    => '<path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.5 9.5 0 0 1-2.8-.4L3 21l1.5-4.6A8.2 8.2 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5Z"/>',
        'users'   => '<path d="M16 20v-1.6a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4V20"/><circle cx="9" cy="7.5" r="3.5"/><path d="M22 20v-1.6a4 4 0 0 0-3-3.8"/><path d="M16 4a3.5 3.5 0 0 1 0 6.9"/>',
        'shield'  => '<path d="M12 21s7-3.2 7-9V6l-7-3-7 3v6c0 5.8 7 9 7 9Z"/><path d="m9.2 12 2 2 3.6-3.8"/>',
        'heart'   => '<path d="M20.3 5.6a5 5 0 0 0-7.1 0l-1.2 1.2-1.2-1.2a5 5 0 1 0-7.1 7.1l8.3 8.3 8.3-8.3a5 5 0 0 0 0-7.1Z"/>',
        'send'    => '<path d="m21 3-9.5 9.5"/><path d="M21 3 14.5 21l-3-7.5L4 10.5 21 3Z"/>',
        'user'    => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'logout'  => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'flag'    => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V4s-1 1-4 1-5-2-8-2-4 1-4 1Z"/><path d="M4 22v-7"/>',
        'back'    => '<path d="m15 18-6-6 6-6"/>',
        'search'  => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
        'lock'    => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'sprout'  => '<path d="M12 21V11"/><path d="M12 11S11 4 5 4c0 6 7 7 7 7Z"/><path d="M12 13s1-6 7-6c0 5.5-7 6-7 6Z"/>',
    ];
    $d = $paths[$name] ?? '';
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" '
         . 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
         . 'aria-hidden="true">' . $d . '</svg>';
}

/**
 * @param string $title  Page title (site name is appended).
 * @param array  $opts   'active' => nav key, 'bodyClass' => extra class,
 *                       'wrap' => container class for the main element.
 */
function page_head(string $title, array $opts = []): void
{
    $user   = current_user();
    $active = $opts['active'] ?? '';
    $unread = $user ? unread_total((int)$user['id']) : 0;

    if ($user) {
        touch_presence();
    }

    $navLink = static function (string $key, string $href, string $label, string $activeKey, string $extra = '') {
        return '<a class="' . ($key === $activeKey ? 'is-active' : '') . '" href="' . e($href) . '">'
             . e($label) . $extra . '</a>';
    };

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#faf6f1">
<title><?= e($title) ?> &middot; <?= e(SITE_NAME) ?></title>
<link rel="stylesheet" href="<?= e(GC_BASE) ?>assets/css/style.css?v=1">
<link rel="icon" href="<?= e(GC_BASE) ?>assets/favicon.svg" type="image/svg+xml">
</head>
<body class="<?= e($opts['bodyClass'] ?? '') ?>">

<header class="site-header">
  <div class="wrap wrap--wide site-header__inner">
    <a class="brand" href="<?= e(GC_BASE) ?>index.php">
      <span class="brand__mark"><?= e(mb_substr(SITE_NAME, 0, 1)) ?></span>
      <span class="brand__label"><?= e(SITE_NAME) ?></span>
    </a>

    <nav class="nav">
      <?php if ($user): ?>
        <?= $navLink('members', GC_BASE . 'members.php', 'Members', $active) ?>
        <?= $navLink('chat', GC_BASE . 'chat.php', 'Messages', $active,
              $unread > 0 ? '<span class="nav__badge" data-unread-badge>' . (int)$unread . '</span>'
                          : '<span class="nav__badge hidden" data-unread-badge>0</span>') ?>
        <?php if ($user['role'] === 'admin'): ?>
          <?= $navLink('admin', GC_BASE . 'admin/index.php', 'Admin', $active) ?>
        <?php endif; ?>
        <span class="nav__me">
          <a href="<?= e(GC_BASE) ?>profile.php" title="Your profile">
            <?= avatar_html($user, 30) ?>
          </a>
          <a class="btn btn--quiet btn--sm nav__logout" href="<?= e(GC_BASE) ?>logout.php"
             title="Log out"><?= icon('logout', 17) ?><span>Log out</span></a>
        </span>
      <?php else: ?>
        <?= $navLink('home', GC_BASE . 'index.php', 'Home', $active) ?>
        <?= $navLink('login', GC_BASE . 'login.php', 'Log in', $active) ?>
        <a class="btn btn--sm" href="<?= e(GC_BASE) ?>register.php">Join free</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<?php if ($user && $user['role'] === 'admin' && ($opts['adminBar'] ?? false)):
    $a = $opts['adminActive'] ?? ''; ?>
<div class="admin-bar">
  <div class="wrap wrap--wide admin-bar__inner">
    <strong>Admin</strong>
    <a class="<?= $a === 'dash' ? 'is-active' : '' ?>"    href="<?= e(GC_BASE) ?>admin/index.php">Dashboard</a>
    <a class="<?= $a === 'users' ? 'is-active' : '' ?>"   href="<?= e(GC_BASE) ?>admin/users.php">Members</a>
    <a class="<?= $a === 'convos' ? 'is-active' : '' ?>"  href="<?= e(GC_BASE) ?>admin/conversations.php">Conversations</a>
    <a class="<?= $a === 'reports' ? 'is-active' : '' ?>" href="<?= e(GC_BASE) ?>admin/reports.php">Reports</a>
    <a class="<?= $a === 'log' ? 'is-active' : '' ?>"     href="<?= e(GC_BASE) ?>admin/activity.php">Activity log</a>
    <span class="spacer"></span>
    <a href="<?= e(GC_BASE) ?>members.php">Back to site</a>
  </div>
</div>
<?php endif; ?>

<main class="<?= e($opts['wrap'] ?? 'wrap page') ?>">
<?php
    foreach (take_flashes() as $f) {
        echo '<div class="alert alert--' . e($f['type']) . '">' . e($f['message']) . '</div>';
    }
}

function page_foot(array $opts = []): void
{
    ?>
</main>

<footer class="site-footer">
  <div class="wrap wrap--wide site-footer__inner">
    <span><?= e(SITE_NAME) ?> &mdash; <?= e(SITE_TAGLINE) ?></span>
    <span class="spacer"></span>
    <a href="<?= e(GC_BASE) ?>guidelines.php">Community guidelines</a>
    <span>&copy; <?= date('Y') ?></span>
  </div>
</footer>

<?php if (is_logged_in()): ?>
<script>
/* Keeps the unread counter in the header live without reloading the page. */
(function () {
  var badge = document.querySelector('[data-unread-badge]');
  if (!badge) return;
  function refresh() {
    fetch('<?= e(GC_BASE) ?>api/unread.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d) return;
        badge.textContent = d.unread;
        badge.classList.toggle('hidden', d.unread < 1);
      })
      .catch(function () {});
  }
  setInterval(refresh, 20000);
})();
</script>
<?php endif; ?>

<?php foreach (($opts['scripts'] ?? []) as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
