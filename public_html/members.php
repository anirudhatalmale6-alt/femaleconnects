<?php
/** Member directory: search by name, city or interest. */

require_once __DIR__ . '/includes/layout.php';

$me = require_login();

$search  = trim((string)($_GET['q'] ?? ''));
$city    = trim((string)($_GET['city'] ?? ''));
$sort    = ($_GET['sort'] ?? '') === 'name' ? 'name' : 'recent';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;
$offset  = ($page - 1) * $perPage;

// Everyone active except me, and except anyone either of us has blocked.
// Members only - the admin account is staff, not someone to chat with.
$where  = ["u.status = 'active'", "u.role = 'member'", 'u.id <> :me'];
$params = ['me' => $me['id']];

$where[] = 'NOT EXISTS (SELECT 1 FROM blocks b
                         WHERE (b.blocker_id = :me2 AND b.blocked_id = u.id)
                            OR (b.blocker_id = u.id AND b.blocked_id = :me3))';
$params['me2'] = $me['id'];
$params['me3'] = $me['id'];

if ($search !== '') {
    $where[] = '(u.name LIKE :s1 OR u.bio LIKE :s2 OR u.interests LIKE :s3 OR u.city LIKE :s4)';
    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $search) . '%';
    $params['s1'] = $params['s2'] = $params['s3'] = $params['s4'] = $like;
}
if ($city !== '') {
    $where[] = 'u.city = :city';
    $params['city'] = $city;
}

$sql = 'FROM users u WHERE ' . implode(' AND ', $where);

$total   = (int)q_val('SELECT COUNT(*) ' . $sql, $params);
$orderBy = $sort === 'name' ? 'u.name ASC' : 'u.last_seen_at IS NULL, u.last_seen_at DESC, u.id DESC';

$members = q_all(
    'SELECT u.* ' . $sql . ' ORDER BY ' . $orderBy . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset,
    $params
);

$cities = q_all("SELECT city, COUNT(*) AS n FROM users
                  WHERE status = 'active' AND city IS NOT NULL AND city <> ''
                  GROUP BY city ORDER BY city ASC");

$pages = max(1, (int)ceil($total / $perPage));

$pageLink = static function (int $n) use ($search, $city, $sort) {
    $qs = array_filter(['q' => $search, 'city' => $city, 'sort' => $sort === 'name' ? 'name' : '', 'page' => $n > 1 ? $n : '']);
    return 'members.php' . ($qs ? '?' . http_build_query($qs) : '');
};

page_head('Members', ['active' => 'members']);
?>

<?php if ($me['status'] === 'pending'): ?>
  <div class="alert alert--warn">
    Your profile is waiting for approval. You can look around, but messaging opens up once the
    admin has approved you.
  </div>
<?php endif; ?>

<div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:6px">
  <h1 style="margin:0">Members</h1>
  <span class="muted"><?= (int)$total ?> <?= $total === 1 ? 'woman' : 'women' ?> to meet</span>
</div>
<p class="muted" style="margin-bottom:22px">Say hello to someone. A first message is only ever one line long.</p>

<form class="toolbar" method="get" action="members.php">
  <div class="field toolbar__grow">
    <label for="q">Search</label>
    <input type="search" id="q" name="q" value="<?= e($search) ?>"
           placeholder="name, interest or town">
  </div>
  <div class="field" style="flex:0 0 190px">
    <label for="city">Town or city</label>
    <select id="city" name="city">
      <option value="">Anywhere</option>
      <?php foreach ($cities as $c): ?>
        <option value="<?= e($c['city']) ?>" <?= $c['city'] === $city ? 'selected' : '' ?>>
          <?= e($c['city']) ?> (<?= (int)$c['n'] ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="field" style="flex:0 0 165px">
    <label for="sort">Sort by</label>
    <select id="sort" name="sort">
      <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>Recently active</option>
      <option value="name"   <?= $sort === 'name'   ? 'selected' : '' ?>>Name A&ndash;Z</option>
    </select>
  </div>
  <button class="btn" type="submit">Search</button>
  <?php if ($search !== '' || $city !== ''): ?>
    <a class="btn btn--ghost" href="members.php">Clear</a>
  <?php endif; ?>
</form>

<?php if (!$members): ?>
  <div class="card empty">
    <h3>No members match that search</h3>
    <p><?= $total === 0 && $search === '' && $city === ''
          ? 'Nobody else has joined yet. Yours is the first profile.'
          : 'Try a wider search, or clear the filters.' ?></p>
  </div>
<?php else: ?>
  <div class="member-grid">
    <?php foreach ($members as $m): ?>
      <div class="card member-card">
        <div class="member-card__top">
          <span class="avatar-wrap">
            <?= avatar_html($m, 52) ?>
            <span class="dot <?= is_online($m['last_seen_at']) ? 'is-online' : '' ?>"
                  title="<?= is_online($m['last_seen_at']) ? 'Online now' : 'Last seen ' . e(time_ago($m['last_seen_at'])) ?>"></span>
          </span>
          <div style="min-width:0">
            <div class="member-card__name"><?= e($m['name']) ?></div>
            <div class="member-card__meta">
              <?php
                $bits = [];
                if ($age = age_from($m['birth_date'])) { $bits[] = $age; }
                if (!empty($m['city']))                { $bits[] = $m['city']; }
                echo e(implode(' · ', $bits) ?: 'Member');
              ?>
            </div>
          </div>
        </div>

        <div class="member-card__bio">
          <?= e($m['bio'] ?: 'This member has not written a bio yet.') ?>
        </div>

        <?php $tags = interest_list($m['interests']); ?>
        <?php if ($tags): ?>
          <div class="chip-set" style="margin-bottom:14px">
            <?php foreach (array_slice($tags, 0, 3) as $t): ?>
              <span class="chip"><?= e($t) ?></span>
            <?php endforeach; ?>
            <?php if (count($tags) > 3): ?>
              <span class="chip">+<?= count($tags) - 3 ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="member-card__foot">
          <a class="btn btn--sm" href="chat.php?with=<?= (int)$m['id'] ?>">Say hello</a>
          <a class="btn btn--ghost btn--sm" href="profile.php?id=<?= (int)$m['id'] ?>">View profile</a>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($pages > 1): ?>
    <div style="display:flex;gap:8px;justify-content:center;align-items:center;margin-top:28px">
      <?php if ($page > 1): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e($pageLink($page - 1)) ?>">Previous</a>
      <?php endif; ?>
      <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?>
        <a class="btn btn--ghost btn--sm" href="<?= e($pageLink($page + 1)) ?>">Next</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php page_foot(); ?>
