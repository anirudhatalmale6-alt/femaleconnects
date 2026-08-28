<?php
/**
 * Girls Connect - site configuration.
 *
 * Edit the database details below to match your hosting account, then upload.
 * Everything else in the site reads its settings from here.
 *
 * (Each setting is written as "define it unless it is already defined", so a
 * config.local.php next to this file can override any of them for testing.)
 */

// Local overrides, if present, win. Never upload config.local.php to the server.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

$gc_defaults = [

    // --- Database ---------------------------------------------------------
    'DB_HOST'   => 'localhost',
    'DB_NAME'   => 'girlsconnect',
    'DB_USER'   => 'root',
    'DB_PASS'   => '',
    'DB_PORT'   => 3306,
    'DB_SOCKET' => '',                 // leave empty on normal hosting

    // --- Site -------------------------------------------------------------
    'SITE_NAME'    => 'Girls Connect',
    'SITE_TAGLINE' => 'Real friendships, made by women, for women.',

    // Minimum age allowed to register. Set to 0 to switch the check off.
    'MIN_AGE' => 16,

    // New sign-ups: 'active' lets them straight in, 'pending' holds them for
    // the admin to approve on the Members screen before they can chat.
    'SIGNUP_STATUS' => 'active',

    // --- Uploads ----------------------------------------------------------
    'AVATAR_DIR'        => __DIR__ . '/../uploads/avatars',
    'AVATAR_URL'        => 'uploads/avatars',
    'AVATAR_MAX_BYTES'  => 3145728,    // 3 MB
    'AVATAR_MAX_PX'     => 512,        // photos are resized down to this

    // --- Chat -------------------------------------------------------------
    'MESSAGE_MAX_CHARS' => 2000,
    'CHAT_POLL_MS'      => 2500,       // how often the browser checks for new messages
    'ONLINE_WINDOW_MIN' => 5,          // "online now" if seen in the last N minutes

    // --- Security ---------------------------------------------------------
    'LOGIN_MAX_ATTEMPTS' => 6,         // per email, within the window below
    'LOGIN_WINDOW_MIN'   => 15,
    'SESSION_NAME'       => 'gcsid',
];

foreach ($gc_defaults as $gc_key => $gc_value) {
    if (!defined($gc_key)) {
        define($gc_key, $gc_value);
    }
}
unset($gc_defaults, $gc_key, $gc_value);
