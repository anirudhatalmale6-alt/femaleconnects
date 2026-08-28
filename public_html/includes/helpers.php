<?php
/**
 * Small shared helpers: escaping, CSRF, flash messages, dates, avatars.
 */

require_once __DIR__ . '/db.php';

// ---------------------------------------------------------------------------
//  Output
// ---------------------------------------------------------------------------

/** Escape anything before it touches HTML. Used on every dynamic value. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Path back to the site root from wherever the current script lives. */
function base_url(): string
{
    return defined('GC_BASE') ? GC_BASE : '';
}

// ---------------------------------------------------------------------------
//  Session
// ---------------------------------------------------------------------------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ---------------------------------------------------------------------------
//  CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_ok(?string $token): bool
{
    start_session();
    return !empty($_SESSION['csrf']) && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/** Guard for POST handlers. Dies with a clear message if the token is wrong. */
function require_csrf(): void
{
    if (!csrf_ok($_POST['csrf'] ?? null)) {
        http_response_code(400);
        exit('Your session expired. Please go back, reload the page and try again.');
    }
}

// ---------------------------------------------------------------------------
//  Flash messages
// ---------------------------------------------------------------------------

function flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    start_session();
    $out = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $out;
}

// ---------------------------------------------------------------------------
//  Dates
// ---------------------------------------------------------------------------

/** "just now" / "14 min ago" / "Tue 14:05" / "12 Mar 2026" */
function time_ago(?string $utcDateTime): string
{
    if (!$utcDateTime) {
        return '';
    }
    $then = strtotime($utcDateTime . ' UTC');
    $diff = time() - $then;

    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 604800) return date('D H:i', $then);
    return date('j M Y', $then);
}

function pretty_date(?string $utcDateTime): string
{
    return $utcDateTime ? date('j M Y, H:i', strtotime($utcDateTime . ' UTC')) : '';
}

function age_from(?string $birthDate): ?int
{
    if (!$birthDate) {
        return null;
    }
    try {
        return (new DateTime($birthDate))->diff(new DateTime('today'))->y;
    } catch (Exception $e) {
        return null;
    }
}

// ---------------------------------------------------------------------------
//  Members
// ---------------------------------------------------------------------------

function avatar_url(?array $user): string
{
    if (!empty($user['avatar'])
        && is_file(AVATAR_DIR . '/' . basename($user['avatar']))) {
        return base_url() . AVATAR_URL . '/' . rawurlencode($user['avatar']);
    }
    return '';
}

/** Two-letter monogram used when a member has no photo. */
function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1, 'UTF-8');
    $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1, 'UTF-8') : '';
    return mb_strtoupper($first . $last, 'UTF-8');
}

/** A stable colour per member so their monogram never changes shade. */
function avatar_tint(int $userId): string
{
    $tints = ['#d98b73', '#7f9c81', '#c8956d', '#8d8ab0', '#b3778f', '#6f9198', '#a68a5b'];
    return $tints[$userId % count($tints)];
}

function is_online(?string $lastSeenUtc): bool
{
    if (!$lastSeenUtc) {
        return false;
    }
    return (time() - strtotime($lastSeenUtc . ' UTC')) < (ONLINE_WINDOW_MIN * 60);
}

/** Turn "reading, hiking" into a clean array of tags. */
function interest_list(?string $raw): array
{
    if (!$raw) {
        return [];
    }
    $tags = array_map('trim', explode(',', $raw));
    return array_values(array_filter($tags, static fn($t) => $t !== ''));
}

/** Render a member's photo or monogram. $size is in pixels. */
function avatar_html(array $user, int $size = 44): string
{
    $url = avatar_url($user);
    $px  = (int)$size;
    if ($url !== '') {
        return '<img class="avatar" style="width:' . $px . 'px;height:' . $px . 'px" '
             . 'src="' . e($url) . '" alt="' . e($user['name']) . '">';
    }
    $font = max(11, (int)round($px * 0.38));
    return '<span class="avatar avatar--letters" style="width:' . $px . 'px;height:' . $px
         . 'px;font-size:' . $font . 'px;background:' . e(avatar_tint((int)$user['id'])) . '">'
         . e(initials($user['name'])) . '</span>';
}

function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}
