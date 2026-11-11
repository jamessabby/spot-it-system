<?php
/**
 * S.P.O.T.-IT — Room-specific equipment categories (Room Monitor second dropdown)
 * auth/room_categories.php
 *
 *   GET  room_id                        -> the room's categories, every saved ROI with the
 *                                          category it belongs to, and the category shown now
 *   POST action=select, room_id, name   -> show only that category's ROI boxes on the video
 *   POST action=save,   room_id,
 *        categories (JSON [{name, keywords[]}]),
 *        assignments (JSON {roi_label: category name})               [admin only]
 *
 * An ROI belongs to the category it is explicitly assigned to in the editor; an
 * unassigned ROI falls back to keyword matching on its label ("Keyboard" ->
 * keyboard, kb), so existing rooms work with no setup. Assignments are stored
 * here, NOT in the ROI/calibration files, which stay untouched.
 *
 * The ROI list is the room's saved registration: registered_lab_items (DB) plus
 * the room's rois_<ROOM>.json when present (local deploy) — the union, so a label
 * that exists in either is never hidden from the editor.
 *
 * Selecting a category is DISPLAY ONLY: main.py hides the other boxes on the video
 * but keeps monitoring, timing and alerting on every registered ROI. Separate from
 * the detection-mode (pipeline) dropdown. Storage: room_categories.json (no DB
 * change; web access denied in .htaccess). Rooms without an entry use defaults by
 * room type.
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/detector_state.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}
session_write_close();

const ROOMCAT_FILE = __DIR__ . '/../room_categories.json';

function roomcat_defaults(string $roomType): array
{
    if (stripos($roomType, 'electronic') !== false) {
        return [
            ['name' => 'Oscilloscope',       'keywords' => ['oscilloscope', 'scope']],
            ['name' => 'Power Supply',       'keywords' => ['powersupply', 'power_supply', 'psu', 'supply']],
            ['name' => 'Function Generator', 'keywords' => ['function', 'generator', 'funcgen']],
        ];
    }
    return [
        ['name' => 'Keyboard', 'keywords' => ['keyboard', 'kb']],
        ['name' => 'Mouse',    'keywords' => ['mouse']],
        ['name' => 'Monitor',  'keywords' => ['monitor', 'screen', 'display']],
        ['name' => 'PC Unit',  'keywords' => ['pc', 'cpu', 'tower', 'unit', 'computer']],
    ];
}

function roomcat_all(): array
{
    $d = is_file(ROOMCAT_FILE) ? json_decode((string) file_get_contents(ROOMCAT_FILE), true) : null;
    return is_array($d) ? $d : [];
}

/** Pure: does an ROI label match a category's keywords? */
function roomcat_matches(string $label, array $keywords): bool
{
    $low = strtolower($label);
    foreach ($keywords as $k) {
        if ($k !== '' && str_contains($low, strtolower($k))) {
            return true;
        }
    }
    return false;
}

/** Pure: the category each label belongs to — explicit assignment first, then keywords. */
function roomcat_resolve(array $labels, array $categories, array $assignments): array
{
    $names = array_column($categories, 'name');
    $out = [];
    foreach ($labels as $label) {
        $assigned = $assignments[$label] ?? null;
        if ($assigned !== null && in_array($assigned, $names, true)) {
            $out[$label] = ['category' => $assigned, 'via' => 'assigned'];
            continue;
        }
        $out[$label] = ['category' => null, 'via' => null];
        foreach ($categories as $c) {
            if (roomcat_matches($label, $c['keywords'])) {
                $out[$label] = ['category' => $c['name'], 'via' => 'keyword'];
                break;
            }
        }
    }
    return $out;
}

/** Saved ROI labels for the room: registered_lab_items ∪ rois_<ROOM>.json. */
function roomcat_labels(PDO $monitorPdo, string $roomId): array
{
    $labels = ms_registered_labels($monitorPdo, $roomId);
    $roiFile = detector_roi_file($roomId);
    if (is_file($roiFile)) {
        foreach ((json_decode((string) file_get_contents($roiFile), true) ?: []) as $r) {
            $labels[] = (string) ($r['label'] ?? '');
        }
    }
    $labels = array_values(array_unique(array_filter(array_map('trim', $labels))));
    sort($labels, SORT_NATURAL | SORT_FLAG_CASE);
    return $labels;
}

$roomId = detector_clean_room($_REQUEST['room_id'] ?? '');
if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'Valid room_id required.'], 400);
}
$st = $monitorPdo->prepare('SELECT room_type FROM rooms WHERE room_id = ? LIMIT 1');
$st->execute([$roomId]);
$roomType = (string) ($st->fetchColumn() ?: '');
$all = roomcat_all();
$entry = $all[$roomId] ?? null;
// Backward compatible with the first format (a bare list of categories).
if (is_array($entry) && !isset($entry['categories'])) {
    $entry = ['categories' => $entry, 'assignments' => []];
}
$isDefault   = $entry === null;
$categories  = $entry['categories'] ?? roomcat_defaults($roomType);
$assignments = is_array($entry['assignments'] ?? null) ? $entry['assignments'] : [];
$labels      = roomcat_labels($monitorPdo, $roomId);

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if ($role !== 'admin') {
        ms_json(['success' => false, 'message' => 'Only an admin can change room categories.'], 403);
    }
    $in = json_decode((string) ($_POST['categories'] ?? ''), true);
    if (!is_array($in) || count($in) > 12) {
        ms_json(['success' => false, 'message' => 'Provide 0-12 categories.'], 400);
    }
    $clean = [];
    $seen  = [];
    foreach ($in as $c) {
        $name = trim((string) ($c['name'] ?? ''));
        $kws = array_values(array_unique(array_filter(array_map(
            fn($k) => preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $k))),
            (array) ($c['keywords'] ?? [])
        ), fn($k) => $k !== '' && strlen($k) <= 30)));
        if ($name === '' || mb_strlen($name) > 40 || strcasecmp($name, 'All') === 0) {
            ms_json(['success' => false, 'message' => "Each category needs a name (not 'All', max 40 characters)."], 400);
        }
        if (isset($seen[strtolower($name)])) {
            ms_json(['success' => false, 'message' => "Duplicate category name '{$name}'."], 400);
        }
        $seen[strtolower($name)] = true;
        $clean[] = ['name' => $name, 'keywords' => $kws];
    }
    $names = array_column($clean, 'name');
    $inAssign = json_decode((string) ($_POST['assignments'] ?? '{}'), true);
    $cleanAssign = [];
    foreach (is_array($inAssign) ? $inAssign : [] as $label => $cat) {
        $label = trim((string) $label);
        $cat   = trim((string) $cat);
        // Only saved ROIs, only categories that exist; '' clears an assignment.
        if ($label !== '' && in_array($label, $labels, true) && $cat !== '' && in_array($cat, $names, true)) {
            $cleanAssign[$label] = $cat;
        }
    }
    $all[$roomId] = ['categories' => $clean, 'assignments' => $cleanAssign];
    $tmp = ROOMCAT_FILE . '.tmp';
    if (@file_put_contents($tmp, json_encode($all, JSON_PRETTY_PRINT)) === false || !@rename($tmp, ROOMCAT_FILE)) {
        ms_json(['success' => false, 'message' => 'Could not save categories.'], 500);
    }
    ms_json(['success' => true, 'room_id' => $roomId, 'categories' => $clean, 'assignments' => $cleanAssign]);
}

$resolved = roomcat_resolve($labels, $categories, $assignments);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'select') {
    $name = trim((string) ($_POST['name'] ?? 'All'));
    $keywords = [];
    $show = [];
    if (strcasecmp($name, 'All') !== 0) {
        foreach ($categories as $c) {
            if ($c['name'] === $name) {
                $keywords = $c['keywords'];
                $show = array_keys(array_filter($resolved, fn($r) => $r['category'] === $name));
            }
        }
        if (!$keywords && !$show) {
            ms_json(['success' => false, 'message' => "Unknown category '{$name}' for {$roomId}."], 400);
        }
    }
    $modeFile = detector_mode_file();
    $mode = is_file($modeFile) ? (json_decode((string) file_get_contents($modeFile), true) ?: []) : [];
    $selected = ($keywords || $show) ? $name : 'All';
    // `labels` is the exact list main.py shows; `keywords` kept for older detectors.
    $mode['display_filter'] = ['room_id' => $roomId, 'name' => $selected, 'keywords' => $keywords, 'labels' => array_values($show)];
    if (@file_put_contents($modeFile, json_encode($mode, JSON_PRETTY_PRINT)) === false) {
        ms_json(['success' => false, 'message' => 'Could not update the display filter.'], 500);
    }
    ms_json(['success' => true, 'room_id' => $roomId, 'selected' => $selected, 'shown' => array_values($show),
             'note' => 'Display only — every registered ROI is still monitored.']);
}

// GET
$out = [];
foreach ($categories as $c) {
    $hits = array_keys(array_filter($resolved, fn($r) => $r['category'] === $c['name']));
    $out[] = ['name' => $c['name'], 'keywords' => $c['keywords'], 'roi_count' => count($hits), 'rois' => array_values($hits)];
}
$modeFile = detector_mode_file();
$mode = is_file($modeFile) ? (json_decode((string) file_get_contents($modeFile), true) ?: []) : [];
$df = $mode['display_filter'] ?? [];
ms_json([
    'success'      => true,
    'room_id'      => $roomId,
    'room_type'    => $roomType,
    'is_default'   => $isDefault,
    'categories'   => $out,
    'rois'         => array_map(fn($l) => ['label' => $l] + $resolved[$l], $labels),
    'roi_total'    => count($labels),
    'uncategorised'=> array_values(array_keys(array_filter($resolved, fn($r) => $r['category'] === null))),
    'selected'     => (($df['room_id'] ?? '') === $roomId) ? ($df['name'] ?? 'All') : 'All',
]);
