<?php
// Reset all file statuses to unresolved

$jsonFile = __DIR__ . '/crop_fixes.json';
$content = file_get_contents($jsonFile);
$data = json_decode($content, true);

if (!isset($data['fixes'])) {
    die("Invalid JSON structure\n");
}

$fixes = $data['fixes'];
$updated = 0;

foreach ($fixes as &$fix) {
    if (isset($fix['files']) && is_array($fix['files'])) {
        foreach ($fix['files'] as &$fileEntry) {
            if ($fileEntry['status'] === 'resolved') {
                $fileEntry['status'] = 'unresolved';
                $fileEntry['resolved_at'] = null;
                $updated++;
            }
        }
    }
}

// Write updated JSON back to file
$json = json_encode(['fixes' => $fixes], JSON_PRETTY_PRINT);
file_put_contents($jsonFile, $json);

echo "Reset complete! Updated $updated files to unresolved status.\n";
?>
