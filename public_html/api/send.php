<?php
/** Post a message into a conversation. Called by assets/js/chat.js. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/chat.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

start_session();
$me = current_user();

if (!$me) {
    json_out(['ok' => false, 'error' => 'Please log in again.'], 401);
}
if (!csrf_ok($_POST['csrf'] ?? null)) {
    json_out(['ok' => false, 'error' => 'Your session expired. Please reload the page.'], 400);
}
if ($me['status'] !== 'active') {
    json_out(['ok' => false, 'error' => 'Your profile is not approved for messaging yet.'], 403);
}

session_write_close();

[$ok, $result] = send_message(
    (int)($_POST['conversation_id'] ?? 0),
    (int)$me['id'],
    (string)($_POST['body'] ?? '')
);

if (!$ok) {
    json_out(['ok' => false, 'error' => $result], 400);
}

$ts = strtotime($result['created_at'] . ' UTC');
$day = date('Y-m-d', $ts);

json_out([
    'ok'      => true,
    'message' => [
        'id'        => (int)$result['id'],
        'sender_id' => (int)$result['sender_id'],
        'body'      => $result['body'],
        'time'      => date('H:i', $ts),
        'day'       => $day,
        'day_label' => $day === date('Y-m-d') ? 'Today' : date('j M Y', $ts),
        'read'      => false,
    ],
]);
