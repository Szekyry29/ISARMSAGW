  </div><!-- /.page-content -->
</main><!-- /.main -->

<script>
/* ── Global Toast ── */
function showToast(msg, type = 'success') {
  const colors = {
    success: '#16a34a',
    error:   '#dc2626',
    info:    '#1a3a8c',
    warning: '#d97706',
  };
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = `
    position:fixed; bottom:24px; right:24px; z-index:9999;
    padding:13px 20px; border-radius:11px;
    font-size:13.5px; font-weight:600; color:#fff;
    background:${colors[type]||colors.info};
    box-shadow:0 6px 24px rgba(0,0,0,.18);
    animation:toastIn .3s ease;
    max-width:340px; line-height:1.4;
  `;
  const style = document.createElement('style');
  style.textContent = `@keyframes toastIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}`;
  document.head.appendChild(style);
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

/* ── Modal helpers ── */
function openModal(id)  { const m = document.getElementById(id); if(m) { m.style.display='flex'; } }
function closeModal(id) { const m = document.getElementById(id); if(m) { m.style.display='none'; } }

const notificationButton = document.getElementById('notificationButton');
const notificationPopover = document.getElementById('notificationPopover');
const notificationList = document.getElementById('notificationList');
if (notificationButton && notificationPopover) {
  notificationButton.addEventListener('click', async () => {
    const opening = notificationPopover.hidden;
    notificationPopover.hidden = !opening;
    notificationButton.setAttribute('aria-expanded', opening ? 'true' : 'false');
    if (!opening) return;
    const response = await fetch('<?= BASE_URL ?>api/notifications.php?action=list');
    const data = await response.json();
    notificationList.innerHTML = (data.notifications || []).slice(0, 8).map(n =>
      `<button type="button" class="notification-item ${Number(n.isRead) ? '' : 'unread'}" data-notification-id="${n.notifID}">${escapeHtml(n.title)}<small>${escapeHtml(n.message)}</small></button>`
    ).join('') || '<div class="notification-empty">No notifications yet.</div>';
    fetch('<?= BASE_URL ?>api/notifications.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({action:'mark_read'})
    }).then(() => {
      notificationList.querySelectorAll('.notification-item').forEach(item => item.classList.remove('unread'));
      clearNotificationBadge();
    });
    notificationList.querySelectorAll('[data-notification-id]').forEach(item => item.addEventListener('click', () => {
      fetch('<?= BASE_URL ?>api/notifications.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'mark_read', notifID:item.dataset.notificationId})})
        .then(() => clearNotificationBadge(item));
    }));
  });
  document.addEventListener('click', event => { if (!notificationButton.contains(event.target) && !notificationPopover.contains(event.target)) notificationPopover.hidden = true; });
}
function escapeHtml(value) { const div = document.createElement('div'); div.textContent = value ?? ''; return div.innerHTML; }
document.querySelectorAll('[data-notification-id]').forEach(item => item.addEventListener('click', () => {
  fetch('<?= BASE_URL ?>api/notifications.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'mark_read', notifID:item.dataset.notificationId})})
    .then(() => clearNotificationBadge(item));
  item.style.background = '#fff';
  const title = item.querySelector('span');
  if (title) title.style.fontWeight = '600';
}));
function clearNotificationBadge(item) {
  if (item) item.classList.remove('unread');
  fetch('<?= BASE_URL ?>api/notifications.php?action=count')
    .then(response => response.json())
    .then(data => {
      const button = document.getElementById('notificationButton');
      if (!button) return;
      let badge = button.querySelector('.notif-badge');
      const count = Number(data.count || 0);
      if (count <= 0) {
        if (badge) badge.remove();
        return;
      }
      if (!badge) {
        badge = document.createElement('span');
        badge.className = 'notif-badge';
        button.appendChild(badge);
      }
      badge.textContent = count > 9 ? '9+' : String(count);
    })
    .catch(() => {});
}

/* ── Close modal on backdrop click ── */
document.querySelectorAll('.modal-overlay, [id$="Modal"]').forEach(m => {
  m.addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
  });
});
</script>
</body>
</html>
