<?php
declare(strict_types=1);
/* Serves logos stored in the database (Vercel's disk is read-only). Public on purpose: logos appear on the login page. */
require_once __DIR__ . '/app/functions.php';

$k = (string)($_GET['k'] ?? '');
if (!array_key_exists($k, logo_slots())) {
    http_response_code(404);
    exit;
}
try {
    $q = db()->prepare('SELECT mime,data FROM site_assets WHERE k=?');
    $q->execute([$k]);
    $r = $q->fetch();
} catch (Throwable $e) {
    $r = false;
}
if (!$r || !in_array($r['mime'], ['image/png', 'image/jpeg', 'image/webp', 'image/gif'], true)) {
    // fall back to the bundled logo so pages never show a broken image
    $fallback = __DIR__ . '/../public/assets/logo.jpg';
    if ($k === 'main' && is_file($fallback)) {
        header('Content-Type: image/jpeg');
        readfile($fallback);
        exit;
    }
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $r['mime']);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');   // URL carries ?v=timestamp, so a new upload busts the cache
echo $r['data'];
