<?php
require_once __DIR__ . '/includes/layout.php';

page_head('Community guidelines', ['active' => '']);
?>

<div class="wrap wrap--narrow" style="padding:0">
  <h1>Community guidelines</h1>
  <p class="muted">Short, and applied to everyone the same way.</p>

  <div class="card stack" style="padding:26px">
    <div>
      <h3>1. This is a friendship site</h3>
      <p class="muted">No dating, no flirting, no romantic or sexual messages of any kind. If a
      conversation turns that way, block and report it &mdash; you never owe anyone a reply.</p>
    </div>
    <div>
      <h3>2. Women only</h3>
      <p class="muted">Membership is for women. Accounts that are not are removed.</p>
    </div>
    <div>
      <h3>3. Be the friend you are hoping to find</h3>
      <p class="muted">Warm, curious, patient. Not everyone will click with you, and that is fine.
      A quiet "thanks, but I do not think we are a match" is always enough.</p>
    </div>
    <div>
      <h3>4. Nothing for sale</h3>
      <p class="muted">No businesses, courses, coaching, multi level marketing or recruiting.
      No asking members for money, ever, for any reason.</p>
    </div>
    <div>
      <h3>5. Keep private things private</h3>
      <p class="muted">Do not share another member's photos, messages or details anywhere else.
      Share your own address, workplace or phone number only when you are ready.</p>
    </div>
    <div>
      <h3>6. Meeting up safely</h3>
      <p class="muted">Meet somewhere public the first time. Tell a friend or family member where
      you are going and who with. Arrange your own travel there and back. If something feels
      off, leave &mdash; you do not need a reason.</p>
    </div>
    <div>
      <h3>7. Reporting</h3>
      <p class="muted">Every report is read by a person. Blocking someone is instant and they are
      never told. Serious breaches get the account suspended.</p>
    </div>
  </div>

  <p style="margin-top:24px">
    <?php if (is_logged_in()): ?>
      <a class="btn" href="members.php">Back to members</a>
    <?php else: ?>
      <a class="btn" href="register.php">Create your free profile</a>
    <?php endif; ?>
  </p>
</div>

<?php page_foot(); ?>
