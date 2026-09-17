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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Settings — Run AI Technologies</title>
    <link href="https://fonts.googleapis.com/css2?family=Cabinet+Grotesk:wght@400;500;700;800&family=Sentient:wght@400;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #0a0b10; --panel: #111218; --panel2: #16181f; --border: #252830;
            --ink: #eceef5; --ink-soft: #7a7f94; --gold: #f0b429; --gold-dim: rgba(240,180,41,0.1);
            --red: #ef4444; --green: #10b981; --radius: 12px;
        }
        
        body { font-family: 'Cabinet Grotesk', sans-serif; background: var(--bg); color: var(--ink); display: flex; justify-content: center; padding: 40px 20px; min-height: 100vh; }
        
        .container { width: 100%; max-width: 680px; }
        
        .page-header { margin-bottom: 24px; text-align: center; }
        .page-header h1 { font-family: 'Sentient', serif; font-size: 32px; font-weight: 700; color: var(--gold); margin-bottom: 8px; }
        .page-header p { font-size: 15px; color: var(--ink-soft); font-weight: 400; }

        .flash { background: rgba(16,185,129,0.1); color: var(--green); border: 1px solid var(--green); padding: 14px 18px; border-radius: var(--radius); margin-bottom: 24px; font-size: 14.5px; }
        .flash.error { background: rgba(239,68,68,0.1); color: var(--red); border-color: var(--red); }

        .card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); padding: 32px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        
        .form-section { margin-bottom: 24px; }
        .section-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--gold); border-bottom: 1px solid var(--border); padding-bottom: 8px; margin-bottom: 20px; }
        
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 13px; font-weight: 700; color: var(--ink-soft); margin-bottom: 8px; }
        .form-label .req { color: var(--gold); }
        
        .form-control { width: 100%; background: var(--panel2); border: 1px solid var(--border); border-radius: 8px; color: var(--ink); padding: 12px 14px; font-family: inherit; font-size: 15px; outline: none; transition: 0.2s; }
        .form-control:focus { border-color: var(--gold); background: var(--panel); box-shadow: 0 0 0 3px var(--gold-dim); }
        textarea.form-control { resize: vertical; min-height: 80px; }

        /* Logo Upload Styles */
        .logo-preview-area { display: flex; align-items: center; gap: 24px; background: var(--panel2); border: 1px dashed var(--border); border-radius: 8px; padding: 20px; }
        .current-logo { width: 80px; height: 80px; border-radius: 8px; background: var(--panel); border: 1px solid var(--border); display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .current-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .current-logo .no-logo { font-size: 11px; color: var(--ink-soft); text-transform: uppercase; font-weight: 700; }
        
        .upload-btn-wrapper { position: relative; overflow: hidden; display: inline-block; }
        .btn-upload { border: 1px solid var(--gold); color: var(--gold); background: transparent; padding: 8px 16px; border-radius: 6px; font-weight: 700; font-family: inherit; cursor: pointer; transition: 0.2s; }
        .btn-upload:hover { background: var(--gold-dim); }
        .upload-btn-wrapper input[type=file] { font-size: 100px; position: absolute; left: 0; top: 0; opacity: 0; cursor: pointer; }
        .upload-hint { display: block; font-size: 12px; color: var(--ink-soft); margin-top: 8px; font-weight: 400; }

        .btn-submit { width: 100%; background: var(--gold); color: #0a0b10; border: none; padding: 16px; font-family: inherit; font-weight: 800; font-size: 16px; border-radius: 8px; cursor: pointer; margin-top: 10px; transition: 0.2s; }
        .btn-submit:hover { opacity: 0.9; transform: translateY(-1px); }

        @media (max-width: 600px) {
            .grid-2 { grid-template-columns: 1fr; gap: 0; }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="page-header">
        <h1>Company Profile</h1>
        <p>Manage the branding and contact details used on official documents.</p>
    </div>

    <?php if ($flash): ?>
        <?php /* Escaped, like every other flash in the application.
                 These messages are not all written by us: a save that
                 trips a database rule comes back through
                 db_rule_message(), and a PostgreSQL error text can
                 quote the value that caused it — which is a value
                 somebody typed. */ ?>
        <div class="flash <?= $flash['success'] ? 'success' : 'error' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="POST" action="" enctype="multipart/form-data">
            <?= csrf_field() ?>
            
            <div class="form-section">
                <div class="section-title">Branding</div>
                <div class="form-group">
                    <label class="form-label">Company Logo (For PDFs & Invoices)</label>
                    <div class="logo-preview-area">
                        <div class="current-logo">
                            <?php if (!empty($currentSettings['logo_path'])): ?>
                                <img src="<?= htmlspecialchars(company_logo_url() ?? '') ?>" alt="Logo">
                            <?php else: ?>
                                <span class="no-logo">No Logo</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="upload-btn-wrapper">
                                <button type="button" class="btn-upload">Choose New Logo</button>
                                <input type="file" name="logo" accept="image/jpeg, image/png, image/webp" data-logo-input>
                            </div>
                            <span class="upload-hint" id="fileNameHint">Max 2MB. JPG, PNG, WebP allowed.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-title">Core Details</div>
                
                <div class="form-group">
                    <label class="form-label">Company Name <span class="req">*</span></label>
                    <input type="text" name="company_name" class="form-control" 
                           value="<?= htmlspecialchars($currentSettings['company_name'] ?? 'Run AI Technologies') ?>" required>
                </div>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Email Address</label>
                        <input type="email" name="email" class="form-control" 
                               value="<?= htmlspecialchars($currentSettings['email'] ?? 'info@runaitechnologies.com') ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Mobile Number</label>
                        <input type="tel" name="mobile" class="form-control" 
                               value="<?= htmlspecialchars($currentSettings['mobile'] ?? '+254 706 576244') ?>">
                    </div>
                </div>

                <?php if (column_exists('company_settings', 'extra_emails')): ?>
                <div class="form-group">
                    <label class="form-label">Other Email Addresses</label>
                    <textarea name="extra_emails" class="form-control" rows="3"
                              placeholder="accounts@example.co.ke&#10;support@example.co.ke"><?= htmlspecialchars($currentSettings['extra_emails'] ?? '') ?></textarea>
                    <span class="upload-hint">
                        One per line. They print on every document after the main
                        address above, in this order. Anything that is not an email
                        address is dropped rather than printed.
                    </span>
                </div>
                <?php endif; // A box that silently discards what is typed
                              // into it is worse than no box. ?>

                <div class="form-group">
                    <label class="form-label">Website URL</label>
                    <input type="text" name="website" class="form-control" 
                           value="<?= htmlspecialchars($currentSettings['website'] ?? 'www.runaitechnologies.com') ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Physical Address / Location</label>
                    <textarea name="location" class="form-control" rows="3"><?= htmlspecialchars($currentSettings['location'] ?? "Gaberone Road, Montana Mall 2nd Floor\nShop Number M206") ?></textarea>
                </div>

                <?php if (column_exists('company_settings', 'tax_pin')): ?>
                <div class="form-group">
                    <label class="form-label">KRA PIN</label>
                    <input type="text" name="tax_pin" class="form-control" placeholder="P051234567X"
                           value="<?= htmlspecialchars($currentSettings['tax_pin'] ?? '') ?>">
                    <span class="upload-hint">
                        Prints under the company details on every document. A tax
                        invoice needs it.
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (column_exists('company_settings', 'mpesa_paybill')): ?>
            <div class="form-section">
                <div class="section-title">How Customers Pay You</div>
                <p class="upload-hint" style="margin-bottom:14px;">
                    Printed on invoices, proformas and quotes — the documents that
                    ask for money. An invoice that does not say where to send it
                    gets answered with a phone call, and the paybill arrives by
                    WhatsApp with a digit missing.
                </p>

                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">M-Pesa Paybill / Till</label>
                        <input type="text" name="mpesa_paybill" class="form-control"
                               inputmode="numeric" placeholder="400200"
                               value="<?= htmlspecialchars($currentSettings['mpesa_paybill'] ?? '') ?>">
                        <span class="upload-hint">Digits only. Spaces are stripped.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Account Number</label>
                        <input type="text" name="mpesa_account" class="form-control"
                               placeholder="Your account, or leave blank"
                               value="<?= htmlspecialchars($currentSettings['mpesa_account'] ?? '') ?>">
                        <span class="upload-hint">
                            Leave this empty if customers should quote the invoice
                            number instead — the document then says so.
                        </span>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Bank Details</label>
                    <textarea name="bank_details" class="form-control" rows="4"
                              placeholder="Equity Bank, Westlands Branch&#10;Run AI Technologies Ltd&#10;0123456789"><?= htmlspecialchars($currentSettings['bank_details'] ?? '') ?></textarea>
                    <span class="upload-hint">
                        Free text, printed as you type it. Every bank wants a
                        different set of details and a customer paying by transfer
                        copies the block whole.
                    </span>
                </div>
            </div>
            <?php endif; ?>

            <button type="submit" class="btn-submit">Save Settings</button>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
    // Show the chosen filename before uploading. Bound here rather
    // than with an onchange attribute: the CSP allows script from
    // 'self' and this nonce, and nothing inline, so an attribute
    // handler is refused by the browser and simply never runs.
    (function () {
        const input = document.querySelector('[data-logo-input]');
        const hint  = document.getElementById('fileNameHint');
        if (!input || !hint) return;
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            hint.textContent = file ? 'Selected file: ' + file.name
                                    : 'Max 2MB. JPG, PNG, WebP allowed.';
            hint.style.color = file ? 'var(--gold)' : 'var(--ink-soft)';
        });
    })();
</script>

<script src="../../assets/js/legacy.js" defer></script>
</body>
</html>