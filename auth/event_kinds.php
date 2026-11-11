<?php
/**
 * S.P.O.T.-IT — Detection event kinds (registered equipment vs. unregistered items)
 * auth/event_kinds.php
 *
 * Two different things reach the `detections` table:
 *
 *   REGISTERED EQUIPMENT — a registered ROI (keyboard, mouse, oscilloscope...)
 *     went missing. When staff find it, it goes back to the lab. It is lab
 *     property and must NEVER become a student-claimable lost-and-found item.
 *
 *   UNREGISTERED ITEM — the camera saw something new left in the room
 *     (`objectN`, possibly relabelled by staff). It is only a CANDIDATE. It
 *     becomes claimable only when a staff member marks it recovered, i.e.
 *     confirms it is real and has taken it into custody.
 *
 * The distinction needs no new column: an event is registered equipment when
 * its room has a registered_lab_items row whose roi_label is the event's zone —
 * any tier (MLH301's oscilloscopes are registered as tier2, but are still lab
 * property). Both tables live in spotit_monitor_db, so this stays inside one
 * service.
 *
 * The decision functions are pure (no DB access) so tests/test_event_kinds.php
 * can exercise them without touching any database.
 */

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(404);
    exit;
}

const EVENT_KIND_REGISTERED   = 'registered_equipment';
const EVENT_KIND_UNREGISTERED = 'unregistered_item';

/** The zone label an event refers to (the ROI label, or objectN for left items). */
function ms_event_zone(array $det): string
{
    return trim((string) ($det['object_zone'] ?? $det['object_type'] ?? ''));
}

/** Pure: classify an event given the room's registered ROI labels. */
function ms_event_kind(array $det, array $registeredLabels): string
{
    $zone = ms_event_zone($det);
    if ($zone === '') {
        return EVENT_KIND_UNREGISTERED;
    }
    $lower = array_map(fn($l) => strtolower(trim((string) $l)), $registeredLabels);
    return in_array(strtolower($zone), $lower, true) ? EVENT_KIND_REGISTERED : EVENT_KIND_UNREGISTERED;
}

/** DB: the registered ROI labels for one room (read-only). */
function ms_registered_labels(PDO $monitorPdo, string $roomId): array
{
    $stmt = $monitorPdo->prepare('SELECT roi_label FROM registered_lab_items WHERE room_id = ?');
    $stmt->execute([$roomId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Pure: what a staff status change should do beyond updating the event row.
 *
 * Only an explicit staff "recovered" on an UNREGISTERED item creates a
 * claimable lost-and-found record (that action is the custody confirmation),
 * and never twice for the same event. Registered equipment that is found is
 * logged as returned to the lab instead.
 */
function ms_recovery_plan(string $kind, string $newStatus, bool $alreadyInLostFound): array
{
    if ($newStatus !== 'recovered') {
        return ['create_claimable' => false, 'notify_students' => false, 'log' => null];
    }
    if ($kind === EVENT_KIND_REGISTERED) {
        return [
            'create_claimable' => false,
            'notify_students'  => false,
            'log' => 'Registered lab equipment located/returned — kept out of lost & found (not claimable).',
        ];
    }
    if ($alreadyInLostFound) {
        return ['create_claimable' => false, 'notify_students' => false,
                'log' => 'Already in lost & found — no duplicate record created.'];
    }
    return [
        'create_claimable' => true,
        'notify_students'  => true,
        'log' => 'Unregistered item taken into staff custody — now claimable in lost & found.',
    ];
}

/**
 * Pure: was this event's current status set by a person, or only by a timer?
 * update_event_status.php records verified_by on every staff action; the
 * 30/60-minute escalation never does. So a `confirmed_missing` with no
 * verified_by is a timer escalation, not a human confirmation.
 */
function ms_staff_confirmed(array $det): bool
{
    return !empty($det['verified_by']);
}

/** Pure: status label that never presents a timer escalation as a confirmation. */
function ms_status_label(array $det): string
{
    $status = (string) ($det['status'] ?? 'pending');
    if ($status === 'confirmed_missing') {
        return ms_staff_confirmed($det) ? 'Confirmed Missing' : 'Escalated — awaiting staff check';
    }
    return match ($status) {
        'potential' => 'Potential',
        'dismissed' => 'Dismissed',
        'recovered' => 'Recovered',
        default     => 'Pending',
    };
}
