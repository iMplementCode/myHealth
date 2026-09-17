<?php

/**
 * ============================================================
 *  User Management — Create
 * ------------------------------------------------------------
 *  Add a new user. Administrators only. CSRF-protected, fully
 *  validated, password hashed with password_hash().
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(ROLE_ADMIN);

$roles  = db_all("SELECT role_id, name FROM roles ORDER BY name");
$errors = [];
$old    = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'mobile' => '', 'role_id' => null, 'is_active' => true];

if (is_post()) {
    csrf_check();

    $old = [
        'first_name' => input($_POST, 'first_name'),
        'last_name'  => input($_POST, 'last_name'),
        'username'   => input($_POST, 'username'),
        'email'      => strtolower(input($_POST, 'email')),
        'mobile'     => input($_POST, 'mobile'),
        'role_id'    => input_int($_POST, 'role_id'),
        'is_active'  => isset($_POST['is_active']),
    ];
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    // ── Validation ──────────────────────────────────────────
    if ($old['first_name'] === '') $errors['first_name'] = 'First name is required.';
    if ($old['last_name'] === '')  $errors['last_name']  = 'Last name is required.';
    if (!valid_email($old['email'])) $errors['email'] = 'A valid email address is required.';
    if ($old['username'] !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $old['username'])) {
        $errors['username'] = 'Username may use letters, numbers, dot, underscore and hyphen (3–50 chars).';
    }
    // One policy, checked in one function, so the rule at sign-up
    // is the rule at every change afterwards.
    $strength = password_policy_check($password, [
        $old['first_name'], $old['last_name'], explode('@', $old['email'])[0] ?? '',
    ]);
    if ($strength !== null) $errors['password'] = $strength;
    if ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';
    if ($old['role_id'] === null) $errors['role_id'] = 'Please select a role.';

    // Uniqueness checks
    if (!isset($errors['email'])) {
        if (db_value("SELECT 1 FROM users WHERE LOWER(email) = :e", [':e' => $old['email']])) {
            $errors['email'] = 'That email is already in use.';
        }
    }
    if ($old['username'] !== '' && !isset($errors['username'])) {
        if (db_value("SELECT 1 FROM users WHERE LOWER(username) = :u", [':u' => strtolower($old['username'])])) {
            $errors['username'] = 'That username is already taken.';
        }
    }

    $isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

    if (!$errors) {
        try {
            db_run(
                "INSERT INTO users (username, first_name, last_name, email, mobile, password_hash, role_id, is_active, created_at, updated_at)
                 VALUES (:username, :first_name, :last_name, :email, :mobile, :hash, :role_id, :is_active, NOW(), NOW())",
                [
                    ':username'   => $old['username'] !== '' ? $old['username'] : null,
                    ':first_name' => $old['first_name'],
                    ':last_name'  => $old['last_name'],
                    ':email'      => $old['email'],
                    ':mobile'     => $old['mobile'] !== '' ? $old['mobile'] : null,
                    ':hash'       => password_hash($password, PASSWORD_DEFAULT),
                    ':role_id'    => $old['role_id'],
                    ':is_active'  => $old['is_active'] ? 't' : 'f',
                ]
            );
            audit_log('user.create', 'users', $old['email']);
            if ($isAjax) {
                json_response(['success' => true, 'message' => 'User ' . $old['first_name'] . ' ' . $old['last_name'] . ' created.']);
            }
            flash('success', 'User ' . $old['first_name'] . ' ' . $old['last_name'] . ' created.');
            redirect('users/index.php');
        } catch (PDOException $ex) {
            error_log('[USERS] create failed: ' . $ex->getMessage());
            $errors['general'] = $ex->getCode() === '23505'
                ? 'That email, username or mobile is already in use.'
                : db_rule_message($ex, 'Could not create the user. Please try again.');
        }
    }

    // Modal (AJAX) submissions get validation feedback as JSON.
    if ($isAjax && $errors) {
        json_response(['success' => false, 'message' => implode(' ', array_values($errors))]);
    }
}

$pageTitle    = 'Add User';
$pageSubtitle = 'Create a new account';
$pageStyles   = ['forms.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Users', 'href' => 'users/index.php'], ['label' => 'Add User']];

require __DIR__ . '/../../includes/header.php';
$err = fn(string $f) => isset($errors[$f]) ? 'is-error' : '';
?>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert--error"><span><?= e($errors['general']) ?></span></div>
<?php endif; ?>

<form method="POST" action="<?= e(url('users/create.php')) ?>" novalidate class="form-card" data-validate>
    <?= csrf_field() ?>

    <div class="form-grid-2">
        <div class="form-group <?= $err('first_name') ?>">
            <label class="form-label" for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" class="form-control" required
                   value="<?= e($old['first_name']) ?>">
            <?php if (isset($errors['first_name'])): ?><span class="field-err"><?= e($errors['first_name']) ?></span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('last_name') ?>">
            <label class="form-label" for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" class="form-control" required
                   value="<?= e($old['last_name']) ?>">
            <?php if (isset($errors['last_name'])): ?><span class="field-err"><?= e($errors['last_name']) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group <?= $err('email') ?>">
            <label class="form-label" for="email">Email <span class="req">*</span></label>
            <input type="email" id="email" name="email" class="form-control" required
                   value="<?= e($old['email']) ?>">
            <?php if (isset($errors['email'])): ?><span class="field-err"><?= e($errors['email']) ?></span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('username') ?>">
            <label class="form-label" for="username">Username <span class="opt">(optional)</span></label>
            <input type="text" id="username" name="username" class="form-control" autocomplete="off"
                   value="<?= e($old['username']) ?>">
            <?php if (isset($errors['username'])): ?><span class="field-err"><?= e($errors['username']) ?></span>
            <?php else: ?><span class="hint">Used for signing in, in addition to email.</span><?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label" for="mobile">Mobile <span class="opt">(optional)</span></label>
            <input type="text" id="mobile" name="mobile" class="form-control" value="<?= e($old['mobile']) ?>">
        </div>
        <div class="form-group <?= $err('role_id') ?>">
            <label class="form-label" for="role_id">Role <span class="req">*</span></label>
            <select id="role_id" name="role_id" class="form-control" required>
                <option value="">— Select role —</option>
                <?php foreach ($roles as $r): ?>
                    <option value="<?= (int) $r['role_id'] ?>" <?= $old['role_id'] === (int) $r['role_id'] ? 'selected' : '' ?>>
                        <?= e($r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['role_id'])): ?><span class="field-err"><?= e($errors['role_id']) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group <?= $err('password') ?>">
            <label class="form-label" for="password">Password <span class="req">*</span></label>
            <div class="input-affix">
                <input type="password" id="password" name="password" class="form-control" required
                       minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" data-match="confirm_password">
                <button type="button" class="affix-btn" data-toggle-password="password">Show</button>
            </div>
            <?php if (isset($errors['password'])): ?><span class="field-err"><?= e($errors['password']) ?></span>
            <?php else: ?><span class="hint">Minimum 8 characters.</span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('confirm_password') ?>">
            <label class="form-label" for="confirm_password">Confirm password <span class="req">*</span></label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="<?= (int) PASSWORD_MIN_LENGTH ?>">
            <?php if (isset($errors['confirm_password'])): ?><span class="field-err"><?= e($errors['confirm_password']) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="form-group">
        <label class="switch-row">
            <span>
                <span class="switch-title">Active account</span>
                <span class="switch-sub">Inactive users cannot sign in.</span>
            </span>
            <span class="switch">
                <input type="checkbox" name="is_active" <?= $old['is_active'] ? 'checked' : '' ?>>
                <span class="slider"></span>
            </span>
        </label>
    </div>

    <div class="form-actions">
        <a href="<?= e(url('users/index.php')) ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Create user</button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
