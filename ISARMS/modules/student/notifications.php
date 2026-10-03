<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];

// Fetch all notifications
$notifications = $db->fetchAll("
    SELECT * FROM notifications
    WHERE recipientID=:id AND recipientType='Student'
    ORDER BY createdAt DESC
    LIMIT 50
", [':id' => $studentID]);

// Type to icon/color map
$typeMap = [
    'Grade'    => ['fa-star', 'blue',   'Grade Update'],
    'GWA'      => ['fa-calculator',    'gold',   'GWA Computed'],
    'Honors'   => ['fa-award',         'gold',   'Honors'],
    'Approval' => ['fa-check-double',  'green',  'Approval'],
    'System'   => ['fa-cog',           'purple', 'System'],
];

$pageTitle  = 'Notifications';
$activeMenu = 'notifications.php';
require_once ROOT . '/includes/header.php';
?>

<div style="max-width:720px;">

  <!-- Header Row -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <div style="font-size:13px;color:var(--text-muted);">
      <?= count($notifications) ?> notification(s) total
    </div>
    <?php if (!empty($notifications)): ?>
    <form method="POST" action="<?= BASE_URL ?>api/notifications.php" style="display:inline;">
      <input type="hidden" name="action" value="clear_student"/>
      <button type="button" class="btn btn-outline btn-sm" onclick="clearAll()">
        <i class="fas fa-trash"></i> Delete
      </button>
    </form>
    <?php endif; ?>
  </div>

  <?php if (empty($notifications)): ?>
  <div class="card">
    <div style="padding:60px;text-align:center;color:var(--text-muted);">
      <i class="fas fa-bell-slash" style="font-size:48px;display:block;margin-bottom:14px;opacity:.2;color:var(--blue);"></i>
      <div style="font-size:16px;font-weight:600;margin-bottom:8px;">No notifications yet</div>
      <div style="font-size:13px;">You'll be notified when your grades are approved, GWA is updated, or honors are confirmed.</div>
    </div>
  </div>
  <?php else: ?>

  <div class="card">
    <?php foreach ($notifications as $i => $n):
      [$icon, $color, $typeLabel] = $typeMap[$n['type']] ?? ['fa-bell','blue','Notification'];
      $isRead = (bool)$n['isRead'];
      $timeAgo = (function($dt) {
        $diff = time() - strtotime($dt);
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff/60) . 'm ago';
        if ($diff < 86400) return floor($diff/3600) . 'h ago';
        return date('M d, Y', strtotime($dt));
      })($n['createdAt']);
    ?>
    <div data-notification-id="<?= (int)$n['notifID'] ?>" style="display:flex;align-items:flex-start;gap:14px;padding:16px 20px;border-bottom:<?= $i<count($notifications)-1?'1px solid var(--border)':'none' ?>;background:<?= $isRead?'#fff':'var(--blue-light)' ?>;cursor:pointer;">
      <!-- Icon -->
      <div style="width:42px;height:42px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:17px;
        <?php echo match($color) {
          'blue'   => 'background:var(--blue-light);color:var(--blue);',
          'gold'   => 'background:var(--gold-light);color:var(--gold-deep);',
          'green'  => 'background:#dcfce7;color:var(--success);',
          'purple' => 'background:#f3e8ff;color:#9333ea;',
          default  => 'background:var(--blue-light);color:var(--blue);',
        }; ?>">
        <i class="fas <?= $icon ?>"></i>
      </div>
      <!-- Content -->
      <div style="flex:1;min-width:0;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:3px;">
          <span style="font-weight:<?= $isRead?'600':'700' ?>;font-size:13.5px;"><?= htmlspecialchars($n['title']) ?></span>
          <span style="font-size:10.5px;background:var(--blue-light);color:var(--blue);padding:2px 8px;border-radius:10px;font-weight:600;"><?= $typeLabel ?></span>
          <?php if (!$isRead): ?><span style="width:7px;height:7px;border-radius:50%;background:var(--blue);flex-shrink:0;"></span><?php endif; ?>
        </div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.6;"><?= htmlspecialchars($n['message']) ?></div>
        <div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;"><i class="fas fa-clock" style="margin-right:4px;"></i><?= $timeAgo ?></div>
      </div>
      <button type="button" class="btn btn-outline btn-sm" title="Delete notification" onclick="deleteNotification(event, <?= (int)$n['notifID'] ?>)"><i class="fas fa-trash"></i></button>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<script>
function deleteNotification(event, id) {
  event.stopPropagation();
  fetch('<?= BASE_URL ?>api/notifications.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete', notifID:id})}).then(() => location.reload());
}
function clearAll() {
  if (!confirm('Clear all notifications?')) return;
  fetch('<?= BASE_URL ?>api/notifications.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({action:'delete_all'})
  }).then(() => location.reload());
}
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>