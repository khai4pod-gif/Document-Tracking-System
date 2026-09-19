<?php
/**
 * includes/auto_routing.php
 *
 * The automatic document chain. Nobody chooses a recipient:
 *
 *   1. A document is created
 *        -> routed to the Office of the Secretary's receiving user
 *   2. That user acknowledges it
 *        -> routed to the approver assigned to the originating office
 *
 * Both hops are ordinary document_routes rows written by the system
 * instead of by a click, so the timeline, the "current holder" and every
 * existing screen keep working unchanged.
 *
 * Which approver a document reaches is decided by the office it came
 * from — a Digital Media Service document goes to the DMS approver. That
 * is the single rule, and it lives in approverForDocument() so a future
 * classifier can decide the office instead by changing one function.
 */

declare(strict_types=1);

/** Code of the office everything passes through first. */
const OSEC_DEPARTMENT_CODE = 'MAIN';

/**
 * Action-required labels for the two automatic hops.
 *
 * Both are drawn from ROUTE_ACTIONS in config.php and written in the
 * "CODE - LABEL" form is_valid_route_action() accepts, so an automatic
 * route is indistinguishable from a manual one everywhere it is read
 * back or printed.
 */
const AUTO_ROUTE_ACTION_ACKNOWLEDGE = 'FYI - FOR INFORMATION/REFERENCE';
const AUTO_ROUTE_ACTION_APPROVE     = 'RA - REQUEST FOR APPROVAL';

/**
 * Returning the decision to the creator.
 *
 * An approval is information; a rejection needs the creator to do
 * something about it, so the two carry different actions rather than one
 * neutral label that hides which happened.
 */
const AUTO_ROUTE_ACTION_APPROVED_BACK = 'FYI - FOR INFORMATION/REFERENCE';
const AUTO_ROUTE_ACTION_REJECTED_BACK = 'FAA - FOR APPROPRIATE ACTION';

/**
 * The Office of the Secretary row, with its assigned receiving user.
 *
 * @return array{id:int,name:string,receiver_user_id:?int,receiver_name:?string,receiver_active:?int}|null
 */
function osec_office(PDO $pdo): ?array
{
    $stmt = $pdo->prepare(
        "SELECT d.id, d.name, d.receiver_user_id,
                u.full_name AS receiver_name, u.is_active AS receiver_active
           FROM departments d
           LEFT JOIN users u ON u.id = d.receiver_user_id
          WHERE d.code = :code
          LIMIT 1"
    );
    $stmt->execute(['code' => OSEC_DEPARTMENT_CODE]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * The approver a document should end up with, decided by the office it
 * originated from.
 *
 * Returns a reason instead of a user when it cannot be resolved, so the
 * caller can say why the document stopped rather than failing silently.
 *
 * @return array{user:?array,reason:?string}
 */
function approverForDocument(array $doc, PDO $pdo): array
{
    $officeId = (int)($doc['origin_department_id'] ?? 0);
    if ($officeId <= 0) {
        return ['user' => null, 'reason' => 'the document has no originating office'];
    }

    $stmt = $pdo->prepare(
        "SELECT d.name AS office_name, d.approver_user_id,
                u.id, u.full_name, u.department_id, u.is_active, u.role
           FROM departments d
           LEFT JOIN users u ON u.id = d.approver_user_id
          WHERE d.id = :id
          LIMIT 1"
    );
    $stmt->execute(['id' => $officeId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['user' => null, 'reason' => 'the originating office no longer exists'];
    }
    if ($row['approver_user_id'] === null) {
        return ['user' => null, 'reason' => 'no approver is assigned to ' . $row['office_name']];
    }
    if ((int)$row['is_active'] !== 1) {
        return ['user' => null, 'reason' => 'the approver for ' . $row['office_name'] . ' is deactivated'];
    }

    return ['user' => [
        'id'            => (int)$row['id'],
        'full_name'     => $row['full_name'],
        'department_id' => $row['department_id'] !== null ? (int)$row['department_id'] : null,
        'office_name'   => $row['office_name'],
    ], 'reason' => null];
}

/**
 * Step 1 — send a newly created document to the Office of the Secretary.
 *
 * @return array{routed:bool,to:?string,reason:?string}
 */
function autoRouteToSecretary(Document $model, int $documentId, array $creator, PDO $pdo, ?string $transmittalMode = null): array
{
    $osec = osec_office($pdo);

    if (!$osec) {
        return ['routed' => false, 'to' => null, 'reason' => 'the Office of the Secretary is not set up'];
    }
    if ($osec['receiver_user_id'] === null) {
        return ['routed' => false, 'to' => null,
                'reason' => 'no receiving user is assigned to the Office of the Secretary'];
    }
    if ((int)$osec['receiver_active'] !== 1) {
        return ['routed' => false, 'to' => null,
                'reason' => 'the receiving user for the Office of the Secretary is deactivated'];
    }

    // A document created by the OSEC receiver would otherwise be routed to
    // that same person, who would then acknowledge their own upload.
    if ((int)$osec['receiver_user_id'] === (int)$creator['id']) {
        return ['routed' => false, 'to' => null,
                'reason' => 'you are the receiving user for the Office of the Secretary'];
    }

    $ok = $model->route($documentId, [
        'to_user_id'         => (int)$osec['receiver_user_id'],
        'from_department_id' => $creator['department_id'] ?? null,
        'to_department_id'   => (int)$osec['id'],
        'action_required'    => AUTO_ROUTE_ACTION_ACKNOWLEDGE,
        'transmittal_mode'   => $transmittalMode,
        'remarks'            => 'Automatically routed on upload.',
    ], (int)$creator['id']);

    return $ok
        ? ['routed' => true, 'to' => $osec['receiver_name'], 'reason' => null]
        : ['routed' => false, 'to' => null, 'reason' => 'the routing step failed'];
}

/**
 * Step 2 — after the Office of the Secretary acknowledges, hand the
 * document to the originating office's approver.
 *
 * Only fires for a route that was addressed to the OSEC receiving user;
 * acknowledging anywhere else in the chain must not move the document on.
 *
 * @return array{routed:bool,to:?string,reason:?string}
 */
function autoRouteToApprover(Document $model, array $route, PDO $pdo): array
{
    $osec = osec_office($pdo);
    if (!$osec || $osec['receiver_user_id'] === null) {
        return ['routed' => false, 'to' => null, 'reason' => null];
    }

    // Not the Office of the Secretary hop — nothing automatic happens.
    if ((int)$route['to_user_id'] !== (int)$osec['receiver_user_id']) {
        return ['routed' => false, 'to' => null, 'reason' => null];
    }

    $doc = $model->find((int)$route['document_id']);
    if (!$doc) {
        return ['routed' => false, 'to' => null, 'reason' => 'the document could not be reloaded'];
    }
    if ((int)$doc['is_archived'] === 1) {
        return ['routed' => false, 'to' => null, 'reason' => 'the document is archived'];
    }

    $resolved = approverForDocument($doc, $pdo);
    if ($resolved['user'] === null) {
        // The document stays with the Office of the Secretary, and the
        // message says why — better than a document that silently stops.
        return ['routed' => false, 'to' => null, 'reason' => $resolved['reason']];
    }

    $approver = $resolved['user'];
    $ok = $model->route((int)$route['document_id'], [
        'to_user_id'         => $approver['id'],
        'from_department_id' => (int)$osec['id'],
        'to_department_id'   => $approver['department_id'],
        'action_required'    => AUTO_ROUTE_ACTION_APPROVE,
        'transmittal_mode'   => $model->latestTransmittalMode((int)$route['document_id']),
        'remarks'            => 'Automatically routed after acknowledgement — '
                              . $approver['office_name'] . ' approver.',
    ], (int)$osec['receiver_user_id']);

    return $ok
        ? ['routed' => true, 'to' => $approver['full_name'], 'reason' => null]
        : ['routed' => false, 'to' => null, 'reason' => 'the routing step failed'];
}

/**
 * Step 3 — hand the decision back to whoever created the document.
 *
 * Runs for both outcomes: an approval is news the creator needs, and a
 * rejection is work only they can do. Either way the document stops
 * sitting with the approver.
 *
 * @param string $decision 'Approved' or 'Rejected'
 * @param string $note     the approver's optional message to the creator
 * @return array{routed:bool,to:?string,reason:?string}
 */
function autoRouteToCreator(
    Document $model,
    array $doc,
    string $decision,
    int $approverId,
    PDO $pdo,
    string $note = ''
): array {
    $creatorId = (int)($doc['created_by'] ?? 0);
    if ($creatorId <= 0) {
        return ['routed' => false, 'to' => null, 'reason' => 'the document has no recorded creator'];
    }

    // The approver deciding on their own document is already where it
    // needs to be; routing it to themselves would add a meaningless hop.
    if ($creatorId === $approverId) {
        return ['routed' => false, 'to' => null, 'reason' => null];
    }

    $stmt = $pdo->prepare(
        "SELECT id, full_name, department_id, is_active FROM users WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $creatorId]);
    $creator = $stmt->fetch();

    if (!$creator) {
        return ['routed' => false, 'to' => null, 'reason' => 'the creator account no longer exists'];
    }
    if ((int)$creator['is_active'] !== 1) {
        return ['routed' => false, 'to' => null, 'reason' => 'the creator account is deactivated'];
    }

    $approved = $decision === 'Approved';

    // The approver's own words lead, because that is what the creator needs
    // to read; the automatic part is the footnote. Without a note the
    // standard sentence stands on its own.
    $note    = trim($note);
    $standard = $approved
        ? 'Approved — automatically returned to the creator.'
        : 'Rejected — automatically returned to the creator for revision.';
    $remarks = $note !== ''
        ? mb_substr($note, 0, 900) . "\n\n(" . $standard . ')'
        : $standard;

    $ok = $model->route((int)$doc['id'], [
        'to_user_id'         => (int)$creator['id'],
        'from_department_id' => $doc['origin_department_id'] ?? null,
        'to_department_id'   => $creator['department_id'] !== null ? (int)$creator['department_id'] : null,
        'action_required'    => $approved
            ? AUTO_ROUTE_ACTION_APPROVED_BACK
            : AUTO_ROUTE_ACTION_REJECTED_BACK,
        'transmittal_mode'   => $model->latestTransmittalMode((int)$doc['id']),
        'remarks'            => $remarks,
        // Critical for a rejection: without this, a creator who happens to
        // hold the admin or approver role would trip route()'s
        // resubmission rule and the rejection would be undone on delivery.
        'no_reopen'          => true,
    ], $approverId);

    return $ok
        ? ['routed' => true, 'to' => $creator['full_name'], 'reason' => null]
        : ['routed' => false, 'to' => null, 'reason' => 'the routing step failed'];
}
