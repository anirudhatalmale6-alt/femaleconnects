<?php
/** Admin: audit trail of admin actions, plus recent sign-in attempts. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

require_admin();

$log = q_all(
    'SELECT l.*, u.name AS admin_name
       FROM admin_log l LEFT JOIN users u ON u.id = l.admin_id
      ORDER BY l.created_at DESC LIMIT 200'
);

$failed = q_all(
    'SELECT email, ip, created_at
       FROM login_attempts
      WHERE successful = 0
      ORDER BY created_at DESC LIMIT 40'
);

page_head('Activity log', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'log',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<h1>Activity log</h1>
<p class="muted">Every admin action is recorded here, so nothing changes on the site without a trace.</p>

<div class="card table-scroll" style="margin-bottom:26px">
  <table class="data">
    <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>Target</th><th>Details</th></tr></thead>
    <tbody>
      <?php if (!$log): ?>
        <tr><td colspan="5" class="center muted" style="padding:30px">Nothing has been done yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($log as $l): ?>
        <tr>
          <td class="muted small nowrap"><?= e(pretty_date($l['created_at'])) ?></td>
          <td class="small"><?= e($l['admin_name'] ?: 'removed admin') ?></td>
          <td><span class="chip"><?= e(str_replace('_', ' ', $l['action'])) ?></span></td>
          <td class="muted small"><?= e(trim(($l['target_type'] ?? '') . ' ' . ($l['target_id'] ?? ''))) ?: '-' ?></td>
          <td class="muted small"><?= e($l['details'] ?: '-') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card table-scroll">
  <div class="card__head"><h3>Recent failed logins</h3>
    <span class="muted small" style="margin-left:auto">
      An account locks for <?= (int)LOGIN_WINDOW_MIN ?> minutes after <?= (int)LOGIN_MAX_ATTEMPTS ?> wrong passwords.
    </span>
  </div>
  <table class="data">
    <thead><tr><th>When</th><th>Email tried</th><th>IP address</th></tr></thead>
    <tbody>
      <?php if (!$failed): ?>
        <tr><td colspan="3" class="center muted" style="padding:30px">No failed logins.</td></tr>
      <?php endif; ?>
      <?php foreach ($failed as $f): ?>
        <tr>
          <td class="muted small nowrap"><?= e(pretty_date($f['created_at'])) ?></td>
          <td class="small"><?= e($f['email']) ?></td>
          <td class="muted small"><?= e($f['ip']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php page_foot(); ?>
