<?php
// includes/topbar.php — shared top navigation
// Requires $me (from current_user()) to be set in the including file.
// Falls back gracefully if not logged in.
$_topbar_me = current_user();
?>
<div id="topbar">
  <a href="<?= logged_in() ? 'home.php' : 'index.php' ?>" class="topbar-logo">G<span>oo</span>dbook</a>

  <?php if (logged_in()): ?>
    <div class="topbar-search">
      <input type="text" id="topbar-search-input" placeholder="Search people..." autocomplete="off">
      <div id="search-results"></div>
    </div>
    <div class="topbar-right">
      <a href="home.php" class="topbar-icon-btn" title="Home">🏠</a>
      <a href="profile.php?u=<?= h($_topbar_me['username']) ?>" class="topbar-icon-btn" title="Profile">👤</a>
      <a href="mojis.php" class="topbar-icon-btn" title="Mojis">✨</a>
      <a href="settings.php" class="topbar-icon-btn" title="Settings">⚙️</a>
      <a href="api/auth.php?action=logout" class="topbar-icon-btn topbar-logout" title="Log Out">↩ Log out</a>
    </div>
  <?php else: ?>
    <div style="flex:1;"></div>
    <a href="index.php" style="color:white;font-size:12px;text-decoration:none;">Log In / Sign Up →</a>
  <?php endif; ?>
</div>

<?php if (logged_in()): ?>
<script>
// Topbar people search
(function() {
  const inp = document.getElementById('topbar-search-input');
  const res = document.getElementById('search-results');
  let timer;
  if (!inp) return;
  inp.addEventListener('input', function() {
    clearTimeout(timer);
    const q = this.value.trim();
    if (q.length < 2) { res.innerHTML = ''; res.style.display = 'none'; return; }
    timer = setTimeout(() => {
      fetch('api/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
          if (!data.length) { res.innerHTML = '<div class="sr-none">No results</div>'; res.style.display = 'block'; return; }
          res.innerHTML = data.map(u => `
            <a href="profile.php?u=${encodeURIComponent(u.username)}" class="sr-item">
              <img src="${u.avatar_url}" style="width:28px;height:28px;border-radius:50%;object-fit:cover;">
              <div><strong>${u.display_name}</strong><br><span>@${u.username}</span></div>
            </a>
          `).join('');
          res.style.display = 'block';
        });
    }, 250);
  });
  document.addEventListener('click', e => {
    if (!inp.contains(e.target) && !res.contains(e.target)) {
      res.style.display = 'none';
    }
  });
})();
</script>
<?php endif; ?>
