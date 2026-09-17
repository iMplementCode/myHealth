<?php

/**
 * ============================================================
 *  Serve a private uploaded document
 * ------------------------------------------------------------
 *  Payment slips, refund proofs, investor agreements and staff
 *  advance receipts were written into public/uploads and linked
 *  as plain URLs. nginx served them off the disk without ever
 *  asking who was asking, so a link was a permanent, unauthenticated
 *  key to a customer's bank details — valid for anyone it was ever
 *  forwarded to, and valid forever.
 *
 *  The filenames are generated, so guessing one is hard. That is
 *  not the same as being protected: hard-to-guess is not a session
 *  check, and a URL leaks through forwarded email, a shared browser
 *  history, a chat message or a proxy log without anybody attacking
 *  anything.
 *
 *  So those folders are denied at the web server now, and this is
 *  the only way in. It asks three questions in order — is this
 *  person signed in, is the path one we are willing to serve, and
 *  does the file actually sit inside the uploads directory — and
 *  refuses on the first no.
 *
 *  Product images and the company logo deliberately do NOT come
 *  through here. They belong on documents and pages that are
 *  themselves public, and routing them through PHP would cost a
 *  process per image for no privacy anybody wants.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

require_login();

/*  The folders this endpoint will serve. Anything not listed here
 *  is refused even if it exists — a new upload folder has to be
 *  named deliberately rather than becoming readable by accident. */
const PRIVATE_DOC_FOLDERS = ['payments', 'refunds', 'investments', 'advances'];

/*  And the only types that can come back. The upload path already
 *  refuses anything else, but a file that arrived some other way
 *  must not be served as, say, text/html in our own origin. */
const PRIVATE_DOC_TYPES = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
];

$requested = (string) ($_GET['f'] ?? '');

/*  ── The path, taken apart rather than trusted ───────────────
 *  Never concatenated straight onto a directory. The folder is
 *  matched against a list and the filename is reduced to its
 *  basename, so "../../../.env" and "payments/../../config.php"
 *  both come out as something that cannot escape.               */
$parts = explode('/', str_replace('\\', '/', $requested));
$parts = array_values(array_filter($parts, static fn($p) => $p !== '' && $p !== '.'));

if (count($parts) !== 2) {
    http_response_code(404);
    exit('Not found.');
}

[$folder, $name] = $parts;
$name = basename($name);

if (!in_array($folder, PRIVATE_DOC_FOLDERS, true)) {
    http_response_code(404);
    exit('Not found.');
}

$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
if (!isset(PRIVATE_DOC_TYPES[$ext])) {
    http_response_code(404);
    exit('Not found.');
}

$base = realpath(UPLOAD_PATH);
$path = realpath(UPLOAD_PATH . '/' . $folder . '/' . $name);

/*  The belt to the braces above: whatever the string games
 *  produced, the resolved file has to sit inside the uploads
 *  directory. A symlink pointing out of it fails here.          */
if ($base === false || $path === false
    || !is_file($path)
    || strncmp($path, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
    http_response_code(404);
    exit('Not found.');
}

/*  What the file actually is, not what it is called. An uploaded
 *  file whose contents disagree with its extension is not served
 *  under the extension's type.                                   */
$actual = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
if ($actual !== PRIVATE_DOC_TYPES[$ext]) {
    http_response_code(404);
    exit('Not found.');
}

header('Content-Type: ' . PRIVATE_DOC_TYPES[$ext]);
header('Content-Length: ' . filesize($path));

/*  inline so a slip opens in the viewer, but the filename is
 *  quoted and stripped of anything that could break the header. */
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
$disposition = !empty($_GET['dl']) ? 'attachment' : 'inline';
header('Content-Disposition: ' . $disposition . '; filename="' . $safeName . '"');

/*  Never sniffed, never framed, never cached by anything shared.  */
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'self\'; sandbox');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

/*  Any buffered output would corrupt the file it is stapled to. */
while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($path);
