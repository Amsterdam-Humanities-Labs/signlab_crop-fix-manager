<?php
// Add missing l_file and r_file for specific m_file entries

require_once 'mysql_config.php';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Read current crop_fixes.json
$jsonFile = __DIR__ . '/crop_fixes.json';
$content = file_get_contents($jsonFile);
$data = json_decode($content, true);

if (!isset($data['fixes'])) {
    die("Invalid JSON structure\n");
}

$fixes = $data['fixes'];

// M_files that need l_file and r_file added
$mFilesToUpdate = [
    'M20250818_3245.wav',
    'M20251219_8909.wav',
    'M20251219_8910.wav',
    'M20251219_9155.wav'
];

$updated = 0;

foreach ($fixes as &$fix) {
    $m_file = $fix['m_file'];

    // Check if this is one of the files we need to update
    if (!in_array($m_file, $mFilesToUpdate)) {
        continue;
    }

    // Query database for l_file and r_file
    $sql = "SELECT m_file, l_file, r_file FROM matched_transcriptions WHERE m_file = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $m_file);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo "Warning: m_file '$m_file' not found in database\n";
        continue;
    }

    // Get current m_file status and resolved_at from existing files array
    $m_file_status = 'unresolved';
    $m_file_resolved_at = null;

    if (isset($fix['files']) && is_array($fix['files'])) {
        foreach ($fix['files'] as $fileEntry) {
            if ($fileEntry['type'] === 'm_file') {
                $m_file_status = $fileEntry['status'];
                $m_file_resolved_at = $fileEntry['resolved_at'];
                break;
            }
        }
    }

    // Build new files array with all three files
    $filesArray = [];

    // Add m_file
    if (!empty($row['m_file'])) {
        $filesArray[] = [
            "file" => $row['m_file'],
            "type" => "m_file",
            "status" => $m_file_status,
            "resolved_at" => $m_file_resolved_at
        ];
    }

    // Add l_file if exists
    if (!empty($row['l_file'])) {
        $filesArray[] = [
            "file" => $row['l_file'],
            "type" => "l_file",
            "status" => "unresolved", // New files start as unresolved
            "resolved_at" => null
        ];
    }

    // Add r_file if exists
    if (!empty($row['r_file'])) {
        $filesArray[] = [
            "file" => $row['r_file'],
            "type" => "r_file",
            "status" => "unresolved", // New files start as unresolved
            "resolved_at" => null
        ];
    }

    // Update the fix entry
    $fix['files'] = $filesArray;
    $updated++;

    echo "Updated: $m_file (now has " . count($filesArray) . " files)\n";
}

// Write updated JSON back to file
$json = json_encode(['fixes' => $fixes], JSON_PRETTY_PRINT);
file_put_contents($jsonFile, $json);

echo "\nComplete! Updated $updated entries.\n";

$conn->close();
?>
