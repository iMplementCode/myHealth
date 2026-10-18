<?php

/**
 * ============================================================
 *  Which upload folders are private
 * ------------------------------------------------------------
 *  One list, because there are three gatekeepers in front of
 *  these files and they have to agree:
 *
 *    1. public/router.php      — the built-in server, which is
 *                                what nixpacks actually runs
 *    2. deploy/nginx-site.conf — nginx, for the container build
 *    3. public/documents/view.php — the only way in
 *
 *  It was three separate copies, and adding the leave folder
 *  proved why that does not hold: it went into nginx and into
 *  the endpoint, and not into the router — so on the deployment
 *  that is actually live, a sick note was readable off the disk
 *  by anybody with the URL. Two out of three is a hole.
 *
 *  nginx cannot read PHP, so its copy stays hand-written. The CI
 *  workflow checks that it lists every folder named here, which
 *  turns the next omission into a failed build rather than a
 *  file served to a stranger.
 *
 *  This file is deliberately tiny and depends on nothing. The
 *  router includes it on every request, including requests for
 *  static assets, and must not pay for a configuration load to
 *  find out whether a path is private.
 * ============================================================
 */

declare(strict_types=1);

if (!defined('PRIVATE_UPLOAD_FOLDERS')) {
    define('PRIVATE_UPLOAD_FOLDERS', [
        'payments',     // customers' payment slips — bank details
        'refunds',      // refund proofs
        'investments',  // signed investor agreements
        'advances',     // staff advance receipts
        'leave',        // sick notes and burial permits — medical
    ]);
}
