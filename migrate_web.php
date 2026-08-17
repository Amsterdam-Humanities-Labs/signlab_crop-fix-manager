<?php
header('Content-Type: text/plain');

// Security check - only allow local access
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    die("Access denied\n");
}

// Migrate crop_fixes.json to include l_file and r_file from database

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
$updated = 0;

foreach ($fixes as &$fix) {
    $m_file = $fix['m_file'];

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

    // Get current m_file status and resolved_at
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
            "status" => $m_file_status, // Copy same status from m_file
            "resolved_at" => $m_file_resolved_at
        ];
    }

    // Add r_file if exists
    if (!empty($row['r_file'])) {
        $filesArray[] = [
            "file" => $row['r_file'],
            "type" => "r_file",
            "status" => $m_file_status, // Copy same status from m_file
            "resolved_at" => $m_file_resolved_at
        ];
    }

    // Update the fix entry
    $fix['files'] = $filesArray;
    $updated++;

    echo "Updated: $m_file (found " . count($filesArray) . " files)\n";
}

// Write updated JSON back to file
$json = json_encode(['fixes' => $fixes], JSON_PRETTY_PRINT);
$success = file_put_contents($jsonFile, $json);

if ($success === false) {
    die("\nError: Failed to write to crop_fixes.json\n");
}

echo "\nMigration complete! Updated $updated entries.\n";

$conn->close();
?>
