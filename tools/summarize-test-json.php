<?php

$path = $argv[1] ?? 'storage/logs/test-simp.txt';
$raw = file_get_contents($path);
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
$start = strpos($raw, '{');
if ($start !== false) {
    $raw = substr($raw, $start);
}
$j = json_decode($raw, true);
if (! is_array($j)) {
    echo "Not JSON. Head:\n".substr($raw, 0, 400)."\n";
    exit(1);
}

echo ($j['result'] ?? '?').' tests='.($j['tests'] ?? '?').' passed='.($j['passed'] ?? '?').' failed='.($j['failed'] ?? 0)."\n";
$items = [];
if (isset($j['failures']) && is_array($j['failures'])) {
    $items = array_merge($items, $j['failures']);
}
if (isset($j['errors']) && is_array($j['errors'])) {
    $items = array_merge($items, $j['errors']);
}
foreach ($items as $f) {
    if (! is_array($f)) {
        continue;
    }
    $msg = $f['message'] ?? '';
    echo ($f['test'] ?? '?').' line '.($f['line'] ?? '?')."\n";
    if (preg_match('/contains \"([^\"]+)\"/', $msg, $m)) {
        echo "  needle: {$m[1]}\n";
    } elseif (preg_match('/Expected response status code \[(\d+)\] but received (\d+)/', $msg, $m)) {
        echo "  status expected {$m[1]} got {$m[2]}\n";
    } else {
        echo '  '.substr(preg_replace('/\s+/', ' ', $msg), 0, 200)."\n";
    }
}
