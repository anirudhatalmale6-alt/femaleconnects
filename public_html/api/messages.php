<?php
/**
 * Poll endpoint: returns anything in this conversation newer than ?after=<id>.
 *
 *   GET api/messages.php?c=12&after=340
 */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/chat.php';

start_session();
$me = current_user();

// The session lock is released here so a hanging poll never blocks the rest
// of the site in another tab.
session_write_close();

if (!$me) {
    json_out(['ok' => false, 'error' => 'Please log in again.'], 401);
}

$convId = (int)($_GET['c'] ?? 0);
$after  = max(0, (int)($_GET['after'] ?? 0));
$meId   = (int)$me['id'];

$conv = q_row('SELECT * FROM conversations WHERE id = ?', [$convId]);
if (!$conv || !user_in_conversation($conv, $meId)) {
    json_out(['ok' => false, 'error' => 'Conversation not available.'], 403);
}

$partnerId = conversation_partner_id($conv, $meId);
$partner   = q_row('SELECT id, name, status, last_seen_at FROM users WHERE id = ?', [$partnerId]);

$rows = fetch_messages($convId, $after);

// Anything the other person just sent counts as read the moment it lands on
// screen - but only mark when this poll actually carried one of her messages.
foreach ($rows as $row) {
    if ((int)$row['sender_id'] !== $meId) {
        mark_read($convId, $meId);
        break;
    }
}

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$out = [];
foreach ($rows as $m) {
    $ts  = strtotime($m['created_at'] . ' UTC');
    $day = date('Y-m-d', $ts);
    $out[] = [
        'id'        => (int)$m['id'],
        'sender_id' => (int)$m['sender_id'],
        'body'      => $m['body'],
        'time'      => date('H:i', $ts),
        'day'       => $day,
        'day_label' => $day === $today ? 'Today' : ($day === $yesterday ? 'Yesterday' : date('j M Y', $ts)),
        'read'      => $m['read_at'] !== null,
    ];
}

// Highest id of my own messages the other person has now read, so the browser
// can flip "Sent" to "Read" without re-rendering the thread.
$readUpTo = (int)q_val(
    'SELECT COALESCE(MAX(id), 0) FROM messages
      WHERE conversation_id = ? AND sender_id = ? AND read_at IS NOT NULL',
    [$convId, $meId]
);

json_out([
    'ok'         => true,
    'messages'   => $out,
    'read_up_to' => $readUpTo,
    'partner'    => [
        'id'       => (int)$partner['id'],
        'presence' => $partner['status'] !== 'active'
            ? 'No longer available'
            : (is_online($partner['last_seen_at'])
                ? 'Online now'
                : ($partner['last_seen_at'] ? 'Last seen ' . time_ago($partner['last_seen_at']) : 'Not been back yet')),
    ],
]);
