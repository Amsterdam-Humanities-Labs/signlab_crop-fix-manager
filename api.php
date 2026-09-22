<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/sc_paths.php';

// The crop-fix queue is runtime state. It lives OUTSIDE the checkout, at
// <webroot>/videofix_data/crop_fixes.json, so a redeploy (git reset --hard +
// clean -fd) cannot revert or delete it. seed/crop_fixes.json is the copy that
// used to be tracked here: a missing data file is seeded from it on first use;
// an existing one is never touched.
define('CROP_FIXES_FILE', sc_path('videofix_data', 'crop_fixes.json'));
define('CROP_FIXES_SEED', __DIR__ . '/seed/crop_fixes.json');

// .htaccess maps /videoFix/crop_fixes.json here: signlab_drs fetches that URL.
// Answered before the DB config/connect, as the static file it replaces was.
if (($_GET['action'] ?? '') === 'crop_fixes_json') {
    echo json_encode(["fixes" => readCropFixes()], JSON_PRETTY_PRINT);
    exit;
}

// Include database configuration
require_once 'mysql_config.php';

// Create database connection
$conn = new mysqli($servername, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(["error" => "Connection failed: " . $conn->connect_error]));
}

// Process API requests
$action = $_GET['action'] ?? 'search';

switch ($action) {
    case 'search':
        searchMFile($conn);
        break;
    case 'add_fix':
        addFix($conn);
        break;
    case 'update_status':
        updateStatus();
        break;
    case 'get_fixes':
        getFixes();
        break;
    case 'get_resolved':
        getResolved();
        break;
    case 'get_unresolved':
        getUnresolved();
        break;
    case 'update_oob':
        updateOob();
        break;
    case 'remove_fix':
        removeFix();
        break;
    case 'populate_from_labels':
        populateFromLabels($conn);
        break;
    default:
        echo json_encode(["error" => "Invalid action"]);
}

$conn->close();

/**
 * Search matched_transcriptions by m_file with wildcard
 */
function searchMFile($conn) {
    $query = $_GET['query'] ?? '';

    if (empty($query)) {
        echo json_encode(["error" => "Query parameter is required"]);
        return;
    }

    // Add wildcards for LIKE search
    $searchPattern = '%' . $query . '%';

    $sql = "SELECT id, m_file, l_file, r_file, date, rendered, post_processed, added
            FROM matched_transcriptions
            WHERE m_file LIKE ?
            ORDER BY id DESC
            LIMIT 50";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $searchPattern);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    // Merge with crop fix status
    $fixes = readCropFixes();
    $fixesMap = [];
    foreach ($fixes as $fix) {
        $fixesMap[$fix['m_file']] = $fix;
    }

    foreach ($rows as &$row) {
        if (isset($fixesMap[$row['m_file']])) {
            $fix = $fixesMap[$row['m_file']];
            $row['crop_fix_requested_at'] = $fix['requested_at'];
            $row['oob'] = $fix['oob'] ?? ["top" => false, "left" => false, "right" => false, "bottom" => false];

            // Extract status for each file type
            if (isset($fix['files']) && is_array($fix['files'])) {
                foreach ($fix['files'] as $fileEntry) {
                    $fileType = $fileEntry['type'];
                    $row[$fileType . '_crop_status'] = $fileEntry['status'];
                    $row[$fileType . '_crop_resolved_at'] = $fileEntry['resolved_at'];
                }
            }

            // Set defaults for files that don't have crop fix entries
            foreach (['m_file', 'l_file', 'r_file'] as $fileType) {
                if (!isset($row[$fileType . '_crop_status'])) {
                    $row[$fileType . '_crop_status'] = null;
                    $row[$fileType . '_crop_resolved_at'] = null;
                }
            }
        } else {
            // No crop fix found for this m_file
            $row['crop_fix_requested_at'] = null;
            $row['oob'] = null;
            $row['m_file_crop_status'] = null;
            $row['l_file_crop_status'] = null;
            $row['r_file_crop_status'] = null;
            $row['m_file_crop_resolved_at'] = null;
            $row['l_file_crop_resolved_at'] = null;
            $row['r_file_crop_resolved_at'] = null;
        }
    }

    echo json_encode($rows);
    $stmt->close();
}

/**
 * Add a new crop fix request
 */
function addFix($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $m_file = $input['m_file'] ?? '';

    if (empty($m_file)) {
        echo json_encode(["error" => "m_file is required"]);
        return;
    }

    // Validate m_file format (basic check)
    if (!preg_match('/^[A-Za-z0-9_\-\.]+$/', $m_file)) {
        echo json_encode(["error" => "Invalid m_file format"]);
        return;
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
        echo json_encode(["error" => "m_file not found in matched_transcriptions"]);
        return;
    }

    $fixes = readCropFixes();

    // Check if already exists
    foreach ($fixes as $fix) {
        if ($fix['m_file'] === $m_file || (isset($fix['id']) && $fix['id'] === $m_file)) {
            echo json_encode(["error" => "Crop fix already requested for this file"]);
            return;
        }
    }

    // Build files array (skip NULL/empty)
    $filesArray = [];
    foreach (['m_file', 'l_file', 'r_file'] as $fileType) {
        if (!empty($row[$fileType])) {
            $filesArray[] = [
                "file" => $row[$fileType],
                "type" => $fileType,
                "status" => "unresolved",
                "resolved_at" => null
            ];
        }
    }

    // Validate that at least one file exists
    if (empty($filesArray)) {
        echo json_encode(["error" => "No valid files found for this m_file"]);
        return;
    }

    // Parse OOB sides (default all false)
    $defaultOob = ["top" => false, "left" => false, "right" => false, "bottom" => false];
    $oob = $defaultOob;
    if (isset($input['oob']) && is_array($input['oob'])) {
        foreach ($defaultOob as $side => $_) {
            if (isset($input['oob'][$side])) {
                $oob[$side] = (bool) $input['oob'][$side];
            }
        }
    }

    // Create new fix with files array
    $newFix = [
        "id" => $m_file,
        "m_file" => $m_file,
        "requested_at" => date('c'),
        "oob" => $oob,
        "files" => $filesArray
    ];

    $fixes[] = $newFix;

    if (writeCropFixes($fixes)) {
        echo json_encode(["success" => true, "message" => "Crop fix requested", "fix" => $newFix]);
    } else {
        echo json_encode(["error" => "Failed to write crop fixes file"]);
    }
}

/**
 * Update status of a crop fix (for render server)
 */
function updateStatus() {
    $input = json_decode(file_get_contents('php://input'), true);
    $m_file = $input['m_file'] ?? '';
    $file = $input['file'] ?? ''; // The specific file to update
    $status = $input['status'] ?? '';

    if (empty($m_file) || empty($file) || empty($status)) {
        echo json_encode(["error" => "m_file, file, and status are required"]);
        return;
    }

    if (!in_array($status, ['unresolved', 'resolved'])) {
        echo json_encode(["error" => "Status must be 'unresolved' or 'resolved'"]);
        return;
    }

    $fixes = readCropFixes();
    $found = false;

    foreach ($fixes as &$fix) {
        if ($fix['m_file'] === $m_file || (isset($fix['id']) && $fix['id'] === $m_file)) {
            // Find and update the specific file
            if (isset($fix['files']) && is_array($fix['files'])) {
                foreach ($fix['files'] as &$fileEntry) {
                    if ($fileEntry['file'] === $file) {
                        $fileEntry['status'] = $status;
                        if ($status === 'resolved') {
                            $fileEntry['resolved_at'] = date('c');
                        } else {
                            $fileEntry['resolved_at'] = null;
                        }
                        $found = true;
                        break 2;
                    }
                }
            }
        }
    }

    if (!$found) {
        echo json_encode(["error" => "Crop fix or file not found"]);
        return;
    }

    if (writeCropFixes($fixes)) {
        echo json_encode(["success" => true, "message" => "Status updated to $status for file $file"]);
    } else {
        echo json_encode(["error" => "Failed to write crop fixes file"]);
    }
}

/**
 * Update OOB sides on an existing crop fix
 */
function updateOob() {
    $input = json_decode(file_get_contents('php://input'), true);
    $m_file = $input['m_file'] ?? '';

    if (empty($m_file)) {
        echo json_encode(["error" => "m_file is required"]);
        return;
    }

    if (!isset($input['oob']) || !is_array($input['oob'])) {
        echo json_encode(["error" => "oob object is required"]);
        return;
    }

    $defaultOob = ["top" => false, "left" => false, "right" => false, "bottom" => false];
    $oob = $defaultOob;
    foreach ($defaultOob as $side => $_) {
        if (isset($input['oob'][$side])) {
            $oob[$side] = (bool) $input['oob'][$side];
        }
    }

    $fixes = readCropFixes();
    $found = false;

    foreach ($fixes as &$fix) {
        if ($fix['m_file'] === $m_file || (isset($fix['id']) && $fix['id'] === $m_file)) {
            $fix['oob'] = $oob;
            $found = true;
            break;
        }
    }

    if (!$found) {
        echo json_encode(["error" => "Crop fix not found for m_file: $m_file"]);
        return;
    }

    if (writeCropFixes($fixes)) {
        echo json_encode(["success" => true, "message" => "OOB updated", "oob" => $oob]);
    } else {
        echo json_encode(["error" => "Failed to write crop fixes file"]);
    }
}

/**
 * Remove a crop fix (allows resubmission)
 */
function removeFix() {
    $input = json_decode(file_get_contents('php://input'), true);
    $m_file = $input['m_file'] ?? '';

    if (empty($m_file)) {
        echo json_encode(["error" => "m_file is required"]);
        return;
    }

    $fixes = readCropFixes();
    $found = false;
    $fixes = array_values(array_filter($fixes, function($fix) use ($m_file, &$found) {
        if ($fix['m_file'] === $m_file || (isset($fix['id']) && $fix['id'] === $m_file)) {
            $found = true;
            return false;
        }
        return true;
    }));

    if (!$found) {
        echo json_encode(["error" => "Crop fix not found for m_file: $m_file"]);
        return;
    }

    if (writeCropFixes($fixes)) {
        echo json_encode(["success" => true, "message" => "Crop fix removed for $m_file"]);
    } else {
        echo json_encode(["error" => "Failed to write crop fixes file"]);
    }
}

/**
 * Get all crop fixes
 */
function getFixes() {
    $fixes = readCropFixes();
    echo json_encode($fixes);
}

/**
 * Get only resolved fixes
 */
function getResolved() {
    $fixes = readCropFixes();
    $resolved = array_filter($fixes, function($fix) {
        // A fix is resolved if ALL files are resolved
        if (!isset($fix['files']) || !is_array($fix['files'])) {
            return false;
        }

        foreach ($fix['files'] as $fileEntry) {
            if ($fileEntry['status'] !== 'resolved') {
                return false;
            }
        }

        return true;
    });
    echo json_encode(array_values($resolved));
}

/**
 * Get only unresolved fixes
 */
function getUnresolved() {
    $fixes = readCropFixes();
    $unresolved = array_filter($fixes, function($fix) {
        // A fix is unresolved if ANY file is unresolved
        if (!isset($fix['files']) || !is_array($fix['files'])) {
            return true; // Treat malformed entries as unresolved
        }

        foreach ($fix['files'] as $fileEntry) {
            if ($fileEntry['status'] === 'unresolved') {
                return true;
            }
        }

        return false;
    });
    echo json_encode(array_values($unresolved));
}

/**
 * Populate crop fixes from form_data with "GEBAAR UIT BEELD" label
 */
function populateFromLabels($conn) {
    // Query to find m_file, l_file, r_file from matched_transcriptions
    // where form_data has "GEBAAR UIT BEELD" label
    // and zOg is 'label', 'glos', or 'extern'
    $sql = "SELECT DISTINCT mt.m_file, mt.l_file, mt.r_file
            FROM form_data fd
            INNER JOIN matched_transcriptions mt
              ON mt.definitive_outcome = fd.id
            WHERE fd.labels LIKE '%GEBAAR UIT DE BEELD%'
              AND mt.zOg IN ('labels', 'glos', 'extern')
              AND mt.m_file IS NOT NULL
              AND mt.m_file != ''";

    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(["error" => "Query failed: " . $conn->error]);
        return;
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    if (empty($rows)) {
        echo json_encode(["success" => true, "added" => 0, "skipped" => 0, "message" => "No matching videos found"]);
        return;
    }

    // Read existing fixes
    $fixes = readCropFixes();
    $existingFiles = array_column($fixes, 'm_file');

    $added = 0;
    $skipped = 0;

    foreach ($rows as $row) {
        $m_file = $row['m_file'];

        if (in_array($m_file, $existingFiles)) {
            $skipped++;
            continue;
        }

        // Build files array (skip NULL/empty)
        $filesArray = [];
        foreach (['m_file', 'l_file', 'r_file'] as $fileType) {
            if (!empty($row[$fileType])) {
                $filesArray[] = [
                    "file" => $row[$fileType],
                    "type" => $fileType,
                    "status" => "unresolved",
                    "resolved_at" => null
                ];
            }
        }

        // Only add if at least one file exists
        if (!empty($filesArray)) {
            $fixes[] = [
                "id" => $m_file,
                "m_file" => $m_file,
                "requested_at" => date('c'),
                "oob" => ["top" => false, "left" => false, "right" => false, "bottom" => false],
                "files" => $filesArray
            ];
            $added++;
        }
    }

    if ($added > 0) {
        if (!writeCropFixes($fixes)) {
            echo json_encode(["error" => "Failed to write crop fixes file"]);
            return;
        }
    }

    echo json_encode([
        "success" => true,
        "added" => $added,
        "skipped" => $skipped,
        "message" => "Added $added crop fixes, skipped $skipped existing"
    ]);
}

/**
 * Read crop fixes from JSON file
 */
function readCropFixes() {
    cropFixesReady();
    $file = file_exists(CROP_FIXES_FILE) ? CROP_FIXES_FILE : CROP_FIXES_SEED;
    if (!file_exists($file)) {
        return [];
    }

    $content = file_get_contents($file);
    $data = json_decode($content, true);

    if ($data === null || !isset($data['fixes'])) {
        return [];
    }

    $fixes = $data['fixes'];

    // Auto-migrate old format to new format
    $migrated = false;
    foreach ($fixes as &$fix) {
        // Detect old format (no 'files' array)
        if (!isset($fix['files']) && isset($fix['m_file'])) {
            $fix['id'] = $fix['m_file'];
            $fix['files'] = [[
                "file" => $fix['m_file'],
                "type" => "m_file",
                "status" => $fix['status'] ?? 'unresolved',
                "resolved_at" => $fix['resolved_at'] ?? null
            ]];
            unset($fix['status']);
            unset($fix['resolved_at']);
            $migrated = true;
        }

        // Auto-migrate: add default oob if missing
        if (!isset($fix['oob'])) {
            $fix['oob'] = ["top" => false, "left" => false, "right" => false, "bottom" => false];
            $migrated = true;
        }
    }

    // Save migrated data
    if ($migrated) {
        writeCropFixes($fixes);
    }

    return $fixes;
}

/**
 * Create the data dir and seed the data file if missing. False when the dir
 * cannot be created (reads then fall back to the seed, writes fail).
 */
function cropFixesReady() {
    $dir = dirname(CROP_FIXES_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        error_log('videoFix: cannot create ' . $dir);
        return false;
    }
    if (!file_exists(CROP_FIXES_FILE) && file_exists(CROP_FIXES_SEED)) {
        @copy(CROP_FIXES_SEED, CROP_FIXES_FILE);
    }
    return true;
}

/**
 * Write crop fixes to JSON file with file locking
 */
function writeCropFixes($fixes) {
    $data = ["fixes" => $fixes];
    $json = json_encode($data, JSON_PRETTY_PRINT);

    if (!cropFixesReady()) {
        return false;
    }
    $fp = @fopen(CROP_FIXES_FILE, 'c');
    if ($fp === false) {
        return false;
    }

    // Acquire exclusive lock
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        return true;
    }

    fclose($fp);
    return false;
}
?>
