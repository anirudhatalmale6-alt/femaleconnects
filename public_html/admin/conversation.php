<?php
/** Admin: read one conversation in full. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

require_admin();

$id   = (int)($_GET['id'] ?? 0);
$conv = q_row('SELECT * FROM conversations WHERE id = ?', [$id]);

if (!$conv) {
    page_head('Conversation not found', ['active' => 'admin', 'adminBar' => true, 'adminActive' => 'convos']);
    echo '<div class="card empty"><h3>That conversation does not exist</h3>'
       . '<p style="margin-top:16px"><a class="btn" href="conversations.php">Back to conversations</a></p></div>';
    page_foot();
    exit;
}

$a = q_row('SELECT * FROM users WHERE id = ?', [$conv['user_low_id']]);
$b = q_row('SELECT * FROM users WHERE id = ?', [$conv['user_high_id']]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'delete_message') {
        $mid = (int)($_POST['message_id'] ?? 0);
        $msg = q_row('SELECT * FROM messages WHERE id = ? AND conversation_id = ?', [$mid, $id]);
        if ($msg) {
            q('DELETE FROM messages WHERE id = ?', [$mid]);
            admin_log('delete_message', 'message', $mid,
                      'conversation ' . $id . ': ' . mb_substr($msg['body'], 0, 120));
            flash('success', 'Message deleted.');
        }
        redirect('conversation.php?id=' . $id);
    }
}

$messages = q_all(
    'SELECT m.*, u.name AS sender_name, u.avatar AS sender_avatar
       FROM messages m JOIN users u ON u.id = m.sender_id
      WHERE m.conversation_id = ? ORDER BY m.id ASC',
    [$id]
);

$blockAB = has_blocked((int)$a['id'], (int)$b['id']);
$blockBA = has_blocked((int)$b['id'], (int)$a['id']);

page_head('Conversation', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'convos',
    'wrap'   => 'wrap page',
]);
?>

<p><a class="btn btn--quiet btn--sm" href="conversations.php"><?= icon('back', 16) ?> All conversations</a></p>

<div class="card">
  <div class="card__head" style="flex-wrap:wrap">
    <span style="display:flex">
      <?= avatar_html($a, 34) ?>
      <span style="margin-left:-9px"><?= avatar_html($b, 34) ?></span>
    </span>
    <div>
      <h3 style="margin:0">
        <a href="user.php?id=<?= (int)$a['id'] ?>" style="color:inherit"><?= e($a['name']) ?></a>
        <span class="muted" style="font-weight:400">and</span>
        <a href="user.php?id=<?= (int)$b['id'] ?>" style="color:inherit"><?= e($b['name']) ?></a>
      </h3>
      <div class="tiny muted">
        <?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?> &middot;
        started <?= e(pretty_date($conv['created_at'])) ?>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap">
      <?php if ($blockAB): ?><span class="chip chip--danger"><?= e($a['name']) ?> blocked <?= e($b['name']) ?></span><?php endif; ?>
      <?php if ($blockBA): ?><span class="chip chip--danger"><?= e($b['name']) ?> blocked <?= e($a['name']) ?></span><?php endif; ?>
    </div>
  </div>

  <div class="transcript">
    <?php if (!$messages): ?>
      <p class="muted center" style="padding:26px">No messages in this conversation yet.</p>
    <?php endif; ?>

    <?php
    $lastDay = '';
    foreach ($messages as $m):
        $ts  = strtotime($m['created_at'] . ' UTC');
        $day = date('Y-m-d', $ts);
        if ($day !== $lastDay):
            $lastDay = $day; ?>
            <div class="day-split"><?= e(date('j M Y', $ts)) ?></div>
        <?php endif;
        $fromA = (int)$m['sender_id'] === (int)$a['id']; ?>
        <div class="msg <?= $fromA ? 'msg--in' : 'msg--out' ?>">
          <div class="msg__bubble"><?= e($m['body']) ?></div>
          <div class="msg__meta">
            <span><?= e($m['sender_name']) ?> &middot; <?= e(date('H:i', $ts)) ?></span>
            <span><?= $m['read_at'] ? 'read' : 'unread' ?></span>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Delete this message permanently?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_message">
              <input type="hidden" name="message_id" value="<?= (int)$m['id'] ?>">
              <button type="submit" class="btn btn--quiet btn--sm"
                      style="padding:0 4px;font-size:.7rem;color:var(--danger)">delete</button>
            </form>
          </div>
        </div>
    <?php endforeach; ?>
  </div>
</div>

<p class="small muted" style="margin-top:16px">
  Members cannot see this screen &mdash; each member only ever sees her own conversations.
</p>

<?php page_foot(); ?>
