<?php
/**
 * Tests: registered equipment vs. unregistered items, and their status transitions.
 *
 *   php tests/test_event_kinds.php
 *
 * Pure-function tests — no database is read or written. The labels below mirror
 * the real registered ROIs in MLH305 (tier1) and MLH301 (tier2).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../auth/event_kinds.php';

$pass = $fail = 0;
function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}\n        expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

$MLH305 = ['keyboard1', 'keyboard2', 'keyboard3', 'keyboard4', 'mouse1', 'mouse2'];
$MLH301 = ['oscilloscope_far', 'oscilloscope_mid', 'powersupply_mid', 'powersupply_near'];
$ev = fn(string $zone, array $extra = []) => array_merge(['object_zone' => $zone, 'object_type' => $zone], $extra);

echo "== Event kind\n";
check('registered keyboard (tier1)', ms_event_kind($ev('keyboard2'), $MLH305), EVENT_KIND_REGISTERED);
check('registered mouse (tier1)', ms_event_kind($ev('mouse1'), $MLH305), EVENT_KIND_REGISTERED);
check('registered oscilloscope (tier2 — still lab property)', ms_event_kind($ev('oscilloscope_mid'), $MLH301), EVENT_KIND_REGISTERED);
check('label match is case/space-insensitive', ms_event_kind($ev(' Keyboard1 '), $MLH305), EVENT_KIND_REGISTERED);
check('CCTV left item objectN', ms_event_kind($ev('object1'), $MLH305), EVENT_KIND_UNREGISTERED);
check('relabelled left item', ms_event_kind($ev('red Jansport bag'), $MLH305), EVENT_KIND_UNREGISTERED);
check('label registered in another room is not this room\'s equipment', ms_event_kind($ev('keyboard1'), $MLH301), EVENT_KIND_UNREGISTERED);
check('empty zone falls back to unregistered', ms_event_kind(['object_zone' => '', 'object_type' => ''], $MLH305), EVENT_KIND_UNREGISTERED);

echo "== Staff marks RECOVERED\n";
$p = ms_recovery_plan(EVENT_KIND_REGISTERED, 'recovered', false);
check('registered keyboard: NOT claimable', $p['create_claimable'], false);
check('registered keyboard: no student notification', $p['notify_students'], false);
check('registered keyboard: logged as returned to lab', str_contains((string) $p['log'], 'not claimable'), true);
$p = ms_recovery_plan(EVENT_KIND_UNREGISTERED, 'recovered', false);
check('unregistered item in staff custody: claimable', $p['create_claimable'], true);
check('unregistered item: students notified', $p['notify_students'], true);
$p = ms_recovery_plan(EVENT_KIND_UNREGISTERED, 'recovered', true);
check('unregistered item marked recovered twice: no duplicate', $p['create_claimable'], false);

echo "== Other transitions never create lost-and-found records\n";
foreach (['pending', 'potential', 'confirmed_missing', 'dismissed'] as $st) {
    foreach ([EVENT_KIND_REGISTERED, EVENT_KIND_UNREGISTERED] as $k) {
        $p = ms_recovery_plan($k, $st, false);
        check("{$k} -> {$st}: not claimable", $p['create_claimable'] || $p['notify_students'], false);
    }
}

echo "== Timer escalation is not a human confirmation\n";
$timer = $ev('keyboard2', ['status' => 'confirmed_missing', 'verified_by' => null]);
$staff = $ev('keyboard2', ['status' => 'confirmed_missing', 'verified_by' => 7]);
check('60-min escalation (no verified_by): not staff-confirmed', ms_staff_confirmed($timer), false);
check('60-min escalation label', ms_status_label($timer), 'Escalated — awaiting staff check');
check('staff confirmation (verified_by set): staff-confirmed', ms_staff_confirmed($staff), true);
check('staff confirmation label', ms_status_label($staff), 'Confirmed Missing');
check('timer-escalated unregistered item is still not claimable',
      ms_recovery_plan(EVENT_KIND_UNREGISTERED, 'confirmed_missing', false)['create_claimable'], false);
check('other labels unchanged: potential', ms_status_label(['status' => 'potential']), 'Potential');
check('other labels unchanged: pending', ms_status_label(['status' => 'pending']), 'Pending');
check('other labels unchanged: recovered', ms_status_label(['status' => 'recovered']), 'Recovered');
check('other labels unchanged: dismissed', ms_status_label(['status' => 'dismissed']), 'Dismissed');

echo "== Stage never contradicts the stored status (get_detections.php 'stage')\n";
$now = strtotime('2026-11-11 12:00:00');
$old = '2026-11-03 14:00:00';           // days old: past every timer threshold
$fresh = '2026-11-11 11:50:00';         // 10 minutes old
$st = fn($at, $status, $staff = null) => ms_detection_stage($at, $status, $staff, $now);
check('old RECOVERED event: stage recovered (was "Confirmed Missing")', $st($old, 'recovered')['stage'], 'recovered');
check('old RECOVERED event: label', $st($old, 'recovered')['label'], 'Recovered');
check('old DISMISSED event: stage dismissed', $st($old, 'dismissed')['stage'], 'dismissed');
check('timer-escalated confirmed_missing: label', $st($old, 'confirmed_missing', false)['label'], 'Escalated — awaiting staff check');
check('timer-escalated confirmed_missing: stage key unchanged', $st($old, 'confirmed_missing', false)['stage'], 'confirmed');
check('staff-confirmed confirmed_missing: label', $st($old, 'confirmed_missing', true)['label'], 'Confirmed Missing');
check('caller without staff info keeps old label', $st($old, 'confirmed_missing')['label'], 'Confirmed Missing');
check('old PENDING (escalation job not run): escalation, not confirmation', $st($old, 'pending')['label'], 'Escalated — awaiting staff check');
check('fresh PENDING: detected', $st($fresh, 'pending')['stage'], 'detected');
check('minutes computed', $st($fresh, 'pending')['mins'], 10);

echo "== Event-log tab buckets\n";
check('pending -> pending', ms_status_bucket('pending'), 'pending');
check('potential -> pending', ms_status_bucket('potential'), 'pending');
check('confirmed_missing -> confirmed', ms_status_bucket('confirmed_missing'), 'confirmed');
check('dismissed -> dismissed', ms_status_bucket('dismissed'), 'dismissed');
check('recovered -> recovered', ms_status_bucket('recovered'), 'recovered');

echo "== Confirmation prompts say what will happen\n";
$pr = ms_action_prompt(EVENT_KIND_REGISTERED, 'recovered', 'keyboard2');
check('equipment recovery: says NOT added to Lost & Found', str_contains($pr, 'NOT be added to Lost & Found'), true);
$pu = ms_action_prompt(EVENT_KIND_UNREGISTERED, 'recovered', 'object1');
check('unregistered recovery: asks about physical custody', str_contains($pu, 'into custody'), true);
check('unregistered recovery: says it becomes claimable', str_contains($pu, 'claimable'), true);
check('confirm missing: says it records the staff member',
      str_contains((string) ms_action_prompt(EVENT_KIND_REGISTERED, 'confirmed_missing', 'mouse1'), 'confirmed it'), true);
check('unsupported action: no prompt', ms_action_prompt(EVENT_KIND_REGISTERED, 'pending', 'x'), null);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
