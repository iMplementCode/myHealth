<?php

/**
 * ============================================================
 *  Purchasing — the buying side, in one place
 * ------------------------------------------------------------
 *  The vocabulary of a purchase order's life, and the small
 *  amount of arithmetic that goes with paying for one.
 *
 *  Two independent things are tracked against an order, and
 *  keeping them apart is the whole point of this file:
 *
 *    status          what the supplier said, and what has
 *                    physically arrived
 *    payment_status  what the business has paid
 *
 *  An order can be received and unpaid, or paid and not yet
 *  delivered. Collapsing the two into one column is how a
 *  purchase ledger stops being able to answer either question.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/cashbook.php';

/**
 * The order lifecycle.
 *
 * `manual` marks the transitions a person chooses. The rest are
 * set by events — receiving goods moves an order to partially
 * received or received on its own, and offering those as buttons
 * would only invite someone to contradict the goods received
 * notes.
 */
const PO_STATUSES = [
    'draft'              => ['label' => 'Draft',              'manual' => true,
                             'help'  => 'Still being written. Not with the supplier yet.'],
    'sent'               => ['label' => 'Sent to supplier',   'manual' => true,
                             'help'  => 'Issued and awaiting an answer.'],
    'accepted'           => ['label' => 'Accepted',           'manual' => true,
                             'help'  => 'The supplier has agreed to fulfil it.'],
    'rejected'           => ['label' => 'Rejected',           'manual' => true,
                             'help'  => 'The supplier will not fulfil it.'],
    'partially_received' => ['label' => 'Partially received', 'manual' => false,
                             'help'  => 'Set automatically as goods arrive.'],
    'received'           => ['label' => 'Received',           'manual' => false,
                             'help'  => 'Set automatically once every line has arrived.'],
    'closed'             => ['label' => 'Closed',             'manual' => true,
                             'help'  => 'Finished with — delivered and settled.'],
    'cancelled'          => ['label' => 'Cancelled',          'manual' => true,
                             'help'  => 'Withdrawn by us. Only before any goods arrive.'],
];

const PO_PAYMENT_STATUSES = [
    'unpaid'         => 'Unpaid',
    'partially_paid' => 'Partially paid',
    'paid'           => 'Paid in full',
];

/** Human label for an order status. */
function po_status_label(?string $status): string
{
    return PO_STATUSES[$status]['label'] ?? ucwords(str_replace('_', ' ', (string) $status));
}

/** Human label for an order's payment state. */
function po_payment_label(?string $status): string
{
    return PO_PAYMENT_STATUSES[$status] ?? 'Unpaid';
}

/**
 * Which statuses a person may move this order to.
 *
 * Anything the goods received notes decide is left out, and so
 * is the order's current status — "change it to what it already
 * is" is not a choice.
 */
function po_manual_transitions(array $po): array
{
    $current = (string) $po['status'];
    $out     = [];

    foreach (PO_STATUSES as $key => $meta) {
        if (!$meta['manual'] || $key === $current) {
            continue;
        }
        // Once goods are in, an order cannot go back to being a
        // draft, be un-sent, or be refused.
        if (in_array($current, ['partially_received', 'received'], true)
            && in_array($key, ['draft', 'sent', 'accepted', 'rejected'], true)) {
            continue;
        }
        // A cancelled or rejected order is finished. Reopening one
        // would leave the goods and money already recorded against
        // it pointing at a document that claims not to exist.
        if (in_array($current, ['cancelled', 'rejected'], true) && $key !== 'draft') {
            continue;
        }
        // Closing is for orders that are done, not for ones that
        // have not started.
        if ($key === 'closed' && !in_array($current, ['received', 'partially_received'], true)) {
            continue;
        }
        $out[$key] = $meta;
    }
    return $out;
}

/**
 * Refuse a status change that would contradict the record.
 *
 * Returns an error message, or null when the change is allowed.
 */
function po_status_change_error(array $po, string $to): ?string
{
    if (!isset(PO_STATUSES[$to])) {
        return 'That is not a purchase order status.';
    }
    if (!PO_STATUSES[$to]['manual']) {
        return po_status_label($to) . ' is set by the goods received notes, not by hand.';
    }
    if (!isset(po_manual_transitions($po)[$to])) {
        return sprintf(
            '%s cannot go from %s to %s.',
            $po['po_number'], po_status_label($po['status']), po_status_label($to)
        );
    }

    $received = (int) db_value(
        "SELECT COUNT(*) FROM grns WHERE po_id = :id AND status <> 'cancelled'",
        [':id' => $po['po_id']]
    );
    if ($received > 0 && in_array($to, ['cancelled', 'rejected'], true)) {
        return sprintf(
            'Cannot mark %s as %s: goods have already been received against it. '
            . 'Raise a debit note for the goods instead.',
            $po['po_number'], strtolower(po_status_label($to))
        );
    }

    $paid = (float) db_value(
        "SELECT COALESCE(SUM(amount), 0) FROM purchase_payments WHERE po_id = :id",
        [':id' => $po['po_id']]
    );
    if ($paid > 0.005 && in_array($to, ['cancelled', 'rejected', 'draft'], true)) {
        return sprintf(
            'Cannot mark %s as %s: %s has already been paid against it. '
            . 'Reverse the payment first.',
            $po['po_number'], strtolower(po_status_label($to)), money($paid)
        );
    }

    return null;
}

/**
 * Everything about one order's money, in the terms a buyer uses.
 *
 * `payable_now` is the part that is actually owed — you owe for
 * what has arrived, not for what is still on order — while
 * `balance` is what is left on the order as a whole.
 */
function po_money(array $po): array
{
    $total    = (float) $po['total_amount'];
    $paid     = (float) ($po['amount_paid'] ?? 0);
    $received = (float) db_value(
        "SELECT COALESCE(SUM(total_amount), 0) FROM grns
          WHERE po_id = :id AND status <> 'cancelled'",
        [':id' => $po['po_id']]
    );

    return [
        'total'       => $total,
        'paid'        => $paid,
        'balance'     => max($total - $paid, 0.0),
        'received'    => $received,
        'payable_now' => max($received - $paid, 0.0),
        'prepaid'     => max($paid - $received, 0.0),
    ];
}

/** Tone class for a payment badge. */
function po_payment_tone(string $status): string
{
    return match ($status) {
        'paid'           => 'active',
        'partially_paid' => 'pending',
        default          => 'inactive',
    };
}
