<?php

/**
 * ============================================================
 *  User Management — Actions
 * ------------------------------------------------------------
 *  Central POST handler for user row actions: delete, toggle
 *  active status and reset password. Administrators only,
 *  CSRF-protected, and self-targeting is blocked where unsafe.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_role(ROLE_ADMIN);

if (!is_post()) {
    redirect('users/index.php');
}
csrf_check();

$action = input($_POST, 'action');
$id     = input_int($_POST, 'id');
$me     = current_user();

if ($id === null) {
    flash('error', 'No user specified.');
    redirect('users/index.php');
}

$user = db_one("SELECT user_id, first_name, last_name, email, is_active FROM users WHERE user_id = :id", [':id' => $id]);
if (!$user) {
    flash('error', 'That user no longer exists.');
    redirect('users/index.php');
}
$name  = trim($user['first_name'] . ' ' . $user['last_name']) ?: $user['email'];
$isSelf = $me['id'] === (int) $id;

try {
    switch ($action) {

        case 'delete':
            if ($isSelf) {
                flash('error', 'You cannot delete your own account.');
                break;
            }
            db_run("DELETE FROM users WHERE user_id = :id", [':id' => $id]);
            audit_log('user.delete', 'users', $id, ['name' => $name, 'email' => $user['email']]);
            flash('success', "User {$name} was deleted.");
            break;

        case 'toggle_status':
            if ($isSelf) {
                flash('error', 'You cannot change your own status.');
                break;
            }
            $new = $user['is_active'] ? 'f' : 't';
            db_run("UPDATE users SET is_active = :a, updated_at = NOW() WHERE user_id = :id", [':a' => $new, ':id' => $id]);
            audit_log($user['is_active'] ? 'user.deactivate' : 'user.activate', 'users', $id, ['name' => $name]);
            flash('success', $user['is_active'] ? "{$name} deactivated." : "{$name} activated.");
            break;

        case 'reset_password':
            // Generate a readable temporary password.
            $temp = 'Tmp-' . bin2hex(random_bytes(4));
            db_run(
                "UPDATE users SET password_hash = :h, updated_at = NOW() WHERE user_id = :id",
                [':h' => password_hash($temp, PASSWORD_DEFAULT), ':id' => $id]
            );
            // Invalidate any remember-me sessions for that user.
            db_run("DELETE FROM remember_tokens WHERE user_id = :id", [':id' => $id]);
            audit_log('user.reset_password', 'users', $id, ['name' => $name]);
            flash('success', "Temporary password for {$name}: {$temp} — share it securely; they should change it on next login.");
            break;

        default:
            flash('error', 'Unknown action.');
    }
} catch (PDOException $ex) {
    error_log('[USERS] action "' . $action . '" failed: ' . $ex->getMessage());
    flash('error', 'The action could not be completed. Please try again.');
}

redirect('users/index.php');
