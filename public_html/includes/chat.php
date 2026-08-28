<?php
/**
 * Conversations, messages, blocking.
 *
 * A conversation is one row per pair of members. The pair is always stored
 * with the smaller user id in user_low_id, so a UNIQUE key on the pair makes
 * duplicate threads impossible no matter who opens the chat first.
 */

require_once __DIR__ . '/auth.php';

/** Find the thread between two members, creating it on first message. */
function get_or_create_conversation(int $a, int $b): ?array
{
    if ($a === $b) {
        return null;
    }
    $low  = min($a, $b);
    $high = max($a, $b);

    $conv = q_row('SELECT * FROM conversations WHERE user_low_id = ? AND user_high_id = ?',
                  [$low, $high]);
    if ($conv) {
        return $conv;
    }

    // INSERT IGNORE + re-select handles two browser tabs opening at the same
    // instant: whoever loses the race just reads the row the winner made.
    q('INSERT IGNORE INTO conversations (user_low_id, user_high_id) VALUES (?, ?)', [$low, $high]);

    return q_row('SELECT * FROM conversations WHERE user_low_id = ? AND user_high_id = ?',
                 [$low, $high]);
}

function find_conversation(int $a, int $b): ?array
{
    return q_row('SELECT * FROM conversations WHERE user_low_id = ? AND user_high_id = ?',
                 [min($a, $b), max($a, $b)]);
}

/** The other person in a conversation row. */
function conversation_partner_id(array $conv, int $viewerId): int
{
    return (int)$conv['user_low_id'] === $viewerId
        ? (int)$conv['user_high_id']
        : (int)$conv['user_low_id'];
}

function user_in_conversation(array $conv, int $userId): bool
{
    return (int)$conv['user_low_id'] === $userId || (int)$conv['user_high_id'] === $userId;
}

// ---------------------------------------------------------------------------
//  Blocking
// ---------------------------------------------------------------------------

function has_blocked(int $blockerId, int $blockedId): bool
{
    return (bool)q_val('SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?',
                       [$blockerId, $blockedId]);
}

/** True if either side has blocked the other - the chat is closed both ways. */
function block_between(int $a, int $b): bool
{
    return (bool)q_val(
        'SELECT id FROM blocks
          WHERE (blocker_id = ? AND blocked_id = ?)
             OR (blocker_id = ? AND blocked_id = ?) LIMIT 1',
        [$a, $b, $b, $a]
    );
}

function block_user(int $blockerId, int $blockedId): void
{
    q('INSERT IGNORE INTO blocks (blocker_id, blocked_id) VALUES (?, ?)', [$blockerId, $blockedId]);
}

function unblock_user(int $blockerId, int $blockedId): void
{
    q('DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?', [$blockerId, $blockedId]);
}

// ---------------------------------------------------------------------------
//  Messages
// ---------------------------------------------------------------------------

/**
 * Store a message. Returns [true, $messageRow] or [false, 'reason'].
 */
function send_message(int $conversationId, int $senderId, string $body): array
{
    $body = trim($body);
    if ($body === '') {
        return [false, 'Please type a message first.'];
    }
    if (mb_strlen($body) > MESSAGE_MAX_CHARS) {
        return [false, 'That message is too long (' . MESSAGE_MAX_CHARS . ' characters max).'];
    }

    $conv = q_row('SELECT * FROM conversations WHERE id = ?', [$conversationId]);
    if (!$conv || !user_in_conversation($conv, $senderId)) {
        return [false, 'That conversation is not available.'];
    }

    $partnerId = conversation_partner_id($conv, $senderId);
    if (block_between($senderId, $partnerId)) {
        return [false, 'You cannot send messages in this conversation.'];
    }

    $partner = q_row('SELECT status FROM users WHERE id = ?', [$partnerId]);
    if (!$partner || $partner['status'] === 'suspended') {
        return [false, 'This member is no longer available.'];
    }

    q('INSERT INTO messages (conversation_id, sender_id, body, created_at)
       VALUES (?, ?, ?, UTC_TIMESTAMP())', [$conversationId, $senderId, $body]);
    $id = (int)db()->lastInsertId();

    q('UPDATE conversations SET last_message_at = UTC_TIMESTAMP() WHERE id = ?', [$conversationId]);

    return [true, q_row('SELECT * FROM messages WHERE id = ?', [$id])];
}

/**
 * Messages in a thread. Pass $afterId to fetch only what is new - that is what
 * the browser polls with, so each poll returns almost nothing.
 */
function fetch_messages(int $conversationId, int $afterId = 0, int $limit = 200): array
{
    if ($afterId > 0) {
        return q_all(
            'SELECT m.*, u.name AS sender_name
               FROM messages m
               JOIN users u ON u.id = m.sender_id
              WHERE m.conversation_id = ? AND m.id > ?
              ORDER BY m.id ASC
              LIMIT ' . (int)$limit,
            [$conversationId, $afterId]
        );
    }

    // First load: take the most recent N, then flip back to oldest-first.
    $rows = q_all(
        'SELECT m.*, u.name AS sender_name
           FROM messages m
           JOIN users u ON u.id = m.sender_id
          WHERE m.conversation_id = ?
          ORDER BY m.id DESC
          LIMIT ' . (int)$limit,
        [$conversationId]
    );
    return array_reverse($rows);
}

/** Mark everything the other person sent as read. */
function mark_read(int $conversationId, int $readerId): void
{
    q('UPDATE messages
          SET read_at = UTC_TIMESTAMP()
        WHERE conversation_id = ? AND sender_id <> ? AND read_at IS NULL',
      [$conversationId, $readerId]);
}

function unread_total(int $userId): int
{
    return (int)q_val(
        'SELECT COUNT(*)
           FROM messages m
           JOIN conversations c ON c.id = m.conversation_id
          WHERE m.sender_id <> ?
            AND m.read_at IS NULL
            AND (c.user_low_id = ? OR c.user_high_id = ?)',
        [$userId, $userId, $userId]
    );
}

/**
 * The inbox list: every thread this member has, newest first, with the other
 * person's details, the last line of the thread and an unread count.
 */
function list_conversations(int $userId): array
{
    return q_all(
        "SELECT c.id,
                c.last_message_at,
                u.id            AS partner_id,
                u.name          AS partner_name,
                u.avatar        AS partner_avatar,
                u.city          AS partner_city,
                u.status        AS partner_status,
                u.last_seen_at  AS partner_last_seen,
                (SELECT m.body FROM messages m
                  WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT m.sender_id FROM messages m
                  WHERE m.conversation_id = c.id ORDER BY m.id DESC LIMIT 1) AS last_sender_id,
                (SELECT COUNT(*) FROM messages m
                  WHERE m.conversation_id = c.id
                    AND m.sender_id <> :me1
                    AND m.read_at IS NULL)                                   AS unread,
                EXISTS(SELECT 1 FROM blocks b
                        WHERE (b.blocker_id = :me2 AND b.blocked_id = u.id)
                           OR (b.blocker_id = u.id AND b.blocked_id = :me3))  AS blocked
           FROM conversations c
           JOIN users u
             ON u.id = CASE WHEN c.user_low_id = :me4 THEN c.user_high_id ELSE c.user_low_id END
          WHERE c.user_low_id = :me5 OR c.user_high_id = :me6
          ORDER BY c.last_message_at IS NULL, c.last_message_at DESC, c.id DESC",
        [
            'me1' => $userId, 'me2' => $userId, 'me3' => $userId,
            'me4' => $userId, 'me5' => $userId, 'me6' => $userId,
        ]
    );
}

/** Escape a message body for HTML and turn plain newlines into <br>. */
function message_html(string $body): string
{
    return nl2br(e($body), false);
}
