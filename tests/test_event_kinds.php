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

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
