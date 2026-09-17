<?php

/**
 * ============================================================
 *  Audit Logging
 * ------------------------------------------------------------
 *  Records sensitive operations (logins, user changes,
 *  conversions, deletions) to the audit_logs table. Fail-safe:
 *  auditing must never break the operation being audited.
 *
 *  Usage:
 *      audit_log('user.delete', 'users', $id, ['email' => $email]);
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


function audit_log(string $action, string $entity = '', $entityId = null, array $details = []): void
{
    try {
        db_run(
            "INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address)
             VALUES (:uid, :action, :entity, :eid, :details, :ip)",
            [
                ':uid'     => $_SESSION['user_id'] ?? null,
                ':action'  => mb_substr($action, 0, 100),
                ':entity'  => $entity !== '' ? mb_substr($entity, 0, 50) : null,
                ':eid'     => $entityId !== null ? mb_substr((string) $entityId, 0, 50) : null,
                ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
                ':ip'      => mb_substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45) ?: null,
            ]
        );
    } catch (Throwable $e) {
        // Table may not exist yet (pre-migration) — log and continue.
        error_log('[AUDIT] ' . $action . ' not recorded: ' . $e->getMessage());
    }
}

/* ─────────────────────────────────────────────────────────────
 *  Reading the trail back
 * ------------------------------------------------------------
 *  Actions are written as `entity.verb` — `credit_note.approve`,
 *  `product.delete`, `auth.login`. That convention is what these
 *  helpers lean on, so a module added next year shows up in the
 *  viewer correctly without anybody editing a list here.
 *
 *  Used by public/users/audit.php.
 * ──────────────────────────────────────────────────────────── */

/** "credit_note.approve" → "Credit note · approve". */
function audit_action_label(string $action): string
{
    if ($action === '') {
        return 'Unknown';
    }
    $parts = explode('.', $action, 2);
    $noun  = ucfirst(str_replace('_', ' ', $parts[0]));
    if (!isset($parts[1])) {
        return $noun;
    }
    return $noun . ' · ' . str_replace('_', ' ', $parts[1]);
}

/** "credit_notes" / "credit_note" → "Credit note". */
function audit_entity_label(?string $entity): string
{
    $entity = (string) $entity;
    if ($entity === '') {
        return '—';
    }
    // Tables are written plural by some callers and singular by
    // others. One reading either way.
    $singular = preg_replace('/ies$/', 'y', $entity);
    $singular = preg_replace('/(?<!s)s$/', '', $singular ?? $entity);
    return ucfirst(str_replace('_', ' ', $singular ?: $entity));
}

/**
 * The colour of what happened.
 *
 * Chosen off the verb, so the eye finds the destructive things
 * first — which is what somebody scrolling an audit trail is
 * nearly always looking for.
 */
function audit_action_tone(string $action): string
{
    $verb = str_contains($action, '.') ? explode('.', $action, 2)[1] : $action;

    foreach ([
        'cancelled' => ['delete', 'cancel', 'reverse', 'dispose', 'void', 'remove',
                        'lockout', 'locked_out', 'reuse', 'auth_failed', 'reopen'],
        'active'    => ['create', 'request', 'add', 'login', 'login_remembered'],
        'completed' => ['approve', 'post', 'pay', 'receive', 'disburse', 'repay',
                        'close', 'convert', 'refund', 'bill_shortfall'],
        'warn'      => ['deactivate', 'undispose', 'status', 'fulfilment'],
    ] as $tone => $verbs) {
        if (in_array($verb, $verbs, true)) {
            return $tone;
        }
    }
    return 'info';
}

/**
 * Where to go to see the record itself, or null when there is
 * nowhere useful to send somebody.
 *
 * Deliberately incomplete: a link that 404s is worse than no link,
 * so only the entities with a real detail page are listed.
 */
function audit_entity_url(?string $entity, $entityId): ?string
{
    if ($entity === null || $entityId === null || $entityId === '' || !ctype_digit((string) $entityId)) {
        return null;
    }
    $map = [
        'invoices'           => 'invoices/view.php?id=',
        'credit_notes'       => 'credit_notes/view.php?id=',
        'delivery_notes'     => 'delivery_notes/view.php?id=',
        'proforma_invoices'  => 'proformas/print.php?id=',
        'goods_return_notes' => 'returns/index.php?id=',
        'users'              => 'users/edit.php?id=',
    ];
    return isset($map[$entity]) ? $map[$entity] . (int) $entityId : null;
}

/**
 * The JSON blob, as a sentence.
 *
 * Details are written by forty-nine different callers with no
 * agreed shape, so this makes no assumptions beyond "it is a flat
 * map if it is anything". Anything unreadable is shown as it was
 * stored rather than swallowed — a detail nobody can read is still
 * evidence.
 */
function audit_detail_text(?string $details): string
{
    $details = trim((string) $details);
    if ($details === '') {
        return '—';
    }
    $data = json_decode($details, true);
    if (!is_array($data)) {
        return mb_strimwidth($details, 0, 160, '…');
    }
    $bits = [];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        } elseif (is_bool($v)) {
            $v = $v ? 'yes' : 'no';
        }
        $bits[] = str_replace('_', ' ', (string) $k) . ': ' . (string) $v;
    }
    return mb_strimwidth(implode(', ', $bits), 0, 160, '…');
}
