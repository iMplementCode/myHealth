<?php

/**
 * ============================================================
 *  The company logo, served from the database
 * ------------------------------------------------------------
 *  Bytes in a table have no URL of their own, so this is it.
 *
 *  It exists because a container's filesystem does not survive a
 *  deploy: public/uploads is rebuilt from the repository every
 *  time the code is pushed, and every shop lost its logo every
 *  time. The database is the only thing a shop has that lasts.
 *
 *  Behind the sign-in, like every other page. A logo is not a
 *  secret — it is printed on every invoice the shop sends — but
 *  there is no reason to serve it to strangers either, and no
 *  page needs it before somebody has signed in.
 * ============================================================
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$blob = company_logo_blob();
if ($blob === null) {
    http_response_code(404);
    exit;
}

/*  The bytes are the version. Re-sending an unchanged logo on
 *  every page load is wasteful when it is drawn in the top bar of
 *  every single one; an ETag lets the browser skip it. */
$etag = '"' . md5($blob['bytes']) . '"';
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: ' . $blob['mime']);
header('Content-Length: ' . strlen($blob['bytes']));
header('ETag: ' . $etag);
// Private: one shop's logo must not be held in a shared cache and
// handed to another.
header('Cache-Control: private, max-age=3600');
// It is an image and must be treated as one, whatever it contains.
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="logo"');

echo $blob['bytes'];
