<?php

/**
 * ============================================================
 *  Reset a shop to the day it was created
 * ------------------------------------------------------------
 *  Everything a shop has done is removed — customers, products,
 *  invoices, payments, stock, notes, uploads, and every user but
 *  the one asking — and what is left is a database identical to
 *  one deploy/new-tenant.php has just made.
 *
 *  This exists because a shop is set up, practised on, and then
 *  has to start trading for real with clean books. Deleting rows
 *  by hand across seventy-five tables is how a stray invoice
 *  survives into somebody's first VAT return.
 *
 *  ── Irreversible ──
 *
 *  There is no undo. The only way back is a backup taken before
 *  it ran, which is why both callers make one impossible to
 *  overlook. Nothing here should ever be reachable by accident.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}

/**
 * Tables that a freshly provisioned database also has, and which
 * therefore survive.
 *
 * The people are kept in place rather than saved and put back.
 * The first version of this took a copy of the surviving user,
 * truncated everything, and re-inserted it — and the re-insert
 * failed, because users.user_id is GENERATED ALWAYS AS IDENTITY
 * and will not accept an explicit value. The truncate had already
 * committed. The shop was left with no users at all and nobody
 * able to sign in, which is the single worst outcome this function
 * has. Not removing them cannot fail that way.
 *
 * Unwanted accounts go afterwards with a DELETE, which cascades to
 * their sessions, remembered devices and sign-in codes.
 *
 * These four are also safe from TRUNCATE ... CASCADE, which
 * follows foreign keys inwards: none of them references a table in
 * the wipe list, so nothing can drag them in.
 *
 * `schema_migrations` is emptied and rebuilt by reset_shop() rather
 * than kept, because re-running the migrations is what restores the
 * seeded brands, categories and cash accounts a
 * new shop starts with.
 */
function reset_kept_tables(): array
{
    return ['roles', 'users', 'user_roles', 'sessions'];
}

/**
 * Every table in the database, asked rather than remembered.
 *
 * A hard-coded list is wrong the moment somebody adds a migration,
 * and wrong silently: the new table simply keeps its rows and one
 * shop's data survives into the next tenant's clean start. Asking
 * the database means a table added next year is cleared without
 * anybody having to remember this file exists.
 */
function reset_all_tables(PDO $pdo): array
{
    $rows = $pdo->query(
        "SELECT table_name
           FROM information_schema.tables
          WHERE table_schema = 'public'
            AND table_type   = 'BASE TABLE'
          ORDER BY table_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_values($rows);
}

/**
 * Empty every uploaded file, keeping the folders and the rules
 * that protect them.
 *
 * A reset that leaves the files behind is not a reset. The uploads
 * tree holds payment slips, refund proofs, staff advance receipts
 * and investor agreements — the most sensitive things the system
 * stores — and a shop handed to somebody new must not still have
 * the last one's bank statements on disk.
 *
 * .htaccess stays. It is what stops an uploaded file being served
 * as PHP, and deleting it would turn a clean-up into a web shell.
 *
 * @return int how many files were removed
 */
function reset_uploads(): int
{
    if (!defined('UPLOAD_PATH') || !is_dir(UPLOAD_PATH)) {
        return 0;
    }

    $removed = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(UPLOAD_PATH, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir()) {
            continue;                       // folders and their .htaccess stay
        }
        if ($item->getFilename() === '.htaccess') {
            continue;
        }
        if (@unlink($item->getPathname())) {
            $removed++;
        }
    }

    return $removed;
}

/**
 * Reset the shop.
 *
 * @param int         $keepUserId  the only user that survives
 * @param string      $companyName re-seeded after the wipe, so the
 *                                 shop still knows its own name
 * @param string|null $keepSession session id to preserve, so whoever
 *                                 pressed the button is not signed
 *                                 out halfway through
 * @param callable|null $progress  fn(string $line): void
 *
 * @return array{tables:int, files:int, users_removed:int}
 */
function reset_shop(
    int $keepUserId,
    string $companyName,
    ?string $keepSession = null,
    ?callable $progress = null
): array {
    $say = $progress ?? static function (string $l): void {};
    $pdo = db();

    /*  Who survives. Checked before anything is destroyed: a reset
     *  that empties the shop and then discovers the user it was
     *  told to keep does not exist is a shop nobody can enter. */
    $user = db_one('SELECT user_id, email, username FROM users WHERE user_id = :id', [':id' => $keepUserId]);
    if (!$user) {
        throw new RuntimeException('The user to keep (' . $keepUserId . ') does not exist. Nothing was changed.');
    }

    $usersBefore = (int) db_value('SELECT COUNT(*) FROM users');

    // ── Wipe ────────────────────────────────────────────────
    $keep   = reset_kept_tables();
    $tables = array_values(array_diff(reset_all_tables($pdo), $keep));

    if (!$tables) {
        throw new RuntimeException('No tables found to reset — refusing to continue.');
    }

    /*  One statement, so it is one transaction and one lock. A
     *  reset that failed halfway would leave a shop with its
     *  invoices gone and its stock still there, which is worse
     *  than either extreme.
     *
     *  RESTART IDENTITY so the first real invoice is number one
     *  rather than continuing from the practice run.            */
    $quoted = array_map(static fn(string $t): string => '"' . $t . '"', $tables);
    $say('  wiping ' . count($tables) . ' tables');
    $pdo->exec('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');

    /*  Everyone else goes, and their sessions, remembered devices
     *  and sign-in codes go with them on the cascade.           */
    $removedNow = (int) db_run('DELETE FROM users WHERE user_id <> :id', [':id' => $keepUserId])->rowCount();
    $say('  kept ' . ($user['email'] ?: $user['username']) . '; removed ' . $removedNow . ' other account(s)');

    /*  Every other sign-in ends here. The one that asked for this
     *  is left alone so the page can report what happened rather
     *  than bouncing to a login screen mid-reset.               */
    if ($keepSession !== null && $keepSession !== '') {
        db_run('DELETE FROM sessions WHERE session_id <> :s', [':s' => $keepSession]);
        $say('  kept the current sign-in; everyone else is signed out');
    } else {
        db_run('DELETE FROM sessions');
    }

    /*  Restore the seeds by re-running the migrations.
     *
     *  A new shop does not start empty: it has six brands, eight
     *  categories, six cash accounts, ten expense categories, a
     *  warehouse, all seeded by migration
     *  files. Emptying schema_migrations and running them again is
     *  what brings those back — they are written to be safe to
     *  re-run, which is the same property that lets migrate.php
     *  run on every deploy.
     *
     *  The user is already back at this point, so migration 005
     *  finds an administrator and leaves it alone rather than
     *  re-creating the default account.                         */
    $say('  re-seeding from the migrations');
    $root = dirname(__DIR__);

    /*  Tell it which database, explicitly.
     *
     *  This is a separate process: the constants this one is using
     *  mean nothing to it, and left to itself it reads .env and
     *  migrates whatever that names. It did exactly that — the
     *  seeds came back into a different database and the shop
     *  being reset was left with no brands, no categories, no cash
     *  accounts and no currencies, while the command reported
     *  success.                                                   */
    putenv('RESET_DB_PASSWORD=' . DB_PASS);
    $cmd = sprintf(
        'php %s --host=%s --port=%s --database=%s --user=%s --password-from-env=RESET_DB_PASSWORD 2>&1',
        escapeshellarg($root . '/database_structure/migrate.php'),
        escapeshellarg((string) DB_HOST),
        escapeshellarg((string) DB_PORT),
        escapeshellarg((string) DB_NAME),
        escapeshellarg((string) DB_USER)
    );
    exec($cmd, $out, $code);
    if ($code !== 0) {
        throw new RuntimeException("The re-seed failed and the shop is half reset:\n" . implode("\n", $out));
    }

    /*  Nothing may have crept back in.
     *
     *  Migration 005 creates admin@implement.local when it finds no
     *  administrator, with a password written in the migration file
     *  and known to anyone who has read the repository. If a shop
     *  had replaced that account with its own, the re-seed above
     *  would have handed it straight back. */
    $putBack = (int) db_run('DELETE FROM users WHERE user_id <> :id', [':id' => $keepUserId])->rowCount();
    if ($putBack > 0) {
        $say('  removed ' . $putBack . ' account(s) the re-seed put back');
    }

    $left = (int) db_value('SELECT COUNT(*) FROM users');
    if ($left !== 1) {
        throw new RuntimeException('Expected exactly one user to remain; found ' . $left . '.');
    }

    // ── The shop still knows its own name ───────────────────
    db_run(
        'INSERT INTO company_settings (id, company_name) VALUES (1, :n)
         ON CONFLICT (id) DO UPDATE SET company_name = EXCLUDED.company_name',
        [':n' => $companyName]
    );

    // ── Files ───────────────────────────────────────────────
    $files = reset_uploads();
    $say('  deleted ' . $files . ' uploaded file(s)');

    /*  The one record that survives its own wipe. Written after the
     *  truncate, so it is the first line of the new audit log and
     *  says how the shop came to be empty. */
    audit_log('shop_reset', 'company', 1, [
        'tables_cleared'  => count($tables),
        'files_deleted'   => $files,
        'users_removed'   => max(0, $usersBefore - 1),
        'kept_user'       => $user['email'] ?? $user['username'] ?? (string) $keepUserId,
    ]);

    return [
        'tables'        => count($tables),
        'files'         => $files,
        'users_removed' => max(0, $usersBefore - 1),
    ];
}
