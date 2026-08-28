<?php
/** Unread count for the badge in the header. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/chat.php';

start_session();
$me = current_user();
session_write_close();

if (!$me) {
    json_out(['ok' => false, 'unread' => 0], 401);
}

json_out(['ok' => true, 'unread' => unread_total((int)$me['id'])]);
