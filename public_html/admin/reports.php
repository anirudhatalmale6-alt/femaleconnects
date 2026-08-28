<?php
/** Admin: the report queue. */

define('GC_BASE', '../');
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $reportId = (int)($_POST['report_id'] ?? 0);
    $action   = (string)($_POST['action'] ?? '');
    $report   = q_row('SELECT * FROM reports WHERE id = ?', [$reportId]);

    if (!$report) {
        flash('error', 'That report no longer exists.');
        redirect('reports.php');
    }

    if ($action === 'dismiss' || $action === 'actioned') {
        q('UPDATE reports SET status = ?, reviewed_by = ?, reviewed_at = UTC_TIMESTAMP() WHERE id = ?',
          [$action === 'dismiss' ? 'dismissed' : 'actioned', $admin['id'], $reportId]);
        admin_log('report_' . $action, 'report', $reportId, $report['reason']);
        flash('success', 'Report marked as ' . ($action === 'dismiss' ? 'dismissed' : 'actioned') . '.');

    } elseif ($action === 'suspend') {
        q("UPDATE users SET status = 'suspended' WHERE id = ?", [$report['reported_user_id']]);
        q("UPDATE reports SET status = 'actioned', reviewed_by = ?, reviewed_at = UTC_TIMESTAMP()
            WHERE id = ?", [$admin['id'], $reportId]);
        admin_log('suspend_user', 'user', (int)$report['reported_user_id'], 'from report ' . $reportId);
        flash('success', 'Member suspended and the report closed.');
    }
    redirect('reports.php');
}

$filter = (string)($_GET['status'] ?? 'open');
if (!in_array($filter, ['open', 'actioned', 'dismissed', 'all'], true)) {
    $filter = 'open';
}

$where  = $filter === 'all' ? '1=1' : 'r.status = :st';
$params = $filter === 'all' ? [] : ['st' => $filter];

$reports = q_all(
    'SELECT r.*,
            u.name AS reported_name, u.status AS reported_status, u.avatar AS reported_avatar, u.id AS reported_id,
            v.name AS reporter_name, v.id AS reporter_id,
            w.name AS reviewer_name
       FROM reports r
       JOIN users u ON u.id = r.reported_user_id
       JOIN users v ON v.id = r.reporter_id
       LEFT JOIN users w ON w.id = r.reviewed_by
      WHERE ' . $where . '
      ORDER BY r.status = \'open\' DESC, r.created_at DESC
      LIMIT 200',
    $params
);

$counts = [
    'open'      => (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'open'"),
    'actioned'  => (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'actioned'"),
    'dismissed' => (int)q_val("SELECT COUNT(*) FROM reports WHERE status = 'dismissed'"),
];

page_head('Reports', [
    'active' => 'admin', 'adminBar' => true, 'adminActive' => 'reports',
    'wrap'   => 'wrap wrap--wide page',
]);
?>

<h1>Reports</h1>
<p class="muted">Members flag anything that breaks the guidelines. Every one lands here.</p>

<div class="toolbar">
  <?php foreach (['open' => 'Open', 'actioned' => 'Actioned', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $label): ?>
    <a class="btn <?= $filter === $k ? '' : 'btn--ghost' ?> btn--sm" href="reports.php?status=<?= $k ?>">
      <?= e($label) ?><?= isset($counts[$k]) && $counts[$k] ? ' (' . $counts[$k] . ')' : '' ?>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!$reports): ?>
  <div class="card empty">
    <h3>Nothing here</h3>
    <p><?= $filter === 'open' ? 'No open reports. Everything is calm.' : 'No reports with that status.' ?></p>
  </div>
<?php else: ?>
  <div class="stack">
    <?php foreach ($reports as $r): ?>
      <div class="card" id="r<?= (int)$r['id'] ?>">
        <div class="card__body">
          <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
            <?= avatar_html(['id' => $r['reported_id'], 'name' => $r['reported_name'], 'avatar' => $r['reported_avatar']], 48) ?>

            <div style="flex:1;min-width:220px">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <strong><a href="user.php?id=<?= (int)$r['reported_id'] ?>"><?= e($r['reported_name']) ?></a></strong>
                <span class="chip <?= $r['reported_status'] === 'suspended' ? 'chip--danger' : '' ?>"><?= e($r['reported_status']) ?></span>
                <span class="chip <?= $r['status'] === 'open' ? 'chip--warn' : 'chip--accent' ?>"><?= e($r['status']) ?></span>
              </div>

              <p style="margin:8px 0 4px"><strong><?= e($r['reason']) ?></strong></p>
              <?php if ($r['details']): ?>
                <p class="muted small" style="margin:0 0 8px">&ldquo;<?= e($r['details']) ?>&rdquo;</p>
              <?php endif; ?>

              <p class="tiny muted" style="margin:0">
                Reported by <a href="user.php?id=<?= (int)$r['reporter_id'] ?>"><?= e($r['reporter_name']) ?></a>
                on <?= e(pretty_date($r['created_at'])) ?>
                <?php if ($r['reviewed_at']): ?>
                  &middot; reviewed by <?= e($r['reviewer_name'] ?: 'a removed admin') ?> <?= e(time_ago($r['reviewed_at'])) ?>
                <?php endif; ?>
              </p>
            </div>

            <?php if ($r['status'] === 'open'): ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
                <?php
                  $conv = find_conversation((int)$r['reporter_id'], (int)$r['reported_id']);
                  if ($conv): ?>
                  <a class="btn btn--ghost btn--sm" href="conversation.php?id=<?= (int)$conv['id'] ?>">Read the chat</a>
                <?php endif; ?>

                <form method="post" style="display:inline"
                      onsubmit="return confirm('Suspend <?= e($r['reported_name']) ?> and close this report?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="suspend">
                  <button class="btn btn--danger btn--sm" type="submit">Suspend member</button>
                </form>

                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="actioned">
                  <button class="btn btn--sm" type="submit">Mark actioned</button>
                </form>

                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="report_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="action" value="dismiss">
                  <button class="btn btn--ghost btn--sm" type="submit">Dismiss</button>
                </form>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php page_foot(); ?>
