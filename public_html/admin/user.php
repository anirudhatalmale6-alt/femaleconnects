<?php
/** Admin: one member in full - profile, conversations, reports, actions. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();

$id = (int)($_GET['id'] ?? 0);
$u  = q_row('SELECT * FROM users WHERE id = ?', [$id]);

if (!$u) {
    page_head('Member not found', ['active' => 'admin', 'adminBar' => true, 'adminActive' => 'users']);
    echo '<div class="card empty"><h3>That member does not exist</h3>'
       . '<p style="margin-top:16px"><a class="btn" href="users.php">Back to members</a></p></div>';
    page_foot();
    exit;
}

$convos = q_all(
    "SELECT c.id, c.last_message_at,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS n,
            p.id AS partner_id, p.name AS partner_name, p.avatar AS partner_avatar
       FROM conversations c
       JOIN users p ON p.id = CASE WHEN c.user_low_id = ? THEN c.user_high_id ELSE c.user_low_id END
      WHERE c.user_low_id = ? OR c.user_high_id = ?
      ORDER BY c.last_message_at IS NULL, c.last_message_at DESC",
    [$id, $id, $id]
);

$reportsAgainst = q_all(
    "SELECT r.*, v.name AS reporter_name
       FROM reports r JOIN users v ON v.id = r.reporter_id
      WHERE r.reported_user_id = ? ORDER BY r.created_at DESC",
    [$id]
);
$reportsBy = (int)q_val('SELECT COUNT(*) FROM reports WHERE reporter_id = ?', [$id]);
$blocksOut = (int)q_val('SELECT COUNT(*) FROM blocks WHERE blocker_id = ?', [$id]);
$blocksIn  = (int)q_val('SELECT COUNT(*) FROM blocks WHERE blocked_id = ?', [$id]);
$sent      = (int)q_val('SELECT COUNT(*) FROM messages WHERE sender_id = ?', [$id]);

$chipClass = ['active' => 'chip--accent', 'pending' => 'chip--warn', 'suspended' => 'chip--danger'];
$isMe      = (int)$u['id'] === (int)$admin['id'];

page_head($u['name'], [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'users',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<p><a class="btn btn--quiet btn--sm" href="users.php"><?= icon('back', 16) ?> All members</a></p>

<div class="card" style="margin-bottom:22px">
  <div class="card__body">
    <div class="profile__head">
      <span class="avatar-wrap">
        <?= avatar_html($u, 84) ?>
        <span class="dot <?= is_online($u['last_seen_at']) ? 'is-online' : '' ?>"></span>
      </span>
      <div>
        <h1 style="margin-bottom:6px">
          <?= e($u['name']) ?>
          <span class="chip <?= e($chipClass[$u['status']] ?? '') ?>"><?= e($u['status']) ?></span>
          <?php if ($u['role'] === 'admin'): ?><span class="chip chip--brand">admin</span><?php endif; ?>
        </h1>
        <p class="muted small" style="margin:0"><?= e($u['email']) ?></p>
        <p class="muted tiny" style="margin:4px 0 0">
          Joined <?= e(pretty_date($u['created_at'])) ?> &middot;
          <?= $u['last_seen_at'] ? 'last seen ' . e(time_ago($u['last_seen_at'])) : 'never signed back in' ?>
          <?php if ($u['last_ip']): ?> &middot; last IP <?= e($u['last_ip']) ?><?php endif; ?>
        </p>
      </div>

      <?php if (!$isMe): ?>
      <div class="profile__actions">
        <?php if ($u['status'] === 'pending'): ?>
          <form method="post" action="users.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn" type="submit">Approve</button>
          </form>
        <?php endif; ?>

        <?php if ($u['status'] === 'suspended'): ?>
          <form method="post" action="users.php" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="action" value="reinstate">
            <button class="btn" type="submit">Reinstate</button>
          </form>
        <?php else: ?>
          <form method="post" action="users.php" style="display:inline"
                onsubmit="return confirm('Suspend <?= e($u['name']) ?>? She will be logged out and cannot sign back in.');">
            <?= csrf_field() ?>
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="action" value="suspend">
            <button class="btn btn--ghost" type="submit">Suspend</button>
          </form>
        <?php endif; ?>

        <form method="post" action="users.php" style="display:inline"
              onsubmit="return confirm('Permanently delete <?= e($u['name']) ?> and every message she has sent? This cannot be undone.');">
          <?= csrf_field() ?>
          <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn--danger" type="submit">Delete</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

    <dl class="deflist">
      <dt>Age</dt>         <dd><?= ($a = age_from($u['birth_date'])) ? e($a) : '<span class="muted">not given</span>' ?></dd>
      <dt>Town or city</dt><dd><?= $u['city'] ? e($u['city']) : '<span class="muted">not given</span>' ?></dd>
      <dt>Interests</dt>
      <dd>
        <?php $tags = interest_list($u['interests']); ?>
        <?php if ($tags): ?>
          <span class="chip-set"><?php foreach ($tags as $t): ?><span class="chip"><?= e($t) ?></span><?php endforeach; ?></span>
        <?php else: ?><span class="muted">none listed</span><?php endif; ?>
      </dd>
      <dt>Bio</dt>         <dd><?= $u['bio'] ? nl2br(e($u['bio']), false) : '<span class="muted">no bio</span>' ?></dd>
      <dt>Activity</dt>
      <dd><?= $sent ?> messages sent &middot; <?= count($convos) ?> conversations &middot;
          blocked <?= $blocksOut ?> &middot; blocked by <?= $blocksIn ?> &middot;
          filed <?= $reportsBy ?> report<?= $reportsBy === 1 ? '' : 's' ?></dd>
    </dl>
  </div>
</div>

<?php if ($reportsAgainst): ?>
<div class="card" style="margin-bottom:22px">
  <div class="card__head"><h3>Reports about <?= e($u['name']) ?></h3></div>
  <div class="table-scroll">
    <table class="data">
      <thead><tr><th>Reason</th><th>Details</th><th>From</th><th>When</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($reportsAgainst as $r): ?>
          <tr>
            <td><?= e($r['reason']) ?></td>
            <td class="muted small"><?= $r['details'] ? e($r['details']) : '-' ?></td>
            <td class="muted small"><?= e($r['reporter_name']) ?></td>
            <td class="muted small nowrap"><?= e(time_ago($r['created_at'])) ?></td>
            <td><span class="chip <?= $r['status'] === 'open' ? 'chip--danger' : '' ?>"><?= e($r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card__head"><h3>Conversations</h3>
    <span class="muted small" style="margin-left:auto"><?= count($convos) ?> in total</span></div>
  <div class="table-scroll">
    <table class="data">
      <thead><tr><th>With</th><th>Messages</th><th>Last message</th><th></th></tr></thead>
      <tbody>
        <?php if (!$convos): ?>
          <tr><td colspan="4" class="center muted" style="padding:28px">No conversations yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($convos as $c): ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:10px">
                <?= avatar_html(['id' => $c['partner_id'], 'name' => $c['partner_name'], 'avatar' => $c['partner_avatar']], 30) ?>
                <a href="user.php?id=<?= (int)$c['partner_id'] ?>"><?= e($c['partner_name']) ?></a>
              </div>
            </td>
            <td class="muted"><?= (int)$c['n'] ?></td>
            <td class="muted small nowrap"><?= $c['last_message_at'] ? e(time_ago($c['last_message_at'])) : 'never' ?></td>
            <td class="actions"><a class="btn btn--ghost btn--sm" href="conversation.php?id=<?= (int)$c['id'] ?>">Read</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php page_foot(); ?>
