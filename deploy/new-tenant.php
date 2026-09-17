<?php

/**
 * ============================================================
 *  Provision a shop
 * ------------------------------------------------------------
 *  Creates one tenant: a database, a role that can reach only
 *  that database, the full schema, and an administrator with a
 *  password nobody else has.
 *
 *  Usage, from the project root:
 *
 *      PGPASSWORD=<postgres superuser password> \
 *      php deploy/new-tenant.php \
 *          --slug=mama-electronics \
 *          --name="Mama Electronics Ltd" \
 *          --admin-email=owner@mamaelectronics.co.ke
 *
 *  It prints, once and only once, the block of environment
 *  variables to paste into that shop's Dokploy application and
 *  the administrator's password. Nothing is written to a file and
 *  nothing is emailed: if the output is lost, reset the password
 *  rather than go looking for it.
 *
 *  ── Why a database each ──
 *
 *  The connection string is the tenant boundary. There are around
 *  two hundred hand-written queries in this application and none
 *  of them carries a tenant predicate; adding one to every query,
 *  and to every query written from now on, would make isolation a
 *  matter of somebody remembering. One forgotten WHERE shows one
 *  shop another shop's books.
 *
 *  With a database each, a query cannot reach another shop's data
 *  because the data is not there. See docs/MULTI_TENANT.md.
 * ============================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

$root = dirname(__DIR__);

/* ── Arguments ──────────────────────────────────────────────── */
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m)) {
        $args[$m[1]] = $m[2];
    }
}

function fail(string $msg): void
{
    fwrite(STDERR, "\n  ✗ " . $msg . "\n\n");
    exit(1);
}

$slug = strtolower(trim($args['slug'] ?? ''));
$name = trim($args['name'] ?? '');

/*  The slug becomes a database name and a role name, and neither
 *  can be bound as a query parameter — PostgreSQL will not accept
 *  a placeholder where an identifier goes. So the only thing
 *  standing between this and an injected CREATE is this pattern,
 *  which is why it is a strict allow-list rather than an escape.
 *  Letters, digits and underscores; must start with a letter.   */
if (!preg_match('/^[a-z][a-z0-9_]{2,40}$/', $slug)) {
    fail("--slug must be 3-41 characters: a letter, then letters, digits or underscores.\n"
       . "    It becomes the database and role name, so it cannot contain anything else.\n"
       . "    Given: " . ($slug === '' ? '(nothing)' : $slug));
}
if ($name === '') {
    fail('--name is required: the shop name, as it should print on its invoices.');
}

$adminEmail = strtolower(trim($args['admin-email'] ?? ''));
if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fail('--admin-email is not an email address: ' . $adminEmail);
}

$dbName = $slug;
$dbUser = $slug;

$superHost = $args['admin-host'] ?? getenv('ADMIN_DB_HOST') ?: 'localhost';
$superPort = $args['admin-port'] ?? getenv('ADMIN_DB_PORT') ?: '5432';
$superUser = $args['admin-user'] ?? getenv('ADMIN_DB_USER') ?: 'postgres';
$superPass = (string) (getenv('PGPASSWORD') ?: getenv('ADMIN_DB_PASSWORD') ?: '');

/* ── Secrets ────────────────────────────────────────────────── */

/**
 * A password somebody has to read off a screen and type once.
 *
 * No l, I, 1, O or 0: a shop owner reading this over the phone
 * should not have to ask which character it was. Five groups of
 * four from a 32-character alphabet is about 100 bits, which is
 * far beyond anything that will be guessed.
 */
function readable_secret(int $groups = 5): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $out      = [];
    for ($g = 0; $g < $groups; $g++) {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $out[] = $chunk;
    }
    return implode('-', $out);
}

$dbPassword    = bin2hex(random_bytes(18));   // machine reads this, not a person
$adminPassword = readable_secret();

/* ── Connect as the superuser ───────────────────────────────── */
$superDsn = sprintf('pgsql:host=%s;port=%s;dbname=postgres', $superHost, $superPort);
try {
    $super = new PDO($superDsn, $superUser, $superPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
} catch (PDOException $e) {
    fail("Could not connect to PostgreSQL as '$superUser' on $superHost:$superPort.\n"
       . '    ' . $e->getMessage() . "\n"
       . "    Set PGPASSWORD, and --admin-host/--admin-user if they are not the defaults.");
}

echo "\n  Provisioning \"$name\"\n";
echo "  ────────────────────────────────────────────────────\n";

/*  Refuse to touch anything that is already there.
 *
 *  This is the difference between provisioning a new shop and
 *  quietly re-pointing a live one. If the database exists, the
 *  operator has either run this twice or chosen a slug that is
 *  taken, and both of those end badly if the answer is "carry on".
 */
$exists = $super->prepare('SELECT 1 FROM pg_database WHERE datname = :d');
$exists->execute([':d' => $dbName]);
if ($exists->fetchColumn()) {
    fail("A database called '$dbName' already exists. Refusing to touch it.\n"
       . "    Choose another --slug, or drop that database first if it really is spare.");
}

$roleExists = $super->prepare('SELECT 1 FROM pg_roles WHERE rolname = :r');
$roleExists->execute([':r' => $dbUser]);
$roleIsNew  = !$roleExists->fetchColumn();

// The slug is already restricted to [a-z][a-z0-9_]* above, so it is
// safe to interpolate as an identifier. The password is a literal
// and is quoted as one.
if ($roleIsNew) {
    $super->exec('CREATE ROLE "' . $dbUser . '" LOGIN PASSWORD ' . $super->quote($dbPassword));
    echo "  ✓ role     $dbUser\n";
} else {
    $super->exec('ALTER ROLE "' . $dbUser . '" WITH LOGIN PASSWORD ' . $super->quote($dbPassword));
    echo "  ✓ role     $dbUser (existed; password reset)\n";
}

// CREATE DATABASE cannot run inside a transaction block.
$super->exec('CREATE DATABASE "' . $dbName . '" OWNER "' . $dbUser . '"');
echo "  ✓ database $dbName\n";

/*  Nobody but this shop. PostgreSQL grants CONNECT on every new
 *  database to PUBLIC by default, which would let every other
 *  shop's role open this one. Revoking it is what makes "a query
 *  cannot reach another shop's data" true rather than merely
 *  intended.                                                    */
$super->exec('REVOKE ALL ON DATABASE "' . $dbName . '" FROM PUBLIC');
$super->exec('GRANT ALL PRIVILEGES ON DATABASE "' . $dbName . '" TO "' . $dbUser . '"');
echo "  ✓ revoked PUBLIC access; only $dbUser may connect\n";

/* ── Schema ─────────────────────────────────────────────────── */
/*  Two steps, and both are needed.
 *
 *  schema.sql builds the tables; the migrations add everything
 *  that came after it, plus the seeds — roles, currencies, the
 *  administrator. Running only the migrations against an empty
 *  database fails on the first file, because 001 alters tables
 *  schema.sql was supposed to have created. See docs/DATABASE.md.
 *
 *  It is loaded as the shop's own role, so every table it creates
 *  is owned by that role and not by the superuser.              */
$tenantDsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $superHost, $superPort, $dbName);
$tenant    = new PDO($tenantDsn, $dbUser, $dbPassword, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 10,
]);

$schemaFile = $root . '/database_structure/schema.sql';
$schemaSql  = is_file($schemaFile) ? file_get_contents($schemaFile) : false;
if ($schemaSql === false || trim($schemaSql) === '') {
    fail("database_structure/schema.sql is missing or empty — there is nothing to build from.");
}
try {
    $tenant->exec($schemaSql);
} catch (PDOException $e) {
    fwrite(STDERR, "\n  ✗ schema.sql failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "\n    Drop it and try again:  DROP DATABASE \"$dbName\"; DROP ROLE \"$dbUser\";\n\n");
    exit(1);
}
echo "  ✓ tables   schema.sql applied\n";

putenv('NEW_TENANT_DB_PASSWORD=' . $dbPassword);

$cmd = sprintf(
    'php %s --host=%s --port=%s --database=%s --user=%s --password-from-env=NEW_TENANT_DB_PASSWORD 2>&1',
    escapeshellarg($root . '/database_structure/migrate.php'),
    escapeshellarg($superHost),
    escapeshellarg($superPort),
    escapeshellarg($dbName),
    escapeshellarg($dbUser)
);

exec($cmd, $out, $code);
if ($code !== 0) {
    fwrite(STDERR, "\n  ✗ The migrations failed. The database was created but is not usable.\n");
    fwrite(STDERR, '    ' . implode("\n    ", $out) . "\n");
    fwrite(STDERR, "\n    Drop it and try again:  DROP DATABASE \"$dbName\"; DROP ROLE \"$dbUser\";\n\n");
    exit(1);
}
$applied = count(preg_grep('/✓ ran/', $out));
echo "  ✓ schema   $applied migration(s) applied\n";

/* ── The administrator ──────────────────────────────────────── */
/*  Migration 005 seeds admin@implement.local with a password that
 *  is written in the migration file and therefore known to anyone
 *  who has seen this repository. That is fine for one install and
 *  unacceptable for ten: every shop would ship with the same
 *  credentials. So the seeded account gets this shop's own
 *  password before anybody can reach the site.                  */
$hash = password_hash($adminPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$upd = $tenant->prepare(
    "UPDATE users SET password_hash = :h" . ($adminEmail !== '' ? ', email = :e' : '') .
    " WHERE LOWER(email) = 'admin@implement.local' OR LOWER(username) = 'implement'"
);
$params = [':h' => $hash];
if ($adminEmail !== '') {
    $params[':e'] = $adminEmail;
}
$upd->execute($params);

if ($upd->rowCount() === 0) {
    fail("The schema applied but no administrator was seeded — nobody can sign in.\n"
       . "    Check migration 005 ran against '$dbName'.");
}
echo "  ✓ admin    password set" . ($adminEmail !== '' ? ", email $adminEmail" : '') . "\n";

/*  The shop's own name, so its very first invoice is not headed
 *  with the software's.
 *
 *  An INSERT, not an UPDATE. company_settings is a single-row
 *  table keyed id = 1 and a fresh database has no row in it at
 *  all — so `UPDATE company_settings SET company_name = ...`
 *  matched nothing, changed nothing, and reported success. Every
 *  shop provisioned that way came up unbranded and the script said
 *  it had set the name.
 *
 *  And it is read back rather than trusted. A write that silently
 *  affects no rows is exactly the failure this is recovering
 *  from.                                                         */
try {
    $tenant->prepare(
        'INSERT INTO company_settings (id, company_name) VALUES (1, :n)
         ON CONFLICT (id) DO UPDATE SET company_name = EXCLUDED.company_name'
    )->execute([':n' => $name]);

    $stored = $tenant->query('SELECT company_name FROM company_settings WHERE id = 1')->fetchColumn();
    if ($stored !== $name) {
        fail("The company name did not stick — company_settings holds " . var_export($stored, true) . ".");
    }
    echo "  ✓ branding company name set\n";
} catch (PDOException $e) {
    fail('Could not set the company name: ' . $e->getMessage());
}

/* ── What to do with it ─────────────────────────────────────── */
$loginEmail = $adminEmail !== '' ? $adminEmail : 'admin@implement.local';

echo "\n  ════════════════════════════════════════════════════\n";
echo "  Paste into this shop's Dokploy → Environment:\n";
echo "  ────────────────────────────────────────────────────\n";
echo "DB_HOST=$superHost\n";
echo "DB_PORT=$superPort\n";
echo "DB_NAME=$dbName\n";
echo "DB_USER=$dbUser\n";
echo "DB_PASS=$dbPassword\n";
echo "APP_HOSTS=<this shop's domain>\n";
echo "\n";
echo "  ────────────────────────────────────────────────────\n";
echo "  First sign-in — shown once, not stored anywhere:\n\n";
echo "      $loginEmail\n";
echo "      $adminPassword\n\n";
echo "  Tell them to change it. Then mount a volume for\n";
echo "  /app/public/uploads before they upload a logo, or it\n";
echo "  will be gone at the next deploy — see docs/MULTI_TENANT.md.\n";
echo "  ════════════════════════════════════════════════════\n\n";
