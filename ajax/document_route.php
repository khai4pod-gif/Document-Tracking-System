<?php
/**
 * ajax/document_route.php
 * Retired — manual routing has been removed.
 *
 * A document now moves by itself:
 *
 *   uploaded          -> Office of the Secretary's receiving user
 *   acknowledged      -> the approver assigned to the originating office
 *   approved/rejected -> back to the creator
 *
 * The chain lives in includes/auto_routing.php and is driven from
 * document_save.php, document_receive.php and document_approve.php.
 *
 * Kept as a stub rather than deleted, because removing the button is not
 * the same as closing the door: a stale browser tab or a hand-made POST
 * could otherwise still push a document to an arbitrary recipient,
 * dropping it out of the chain and leaving it where none of the automatic
 * steps will look for it.
 *
 * Document::route() itself is untouched — it is what the automatic hops
 * call. Only this user-driven entry point is closed.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
require_login();

json_response([
    'success' => false,
    'message' => 'Manual routing has been removed. Documents are routed automatically: '
               . 'to the Office of the Secretary on upload, to your office\'s approver once '
               . 'acknowledged, and back to the creator once approved or rejected.',
], 410);
