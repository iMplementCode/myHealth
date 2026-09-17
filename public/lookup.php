<?php

/**
 * ============================================================
 *  Picker lookups
 * ------------------------------------------------------------
 *  What the search box beside a <select> asks as somebody types.
 *  Returns a bounded page of matches instead of the application
 *  shipping the whole table into the HTML — see includes/lookup.php
 *  for why that mattered enough to build.
 *
 *    GET /lookup.php?list=customers&q=acme
 *    → {"rows":[{"id":41,"name":"Acme Security Ltd","search":"…"}]}
 *
 *  Signed-in only, and the list definition carries its own role
 *  test: this endpoint must never become a way to read a table the
 *  caller could not read on a page.
 *
 *  Deliberately NOT under /api/. That path is a tight nginx lane
 *  meant for server-to-server calls, and a person typing a customer
 *  name would be throttled out of their own search box.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
// The answer depends on who is asking, and it is nobody else's.
header('Cache-Control: private, no-store');

$user = current_user();

/*  A typing box makes a request per pause, so the ceiling has to sit
 *  well above a person searching hard and well below a script walking
 *  the customer table one letter at a time. Keyed per user rather
 *  than per address, because a whole office shares one address. */
rate_limit_or_stop('lookup:' . (int) ($user['id'] ?? 0), 600, 60, 'searches');

$list = (string) input($_GET, 'list');
$q    = (string) input($_GET, 'q');

if (!lookup_allowed($list)) {
    // The same answer whether the list does not exist or is not
    // theirs to read: a 404 that distinguishes the two is a way to
    // enumerate what the application has.
    json_response(['error' => 'No such list.'], 404);
}

$keep = array_filter(array_map('intval', explode(',', (string) input($_GET, 'keep'))));

try {
    $rows = lookup_query($list, $q, LOOKUP_LIMIT, $keep);
} catch (Throwable $e) {
    error_log('[LOOKUP] ' . $list . ': ' . $e->getMessage());
    json_response(['error' => 'That search could not be run.'], 500);
}

json_response([
    'list'  => $list,
    'q'     => $q,
    // True when there may be more behind the limit, so the box can
    // say "keep typing" rather than implying it found everything.
    'more'  => count($rows) >= LOOKUP_LIMIT,
    'rows'  => $rows,
]);
