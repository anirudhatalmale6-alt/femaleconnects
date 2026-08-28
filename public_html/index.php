<?php
/** Landing page. Signed-in members go straight to the members directory. */

require_once __DIR__ . '/includes/layout.php';

if (is_logged_in()) {
    redirect('members.php');
}

$memberCount = (int)q_val("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'member'");

page_head('Friendship, not dating', ['active' => 'home', 'wrap' => '']);
?>

<section class="hero">
  <div class="wrap wrap--wide hero__grid">
    <div>
      <span class="eyebrow">Women only &middot; Strictly platonic</span>
      <h1>Find your people.<br>No dating, ever.</h1>
      <p class="hero__lead">
        <?= e(SITE_NAME) ?> is a small, moderated space for women who want real friends &mdash;
        someone to walk with on a Sunday, swap book recommendations with, or message when
        a new city still feels unfamiliar. Nothing romantic. That is the whole point.
      </p>
      <div class="hero__cta">
        <a class="btn btn--lg" href="register.php">Create your free profile</a>
        <a class="btn btn--ghost btn--lg" href="login.php">I already have an account</a>
      </div>
      <p class="hero__note">
        <?= $memberCount > 0
              ? e($memberCount) . ' member' . ($memberCount === 1 ? '' : 's') . ' have joined so far.'
              : 'Be one of the first to join.' ?>
        Free to use, and you can delete your profile at any time.
      </p>
    </div>

    <div class="hero__art" aria-hidden="true">
      <div class="hero__bubble">Hi! I saw you like early morning swims too &mdash; which pool do you go to?</div>
      <div class="hero__bubble hero__bubble--mine">The lido on Green Lane! Fancy going together on Saturday?</div>
      <div class="hero__bubble">Yes please. I have wanted to try it for months but never on my own.</div>
      <div class="hero__bubble hero__bubble--mine">Sorted. 8am, coffee after.</div>
    </div>
  </div>
</section>

<section class="features">
  <div class="wrap wrap--wide features__grid">
    <div class="card feature">
      <div class="feature__icon"><?= icon('user', 21) ?></div>
      <h3>A profile that sounds like you</h3>
      <p>A photo, your city and a few interests. Enough for someone to recognise a kindred
         spirit, and nothing that belongs on a dating site.</p>
    </div>
    <div class="card feature">
      <div class="feature__icon"><?= icon('chat', 21) ?></div>
      <h3>Private one-to-one chat</h3>
      <p>Message any member directly. Replies appear as they are sent, and only the two of
         you can see the conversation.</p>
    </div>
    <div class="card feature">
      <div class="feature__icon"><?= icon('shield', 21) ?></div>
      <h3>Blocked means blocked</h3>
      <p>One tap ends a conversation for good, and anything that crosses the line can be
         reported to a real person who reads every report.</p>
    </div>
  </div>
</section>

<section class="rules">
  <div class="wrap wrap--wide">
    <h2>How we keep it that way</h2>
    <p class="muted" style="margin-bottom:22px;max-width:52em">
      Four house rules, applied to everyone the same way.
    </p>
    <div class="rules__grid">
      <div class="rule">
        <span class="rule__no">1</span>
        <div>
          <strong>Women only</strong>
          <p>Membership is for women. Anyone else is removed as soon as we see it.</p>
        </div>
      </div>
      <div class="rule">
        <span class="rule__no">2</span>
        <div>
          <strong>No romantic or sexual approaches</strong>
          <p>Not from anyone, to anyone. This is a friendship site and only a friendship site.</p>
        </div>
      </div>
      <div class="rule">
        <span class="rule__no">3</span>
        <div>
          <strong>No selling, recruiting or promoting</strong>
          <p>No businesses, no multi level marketing, no "quick question about your income".</p>
        </div>
      </div>
      <div class="rule">
        <span class="rule__no">4</span>
        <div>
          <strong>Meet safely</strong>
          <p>First meets in public, tell someone where you are going, and never send money.</p>
        </div>
      </div>
    </div>
    <p style="margin-top:26px">
      <a class="btn btn--ghost" href="guidelines.php">Read the full community guidelines</a>
    </p>
  </div>
</section>

<?php page_foot(); ?>
