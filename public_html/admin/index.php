<?php
/** Admin dashboard. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

require_admin();

$stats = [
    'members'   => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member'"),
    'active'    => (int)q_val("SELECT COUNT(*) FROM users WHERE role = 'member' AND status = 'active'"),
    'pending'   => (int)q_val("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
    'suspended' => (int)q_val("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
    'convos'    => (int)q_val('SELECT COUNT(*) FROM conversations'),
    'messages'  => (int)q_val('SELECT COUNT(*) FROM messages'),
    'today'     => (int)q_val('SELECT COUNT(*) FROM messages WHERE created_at >= UTC_DATE()'),
    'week'      => (int)q_val('SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)'),
    'online'    => (int)q_val('SELECT COUNT(*) FROM users WHERE last_seen_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . (int)ONLINE_WINDOW_MIN . ' MINUTE)'),
    'reports'   => (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
    'blocks'    => (int)q_val('SELECT COUNT(*) FROM blocks'),
];

$newest = q_all("SELECT * FROM users WHERE role = 'member' ORDER BY created_at DESC LIMIT 6");

$busiest = q_all(
    "SELECT c.id, COUNT(m.id) AS n, c.last_message_at,
            a.name AS a_name, a.id AS a_id, a.avatar AS a_avatar,
            b.name AS b_name, b.id AS b_id, b.avatar AS b_avatar
       FROM conversations c
       JOIN users a ON a.id = c.user_low_id
       JOIN users b ON b.id = c.user_high_id
       LEFT JOIN messages m ON m.conversation_id = c.id
      GROUP BY c.id, c.last_message_at, a.name, a.id, a.avatar, b.name, b.id, b.avatar
      ORDER BY n DESC, c.last_message_at DESC
      LIMIT 6"
);

$openReports = q_all(
    "SELECT r.*, u.name AS reported_name, v.name AS reporter_name
       FROM reports r
       JOIN users u ON u.id = r.reported_user_id
       JOIN users v ON v.id = r.reporter_id
      WHERE r.status = 'open'
      ORDER BY r.created_at DESC LIMIT 5"
);

page_head('Admin dashboard', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'dash',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<h1>Dashboard</h1>
<p class="muted">Everything happening on <?= e(SITE_NAME) ?> right now.</p>

<div class="stat-grid" style="margin-bottom:26px">
  <div class="card stat">
    <div class="stat__label">Members</div>
    <div class="stat__value"><?= $stats['members'] ?></div>
    <div class="stat__sub"><?= $stats['week'] ?> joined this week</div>
  </div>
  <div class="card stat">
    <div class="stat__label">Online now</div>
    <div class="stat__value"><?= $stats['online'] ?></div>
    <div class="stat__sub">seen in the last <?= (int)ONLINE_WINDOW_MIN ?> min</div>
  </div>
  <div class="card stat">
    <div class="stat__label">Conversations</div>
    <div class="stat__value"><?= $stats['convos'] ?></div>
    <div class="stat__sub"><?= $stats['messages'] ?> messages in total</div>
  </div>
  <div class="card stat">
    <div class="stat__label">Messages today</div>
    <div class="stat__value"><?= $stats['today'] ?></div>
    <div class="stat__sub">since midnight UTC</div>
  </div>
  <div class="card stat">
    <div class="stat__label">Open reports</div>
    <div class="stat__value" style="<?= $stats['reports'] ? 'color:var(--danger)' : '' ?>"><?= $stats['reports'] ?></div>
    <div class="stat__sub"><a href="reports.php">Review reports</a></div>
  </div>
  <div class="card stat">
    <div class="stat__label">Waiting for approval</div>
    <div class="stat__value" style="<?= $stats['pending'] ? 'color:var(--warn)' : '' ?>"><?= $stats['pending'] ?></div>
    <div class="stat__sub"><?= $stats['suspended'] ?> suspended &middot; <?= $stats['blocks'] ?> blocks</div>
  </div>
</div>

<?php if ($openReports): ?>
<div class="card" style="margin-bottom:24px">
  <div class="card__head"><h3>Reports waiting for you</h3>
    <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="reports.php">See all</a></div>
  <div class="table-scroll">
    <table class="data">
      <thead><tr><th>Reported</th><th>Reason</th><th>By</th><th>When</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($openReports as $r): ?>
          <tr>
            <td><a href="user.php?id=<?= (int)$r['reported_user_id'] ?>"><?= e($r['reported_name']) ?></a></td>
            <td><?= e($r['reason']) ?></td>
            <td class="muted"><?= e($r['reporter_name']) ?></td>
            <td class="muted nowrap"><?= e(time_ago($r['created_at'])) ?></td>
            <td class="actions"><a class="btn btn--ghost btn--sm" href="reports.php#r<?= (int)$r['id'] ?>">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:24px" class="admin-two">
  <div class="card">
    <div class="card__head"><h3>Newest members</h3>
      <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="users.php">All members</a></div>
    <div class="card__body" style="padding-top:8px">
      <?php if (!$newest): ?>
        <p class="muted small">Nobody has signed up yet.</p>
      <?php else: foreach ($newest as $u): ?>
        <div style="display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line)">
          <?= avatar_html($u, 36) ?>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.92rem"><?= e($u['name']) ?></div>
            <div class="tiny muted"><?= e($u['city'] ?: 'No town') ?> &middot; joined <?= e(time_ago($u['created_at'])) ?></div>
          </div>
          <?php
            $chipClass = ['active' => 'chip--accent', 'pending' => 'chip--warn', 'suspended' => 'chip--danger'];
          ?>
          <span class="chip <?= e($chipClass[$u['status']] ?? '') ?>"><?= e($u['status']) ?></span>
          <a class="btn btn--quiet btn--sm" href="user.php?id=<?= (int)$u['id'] ?>">Open</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card__head"><h3>Busiest conversations</h3>
      <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="conversations.php">All conversations</a></div>
    <div class="card__body" style="padding-top:8px">
      <?php if (!$busiest || (int)$busiest[0]['n'] === 0): ?>
        <p class="muted small">No messages have been sent yet.</p>
      <?php else: foreach ($busiest as $c): if ((int)$c['n'] === 0) continue; ?>
        <div style="display:flex;align-items:center;gap:11px;padding:9px 0;border-bottom:1px solid var(--line)">
          <span style="display:flex">
            <?= avatar_html(['id' => $c['a_id'], 'name' => $c['a_name'], 'avatar' => $c['a_avatar']], 32) ?>
            <span style="margin-left:-10px"><?= avatar_html(['id' => $c['b_id'], 'name' => $c['b_name'], 'avatar' => $c['b_avatar']], 32) ?></span>
          </span>
          <div style="flex:1;min-width:0">
            <div style="font-weight:600;font-size:.92rem"><?= e($c['a_name']) ?> &amp; <?= e($c['b_name']) ?></div>
            <div class="tiny muted"><?= (int)$c['n'] ?> messages &middot; <?= e(time_ago($c['last_message_at'])) ?></div>
          </div>
          <a class="btn btn--quiet btn--sm" href="conversation.php?id=<?= (int)$c['id'] ?>">Read</a>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>

<style>@media (max-width: 900px){ .admin-two{ grid-template-columns:1fr !important } }</style>

<?php page_foot(); ?>
