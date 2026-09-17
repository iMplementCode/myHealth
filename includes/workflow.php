<?php

/**
 * ============================================================
 *  Document Workflow — statuses, transitions and traceability
 * ------------------------------------------------------------
 *  One place that knows the shape of the sales document flow:
 *
 *      Quote
 *       ├── Accepted Quote
 *       │    ├── Sales Order ── Invoice
 *       │    │              └── Delivery Note(s)
 *       │    ├── Proforma Invoice
 *       │    └── Delivery Note(s)
 *
 *  The database enforces the hard rules (stock, over-delivery,
 *  duplicate lines). This file carries the same rules into the
 *  UI so users are told *before* they act, and mirrors the
 *  status vocabularies so labels and dropdowns cannot drift
 *  from the CHECK constraints in migration 009.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


// ─── Status vocabularies (mirror migration 009) ─────────────

const QUOTE_STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'rejected', 'expired', 'converted'];
const SO_STATUSES    = ['draft', 'confirmed', 'partially_delivered', 'fully_delivered', 'invoiced', 'closed', 'cancelled'];
const PI_STATUSES    = ['draft', 'issued', 'cancelled', 'converted'];
const INVOICE_STATUSES = ['draft', 'issued', 'partially_delivered', 'fully_delivered', 'paid', 'partially_paid', 'overdue', 'cancelled'];
const DN_STATUSES    = ['draft', 'posted', 'cancelled'];
const DELIVERY_STATES = ['not_delivered', 'partially_delivered', 'fully_delivered'];

/**
 * Permitted status transitions per document type. Anything not
 * listed is refused. Mirrors the lifecycle in the brief.
 */
function workflow_transitions(string $doc): array
{
    return [
        'quote' => [
            'draft'     => ['sent', 'accepted', 'rejected', 'expired'],
            'sent'      => ['viewed', 'accepted', 'rejected', 'expired'],
            'viewed'    => ['accepted', 'rejected', 'expired'],
            'accepted'  => ['converted', 'expired'],
            'rejected'  => ['draft'],
            'expired'   => ['draft', 'sent'],
            'converted' => [],
        ],
        'sales_order' => [
            'draft'               => ['confirmed', 'cancelled'],
            'confirmed'           => ['partially_delivered', 'fully_delivered', 'invoiced', 'cancelled'],
            'partially_delivered' => ['fully_delivered', 'invoiced', 'cancelled'],
            'fully_delivered'     => ['invoiced', 'closed'],
            'invoiced'            => ['closed'],
            'closed'              => [],
            'cancelled'           => [],
        ],
        'proforma' => [
            'draft'     => ['issued', 'cancelled'],
            'issued'    => ['converted', 'cancelled'],
            'converted' => [],
            'cancelled' => [],
        ],
        'invoice' => [
            'draft'               => ['issued', 'cancelled'],
            'issued'              => ['partially_paid', 'paid', 'overdue', 'cancelled'],
            'partially_delivered' => ['fully_delivered', 'partially_paid', 'paid', 'overdue', 'cancelled'],
            'fully_delivered'     => ['partially_paid', 'paid', 'overdue', 'cancelled'],
            'partially_paid'      => ['paid', 'overdue', 'cancelled'],
            'overdue'             => ['partially_paid', 'paid', 'cancelled'],
            'paid'                => [],
            'cancelled'           => [],
        ],
        'delivery_note' => [
            'draft'     => ['posted', 'cancelled'],
            'posted'    => ['cancelled'],
            'cancelled' => [],
        ],
    ][$doc] ?? [];
}

/**
 * Statuses an administrator may set beyond the normal lifecycle.
 *
 * The everyday rules deliberately make some states terminal — a paid
 * invoice should not casually go back to unpaid. But mistakes happen
 * (a payment allocated to the wrong invoice, a premature cancel), and
 * an administrator needs a way to put it right without touching the
 * database by hand. Every such change is audited.
 *
 * Delivery-derived statuses are never offered: they are recomputed
 * from the posted delivery notes and setting them by hand would only
 * be overwritten.
 */
function admin_override_transitions(string $doc, string $from): array
{
    if (!is_admin()) {
        return [];
    }
    $derived = ['partially_delivered', 'fully_delivered'];

    $all = match ($doc) {
        'invoice'     => INVOICE_STATUSES,
        'proforma'    => PI_STATUSES,
        'quote'       => QUOTE_STATUSES,
        // Sales-order and delivery-note lifecycles move stock, so they
        // stay on the normal rails even for administrators.
        default       => [],
    };

    return array_values(array_diff($all, $derived, [$from]));
}

/** May $doc move from $from to $to? Administrators get the wider set. */
function can_transition(string $doc, string $from, string $to): bool
{
    if ($from === $to) {
        return true;
    }
    if (in_array($to, workflow_transitions($doc)[$from] ?? [], true)) {
        return true;
    }
    return in_array($to, admin_override_transitions($doc, $from), true);
}

/** Statuses reachable from the current one (for building dropdowns). */
function allowed_transitions(string $doc, string $from): array
{
    $normal = workflow_transitions($doc)[$from] ?? [];
    $extra  = array_diff(admin_override_transitions($doc, $from), $normal);
    return array_values(array_merge($normal, $extra));
}

/**
 * True when this move is only possible because the user is an
 * administrator — used to label it in the UI and flag it in the audit
 * trail as an override rather than a routine change.
 */
function is_override_transition(string $doc, string $from, string $to): bool
{
    if ($from === $to) {
        return false;
    }
    return !in_array($to, workflow_transitions($doc)[$from] ?? [], true)
        && in_array($to, admin_override_transitions($doc, $from), true);
}

// ─── Presentation ───────────────────────────────────────────

/** Human label for a machine status ("partially_delivered" → "Partially Delivered"). */
function status_label(?string $status): string
{
    if ($status === null || $status === '') {
        return '—';
    }
    return ucwords(str_replace('_', ' ', $status));
}

/** Badge modifier class for a status, so colour is consistent app-wide. */
function status_tone(?string $status): string
{
    return match ($status) {
        'paid', 'fully_delivered', 'posted', 'accepted', 'closed'  => 'active',
        'cancelled', 'rejected', 'expired'                          => 'inactive',
        'partially_delivered', 'partially_paid', 'overdue'          => 'warn',
        'converted', 'invoiced'                                     => 'info',
        default                                                     => 'pending',
    };
}

// ─── Delivery rules (mirrored from the database triggers) ────

/**
 * May a delivery note be raised against this quote? Returns null
 * when allowed, otherwise the reason to show the user.
 */
function quote_delivery_block(array $quote): ?string
{
    return match ($quote['status'] ?? '') {
        'accepted', 'converted' => null,
        'draft'    => 'Draft quotes cannot be delivered — send and accept it first.',
        'sent', 'viewed' => 'The customer has not accepted this quote yet.',
        'rejected' => 'A rejected quote cannot be delivered.',
        'expired'  => 'This quote has expired.',
        default    => 'Only accepted quotes can be delivered.',
    };
}

/** As above, for a sales order. */
function sales_order_delivery_block(array $so): ?string
{
    return match ($so['status'] ?? '') {
        'confirmed', 'partially_delivered', 'fully_delivered', 'invoiced' => null,
        'draft'     => 'Confirm the sales order before delivering against it.',
        'cancelled' => 'A cancelled sales order cannot be delivered.',
        'closed'    => 'This sales order is closed.',
        default     => 'Only confirmed sales orders can be delivered.',
    };
}

/**
 * Resolve a document the user asked to deliver from to the document
 * delivery is actually tracked against.
 *
 * An invoice raised from a sales order, and a proforma raised from a
 * quote, are billing views of goods already ordered somewhere
 * upstream. Delivering against both ends would count one deal twice,
 * so the request walks up to the origin. Only when nothing sits above
 * it does the invoice or proforma become the source itself.
 *
 * @return array{source: string, id: int}
 */
function resolve_delivery_source(string $source, int $id): array
{
    if ($source === 'invoice') {
        $inv = db_one(
            "SELECT i.sales_order_id, COALESCE(i.quote_id, pi.quote_id) AS quote_id, i.pi_id
               FROM invoices i
               LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
              WHERE i.invoice_id = :id",
            [':id' => $id]
        );
        if ($inv) {
            if (!empty($inv['sales_order_id'])) {
                return ['source' => 'sales_order', 'id' => (int) $inv['sales_order_id']];
            }
            if (!empty($inv['quote_id'])) {
                return ['source' => 'quote', 'id' => (int) $inv['quote_id']];
            }
            if (!empty($inv['pi_id'])) {
                return ['source' => 'proforma', 'id' => (int) $inv['pi_id']];
            }
        }
        return ['source' => 'invoice', 'id' => $id];
    }

    if ($source === 'proforma') {
        $quoteId = db_value("SELECT quote_id FROM proforma_invoices WHERE pi_id = :id", [':id' => $id]);
        if ($quoteId) {
            return ['source' => 'quote', 'id' => (int) $quoteId];
        }
        return ['source' => 'proforma', 'id' => $id];
    }

    return ['source' => $source, 'id' => $id];
}

/**
 * May a delivery note be raised against this proforma or invoice?
 * Returns null when allowed, otherwise the reason to show the user.
 */
function billing_doc_delivery_block(array $doc, string $kind): ?string
{
    $status = $doc['status'] ?? '';
    if ($status === 'cancelled') {
        return 'A cancelled ' . $kind . ' cannot be delivered.';
    }
    if ($kind === 'invoice' && $status === 'draft') {
        return 'Issue the invoice before delivering against it.';
    }
    return null;
}

/**
 * Outstanding quantities for a source document — what may still
 * be put on a delivery note. Returns rows of
 * [product_id, name, sku, ordered, delivered, remaining].
 */
/**
 * The lines of a document that a delivery note could still carry.
 *
 * Goods only, in all four branches. A service is invoiced, not
 * delivered — there is nothing to load onto a vehicle and nothing
 * for the customer to sign for — so offering "Installation" on a
 * delivery note would produce a note that cannot be posted, since
 * the database refuses a service on one (migration 034).
 */
function outstanding_lines(string $source, int $id): array
{
    if ($source === 'invoice') {
        // Credited goods are not owed and never will be: the customer
        // is not having them and is not paying for them. Offering to
        // deliver a credited line is offering to send goods against a
        // sale both sides have agreed is off.
        return db_all(
            "SELECT ii.product_id, p.name, p.sku, p.stock_quantity,
                    ii.quantity AS ordered, ii.delivered_quantity AS delivered,
                    ii.credited_quantity AS credited,
                    GREATEST(ii.quantity - ii.delivered_quantity - ii.credited_quantity, 0) AS remaining
             FROM invoice_items ii
             JOIN products p ON p.product_id = ii.product_id AND " . goods_only_sql() . "
             WHERE ii.invoice_id = :id
             ORDER BY p.name",
            [':id' => $id]
        );
    }
    if ($source === 'proforma') {
        return db_all(
            "SELECT pii.product_id, p.name, p.sku, p.stock_quantity,
                    pii.quantity AS ordered, pii.delivered_quantity AS delivered,
                    (pii.quantity - pii.delivered_quantity) AS remaining
             FROM proforma_invoice_items pii
             JOIN products p ON p.product_id = pii.product_id AND " . goods_only_sql() . "
             WHERE pii.pi_id = :id
             ORDER BY p.name",
            [':id' => $id]
        );
    }
    if ($source === 'sales_order') {
        return db_all(
            "SELECT soi.product_id, p.name, p.sku, p.stock_quantity,
                    soi.quantity AS ordered, soi.delivered_quantity AS delivered,
                    (soi.quantity - soi.delivered_quantity) AS remaining
             FROM sales_order_items soi
             JOIN products p ON p.product_id = soi.product_id AND " . goods_only_sql() . "
             WHERE soi.sales_order_id = :id
             ORDER BY p.name",
            [':id' => $id]
        );
    }
    return db_all(
        "SELECT qi.product_id, p.name, p.sku, p.stock_quantity,
                qi.quantity AS ordered, qi.delivered_quantity AS delivered,
                (qi.quantity - qi.delivered_quantity) AS remaining
         FROM quote_items qi
         JOIN products p ON p.product_id = qi.product_id AND " . goods_only_sql() . "
         WHERE qi.quote_id = :id
         ORDER BY p.name",
        [':id' => $id]
    );
}

/**
 * The live invoice that bills the goods on a delivery note, if any.
 *
 * The invoice may be attached to the note directly, or reach it
 * through the sales order or the quote → proforma chain — all three
 * routes count, because in every case the customer has been billed.
 * Returns null when nothing has been invoiced yet.
 *
 * Mirrors the database guard in migration 011 so the UI can explain
 * the restriction before the user acts on it.
 */
function delivery_note_invoice(array $dn): ?array
{
    return db_one(
        "SELECT i.invoice_id, i.invoice_number, i.status
           FROM invoices i
           LEFT JOIN proforma_invoices pi ON pi.pi_id = i.pi_id
          WHERE i.status <> 'cancelled'
            AND (
                  i.invoice_id = :inv
               OR (:so::int IS NOT NULL AND i.sales_order_id = :so)
               OR (:q::int IS NOT NULL AND (i.quote_id = :q OR pi.quote_id = :q))
                )
          LIMIT 1",
        [
            ':inv' => $dn['invoice_id'] ?? null,
            ':so'  => $dn['sales_order_id'] ?? null,
            ':q'   => $dn['quote_id'] ?? null,
        ]
    ) ?: null;
}

// ─── Traceability ───────────────────────────────────────────

/**
 * Record an edge in the document graph. Safe to call twice — the
 * unique index makes it idempotent.
 */
function link_documents(string $fromType, int $fromId, string $toType, int $toId, string $relation = 'converted_to'): void
{
    try {
        db_run(
            "INSERT INTO document_links (from_type, from_id, to_type, to_id, relation, created_by)
             VALUES (:ft, :fi, :tt, :ti, :rel, :by)
             ON CONFLICT (from_type, from_id, to_type, to_id, relation) DO NOTHING",
            [
                ':ft' => $fromType, ':fi' => $fromId,
                ':tt' => $toType,   ':ti' => $toId,
                ':rel' => $relation, ':by' => current_user()['id'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        // Traceability is valuable but must never break a conversion.
        error_log('[WORKFLOW] link_documents: ' . $e->getMessage());
    }
}

/**
 * Every document related to the given one, in both directions,
 * resolved to a number and a URL so views can render a
 * "related documents" trail without knowing the graph.
 */
function related_documents(string $type, int $id): array
{
    $rows = db_all(
        "SELECT to_type   AS type, to_id   AS id, relation, 'out' AS dir
           FROM document_links WHERE from_type = :t AND from_id = :i
         UNION ALL
         SELECT from_type, from_id, relation, 'in'
           FROM document_links WHERE to_type = :t2 AND to_id = :i2",
        [':t' => $type, ':i' => $id, ':t2' => $type, ':i2' => $id]
    );

    $meta = [
        'quote'         => ['quotes',             'quote_id',      'quote_number',   'modules/sales/manage_quotes.php?id=',   'Quote'],
        'sales_order'   => ['sales_orders',       'sales_order_id', 'order_number',  'modules/sales/list_sales_orders.php?id=', 'Sales Order'],
        'proforma'      => ['proforma_invoices',  'pi_id',         'pi_number',      'proformas/print.php?id=',               'Proforma'],
        'invoice'       => ['invoices',           'invoice_id',    'invoice_number', 'invoices/view.php?id=',                 'Invoice'],
        'delivery_note' => ['delivery_notes',     'dn_id',         'dn_number',      'delivery_notes/view.php?id=',           'Delivery Note'],
    ];

    $out = [];
    foreach ($rows as $r) {
        if (!isset($meta[$r['type']])) {
            continue;
        }
        [$table, $pk, $numCol, $urlBase, $label] = $meta[$r['type']];
        $doc = db_one("SELECT $numCol AS number, status FROM $table WHERE $pk = :id", [':id' => $r['id']]);
        if (!$doc) {
            continue;
        }
        $out[] = [
            'type'   => $r['type'],
            'label'  => $label,
            'number' => $doc['number'],
            'status' => $doc['status'],
            'url'    => $urlBase . (int) $r['id'],
        ];
    }
    return $out;
}
