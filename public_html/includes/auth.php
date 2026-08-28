<?php
/**
 * Accounts: registration, login, throttling, session guards, avatar uploads.
 */

require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
//  Who is logged in
// ---------------------------------------------------------------------------

/** The logged-in member's row, refreshed from the database, or null. */
function current_user(): ?array
{
    static $cached = null;
    static $looked = false;

    if ($looked) {
        return $cached;
    }
    $looked = true;

    start_session();
    $id = $_SESSION['user_id'] ?? null;
    if (!$id) {
        return null;
    }

    $user = q_row('SELECT * FROM users WHERE id = ?', [$id]);

    // Account deleted or suspended while they were logged in - drop them out.
    if (!$user || $user['status'] === 'suspended') {
        logout();
        return null;
    }

    $cached = $user;
    return $cached;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_admin(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin';
}

/** Members must be 'active' to use the chat; 'pending' accounts are held back. */
function is_approved(): bool
{
    $u = current_user();
    return $u !== null && $u['status'] === 'active';
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        start_session();
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect(base_url() . 'login.php');
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        exit('This area is for administrators only.');
    }
    return $u;
}

/** Stamp last_seen_at at most once a minute so we are not writing on every hit. */
function touch_presence(): void
{
    $u = current_user();
    if (!$u) {
        return;
    }
    start_session();
    $now = time();
    if (($_SESSION['presence_at'] ?? 0) > $now - 60) {
        return;
    }
    $_SESSION['presence_at'] = $now;
    q('UPDATE users SET last_seen_at = UTC_TIMESTAMP(), last_ip = ? WHERE id = ?',
      [client_ip(), $u['id']]);
}

// ---------------------------------------------------------------------------
//  Login / logout
// ---------------------------------------------------------------------------

function login_lock_remaining(string $email): int
{
    // The interval is a fixed constant from config.php, cast to int here so it
    // never reaches the query as user input.
    $window = (int)LOGIN_WINDOW_MIN;
    $fails  = (int)q_val(
        'SELECT COUNT(*) FROM login_attempts
          WHERE email = ? AND successful = 0
            AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . $window . ' MINUTE)',
        [$email]
    );
    if ($fails < LOGIN_MAX_ATTEMPTS) {
        return 0;
    }
    $last = q_val(
        'SELECT MAX(created_at) FROM login_attempts WHERE email = ? AND successful = 0',
        [$email]
    );
    $unlockAt = strtotime($last . ' UTC') + LOGIN_WINDOW_MIN * 60;
    return max(1, (int)ceil(($unlockAt - time()) / 60));
}

function record_attempt(string $email, bool $ok): void
{
    q('INSERT INTO login_attempts (email, ip, successful) VALUES (?, ?, ?)',
      [$email, client_ip(), $ok ? 1 : 0]);
}

/**
 * Try to log someone in.
 * Returns [true, $user] or [false, 'reason to show the visitor'].
 */
function attempt_login(string $email, string $password): array
{
    $email = mb_strtolower(trim($email));

    $locked = login_lock_remaining($email);
    if ($locked > 0) {
        return [false, "Too many failed attempts. Please try again in {$locked} minute"
            . ($locked === 1 ? '' : 's') . '.'];
    }

    $user = q_row('SELECT * FROM users WHERE email = ?', [$email]);

    if (!$user || !password_verify($password, $user['password_hash'])) {
        record_attempt($email, false);
        return [false, 'That email and password do not match.'];
    }

    if ($user['status'] === 'suspended') {
        record_attempt($email, false);
        return [false, 'This account has been suspended. Please contact the site admin.'];
    }

    // Rehash if PHP's default cost has moved on since they signed up.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?',
          [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    record_attempt($email, true);
    q('DELETE FROM login_attempts WHERE email = ? AND successful = 0', [$email]);

    start_session();
    session_regenerate_id(true);          // new id on login, blocks session fixation
    $_SESSION['user_id'] = (int)$user['id'];

    q('UPDATE users SET last_seen_at = UTC_TIMESTAMP(), last_ip = ? WHERE id = ?',
      [client_ip(), $user['id']]);

    return [true, $user];
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
//  Registration
// ---------------------------------------------------------------------------

/**
 * Validate a sign-up form.
 * Returns a list of error messages - empty means the form is good.
 */
function validate_registration(array $in): array
{
    $errors = [];

    $name = trim($in['name'] ?? '');
    if (mb_strlen($name) < 2)  $errors[] = 'Please enter your first name (at least 2 letters).';
    if (mb_strlen($name) > 60) $errors[] = 'That name is too long (60 characters max).';

    $email = mb_strtolower(trim($in['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif (q_val('SELECT id FROM users WHERE email = ?', [$email])) {
        $errors[] = 'There is already an account using that email address.';
    }

    $pass = (string)($in['password'] ?? '');
    if (mb_strlen($pass) < 8) {
        $errors[] = 'Your password needs to be at least 8 characters.';
    }
    if ($pass !== (string)($in['password2'] ?? '')) {
        $errors[] = 'The two passwords do not match.';
    }

    $birth = trim($in['birth_date'] ?? '');
    if ($birth !== '') {
        $age = age_from($birth);
        if ($age === null || $age < 0 || $age > 120) {
            $errors[] = 'Please check your date of birth.';
        } elseif (MIN_AGE > 0 && $age < MIN_AGE) {
            $errors[] = 'You need to be at least ' . MIN_AGE . ' to join.';
        }
    } elseif (MIN_AGE > 0) {
        $errors[] = 'Please enter your date of birth.';
    }

    if (mb_strlen(trim($in['bio'] ?? '')) > 600) {
        $errors[] = 'Please keep your bio under 600 characters.';
    }

    if (empty($in['agree'])) {
        $errors[] = 'Please confirm you have read the community rules.';
    }

    return $errors;
}

/** Create the account. Returns the new user id. */
function create_user(array $in): int
{
    q('INSERT INTO users (name, email, password_hash, birth_date, city, bio, interests, status, created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())', [
        trim($in['name']),
        mb_strtolower(trim($in['email'])),
        password_hash((string)$in['password'], PASSWORD_DEFAULT),
        trim($in['birth_date'] ?? '') !== '' ? trim($in['birth_date']) : null,
        trim($in['city'] ?? '') !== '' ? trim($in['city']) : null,
        trim($in['bio'] ?? '') !== '' ? trim($in['bio']) : null,
        clean_interests($in['interests'] ?? ''),
        SIGNUP_STATUS,
    ]);
    return (int)db()->lastInsertId();
}

/** Normalise a comma separated interest string: trim, dedupe, cap at 8 tags. */
function clean_interests(string $raw): ?string
{
    $tags = [];
    foreach (explode(',', $raw) as $tag) {
        $tag = trim(preg_replace('/\s+/u', ' ', $tag));
        if ($tag === '') {
            continue;
        }
        $tag = mb_substr($tag, 0, 28, 'UTF-8');
        if (!in_array(mb_strtolower($tag, 'UTF-8'), array_map('mb_strtolower', $tags), true)) {
            $tags[] = $tag;
        }
        if (count($tags) >= 8) {
            break;
        }
    }
    return $tags ? implode(', ', $tags) : null;
}

// ---------------------------------------------------------------------------
//  Avatar upload
// ---------------------------------------------------------------------------

/**
 * Handle one $_FILES entry. The image is re-encoded through GD, which strips
 * EXIF and guarantees the saved file really is an image and nothing else.
 *
 * Returns [true, 'filename.jpg'] or [false, 'message'].
 */
function save_avatar(array $file, int $userId): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [false, ''];                       // nothing chosen, not an error
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [false, 'That photo is too large. Please pick one under 3 MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [false, 'The photo did not upload correctly. Please try again.'];
    }
    if ($file['size'] > AVATAR_MAX_BYTES) {
        return [false, 'That photo is too large. Please pick one under 3 MB.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return [false, 'That file is not an image we can read. Please use a JPG or PNG.'];
    }

    [$w, $h, $type] = $info;
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($file['tmp_name']); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($file['tmp_name']);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp')
                                    ? @imagecreatefromwebp($file['tmp_name']) : false; break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($file['tmp_name']);  break;
        default:             $src = false;
    }
    if (!$src) {
        return [false, 'Please upload a JPG, PNG or WEBP photo.'];
    }

    // Square crop from the centre, then scale to AVATAR_MAX_PX.
    $side = min($w, $h);
    $sx   = (int)(($w - $side) / 2);
    $sy   = (int)(($h - $side) / 2);
    $out  = min(AVATAR_MAX_PX, $side);

    $dst = imagecreatetruecolor($out, $out);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out, $out, $side, $side);
    imagedestroy($src);

    if (!is_dir(AVATAR_DIR) && !@mkdir(AVATAR_DIR, 0755, true) && !is_dir(AVATAR_DIR)) {
        imagedestroy($dst);
        return [false, 'The server could not save the photo (upload folder missing).'];
    }

    $name = 'u' . $userId . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok   = imagejpeg($dst, AVATAR_DIR . '/' . $name, 86);
    imagedestroy($dst);

    if (!$ok) {
        return [false, 'The server could not save the photo. Please check folder permissions.'];
    }
    return [true, $name];
}

function delete_avatar(?string $filename): void
{
    if (!$filename) {
        return;
    }
    $path = AVATAR_DIR . '/' . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

// ---------------------------------------------------------------------------
//  Admin audit trail
// ---------------------------------------------------------------------------

function admin_log(string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void
{
    $u = current_user();
    q('INSERT INTO admin_log (admin_id, action, target_type, target_id, details)
       VALUES (?, ?, ?, ?, ?)',
      [$u['id'] ?? null, $action, $targetType, $targetId, $details]);
}
