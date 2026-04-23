<?php
// includes/pwa.php
// Injects PWA manifest as a JS-generated blob (required on InfinityFree —
// loading manifest.json directly is blocked by their security system).
// Also registers the service worker.
// Include this inside <head> on every page that should be PWA-capable.
if (!defined('SITE_NAME')) return;
$site_name  = addslashes(SITE_NAME);
$site_url   = addslashes(SITE_URL);
?>
<script id="manifest-placeholder"></script>
<script>
(function() {
  var baseUrl  = <?= json_encode(SITE_URL) ?>;
  var siteName = <?= json_encode(SITE_NAME) ?>;

  var manifestData = {
    "name":             siteName,
    "short_name":       siteName,
    "description":      "Connect with friends on " + siteName,
    "start_url":        baseUrl + "/home.php",
    "scope":            baseUrl + "/",
    "display":          "standalone",
    "background_color": "#e9ebee",
    "theme_color":      "#3b5998",
    "orientation":      "portrait-primary",
    "icons": [
      {
        "src":     baseUrl + "/assets/icon-192.png",
        "sizes":   "192x192",
        "type":    "image/png",
        "purpose": "any maskable"
      },
      {
        "src":     baseUrl + "/assets/icon-512.png",
        "sizes":   "512x512",
        "type":    "image/png",
        "purpose": "any maskable"
      }
    ]
  };

  try {
    var blob = new Blob([JSON.stringify(manifestData)], {type: 'application/json'});
    var blobUrl = URL.createObjectURL(blob);
    var link = document.createElement('link');
    link.rel  = 'manifest';
    link.href = blobUrl;
    document.getElementById('manifest-placeholder').replaceWith(link);
  } catch(e) {
    // Blob URL not supported (very old browser) — silently skip
  }

  // Register service worker
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function() {
      navigator.serviceWorker.register(<?= json_encode(SITE_URL . '/sw.php') ?>)
        .catch(function() {}); // Silently fail — SW is enhancement only
    });
  }
})();
</script>
<meta name="theme-color" content="#3b5998">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="<?= h(SITE_NAME) ?>">
<link rel="apple-touch-icon" href="assets/icon-180.png">
