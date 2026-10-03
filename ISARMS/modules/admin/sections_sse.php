<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');
set_time_limit(30);
ignore_user_abort(false);
session_write_close();
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');
$feedDir = ROOT . '/storage';
$feedPath = $feedDir . '/sections_feed.json';
if (!is_dir($feedDir)) @mkdir($feedDir, 0755, true);
$lastMod = file_exists($feedPath) ? filemtime($feedPath) : 0;
$startTime = time();
$lastHeartbeat = $startTime;
while (!connection_aborted() && time() - $startTime < 25) {
    clearstatcache(false, $feedPath);
    $curMod = file_exists($feedPath) ? filemtime($feedPath) : 0;
    if ($curMod > $lastMod) {
        $data = file_get_contents($feedPath);
        if ($data !== false) {
            echo "data: " . trim($data) . "\n\n";
            @ob_flush(); @flush();
        }
        $lastMod = $curMod;
    } elseif (time() - $lastHeartbeat >= 10) {
        echo ": heartbeat\n\n";
        @ob_flush();
        @flush();
        $lastHeartbeat = time();
    }
    sleep(1);
}

?>