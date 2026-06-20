<?php
session_start();

if (!isset($_SESSION["usertable"]) || $_SESSION["usertable"]["usertype"] !== 'admin') {
    http_response_code(403);
    exit;
}

$output = shell_exec("sudo /usr/local/bin/animeitor-wrapper.sh urls 2>/dev/null");
$entries = [];
$current = null;
foreach (explode("\n", trim((string)$output)) as $line) {
    if (str_starts_with($line, '->')) {
        if ($current !== null) $entries[] = $current;
        $current = ['title' => trim(substr($line, 2)), 'items' => []];
    } elseif ($current !== null && trim($line) !== '') {
        if (preg_match('/^\s*(.+?)\s+em\s+(https?:\/\/\S+)$/', $line, $m)) {
            $current['items'][] = ['label' => trim($m[1]), 'url' => trim($m[2])];
        }
    }
}
if ($current !== null) $entries[] = $current;

header('Content-Type: application/json');
echo json_encode($entries);
