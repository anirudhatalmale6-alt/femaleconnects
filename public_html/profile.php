<?php
/**
 * profile.php          -> your own profile, with the edit form
 * profile.php?id=123   -> another member's profile
 */

require_once __DIR__ . '/includes/layout.php';

$me = require_login();

$viewId = (int)($_GET['id'] ?? 0);
$isSelf = ($viewId === 0 || $viewId === (int)$me['id']);

// -------------------------------------------------------------- other member
if (!$isSelf) {
    $them = q_row('SELECT * FROM users WHERE id = ?', [$viewId]);
    if (!$them || $them['role'] !== 'member' || ($them['status'] !== 'active' && !is_admin())) {
        page_head('Member not found');
        echo '<div class="card empty"><h3>That profile is not available</h3>'
           . '<p>The member may have left or been removed.</p>'
           . '<p style="margin-top:16px"><a class="btn" href="members.php">Back to members</a></p></div>';
        page_foot();
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $action = $_POST['action'] ?? '';
        if ($action === 'block') {
            block_user((int)$me['id'], $viewId);
            flash('success', $them['name'] . ' is blocked. She can no longer message you, and she is not told.');
            redirect('members.php');
        } elseif ($action === 'unblock') {
            unblock_user((int)$me['id'], $viewId);
            flash('success', 'Block removed.');
            redirect('profile.php?id=' . $viewId);
        }
    }

    $blockedByMe = has_blocked((int)$me['id'], $viewId);
    $anyBlock    = block_between((int)$me['id'], $viewId);
    $conv        = find_conversation((int)$me['id'], $viewId);

    page_head($them['name'], ['active' => 'members']);
    ?>
    <p><a class="btn btn--quiet btn--sm" href="members.php"><?= icon('back', 16) ?> All members</a></p>

    <div class="card">
      <div class="card__body">
        <div class="profile__head">
          <span class="avatar-wrap">
            <?= avatar_html($them, 96) ?>
            <span class="dot <?= is_online($them['last_seen_at']) ? 'is-online' : '' ?>"></span>
          </span>
          <div>
            <h1><?= e($them['name']) ?></h1>
            <p class="muted small" style="margin:0">
              <?php
                $bits = [];
                if ($age = age_from($them['birth_date'])) { $bits[] = $age . ' years old'; }
                if (!empty($them['city']))                { $bits[] = $them['city']; }
                $bits[] = is_online($them['last_seen_at'])
                    ? 'Online now'
                    : ($them['last_seen_at'] ? 'Last seen ' . time_ago($them['last_seen_at']) : 'Not been back yet');
                echo e(implode(' · ', $bits));
              ?>
            </p>
            <p class="muted tiny" style="margin:4px 0 0">Member since <?= e(pretty_date($them['created_at'])) ?></p>
          </div>

          <div class="profile__actions">
            <?php if ($anyBlock): ?>
              <?php if ($blockedByMe): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unblock">
                  <button class="btn btn--ghost" type="submit">Unblock</button>
                </form>
              <?php else: ?>
                <span class="chip chip--danger">Unavailable</span>
              <?php endif; ?>
            <?php else: ?>
              <a class="btn" href="chat.php?with=<?= (int)$them['id'] ?>">
                <?= icon('chat', 17) ?> <?= $conv ? 'Open chat' : 'Send a message' ?>
              </a>
              <a class="btn btn--ghost" href="report.php?user=<?= (int)$them['id'] ?>">Report</a>
              <form method="post" style="display:inline"
                    onsubmit="return confirm('Block <?= e($them['name']) ?>? She will not be able to message you and will not be told.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="block">
                <button class="btn btn--ghost" type="submit">Block</button>
              </form>
            <?php endif; ?>
          </div>
        </div>

        <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

        <h3>About <?= e($them['name']) ?></h3>
        <p class="muted"><?= $them['bio'] ? nl2br(e($them['bio']), false) : 'No bio written yet.' ?></p>

        <?php $tags = interest_list($them['interests']); ?>
        <?php if ($tags): ?>
          <h3 style="margin-top:22px">Interests</h3>
          <div class="chip-set">
            <?php foreach ($tags as $t): ?><span class="chip chip--accent"><?= e($t) ?></span><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <p class="small muted" style="margin-top:18px">
      Remember: <?= e(SITE_NAME) ?> is for friendship only. If a conversation turns romantic or
      makes you uncomfortable, block and report it &mdash; you never owe anyone a reply.
    </p>
    <?php
    page_foot();
    exit;
}

// ------------------------------------------------------------- my own profile
$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (($_POST['action'] ?? '') === 'remove_photo') {
        delete_avatar($me['avatar']);
        q('UPDATE users SET avatar = NULL WHERE id = ?', [$me['id']]);
        flash('success', 'Photo removed.');
        redirect('profile.php');
    }

    $name  = trim((string)($_POST['name'] ?? ''));
    $city  = trim((string)($_POST['city'] ?? ''));
    $bio   = trim((string)($_POST['bio'] ?? ''));
    $birth = trim((string)($_POST['birth_date'] ?? ''));
    $tags  = clean_interests((string)($_POST['interests'] ?? ''));

    if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
        $errors[] = 'Please enter a name between 2 and 60 characters.';
    }
    if (mb_strlen($bio) > 600) {
        $errors[] = 'Please keep your bio under 600 characters.';
    }
    if ($birth !== '') {
        $age = age_from($birth);
        if ($age === null || $age < 0 || $age > 120) {
            $errors[] = 'Please check your date of birth.';
        } elseif (MIN_AGE > 0 && $age < MIN_AGE) {
            $errors[] = 'You need to be at least ' . MIN_AGE . ' to use this site.';
        }
    }

    // Optional password change
    $newPass = (string)($_POST['new_password'] ?? '');
    if ($newPass !== '') {
        if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
            $errors[] = 'Your current password is not correct.';
        } elseif (mb_strlen($newPass) < 8) {
            $errors[] = 'Your new password needs to be at least 8 characters.';
        } elseif ($newPass !== (string)($_POST['new_password2'] ?? '')) {
            $errors[] = 'The two new passwords do not match.';
        }
    }

    $newAvatar = null;
    if (!empty($_FILES['avatar']) && ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$okPhoto, $photoMsg] = save_avatar($_FILES['avatar'], (int)$me['id']);
        if (!$okPhoto) {
            $errors[] = $photoMsg;
        } else {
            $newAvatar = $photoMsg;
        }
    }

    if (!$errors) {
        q('UPDATE users SET name = ?, city = ?, bio = ?, birth_date = ?, interests = ? WHERE id = ?', [
            $name,
            $city !== '' ? $city : null,
            $bio  !== '' ? $bio  : null,
            $birth !== '' ? $birth : null,
            $tags,
            $me['id'],
        ]);

        if ($newAvatar !== null) {
            delete_avatar($me['avatar']);
            q('UPDATE users SET avatar = ? WHERE id = ?', [$newAvatar, $me['id']]);
        }
        if ($newPass !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?',
              [password_hash($newPass, PASSWORD_DEFAULT), $me['id']]);
        }

        flash('success', 'Your profile is updated.');
        redirect('profile.php');
    }

    if ($newAvatar !== null) {
        delete_avatar($newAvatar);
    }
    $me = array_merge($me, [
        'name' => $name, 'city' => $city, 'bio' => $bio,
        'birth_date' => $birth, 'interests' => $tags,
    ]);
}

$blockedList = q_all(
    'SELECT u.id, u.name, u.avatar, b.created_at
       FROM blocks b JOIN users u ON u.id = b.blocked_id
      WHERE b.blocker_id = ? ORDER BY b.created_at DESC',
    [$me['id']]
);

page_head('Your profile', ['active' => 'profile']);
?>

<h1>Your profile</h1>
<p class="muted">This is what other members see when they open your profile.</p>

<?php if ($errors): ?>
  <div class="alert alert--error">
    <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
  </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:24px;align-items:start"
     class="profile-layout">

  <form class="card" method="post" enctype="multipart/form-data">
    <div class="card__body">
      <?= csrf_field() ?>

      <div class="field">
        <label for="name">First name</label>
        <input type="text" id="name" name="name" maxlength="60" required value="<?= e($me['name']) ?>">
      </div>

      <div class="row">
        <div class="field">
          <label for="birth_date">Date of birth</label>
          <input type="date" id="birth_date" name="birth_date" max="<?= e(date('Y-m-d')) ?>"
                 value="<?= e($me['birth_date']) ?>">
        </div>
        <div class="field">
          <label for="city">Town or city</label>
          <input type="text" id="city" name="city" maxlength="80" value="<?= e($me['city']) ?>">
        </div>
      </div>

      <div class="field">
        <label for="interests">Interests</label>
        <input type="text" id="interests" name="interests" maxlength="255"
               value="<?= e($me['interests']) ?>" placeholder="reading, hiking, baking">
        <div class="hint">Comma separated, up to eight.</div>
      </div>

      <div class="field">
        <label for="bio">A little about you</label>
        <textarea id="bio" name="bio" maxlength="600" rows="5"><?= e($me['bio']) ?></textarea>
      </div>

      <div class="field">
        <label for="avatar">Change your photo</label>
        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
        <div class="hint">JPG, PNG or WEBP, up to 3 MB.</div>
      </div>

      <hr style="border:0;border-top:1px solid var(--line);margin:22px 0">

      <h3>Change your password</h3>
      <p class="muted small">Leave these blank to keep your current password.</p>
      <div class="field">
        <label for="current_password">Current password</label>
        <input type="password" id="current_password" name="current_password" autocomplete="current-password">
      </div>
      <div class="row">
        <div class="field">
          <label for="new_password">New password</label>
          <input type="password" id="new_password" name="new_password" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="new_password2">Repeat new password</label>
          <input type="password" id="new_password2" name="new_password2" autocomplete="new-password">
        </div>
      </div>

      <button class="btn btn--lg" type="submit">Save changes</button>
    </div>
  </form>

  <div class="stack">
    <div class="card">
      <div class="card__body center">
        <span class="avatar-wrap" style="margin-bottom:12px">
          <?= avatar_html($me, 110) ?>
        </span>
        <div style="font-weight:600;font-size:1.05rem"><?= e($me['name']) ?></div>
        <div class="small muted"><?= e($me['city'] ?: 'No town set') ?></div>
        <?php if ($me['status'] === 'pending'): ?>
          <p style="margin-top:10px"><span class="chip chip--warn">Waiting for approval</span></p>
        <?php endif; ?>
        <?php if (!empty($me['avatar'])): ?>
          <form method="post" style="margin-top:12px"
                onsubmit="return confirm('Remove your profile photo?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="remove_photo">
            <button class="btn btn--ghost btn--sm" type="submit">Remove photo</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__head"><h3>Blocked members</h3></div>
      <div class="card__body">
        <?php if (!$blockedList): ?>
          <p class="muted small" style="margin:0">You have not blocked anyone.</p>
        <?php else: ?>
          <?php foreach ($blockedList as $b): ?>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
              <?= avatar_html($b, 32) ?>
              <div style="flex:1;min-width:0">
                <div class="small" style="font-weight:600"><?= e($b['name']) ?></div>
                <div class="tiny muted"><?= e(time_ago($b['created_at'])) ?></div>
              </div>
              <a class="btn btn--quiet btn--sm" href="profile.php?id=<?= (int)$b['id'] ?>">Manage</a>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<style>
@media (max-width: 860px) { .profile-layout { grid-template-columns: 1fr !important; } }
</style>

<?php page_foot(); ?>
