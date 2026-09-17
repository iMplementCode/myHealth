<?php

/**
 * ============================================================
 *  Image uploads
 * ------------------------------------------------------------
 *  Shared, validated handling for user-supplied images. Files
 *  are checked by real MIME type (via finfo) rather than by the
 *  extension the browser claims, given a generated name so a
 *  caller can never influence the path, and written under
 *  UPLOAD_PATH.
 *
 *  Used by the product form; written generically so future
 *  modules (customer logos, GRN evidence) can reuse it.
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


const IMAGE_MAX_BYTES  = 5 * 1024 * 1024;   // 5 MB per image
const IMAGE_MAX_COUNT  = 8;                 // per product
const IMAGE_MIME_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/**
 * Store an uploaded-image field.
 *
 * @param array  $files  One entry of $_FILES (the multi-file shape).
 * @param string $folder Sub-folder of UPLOAD_PATH, e.g. 'products'.
 * @return array{files: array<int, array{url:string, filename:string}>, errors: string[]}
 */
function store_uploaded_images(array $files, string $folder = 'products'): array
{
    $uploaded = [];
    $errors   = [];

    // Nothing selected — not an error, just nothing to do.
    if (empty($files['name']) || (is_array($files['name']) && $files['name'][0] === '')) {
        return ['files' => [], 'errors' => []];
    }

    // Normalise the single-file shape into the multi-file one.
    if (!is_array($files['name'])) {
        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $k) {
            $files[$k] = [$files[$k]];
        }
    }

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'products';
    $dir = rtrim(UPLOAD_PATH, '/') . '/' . $folder . '/';
    if ($why = upload_dir_problem($dir)) {
        return ['files' => [], 'errors' => [$why]];
    }

    $count = count($files['name']);
    if ($count > IMAGE_MAX_COUNT) {
        return ['files' => [], 'errors' => ['A maximum of ' . IMAGE_MAX_COUNT . ' images is allowed.']];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    for ($i = 0; $i < $count; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $label = 'Image ' . ($i + 1);

        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            $errors[] = "$label could not be uploaded.";
            continue;
        }
        if (($files['size'][$i] ?? 0) > IMAGE_MAX_BYTES) {
            $errors[] = "$label is larger than 5 MB.";
            continue;
        }
        // Only trust a file the server itself identifies as an image,
        // and only one that really came in through an upload.
        if (!is_uploaded_file($files['tmp_name'][$i])) {
            $errors[] = "$label was not a valid upload.";
            continue;
        }
        $mime = $finfo->file($files['tmp_name'][$i]) ?: '';
        if (!isset(IMAGE_MIME_TYPES[$mime])) {
            $errors[] = "$label must be a JPEG, PNG, WebP or GIF.";
            continue;
        }

        // The stored name is generated, never taken from user input.
        $filename = uniqid('img_', true) . '.' . IMAGE_MIME_TYPES[$mime];
        if (!move_uploaded_file($files['tmp_name'][$i], $dir . $filename)) {
            $errors[] = "$label: " . (upload_dir_problem($dir) ?? "could not be saved to $dir.");
            continue;
        }

        $uploaded[] = [
            'url'      => rtrim(UPLOAD_URL, '/') . '/' . $folder . '/' . $filename,
            'filename' => $filename,
        ];
    }

    return ['files' => $uploaded, 'errors' => $errors];
}

/** Proof of payment is a photo or a PDF, and only ever one file. */
const DOCUMENT_MIME_TYPES = IMAGE_MIME_TYPES + ['application/pdf' => 'pdf'];

/**
 * Store a single supporting document — a payment slip, a bank
 * statement, a signed agreement.
 *
 * Same guarantees as the image path: the type is taken from the
 * file itself rather than its name, and the stored name is
 * generated so a caller can never influence the path.
 *
 * @param array  $file   One entry of $_FILES (single-file shape).
 * @param string $folder Sub-folder of UPLOAD_PATH, e.g. 'payments'.
 * @return array{file: ?array{url:string, filename:string}, error: ?string}
 */
function store_uploaded_document(array $file, string $folder = 'documents'): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['file' => null, 'error' => null];   // nothing chosen
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['file' => null, 'error' => 'The file could not be uploaded.'];
    }
    if (($file['size'] ?? 0) > IMAGE_MAX_BYTES) {
        return ['file' => null, 'error' => 'The file is larger than 5 MB.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['file' => null, 'error' => 'That was not a valid upload.'];
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    if (!isset(DOCUMENT_MIME_TYPES[$mime])) {
        return ['file' => null, 'error' => 'Attach a JPEG, PNG, WebP, GIF or PDF.'];
    }

    $folder = preg_replace('/[^a-z0-9_-]/i', '', $folder) ?: 'documents';
    $dir    = rtrim(UPLOAD_PATH, '/') . '/' . $folder . '/';
    if ($why = upload_dir_problem($dir)) {
        return ['file' => null, 'error' => $why];
    }

    $filename = uniqid('doc_', true) . '.' . DOCUMENT_MIME_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        return ['file' => null, 'error' =>
            upload_dir_problem($dir) ?? 'The file could not be saved to ' . $dir . '.'];
    }

    return [
        'file'  => [
            'url'      => rtrim(UPLOAD_URL, '/') . '/' . $folder . '/' . $filename,
            'filename' => $filename,
        ],
        'error' => null,
    ];
}

/**
 * Attach stored images to a product, making the first one primary
 * when the product has no primary image yet.
 */
function attach_product_images(int $productId, array $stored): void
{
    if (!$stored) {
        return;
    }
    $hasPrimary = (bool) db_value(
        "SELECT 1 FROM product_images WHERE product_id = :id AND is_primary",
        [':id' => $productId]
    );
    $nextOrder = (int) db_value(
        "SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = :id",
        [':id' => $productId]
    );

    foreach ($stored as $img) {
        db_run(
            "INSERT INTO product_images (product_id, image_url, is_primary, sort_order)
             VALUES (:pid, :url, :primary, :ord)",
            [
                ':pid'     => $productId,
                ':url'     => $img['url'],
                ':primary' => $hasPrimary ? 'f' : 't',
                ':ord'     => $nextOrder++,
            ]
        );
        $hasPrimary = true;
    }
}

/**
 * Make sure an upload folder exists and can be written to, and say
 * precisely what is wrong when it cannot.
 *
 * ── Why this is worth its own function ──────────────────────────
 *  "Failed to save logo to server" is a true statement and a
 *  useless one. It does not say which folder, whether it exists,
 *  or that the cure is one chmod. That message cost a round trip.
 *
 *  There are only a few reasons `move_uploaded_file()` fails, and
 *  the application can tell them apart:
 *
 *    the folder is missing and cannot be created  → the PARENT is
 *                                                    not writable
 *    the folder exists but is not writable        → it belongs to
 *                                                    another user
 *    the disk is full                             → free space
 *
 *  Each of those has a different fix, so each gets a different
 *  sentence.
 *
 * ── It tries before it complains ────────────────────────────────
 *  A folder that exists but is not writable is worth one attempt
 *  at chmod: where the web server owns the parent, or shares its
 *  group, that quietly fixes it. Where it does not, nothing is
 *  lost and the message below is still accurate.
 *
 * @return string|null null when the folder is ready, otherwise why not.
 */
function upload_dir_problem(string $dir): ?string
{
    $dir    = rtrim($dir, '/\\') . '/';
    $parent = dirname(rtrim($dir, '/\\'));

    if (!is_dir($dir)) {
        if (!is_dir($parent)) {
            return "The uploads folder is missing entirely ($parent). "
                 . "Create it and give the web server permission to write to it.";
        }
        if (!is_writable($parent)) {
            return "The web server cannot create $dir because it cannot write to $parent. "
                 . "Run: chmod 775 " . $parent;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return "Could not create $dir. Check the permissions on $parent.";
        }
    }

    if (!is_writable($dir)) {
        // One attempt, then the truth.
        @chmod($dir, 0775);
        clearstatcache(true, $dir);
    }

    if (!is_writable($dir)) {
        $owner = function_exists('posix_getpwuid') && ($st = @stat($dir))
            ? (@posix_getpwuid($st['uid'])['name'] ?? $st['uid']) : null;
        $me = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (@posix_getpwuid(posix_geteuid())['name'] ?? 'the web server') : 'the web server';

        return "The folder $dir is not writable by $me"
             . ($owner !== null ? " — it belongs to $owner" : '')
             . ". Run: chmod 775 " . rtrim($dir, '/') . ($owner !== null ? "  (or chown it to $me)" : '');
    }

    // A full disk fails the same way and needs saying differently.
    $free = @disk_free_space($dir);
    if ($free !== false && $free < 1024 * 1024) {
        return "There is no space left on the disk holding $dir.";
    }

    return null;
}

/**
 * The link to a private uploaded document.
 *
 * Payment slips and the like are no longer served off the disk by
 * the web server — see public/documents/view.php for why — so a
 * stored proof_url is not a URL anybody can follow any more. This
 * turns whatever is on the row into a link through the endpoint
 * that checks for a session first.
 *
 * It takes what is actually in the database, which after several
 * years of the application is not one shape: an absolute URL from
 * when UPLOAD_URL was baked in, or a bare "payments/doc_x.pdf".
 * Both reduce to the last two segments, which is all the endpoint
 * wants.
 *
 * @param  ?string $stored  proof_url / agreement_url as recorded
 * @return ?string          a URL to follow, or null if there is no document
 */
function private_document_url(?string $stored, bool $download = false): ?string
{
    $stored = trim((string) $stored);
    if ($stored === '') {
        return null;
    }

    // Drop any scheme and host, then take the folder and filename.
    $path  = parse_url($stored, PHP_URL_PATH) ?: $stored;
    $parts = array_values(array_filter(explode('/', str_replace('\\', '/', $path)),
        static fn($p) => $p !== '' && $p !== '.' && $p !== '..'));

    if (count($parts) < 2) {
        return null;
    }
    $name   = array_pop($parts);
    $folder = array_pop($parts);

    return url('documents/view.php?f=' . rawurlencode($folder) . '/' . rawurlencode($name)
        . ($download ? '&dl=1' : ''));
}
