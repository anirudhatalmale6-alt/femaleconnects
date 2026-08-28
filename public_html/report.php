<?php
/** Report a member to the admin. */

require_once __DIR__ . '/includes/layout.php';

$me = require_login();

$targetId = (int)($_GET['user'] ?? $_POST['user'] ?? 0);
$them     = q_row('SELECT * FROM users WHERE id = ?', [$targetId]);

if (!$them || $targetId === (int)$me['id']) {
    flash('error', 'That member is not available.');
    redirect('members.php');
}

$reasons = [
    'romantic'   => 'Romantic or sexual messages',
    'harassment' => 'Harassment, abuse or bullying',
    'selling'    => 'Selling, recruiting or promoting',
    'money'      => 'Asking me for money',
    'fake'       => 'Fake profile or not a woman',
    'other'      => 'Something else',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $reason  = (string)($_POST['reason'] ?? '');
    $details = trim((string)($_POST['details'] ?? ''));

    if (!isset($reasons[$reason])) {
        $errors[] = 'Please choose a reason.';
    }
    if (mb_strlen($details) > 600) {
        $errors[] = 'Please keep the details under 600 characters.';
    }

    $already = q_val(
        "SELECT id FROM reports
          WHERE reporter_id = ? AND reported_user_id = ? AND status = 'open'",
        [$me['id'], $targetId]
    );
    if ($already) {
        $errors[] = 'You already have an open report about this member. The admin is looking at it.';
    }

    if (!$errors) {
        q('INSERT INTO reports (reporter_id, reported_user_id, reason, details)
           VALUES (?, ?, ?, ?)',
          [$me['id'], $targetId, $reasons[$reason], $details !== '' ? $details : null]);

        if (!empty($_POST['also_block'])) {
            block_user((int)$me['id'], $targetId);
            flash('success', 'Report sent and ' . $them['name'] . ' is blocked. Thank you for telling us.');
        } else {
            flash('success', 'Report sent. A person reads every report - thank you for telling us.');
        }
        redirect('members.php');
    }
}

page_head('Report a member', ['active' => 'members']);
?>

<div class="wrap wrap--narrow" style="padding:0">
  <h1>Report <?= e($them['name']) ?></h1>
  <p class="muted">
    Every report is read by a real person. <?= e($them['name']) ?> is never told that you
    reported her.
  </p>

  <?php if ($errors): ?>
    <div class="alert alert--error">
      <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <form class="card" method="post">
    <div class="card__body">
      <?= csrf_field() ?>
      <input type="hidden" name="user" value="<?= (int)$targetId ?>">

      <div class="field">
        <span class="label">What happened?</span>
        <?php foreach ($reasons as $key => $label): ?>
          <label class="check" style="margin-bottom:9px">
            <input type="radio" name="reason" value="<?= e($key) ?>"
                   <?= ($_POST['reason'] ?? '') === $key ? 'checked' : '' ?>>
            <span><?= e($label) ?></span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="field">
        <label for="details">Anything you want to add <span class="muted">(optional)</span></label>
        <textarea id="details" name="details" rows="4" maxlength="600"
                  placeholder="A short note helps the admin act quickly."><?= e($_POST['details'] ?? '') ?></textarea>
      </div>

      <div class="field">
        <label class="check">
          <input type="checkbox" name="also_block" value="1" checked>
          <span>Also block <?= e($them['name']) ?> so she cannot message me again.</span>
        </label>
      </div>

      <div style="display:flex;gap:10px">
        <button class="btn" type="submit">Send report</button>
        <a class="btn btn--ghost" href="profile.php?id=<?= (int)$targetId ?>">Cancel</a>
      </div>
    </div>
  </form>

  <p class="small muted" style="margin-top:16px">
    If you are in immediate danger, please contact your local emergency services first.
  </p>
</div>

<?php page_foot(); ?>
