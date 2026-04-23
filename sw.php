<?php
// sw.php — Service worker served via PHP so we can set no-cache headers.
// This is the fix for CloudFlare/browser caching the SW file itself,
// which prevents Chrome from ever seeing the updated version.
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
// Embed deploy timestamp so the file bytes always differ after a deploy,
// forcing Chrome to treat it as a new SW even if CF caches slipped through.
$ts = filemtime(__FILE__);
?>
// Goodbook Service Worker — built <?= date('Y-m-d H:i:s', $ts) ?> UTC
const CACHE = 'goodbook-v3-<?= $ts ?>';
const PRECACHE = [
  'assets/style.css',
  'assets/goodbook.js',
  'favicon.ico',
  'assets/icon-192.png',
  'assets/icon-512.png',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(PRECACHE))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(k => k !== CACHE).map(k => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);

  // Never intercept: non-GET, API calls, PHP pages
  if (e.request.method !== 'GET') return;
  if (url.pathname.endsWith('.php')) return;
  if (url.pathname.includes('/api/')) return;

  // Static assets — cache-first
  if (url.pathname.match(/\.(css|js|ico|png|jpg|jpeg|gif|webp|svg|woff2?)$/i)) {
    e.respondWith(
      caches.match(e.request).then(hit => {
        if (hit) return hit;
        return fetch(e.request).then(res => {
          if (res && res.status === 200) {
            caches.open(CACHE).then(c => c.put(e.request, res.clone()));
          }
          return res;
        });
      })
    );
  }
  // Everything else — network only, no caching of PHP responses
});
