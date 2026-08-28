<?php
/** Sign-up form. */

require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('members.php');
}

$errors = [];
$in = [
    'name' => '', 'email' => '', 'birth_date' => '',
    'city' => '', 'bio' => '', 'interests' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    foreach ($in as $k => $_) {
        $in[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $in['password']  = (string)($_POST['password'] ?? '');
    $in['password2'] = (string)($_POST['password2'] ?? '');
    $in['agree']     = !empty($_POST['agree']);

    $errors = validate_registration($in);

    // Check the photo before creating anything, so a bad file does not leave
    // a half-finished account behind.
    $avatarFile = $_FILES['avatar'] ?? null;
    if ($avatarFile && ($avatarFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        [$okPhoto, $photoMsg] = save_avatar($avatarFile, 0);
        if (!$okPhoto) {
            $errors[] = $photoMsg;
        } else {
            $pendingAvatar = $photoMsg;
        }
    }

    if (!$errors) {
        $userId = create_user($in);

        if (!empty($pendingAvatar)) {
            // Rename the file to carry the real user id, then attach it.
            $newName = 'u' . $userId . '_' . substr($pendingAvatar, strpos($pendingAvatar, '_') + 1);
            @rename(AVATAR_DIR . '/' . $pendingAvatar, AVATAR_DIR . '/' . $newName);
            q('UPDATE users SET avatar = ? WHERE id = ?', [$newName, $userId]);
        }

        [$ok, $result] = attempt_login($in['email'], $in['password']);
        if ($ok) {
            if (SIGNUP_STATUS === 'pending') {
                flash('info', 'Welcome! Your profile is with the admin for approval - you will be able to message members as soon as it is approved.');
            } else {
                flash('success', 'Welcome to ' . SITE_NAME . '. Have a look around and say hello to someone.');
            }
            redirect('members.php');
        }
        flash('success', 'Your account is ready. Please log in.');
        redirect('login.php');
    } elseif (!empty($pendingAvatar)) {
        delete_avatar($pendingAvatar);          // form failed, do not keep the orphan file
    }
}

page_head('Create your profile', ['active' => 'register']);
?>

<div class="wrap wrap--narrow" style="padding:0">
  <h1>Create your profile</h1>
  <p class="muted">It takes about a minute. You can fill in the rest later.</p>

  <?php if ($errors): ?>
    <div class="alert alert--error">
      <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form class="card" method="post" enctype="multipart/form-data" novalidate>
    <div class="card__body">
      <?= csrf_field() ?>

      <div class="field">
        <label for="name">First name</label>
        <input type="text" id="name" name="name" maxlength="60" required
               value="<?= e($in['name']) ?>" autocomplete="given-name">
        <div class="hint">This is what other members see. A first name is plenty.</div>
      </div>

      <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" maxlength="190" required
               value="<?= e($in['email']) ?>" autocomplete="email">
        <div class="hint">Only used to log in. It is never shown to other members.</div>
      </div>

      <div class="row">
        <div class="field">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required
                 minlength="8" autocomplete="new-password">
          <div class="hint">At least 8 characters.</div>
        </div>
        <div class="field">
          <label for="password2">Repeat password</label>
          <input type="password" id="password2" name="password2" required autocomplete="new-password">
        </div>
      </div>

      <div class="row">
        <div class="field">
          <label for="birth_date">Date of birth</label>
          <input type="date" id="birth_date" name="birth_date" value="<?= e($in['birth_date']) ?>"
                 max="<?= e(date('Y-m-d')) ?>">
          <div class="hint">Members see your age, never your date of birth.</div>
        </div>
        <div class="field">
          <label for="city">Town or city</label>
          <input type="text" id="city" name="city" maxlength="80" value="<?= e($in['city']) ?>"
                 placeholder="e.g. Manchester" autocomplete="address-level2">
        </div>
      </div>

      <div class="field">
        <label for="interests">Interests</label>
        <input type="text" id="interests" name="interests" maxlength="255"
               value="<?= e($in['interests']) ?>"
               placeholder="reading, hiking, baking, live music">
        <div class="hint">Separate them with commas. Up to eight.</div>
      </div>

      <div class="field">
        <label for="bio">A little about you</label>
        <textarea id="bio" name="bio" maxlength="600" rows="4"
                  placeholder="New to the area and looking for someone to explore it with..."><?= e($in['bio']) ?></textarea>
      </div>

      <div class="field">
        <label for="avatar">Profile photo <span class="muted">(optional)</span></label>
        <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp">
        <div class="hint">JPG, PNG or WEBP, up to 3 MB. It gets cropped to a square.</div>
      </div>

      <div class="field">
        <label class="check">
          <input type="checkbox" name="agree" value="1" <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
          <span>I am a woman, I am here for friendship only, and I have read the
                <a href="guidelines.php" target="_blank">community guidelines</a>.</span>
        </label>
      </div>

      <button class="btn btn--block btn--lg" type="submit">Create my profile</button>

      <p class="center small muted" style="margin-top:14px">
        Already a member? <a href="login.php">Log in instead</a>
      </p>
    </div>
  </form>
</div>

<?php page_foot(); ?>
