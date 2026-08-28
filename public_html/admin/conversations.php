<?php
/** Admin: every conversation on the site. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

require_admin();

$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 30;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(a.name LIKE :s1 OR b.name LIKE :s2 OR a.email LIKE :s3 OR b.email LIKE :s4)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = $like;
}

$from = 'FROM conversations c
         JOIN users a ON a.id = c.user_low_id
         JOIN users b ON b.id = c.user_high_id
        WHERE ' . implode(' AND ', $where);

$total = (int)q_val('SELECT COUNT(*) ' . $from, $params);

$rows = q_all(
    'SELECT c.id, c.created_at, c.last_message_at,
            a.id AS a_id, a.name AS a_name, a.avatar AS a_avatar,
            b.id AS b_id, b.name AS b_name, b.avatar AS b_avatar,
            (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) AS n,
            (SELECT m.body FROM messages m WHERE m.conversation_id = c.id
              ORDER BY m.id DESC LIMIT 1) AS last_body
     ' . $from . '
     ORDER BY c.last_message_at IS NULL, c.last_message_at DESC, c.id DESC
     LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per),
    $params
);

$pages = max(1, (int)ceil($total / $per));

page_head('Conversations', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'convos',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<h1>Conversations</h1>
<p class="muted"><?= (int)$total ?> conversation<?= $total === 1 ? '' : 's' ?> on the site. You can read any of them.</p>

<form class="toolbar" method="get">
  <div class="field toolbar__grow">
    <label for="q">Search by member</label>
    <input type="search" id="q" name="q" value="<?= e($search) ?>" placeholder="name or email of either member">
  </div>
  <button class="btn" type="submit">Search</button>
  <?php if ($search !== ''): ?><a class="btn btn--ghost" href="conversations.php">Clear</a><?php endif; ?>
</form>

<div class="card table-scroll">
  <table class="data">
    <thead><tr><th>Between</th><th>Messages</th><th>Latest message</th><th>Last activity</th><th>Started</th><th></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="6" class="center muted" style="padding:32px">No conversations match that search.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $c): ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:9px">
              <span style="display:flex">
                <?= avatar_html(['id' => $c['a_id'], 'name' => $c['a_name'], 'avatar' => $c['a_avatar']], 28) ?>
                <span style="margin-left:-8px"><?= avatar_html(['id' => $c['b_id'], 'name' => $c['b_name'], 'avatar' => $c['b_avatar']], 28) ?></span>
              </span>
              <span>
                <a href="user.php?id=<?= (int)$c['a_id'] ?>"><?= e($c['a_name']) ?></a>
                <span class="muted">and</span>
                <a href="user.php?id=<?= (int)$c['b_id'] ?>"><?= e($c['b_name']) ?></a>
              </span>
            </div>
          </td>
          <td class="muted"><?= (int)$c['n'] ?></td>
          <td class="muted small" style="max-width:320px">
            <?= $c['last_body'] !== null
                  ? e(mb_strimwidth($c['last_body'], 0, 70, '...', 'UTF-8'))
                  : '<em>no messages yet</em>' ?>
          </td>
          <td class="muted small nowrap"><?= $c['last_message_at'] ? e(time_ago($c['last_message_at'])) : '-' ?></td>
          <td class="muted small nowrap"><?= e(date('j M Y', strtotime($c['created_at'] . ' UTC'))) ?></td>
          <td class="actions"><a class="btn btn--ghost btn--sm" href="conversation.php?id=<?= (int)$c['id'] ?>">Read</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($pages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:22px">
    <?php $link = static fn($n) => 'conversations.php?' . http_build_query(array_filter(['q' => $search, 'page' => $n > 1 ? $n : ''])); ?>
    <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($link($page - 1)) ?>">Previous</a><?php endif; ?>
    <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e($link($page + 1)) ?>">Next</a><?php endif; ?>
  </div>
<?php endif; ?>

<?php page_foot(); ?>
