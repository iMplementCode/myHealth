<?php

/**
 * ============================================================
 *  User Management — Edit / My Profile
 * ------------------------------------------------------------
 *  Administrators may edit any user. Non-administrators may
 *  edit only their own profile (name, email, mobile, password)
 *  but not their role or active status.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$me       = current_user();
$targetId = input_int($_GET, 'id') ?? $me['id'];
$isSelf   = $targetId === $me['id'];

// Access: admins edit anyone; everyone else only themselves.
if (!is_admin() && !$isSelf) {
    require_role(ROLE_ADMIN); // renders 403 and exits
}
$canManage = is_admin(); // may change role / active status

$user = db_one(
    "SELECT user_id, username, first_name, last_name, email, mobile, role_id, is_active
     FROM users WHERE user_id = :id",
    [':id' => $targetId]
);
if (!$user) {
    http_response_code(404);
    flash('error', 'That user does not exist.');
    redirect(is_admin() ? 'users/index.php' : 'dashboard/index.php');
}

$roles  = db_all("SELECT role_id, name FROM roles ORDER BY name");
$errors = [];
$old    = $user;

if (is_post()) {
    csrf_check();

    $old['first_name'] = input($_POST, 'first_name');
    $old['last_name']  = input($_POST, 'last_name');
    $old['email']      = strtolower(input($_POST, 'email'));
    $old['mobile']     = input($_POST, 'mobile');
    $old['username']   = trim(input($_POST, 'username'));
    if ($canManage) {
        $old['role_id']   = input_int($_POST, 'role_id');
        $old['is_active'] = isset($_POST['is_active']);
    }
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    // ── Validation ──────────────────────────────────────────
    if ($old['first_name'] === '') $errors['first_name'] = 'First name is required.';
    if ($old['last_name'] === '')  $errors['last_name']  = 'Last name is required.';
    if (!valid_email($old['email'])) $errors['email'] = 'A valid email address is required.';
    // Optional, as it is at sign-up — but it is a login identifier,
    // so its shape and uniqueness matter when one is given.
    if ($old['username'] !== '' && !preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $old['username'])) {
        $errors['username'] = 'Username may use letters, numbers, dot, underscore and hyphen (3–50 characters).';
    }
    if ($canManage && $old['role_id'] === null) $errors['role_id'] = 'Please select a role.';
    if ($password !== '' || $confirm !== '') {
        $strength = password_policy_check($password, [
            $old['first_name'], $old['last_name'], explode('@', $old['email'])[0] ?? '',
        ]);
        if ($strength !== null) $errors['password'] = $strength;
        if ($password !== $confirm) $errors['confirm_password'] = 'Passwords do not match.';

        // Changing your own password requires knowing it. Without
        // this, a session left open on an unattended machine is a
        // permanent account takeover: whoever sits down next sets a
        // new password and the real owner is locked out of their
        // own books. An administrator resetting somebody else's is
        // a different act, and is audited instead.
        if ($isSelf) {
            $current = (string) ($_POST['current_password'] ?? '');
            $hash    = (string) db_value(
                "SELECT password_hash FROM users WHERE user_id = :id",
                [':id' => $targetId]
            );
            if ($current === '' || !password_verify($current, $hash)) {
                $errors['current_password'] = 'Enter your current password to set a new one.';
            }
        }
    }

    // Email uniqueness (excluding this user).
    if (!isset($errors['email'])) {
        if (db_value("SELECT 1 FROM users WHERE LOWER(email) = :e AND user_id <> :id", [':e' => $old['email'], ':id' => $targetId])) {
            $errors['email'] = 'That email is already in use by another account.';
        }
    }

    // Username uniqueness, matched the way the login looks it up.
    if ($old['username'] !== '' && !isset($errors['username'])) {
        $taken = db_one(
            "SELECT TRIM(CONCAT(first_name, ' ', last_name)) AS name
               FROM users WHERE LOWER(username) = :u AND user_id <> :id",
            [':u' => strtolower($old['username']), ':id' => $targetId]
        );
        if ($taken) {
            $errors['username'] = 'That username is already used by ' . $taken['name'] . '.';
        }
    }

    // Guard: don't let an admin deactivate or demote themselves.
    if ($isSelf && $canManage) {
        if (!$old['is_active']) $errors['general'] = 'You cannot deactivate your own account.';
        if ($old['role_id'] !== (int) $user['role_id']) {
            $adminRole = (int) db_value("SELECT role_id FROM roles WHERE name = :n", [':n' => ROLE_ADMIN]);
            if ((int) $user['role_id'] === $adminRole && $old['role_id'] !== $adminRole) {
                $errors['general'] = 'You cannot change your own administrator role.';
            }
        }
    }

    if (!$errors) {
        try {
            $sql = "UPDATE users SET first_name = :fn, last_name = :ln, email = :em,
                                     mobile = :mo, username = :un, updated_at = NOW()";
            $params = [
                ':fn' => $old['first_name'],
                ':ln' => $old['last_name'],
                ':em' => $old['email'],
                ':mo' => $old['mobile'] !== '' ? $old['mobile'] : null,
                // Blank clears it: a username is optional, and the
                // column is UNIQUE, so '' would collide between any
                // two users who both cleared theirs. NULL does not.
                ':un' => $old['username'] !== '' ? $old['username'] : null,
                ':id' => $targetId,
            ];
            if ($canManage) {
                $sql .= ", role_id = :role, is_active = :active";
                $params[':role']   = $old['role_id'];
                $params[':active'] = $old['is_active'] ? 't' : 'f';
            }
            if ($password !== '') {
                $sql .= ", password_hash = :hash";
                $params[':hash'] = password_hash($password, PASSWORD_DEFAULT);
            }
            $sql .= " WHERE user_id = :id";
            db_run($sql, $params);

            $notes = [];

            // A new password ends every other session and every
            // remembered device. That is the whole reason people
            // change one: they want whoever else is signed in to
            // stop being signed in.
            if ($password !== '') {
                $ended = session_store_revoke_user($targetId, $isSelf ? session_id() : null);
                db_run("DELETE FROM remember_tokens WHERE user_id = :u", [':u' => $targetId]);
                audit_log('user.password_changed', 'users', $targetId, [
                    'by_self' => $isSelf, 'sessions_ended' => $ended,
                ]);
                if ($ended > 0) {
                    $notes[] = $ended . ' other ' . ($ended === 1 ? 'session was' : 'sessions were') . ' signed out';
                }
            }

            // Deactivating an account has to take effect now, not
            // whenever the person happens to close their browser.
            if ($canManage && !$old['is_active']) {
                $ended = session_store_revoke_user($targetId);
                db_run("DELETE FROM remember_tokens WHERE user_id = :u", [':u' => $targetId]);
                if ($ended > 0) {
                    $notes[] = 'signed out of ' . $ended . ' active ' . ($ended === 1 ? 'session' : 'sessions');
                }
            }

            // A role change decides what the session may reach, and
            // the session carries the old answer until it is told.
            if ($canManage && (int) $old['role_id'] !== (int) $user['role_id']) {
                audit_log('user.role_changed', 'users', $targetId, [
                    'from' => (int) $user['role_id'], 'to' => (int) $old['role_id'],
                ]);
                if (!$isSelf) {
                    session_store_revoke_user($targetId);
                    $notes[] = 'signed out so the new role takes effect';
                }
            }

            // Keep the session in sync if editing self.
            if ($isSelf) {
                $_SESSION['user_name']  = trim($old['first_name'] . ' ' . $old['last_name']);
                $_SESSION['user_email'] = $old['email'];
            }

            flash('success', 'Changes saved.' . ($notes ? ' ' . ucfirst(implode('; ', $notes)) . '.' : ''));
            redirect(is_admin() ? 'users/index.php' : 'dashboard/index.php');
        } catch (PDOException $ex) {
            error_log('[USERS] update failed: ' . $ex->getMessage());
            // The unique indexes are the last word; say which one
            // rather than leaving the user guessing what to change.
            $errors['general'] = $ex->getCode() === '23505'
                ? 'That email, username or mobile is already in use by another account.'
                : db_rule_message($ex, 'Could not save changes. Please try again.');
        }
    }
}

$pageTitle    = $isSelf ? 'My Profile' : 'Edit User';
$pageSubtitle = trim($user['first_name'] . ' ' . $user['last_name']);
$pageStyles   = ['forms.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = is_admin()
    ? [['label' => 'Users', 'href' => 'users/index.php'], ['label' => 'Edit']]
    : [['label' => 'My Profile']];

require __DIR__ . '/../../includes/header.php';
$err = fn(string $f) => isset($errors[$f]) ? 'is-error' : '';
?>

<?php if (!empty($errors['general'])): ?>
    <div class="alert alert--error"><span><?= e($errors['general']) ?></span></div>
<?php endif; ?>

<form method="POST" action="" novalidate class="form-card" data-validate>
    <?= csrf_field() ?>

    <div class="form-grid-2">
        <div class="form-group <?= $err('first_name') ?>">
            <label class="form-label" for="first_name">First name <span class="req">*</span></label>
            <input type="text" id="first_name" name="first_name" class="form-control" required value="<?= e($old['first_name']) ?>">
            <?php if (isset($errors['first_name'])): ?><span class="field-err"><?= e($errors['first_name']) ?></span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('last_name') ?>">
            <label class="form-label" for="last_name">Last name <span class="req">*</span></label>
            <input type="text" id="last_name" name="last_name" class="form-control" required value="<?= e($old['last_name']) ?>">
            <?php if (isset($errors['last_name'])): ?><span class="field-err"><?= e($errors['last_name']) ?></span><?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group <?= $err('email') ?>">
            <label class="form-label" for="email">Email <span class="req">*</span></label>
            <input type="email" id="email" name="email" class="form-control" required value="<?= e($old['email']) ?>">
            <?php if (isset($errors['email'])): ?><span class="field-err"><?= e($errors['email']) ?></span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('username') ?>">
            <label class="form-label" for="username">Username <span class="opt">(optional)</span></label>
            <input type="text" id="username" name="username" class="form-control" autocomplete="off"
                   maxlength="50" value="<?= e($old['username'] ?? '') ?>">
            <?php if (isset($errors['username'])): ?>
                <span class="field-err"><?= e($errors['username']) ?></span>
            <?php else: ?>
                <span class="hint">Letters, numbers, dot, underscore and hyphen. Can be used to sign in instead of the email. Leave blank for none.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label class="form-label" for="mobile">Mobile</label>
            <input type="text" id="mobile" name="mobile" class="form-control" value="<?= e($old['mobile'] ?? '') ?>">
        </div>
        <?php if ($canManage): ?>
            <div class="form-group <?= $err('role_id') ?>">
                <label class="form-label" for="role_id">Role <span class="req">*</span></label>
                <select id="role_id" name="role_id" class="form-control" required>
                    <option value="">— Select role —</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= (int) $r['role_id'] ?>" <?= (int) $old['role_id'] === (int) $r['role_id'] ? 'selected' : '' ?>>
                            <?= e($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['role_id'])): ?><span class="field-err"><?= e($errors['role_id']) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="form-divider"><span>Change password</span></div>

    <?php if ($isSelf): ?>
        <?php // Proving you know the current one is what stops a session
              // left open on an unattended machine becoming a takeover. ?>
        <div class="form-group <?= $err('current_password') ?>">
            <label class="form-label" for="current_password">Current password</label>
            <div class="input-affix">
                <input type="password" id="current_password" name="current_password"
                       class="form-control" autocomplete="current-password">
                <button type="button" class="affix-btn" data-toggle-password="current_password">Show</button>
            </div>
            <?php if (isset($errors['current_password'])): ?><span class="field-err"><?= e($errors['current_password']) ?></span>
            <?php else: ?><span class="hint">Needed only if you are setting a new password.</span><?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="form-grid-2">
        <div class="form-group <?= $err('password') ?>">
            <label class="form-label" for="password">New password</label>
            <div class="input-affix">
                <input type="password" id="password" name="password" class="form-control"
                       minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" autocomplete="new-password"
                       data-match="confirm_password">
                <button type="button" class="affix-btn" data-toggle-password="password">Show</button>
            </div>
            <?php if (isset($errors['password'])): ?><span class="field-err"><?= e($errors['password']) ?></span>
            <?php else: ?><span class="hint">Leave blank to keep the current password. At least
                <?= (int) PASSWORD_MIN_LENGTH ?> characters — a short phrase works well.</span><?php endif; ?>
        </div>
        <div class="form-group <?= $err('confirm_password') ?>">
            <label class="form-label" for="confirm_password">Confirm new password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                   minlength="<?= (int) PASSWORD_MIN_LENGTH ?>" autocomplete="new-password">
            <?php if (isset($errors['confirm_password'])): ?><span class="field-err"><?= e($errors['confirm_password']) ?></span><?php endif; ?>
        </div>
    </div>

    <?php if ($canManage && !$isSelf): ?>
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
    <?php endif; ?>

    <div class="form-actions">
        <a href="<?= e(url(is_admin() ? 'users/index.php' : 'dashboard/index.php')) ?>" class="btn btn-ghost">Cancel</a>
        <button type="submit" class="btn btn-primary">Save changes</button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
