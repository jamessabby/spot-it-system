<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$action = $_POST['action'] ?? '';

if ($action === 'clear') {
    try {
        $monitorPdo->query("TRUNCATE TABLE accuracy_trials");
        ms_json(['success' => true, 'message' => 'Trial logs cleared.']);
    } catch (PDOException $e) {
        ms_json(['success' => false, 'message' => 'Failed to clear logs.']);
    }
}

$expected = $_POST['expected'] ?? '';
$detected = $_POST['detected'] ?? '';
$notes = trim($_POST['notes'] ?? '');

if (!in_array($expected, ['present', 'absent'], true) || !in_array($detected, ['present', 'absent'], true)) {
    ms_json(['success' => false, 'message' => 'Invalid classification parameters.']);
}

// Classification mapping:
// Positive: Absent (missing/deviation event occurred)
// Negative: Present (normal)
if ($expected === 'absent' && $detected === 'absent') {
    $classification = 'TP';
} elseif ($expected === 'present' && $detected === 'absent') {
    $classification = 'FP';
} elseif ($expected === 'present' && $detected === 'present') {
    $classification = 'TN';
} else { // expected === 'absent' && detected === 'present'
    $classification = 'FN';
}

try {
    $stmt = $monitorPdo->prepare("
        INSERT INTO accuracy_trials (expected_state, detected_state, classification, notes, trial_timestamp)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$expected, $detected, $classification, $notes]);
    ms_json(['success' => true, 'message' => 'Trial logged successfully.', 'classification' => $classification]);
} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
