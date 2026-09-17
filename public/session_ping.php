<?php

/**
 * ============================================================
 *  Session keep-alive
 * ------------------------------------------------------------
 *  Called when someone answers the idle warning with "Stay
 *  signed in". Loading this page through the normal bootstrap
 *  is itself the keep-alive: auth_start_session() stamps
 *  last_activity on every request, so simply arriving here
 *  resets the clock.
 *
 *  It is deliberately not something the browser polls. A page
 *  that pinged on a timer would keep the session alive forever
 *  and there would be no idle timeout at all — the ping only
 *  ever happens because a person clicked a button.
 *
 *  If the session has already gone, bootstrap's require_login()
 *  answers with a 401 JSON body (the request asks for JSON), and
 *  the browser sends the user to sign in.
 * ============================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

json_response([
    'success'        => true,
    'timeoutSeconds' => SESSION_LIFETIME,
    'user'           => current_user()['name'] ?? null,
]);
