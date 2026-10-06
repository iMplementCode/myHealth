<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/company.php';
require_once __DIR__ . '/../../../includes/uploads.php';
// The company's own details — its name, address, tax number and
// logo — appear on every document the business issues. Changing
// them is not something a salesperson does, and the page had no
// guard at all: any signed-in user could rewrite the letterhead.
require_role(ROLE_MANAGER);
// ============================================================
//  Company Settings — Insert / Update (Upsert)
//  Run AI Technologies | Secure PDO + PostgreSQL
// ============================================================


// ─── CONFIGURATION ───────────────────────────────────────────
//  These were `__DIR__ . '/assets/img/'` and `'/assets/img/'`.
//
//  The first put every uploaded logo in
//  public/modules/settings/assets/img/ — a folder nothing serves and
//  nothing reads — and the second was a re-define of a constant
//  bootstrap.php had already set, so PHP kept the original and the
//  stored path came out as "/uploadscompany_logo.jpg". The upload
//  reported success and the documents kept the old logo.
//
//  Distinct names now, so neither can collide with config.php again,
//  and a real folder under uploads/ — which already refuses to
//  execute anything (public/uploads/.htaccess).
define('LOGO_DIR', PUBLIC_PATH . '/uploads/company/');
// Stored relative to the document root, which is what
// company_logo_fs_path() and company_logo_url() both expect.
define('LOGO_REL', 'uploads/company/');
define('MAX_LOGO_SIZE', 2 * 1024 * 1024); // 2MB limit
define('ALLOWED_MIMES', ['image/jpeg', 'image/png', 'image/webp']);

// ─── 1. DATABASE CONNECTION ──────────────────────────────────

// ─── 2. FETCH CURRENT SETTINGS ───────────────────────────────
function getCompanySettings(PDO $pdo): ?array {
    try {
        $stmt = $pdo->query("SELECT * FROM company_settings WHERE id = 1");
        $result = $stmt->fetch();
        return $result ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

// ─── 3. LOGO UPLOAD HANDLER ──────────────────────────────────
function uploadLogo(array $file): array {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'path' => null]; // No file uploaded, which is fine
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => "Upload error occurred."];
    }

    if ($file['size'] > MAX_LOGO_SIZE) {
        return ['success' => false, 'message' => "Logo exceeds the 2MB size limit."];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!in_array($mimeType, ALLOWED_MIMES)) {
        return ['success' => false, 'message' => "Invalid file type. Only JPG, PNG, and WebP are allowed."];
    }

    // Says which folder and why, because "failed to save" sends
    // somebody looking at the image file for a fault that is in the
    // filesystem. See upload_dir_problem() in includes/uploads.php.
    if ($why = upload_dir_problem(LOGO_DIR)) {
        return ['success' => false, 'message' => $why];
    }

    // Generate specific filename to overwrite older logos and save space
    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'png'
    };
    
    // Only a real upload, and only under a name this code chose — a
    // filename from the browser is the caller's to pick, and a path
    // is not something a caller gets to influence.
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['success' => false, 'message' => "That was not a valid upload."];
    }

    /*  Into the database first, because that is the copy that lasts.
     *
     *  A container's filesystem is rebuilt from the repository on
     *  every deploy, so the file written below is gone the next time
     *  the code is pushed. Before this, that meant every shop lost
     *  its logo on every release and the application fell back to
     *  the logo shipped with it — one real company's — which then
     *  printed on everybody else's invoices.
     *
     *  The file is still written: it costs nothing, it keeps a
     *  single-server install working exactly as before, and it is
     *  what company_logo_fs_path() reads on a database that has not
     *  had migration 058 applied yet.                             */
    $bytes = @file_get_contents($file['tmp_name']);
    if ($bytes === false || $bytes === '') {
        return ['success' => false, 'message' => 'The uploaded file could not be read.'];
    }
    if (!company_logo_store($bytes, $mimeType)) {
        // Not fatal on its own — the file copy below may still serve
        // it — but it is the copy that survives, so say so.
        error_log('[LOGO] stored on disk only; it will not survive the next deploy.');
    }

    // Stamped, so a browser holding yesterday's logo in cache does not
    // keep showing it after the letterhead has changed. Replacing one
    // fixed filename is exactly how a changed logo looks unchanged.
    $filename = 'company_logo_' . date('YmdHis') . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], LOGO_DIR . $filename)) {
        // The folder was checked above, so getting here means
        // something changed underneath us or the reason is one the
        // check cannot see. Ask again — a second opinion beats
        // "failed to save".
        return ['success' => false, 'message' =>
            upload_dir_problem(LOGO_DIR) ?? 'Could not write the logo to ' . LOGO_DIR
            . '. Check the web server has permission to write there.'];
    }

    // The previous logo is no longer anybody's letterhead.
    foreach (glob(LOGO_DIR . 'company_logo_*') ?: [] as $old) {
        if (basename($old) !== $filename) {
            @unlink($old);
        }
    }

    return ['success' => true, 'path' => LOGO_REL . $filename];
}

// ─── 4. UPSERT LOGIC (Insert or Update) ──────────────────────
function saveCompanySettings(PDO $pdo, array $data): array {
    try {
        /*  Every column this form can write, and what to write into
         *  it. The list is filtered against the database before the
         *  statement is built, so a server that has pulled the code
         *  but not run the migrations saves what it can rather than
         *  failing the whole form.
         *
         *  That matters more than it sounds: when this failed as a
         *  whole, the failure people noticed was the logo — written
         *  to disk successfully and then never recorded — and the
         *  cause looked like anything but a missing column.
         *
         *  It used to be one $hasX/$xCol/$xVal/$xSet quartet per
         *  guarded column, which was already awkward at one column
         *  and does not survive five.                              */
        $columns = [
            'company_name'  => $data['company_name'],
            'location'      => $data['location']      ?: null,
            'email'         => $data['email']         ?: null,
            'extra_emails'  => $data['extra_emails']  ?: null,
            'mobile'        => $data['mobile']        ?: null,
            'website'       => $data['website']       ?: null,
            'tax_pin'       => $data['tax_pin']       ?: null,
            'mpesa_paybill' => $data['mpesa_paybill'] ?: null,
            'mpesa_account' => $data['mpesa_account'] ?: null,
            'bank_details'  => $data['bank_details']  ?: null,
            'logo_path'     => $data['logo_path']     ?: null,
        ];

        $columns = array_filter(
            $columns,
            static fn($col) => column_exists('company_settings', $col),
            ARRAY_FILTER_USE_KEY
        );

        if (!isset($columns['company_name'])) {
            return ['success' => false, 'message' =>
                'The company_settings table is missing its company_name column. '
                . 'Run the outstanding migrations.'];
        }

        $names  = array_keys($columns);
        $params = [];
        $sets   = [];
        foreach ($names as $col) {
            $params[':' . $col] = $columns[$col];
            /*  logo_path is the one exception: the form only sends it
             *  when a new file was uploaded, so an empty value must
             *  leave the existing logo alone rather than clearing it. */
            $sets[] = $col === 'logo_path'
                ? 'logo_path = COALESCE(EXCLUDED.logo_path, company_settings.logo_path)'
                : "$col = EXCLUDED.$col";
        }

        $sql = 'INSERT INTO company_settings (id, ' . implode(', ', $names) . ', updated_at)'
             . ' VALUES (1, :' . implode(', :', $names) . ', NOW())'
             . ' ON CONFLICT (id) DO UPDATE SET ' . implode(', ', $sets)
             . ', updated_at = NOW()';

        $pdo->prepare($sql)->execute($params);

        return ['success' => true, 'message' => "Company settings saved successfully!"];

    } catch (PDOException $e) {
        error_log("[DB] Settings Save Failed: " . $e->getMessage());
        return ['success' => false, 'message' => db_rule_message($e, "Database error occurred while saving settings.")];
    }
}

// ─── 5. HANDLE FORM SUBMISSION ───────────────────────────────
$pdo = getDBConnection();
$flash = null;
$currentSettings = getCompanySettings($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // A form that changes data must prove the request came from
    // this application and not from a page on someone else's.
    csrf_check();

    $companyName = trim($_POST['company_name'] ?? '');
    
    if (empty($companyName)) {
        $flash = ['success' => false, 'message' => "Company Name is required."];
    } else {
        $logoPath = null;
        
        // Handle logo upload if provided
        if (!empty($_FILES['logo']['name'])) {
            $uploadResult = uploadLogo($_FILES['logo']);
            if (!$uploadResult['success']) {
                $flash = ['success' => false, 'message' => $uploadResult['message']];
            } else {
                $logoPath = $uploadResult['path'];
            }
        }

        // Only save to DB if there wasn't an upload error
        if (!$flash) {
            $data = [
                'company_name' => $companyName,
                'location'     => trim($_POST['location'] ?? ''),
                'email'        => strtolower(trim($_POST['email'] ?? '')),
                'mobile'       => trim($_POST['mobile'] ?? ''),
                'website'      => strtolower(trim($_POST['website'] ?? '')),
                // Validated here rather than at print time, and the main
                // address is not repeated back: seeing it in both boxes
                // reads as a mistake.
                'extra_emails' => implode("\n", array_values(array_diff(
                    array_unique(array_filter(array_map(
                        static fn($line) => valid_email(trim($line)) ?? '',
                        preg_split('/[\r\n,;]+/', (string) ($_POST['extra_emails'] ?? '')) ?: []
                    ))),
                    [strtolower(trim($_POST['email'] ?? ''))]
                ))),
                // Digits only, and spaces stripped: a paybill is keyed
                // into a phone, and "400 200" pasted from a website is
                // not what the customer types.
                'tax_pin'       => strtoupper(trim($_POST['tax_pin'] ?? '')),
                'mpesa_paybill' => preg_replace('/\D+/', '', (string) ($_POST['mpesa_paybill'] ?? '')),
                'mpesa_account' => trim($_POST['mpesa_account'] ?? ''),
                'bank_details'  => trim($_POST['bank_details'] ?? ''),
                'logo_path'    => $logoPath
            ];

            $flash = saveCompanySettings($pdo, $data);
            
            // Refresh settings for display
            if ($flash['success']) {
                $currentSettings = getCompanySettings($pdo);
            }
        }
    }
}
$pdo = null;
?>
<?php
/*  Until now this page built its own HTML document: its own
    <head>, its own sixty-line <style> block restating the design
    tokens, its own web fonts, its own class names, and a <title>
    naming a different company. Clicking Company Details therefore
    took the whole application away — no sidebar, no top bar, no
    breadcrumbs, nothing to get back with — which is why it felt
    like a new window had opened over the one somebody was working
    in.

    It now uses the same layout as every other page. The styling
    that was duplicated here comes from style.css and forms.css,
    so this screen follows the light and dark theme like the rest
    instead of being permanently dark.                           */

$pageTitle   = 'Company Settings';
//  finance.css as well as forms.css: .u-pad and .u-nomargin are
//  defined there, and this page uses both. Loading only forms.css
//  left every field flush against the panel border.
$pageStyles  = ['forms.css', 'finance.css'];
$pageScripts = ['legacy.js'];
$breadcrumbs = [['label' => 'Settings'], ['label' => 'Company Details']];

require __DIR__ . '/../../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert--<?= $flash['success'] ? 'success' : 'error' ?>" role="status">
        <?php /*  Escaped, like every other flash in the application.
                  These messages are not all written by us: a save that
                  trips a database rule comes back through
                  db_rule_message(), and a PostgreSQL error text can
                  quote the value that caused it — which is a value
                  somebody typed.                                   */ ?>
        <span><?= e($flash['message']) ?></span>
    </div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data">
    <?= csrf_field() ?>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Branding</h2>
        </div>
        <div class="u-pad">
            <div class="form-grid-2">
                <div class="form-group">
                    <div class="form-label">Current logo</div>
                    <?php if (!empty($currentSettings['logo_path'])): ?>
                        <img src="<?= e(company_logo_url() ?? '') ?>" alt="Company logo"
                             style="max-height:72px;max-width:100%;">
                    <?php else: ?>
                        <p class="hint u-nomargin">No logo uploaded yet.</p>
                    <?php endif; ?>
                </div>
                <div class="form-group">
                    <label class="form-label" for="logo">Replace it</label>
                    <input type="file" id="logo" name="logo" class="form-control"
                           accept="image/jpeg, image/png, image/webp" data-logo-input>
                    <span class="hint" id="fileNameHint">
                        Prints on invoices and receipts. Max 2MB &mdash; JPG, PNG or WebP.
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Core details</h2>
        </div>
        <div class="u-pad">
            <div class="form-group">
                <label class="form-label" for="company_name">
                    Company name <span class="req">*</span>
                </label>
                <?php /*  No default. This used to pre-fill the parent
                          company's name, address and telephone number,
                          so a new pharmacy opened its settings and
                          found somebody else's business already typed
                          in — and printed it on an invoice if nobody
                          looked.                                    */ ?>
                <input type="text" id="company_name" name="company_name" class="form-control"
                       value="<?= e($currentSettings['company_name'] ?? '') ?>" required>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label class="form-label" for="email">Email address</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= e($currentSettings['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="mobile">Mobile number</label>
                    <input type="tel" id="mobile" name="mobile" class="form-control"
                           value="<?= e($currentSettings['mobile'] ?? '') ?>">
                </div>
            </div>

            <?php if (column_exists('company_settings', 'extra_emails')): ?>
                <div class="form-group">
                    <label class="form-label" for="extra_emails">Other email addresses</label>
                    <textarea id="extra_emails" name="extra_emails" class="form-control" rows="3"
                              placeholder="accounts@example.co.ke&#10;support@example.co.ke"><?= e($currentSettings['extra_emails'] ?? '') ?></textarea>
                    <span class="hint">
                        One per line, printed after the main address in this order.
                        Anything that is not an email address is dropped rather than printed.
                    </span>
                </div>
            <?php endif; // A box that silently discards what is typed
                         // into it is worse than no box. ?>

            <div class="form-group">
                <label class="form-label" for="website">Website</label>
                <input type="text" id="website" name="website" class="form-control"
                       value="<?= e($currentSettings['website'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label class="form-label" for="location">Physical address</label>
                <textarea id="location" name="location" class="form-control" rows="3"><?= e($currentSettings['location'] ?? '') ?></textarea>
            </div>

            <?php if (column_exists('company_settings', 'tax_pin')): ?>
                <div class="form-group">
                    <label class="form-label" for="tax_pin">KRA PIN</label>
                    <input type="text" id="tax_pin" name="tax_pin" class="form-control"
                           placeholder="P051234567X"
                           value="<?= e($currentSettings['tax_pin'] ?? '') ?>">
                    <span class="hint">
                        Prints under the company details on every document. A tax invoice needs it.
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (column_exists('company_settings', 'mpesa_paybill')): ?>
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">How customers pay you</h2>
            </div>
            <div class="u-pad">
                <p class="hint">
                    Printed on invoices, proformas and quotes &mdash; the documents that ask
                    for money. An invoice that does not say where to send it gets answered
                    with a phone call, and the paybill arrives by WhatsApp with a digit missing.
                </p>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="mpesa_paybill">M-Pesa paybill or till</label>
                        <input type="text" id="mpesa_paybill" name="mpesa_paybill" class="form-control"
                               inputmode="numeric" placeholder="400200"
                               value="<?= e($currentSettings['mpesa_paybill'] ?? '') ?>">
                        <span class="hint">Digits only. Spaces are stripped.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="mpesa_account">Account number</label>
                        <input type="text" id="mpesa_account" name="mpesa_account" class="form-control"
                               placeholder="Your account, or leave blank"
                               value="<?= e($currentSettings['mpesa_account'] ?? '') ?>">
                        <span class="hint">
                            Leave empty if customers should quote the invoice number instead
                            &mdash; the document then says so.
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="bank_details">Bank details</label>
                    <textarea id="bank_details" name="bank_details" class="form-control" rows="4"
                              placeholder="Equity Bank, Westlands Branch&#10;Account name&#10;0123456789"><?= e($currentSettings['bank_details'] ?? '') ?></textarea>
                    <span class="hint">
                        Free text, printed as you type it. Every bank wants a different set of
                        details and a customer paying by transfer copies the block whole.
                    </span>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save settings</button>
    </div>
</form>

<script nonce="<?= csp_nonce() ?>">
    // Show the chosen filename before uploading. Bound here rather
    // than with an onchange attribute: the CSP allows script from
    // 'self' and this nonce, and nothing inline, so an attribute
    // handler is refused by the browser and simply never runs.
    (function () {
        const input = document.querySelector('[data-logo-input]');
        const hint  = document.getElementById('fileNameHint');
        if (!input || !hint) return;
        const original = hint.textContent;
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            hint.textContent = file ? 'Selected file: ' + file.name : original;
        });
    })();
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
