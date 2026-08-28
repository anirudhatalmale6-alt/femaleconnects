<?php
/** Admin: every member, with approve / suspend / delete. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = (string)($_POST['action'] ?? '');
    $userId = (int)($_POST['user_id'] ?? 0);
    $target = q_row('SELECT * FROM users WHERE id = ?', [$userId]);

    if (!$target) {
        flash('error', 'That member no longer exists.');
        redirect('users.php');
    }
    if ((int)$target['id'] === (int)$admin['id'] && $action !== 'note') {
        flash('error', 'You cannot change your own account from here.');
        redirect('users.php');
    }

    switch ($action) {
        case 'approve':
            q("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
            admin_log('approve_user', 'user', $userId, $target['name']);
            flash('success', $target['name'] . ' is approved and can now message members.');
            break;

        case 'suspend':
            q("UPDATE users SET status = 'suspended' WHERE id = ?", [$userId]);
            admin_log('suspend_user', 'user', $userId, $target['name']);
            flash('success', $target['name'] . ' is suspended. She is logged out and cannot sign back in.');
            break;

        case 'reinstate':
            q("UPDATE users SET status = 'active' WHERE id = ?", [$userId]);
            admin_log('reinstate_user', 'user', $userId, $target['name']);
            flash('success', $target['name'] . ' is active again.');
            break;

        case 'delete':
            // Conversations, messages, blocks and reports cascade away with her.
            delete_avatar($target['avatar']);
            q('DELETE FROM users WHERE id = ?', [$userId]);
            admin_log('delete_user', 'user', $userId, $target['name'] . ' <' . $target['email'] . '>');
            flash('success', $target['name'] . ' and all of her messages have been deleted.');
            break;

        default:
            flash('error', 'Unknown action.');
    }
    redirect('users.php' . (!empty($_POST['back']) ? '?' . (string)$_POST['back'] : ''));
}

$search = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 30;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(u.name LIKE :s1 OR u.email LIKE :s2 OR u.city LIKE :s3)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    $params['s1'] = $params['s2'] = $params['s3'] = $like;
}
if (in_array($status, ['active', 'pending', 'suspended'], true)) {
    $where[] = 'u.status = :st';
    $params['st'] = $status;
}

$sql   = 'FROM users u WHERE ' . implode(' AND ', $where);
$total = (int)q_val('SELECT COUNT(*) ' . $sql, $params);
$rows  = q_all(
    'SELECT u.*,
            (SELECT COUNT(*) FROM messages m WHERE m.sender_id = u.id) AS sent,
            (SELECT COUNT(*) FROM conversations c
              WHERE c.user_low_id = u.id OR c.user_high_id = u.id)     AS convos,
            (SELECT COUNT(*) FROM reports r
              WHERE r.reported_user_id = u.id AND r.status = \'open\') AS open_reports
     ' . $sql . ' ORDER BY u.created_at DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per),
    $params
);
$pages = max(1, (int)ceil($total / $per));
$back  = http_build_query(array_filter(['q' => $search, 'status' => $status, 'page' => $page > 1 ? $page : '']));

$chipClass = ['active' => 'chip--accent', 'pending' => 'chip--warn', 'suspended' => 'chip--danger'];

page_head('Members', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'users',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<h1>Members</h1>
<p class="muted"><?= (int)$total ?> account<?= $total === 1 ? '' : 's' ?> matching this view.</p>

<form class="toolbar" method="get">
  <div class="field toolbar__grow">
    <label for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="name, email or town">
  </div>
  <div class="field" style="flex:0 0 180px">
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All statuses</option>
      <?php foreach (['active', 'pending', 'suspended'] as $s): ?>
        <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn" type="submit">Filter</button>
  <?php if ($search !== '' || $status !== ''): ?>
    <a class="btn btn--ghost" href="users.php">Clear</a>
  <?php endif; ?>
</form>

<div class="card table-scroll">
  <table class="data">
    <thead>
      <tr>
        <th>Member</th><th>Email</th><th>Town</th><th>Status</th>
        <th>Chats</th><th>Sent</th><th>Joined</th><th>Last seen</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="9" class="center muted" style="padding:32px">No members match that filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $u): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <?= avatar_html($u, 32) ?>
              <div>
                <a href="user.php?id=<?= (int)$u['id'] ?>" style="font-weight:600"><?= e($u['name']) ?></a>
                <?php if ($u['role'] === 'admin'): ?>
                  <span class="chip chip--brand">admin</span>
                <?php endif; ?>
                <?php if ((int)$u['open_reports'] > 0): ?>
                  <span class="chip chip--danger"><?= (int)$u['open_reports'] ?> report<?= (int)$u['open_reports'] === 1 ? '' : 's' ?></span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="muted small"><?= e($u['email']) ?></td>
          <td class="muted small"><?= e($u['city'] ?: '-') ?></td>
          <td><span class="chip <?= e($chipClass[$u['status']] ?? '') ?>"><?= e($u['status']) ?></span></td>
          <td class="muted"><?= (int)$u['convos'] ?></td>
          <td class="muted"><?= (int)$u['sent'] ?></td>
          <td class="muted small nowrap"><?= e(date('j M Y', strtotime($u['created_at'] . ' UTC'))) ?></td>
          <td class="muted small nowrap"><?= $u['last_seen_at'] ? e(time_ago($u['last_seen_at'])) : 'never' ?></td>
          <td class="actions">
            <a class="btn btn--ghost btn--sm" href="user.php?id=<?= (int)$u['id'] ?>">Open</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:22px">
    <?php
      $link = static function ($n) use ($search, $status) {
          $qs = array_filter(['q' => $search, 'status' => $status, 'page' => $n > 1 ? $n : '']);
          return 'users.php' . ($qs ? '?' . http_build_query($qs) : '');
      };
    ?>
    <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($link($page - 1)) ?>">Previous</a><?php endif; ?>
    <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e($link($page + 1)) ?>">Next</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php page_foot(); ?>
