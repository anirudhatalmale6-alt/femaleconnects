<?php
/**
 * Messages.
 *
 *   chat.php              -> inbox, nothing open
 *   chat.php?c=12         -> open conversation 12
 *   chat.php?with=7       -> open (or start) the conversation with member 7
 */

require_once __DIR__ . '/includes/layout.php';

$me   = require_login();
$meId = (int)$me['id'];

if ($me['status'] === 'pending') {
    page_head('Messages', ['active' => 'chat']);
    echo '<div class="card empty"><h3>Messaging opens once your profile is approved</h3>'
       . '<p>The admin reviews new profiles by hand. You can browse members in the meantime.</p>'
       . '<p style="margin-top:16px"><a class="btn" href="members.php">Browse members</a></p></div>';
    page_foot();
    exit;
}

$conv    = null;
$partner = null;

// ---- opening a chat from a member's profile -------------------------------
if (isset($_GET['with'])) {
    $withId = (int)$_GET['with'];
    $target = q_row("SELECT * FROM users WHERE id = ? AND status = 'active' AND role = 'member'", [$withId]);

    if (!$target || $withId === $meId) {
        flash('error', 'That member is not available.');
        redirect('chat.php');
    }
    if (block_between($meId, $withId)) {
        flash('error', 'That conversation is not available.');
        redirect('members.php');
    }
    $conv = get_or_create_conversation($meId, $withId);
    if ($conv) {
        redirect('chat.php?c=' . (int)$conv['id']);   // clean URL, safe to refresh
    }
}

// ---- opening an existing thread -------------------------------------------
if (isset($_GET['c'])) {
    $conv = q_row('SELECT * FROM conversations WHERE id = ?', [(int)$_GET['c']]);
    if (!$conv || !user_in_conversation($conv, $meId)) {
        flash('error', 'That conversation is not available.');
        redirect('chat.php');
    }
    $partnerId = conversation_partner_id($conv, $meId);
    $partner   = q_row('SELECT * FROM users WHERE id = ?', [$partnerId]);
    mark_read((int)$conv['id'], $meId);
}

$threads  = list_conversations($meId);
$messages = $conv ? fetch_messages((int)$conv['id']) : [];
$locked   = $partner && (block_between($meId, (int)$partner['id']) || $partner['status'] !== 'active');

page_head($partner ? 'Chat with ' . $partner['name'] : 'Messages', [
    'active' => 'chat',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<div class="chat <?= $conv ? 'has-open' : '' ?>">

  <!-- ------------------------------------------------------- inbox list -->
  <aside class="threads">
    <div class="threads__head">
      <h2>Messages</h2>
      <a class="btn btn--ghost btn--sm" style="margin-left:auto" href="members.php">Find someone</a>
    </div>
    <div class="threads__list">
      <?php if (!$threads): ?>
        <div class="empty" style="padding:34px 22px">
          <p class="small">No conversations yet.<br>Open a member's profile and say hello.</p>
        </div>
      <?php else: ?>
        <?php foreach ($threads as $t): ?>
          <a class="thread <?= $conv && (int)$t['id'] === (int)$conv['id'] ? 'is-active' : '' ?>"
             href="chat.php?c=<?= (int)$t['id'] ?>">
            <span class="avatar-wrap">
              <?= avatar_html(['id' => $t['partner_id'], 'name' => $t['partner_name'], 'avatar' => $t['partner_avatar']], 42) ?>
              <span class="dot <?= is_online($t['partner_last_seen']) ? 'is-online' : '' ?>"></span>
            </span>
            <span class="thread__main">
              <span class="thread__row">
                <span class="thread__name"><?= e($t['partner_name']) ?></span>
                <span class="thread__time"><?= e(time_ago($t['last_message_at'])) ?></span>
              </span>
              <span class="thread__last">
                <?php
                  if ($t['last_body'] === null) {
                      echo '<em>No messages yet</em>';
                  } else {
                      $prefix = (int)$t['last_sender_id'] === $meId ? 'You: ' : '';
                      echo e($prefix . mb_strimwidth($t['last_body'], 0, 46, '...', 'UTF-8'));
                  }
                ?>
              </span>
            </span>
            <?php if ((int)$t['unread'] > 0 && (!$conv || (int)$t['id'] !== (int)$conv['id'])): ?>
              <span class="thread__unread"><?= (int)$t['unread'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- --------------------------------------------------- open conversation -->
  <section class="convo">
    <?php if (!$conv): ?>
      <div class="convo__placeholder">
        <div>
          <div style="color:var(--line-strong);margin-bottom:10px"><?= icon('chat', 46) ?></div>
          <h3>Pick a conversation</h3>
          <p class="small">Choose someone on the left, or
             <a href="members.php">browse the members</a> to start a new chat.</p>
        </div>
      </div>
    <?php else: ?>

      <header class="convo__head">
        <a class="btn btn--quiet btn--sm convo__back" href="chat.php"><?= icon('back', 16) ?></a>
        <a href="profile.php?id=<?= (int)$partner['id'] ?>" class="avatar-wrap">
          <?= avatar_html($partner, 40) ?>
          <span class="dot <?= is_online($partner['last_seen_at']) ? 'is-online' : '' ?>"></span>
        </a>
        <div class="convo__who">
          <strong><a href="profile.php?id=<?= (int)$partner['id'] ?>" style="color:inherit"><?= e($partner['name']) ?></a></strong>
          <span data-presence>
            <?= is_online($partner['last_seen_at'])
                  ? 'Online now'
                  : ($partner['last_seen_at'] ? 'Last seen ' . e(time_ago($partner['last_seen_at'])) : 'Not been back yet') ?>
          </span>
        </div>
        <a class="btn btn--ghost btn--sm" href="report.php?user=<?= (int)$partner['id'] ?>">Report</a>
        <a class="btn btn--ghost btn--sm" href="profile.php?id=<?= (int)$partner['id'] ?>">Profile</a>
      </header>

      <div class="convo__body" id="messages"
           data-conversation="<?= (int)$conv['id'] ?>"
           data-me="<?= $meId ?>"
           data-poll="<?= (int)CHAT_POLL_MS ?>"
           data-last="<?= $messages ? (int)end($messages)['id'] : 0 ?>">
        <?php if (!$messages): ?>
          <div class="convo__placeholder" style="flex:1" data-empty-note>
            <div>
              <h3>Say hello to <?= e($partner['name']) ?></h3>
              <p class="small">One friendly line is plenty. Ask about something on her profile.</p>
            </div>
          </div>
        <?php endif; ?>

        <?php
        $lastDay = '';
        foreach ($messages as $m):
            $day = date('Y-m-d', strtotime($m['created_at'] . ' UTC'));
            if ($day !== $lastDay):
                $lastDay = $day;
                $label = $day === date('Y-m-d') ? 'Today'
                       : ($day === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday'
                       : date('j M Y', strtotime($m['created_at'] . ' UTC')));
        ?>
          <div class="day-split" data-day="<?= e($day) ?>"><?= e($label) ?></div>
        <?php endif; $mine = (int)$m['sender_id'] === $meId; ?>
          <div class="msg <?= $mine ? 'msg--out' : 'msg--in' ?>" data-id="<?= (int)$m['id'] ?>">
            <div class="msg__bubble"><?= e($m['body']) ?></div>
            <div class="msg__meta">
              <span><?= e(date('H:i', strtotime($m['created_at'] . ' UTC'))) ?></span>
              <?php if ($mine): ?>
                <span class="msg__tick" data-tick><?= $m['read_at'] ? 'Read' : 'Sent' ?></span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($locked): ?>
        <div class="composer__locked">
          <?= icon('lock', 16) ?>
          <?= $partner['status'] !== 'active'
                ? 'This member is no longer available.'
                : 'This conversation is closed. You can manage blocks on your profile page.' ?>
        </div>
      <?php else: ?>
        <form class="composer" id="composer" method="post" action="api/send.php">
          <?= csrf_field() ?>
          <input type="hidden" name="conversation_id" value="<?= (int)$conv['id'] ?>">
          <textarea name="body" id="composer-body" rows="1"
                    maxlength="<?= (int)MESSAGE_MAX_CHARS ?>"
                    placeholder="Message <?= e($partner['name']) ?>..."
                    autocomplete="off"></textarea>
          <button class="btn" type="submit" id="composer-send" title="Send">
            <?= icon('send', 18) ?><span class="nowrap">Send</span>
          </button>
        </form>
      <?php endif; ?>

    <?php endif; ?>
  </section>
</div>

<?php page_foot(['scripts' => ['assets/js/chat.js?v=1']]); ?>
