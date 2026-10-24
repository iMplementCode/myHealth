<?php

/**
 * ============================================================
 *  Is this PHP patched against PHP2Shell?
 * ------------------------------------------------------------
 *  On 21 October 2026 PHP released 8.5.12, 8.4.27, 8.3.36 and
 *  8.2.35 to fix CVE-2026-109618 and CVE-2026-109619 — two
 *  flaws in PHP itself that allow REMOTE CODE EXECUTION WITHOUT
 *  AUTHENTICATION, scored 9.2, and already being exploited in
 *  the wild when the patch landed.
 *
 *  "Without authentication" is the part that matters for this
 *  application. It does not require a login, a session, or a
 *  page that does anything in particular — one of the paths runs
 *  inside PHP's own upload handling, before a line of this
 *  system's code is reached. Every sign-in page on the internet
 *  is a front door for it. Nothing that could be written here
 *  would close it.
 *
 *  So this is not a fix. It is the thing that tells somebody
 *  whether the fix has been applied, because the honest answer
 *  to "are we safe" is a version number and nobody can see a
 *  version number from the outside.
 *
 *  Run it anywhere:
 *
 *      php deploy/php-version-check.php
 *
 *  It is deliberately standalone — no config, no database, no
 *  composer — so it runs inside a half-built container image, on
 *  a bare VPS, or as the first line of a deploy script.
 * ============================================================
 */

declare(strict_types=1);

/**
 * The first release of each branch that carries the fix.
 *
 * A branch missing from this list is one PHP no longer supports.
 * 8.1 and everything before it reached end of life before this
 * was disclosed and will not get a fix from php.net — a
 * distribution may backport one, which is why the verdict for
 * those says to check the vendor rather than simply "vulnerable".
 */
function php2shell_fixed_releases(): array
{
    return [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];
}

/**
 * The distribution package behind this PHP, if there is one.
 *
 * This matters more than it looks. Debian and Ubuntu — and Red Hat,
 * and Amazon Linux — backport security fixes into the package they
 * already ship and DO NOT change the upstream version number. On
 * Ubuntu 24.04 the PHP_VERSION constant reads 8.3.6 whether the
 * package is the original 8.3.6-0ubuntu0.24.04.2 or a patched
 * 8.3.6-0ubuntu0.24.04.11.
 *
 * So comparing PHP_VERSION against 8.3.36 on a distribution build
 * says "vulnerable" about a machine that may well be patched. A
 * check that cries wolf gets ignored, and then it is worth nothing
 * on the day it is right.
 *
 * @return array{manager:string, package:string, version:string}|null
 */
function php2shell_distro_package(): ?array
{
    //  Only ask the package manager about the PHP that is actually
    //  running: a binary under /usr is a distribution one, a binary
    //  under /usr/local is almost always a hand-built or official
    //  Docker PHP, where the upstream number is the whole truth.
    $binary = PHP_BINARY;
    if ($binary === '' || !str_starts_with($binary, '/usr/bin')) {
        return null;
    }

    $try = static function (string $cmd): ?string {
        $out = @shell_exec($cmd . ' 2>/dev/null');
        $out = is_string($out) ? trim($out) : '';
        return $out !== '' ? $out : null;
    };

    $branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;

    foreach (["php{$branch}-cli", 'php-cli', 'php'] as $pkg) {
        if ($v = $try("dpkg-query -W -f='\${Version}' " . escapeshellarg($pkg))) {
            return ['manager' => 'dpkg', 'package' => $pkg, 'version' => $v];
        }
    }
    foreach (['php-cli', "php{$branch}-cli", 'php'] as $pkg) {
        if ($v = $try('rpm -q --qf "%{VERSION}-%{RELEASE}" ' . escapeshellarg($pkg))) {
            if (!str_contains($v, 'not installed')) {
                return ['manager' => 'rpm', 'package' => $pkg, 'version' => $v];
            }
        }
    }

    return null;
}

/**
 * Judge one version string.
 *
 * @return array{state:string, detail:string, needed:?string}
 *         state: patched | vulnerable | unsupported | unknown
 */
function php2shell_verdict(string $version): array
{
    //  "8.3.6-1ubuntu1" and "8.3.6" are the same branch. Take the
    //  three numbers and ignore whatever a distribution appended.
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m)) {
        return ['state' => 'unknown', 'needed' => null,
                'detail' => 'Could not read a version number from "' . $version . '".'];
    }

    $branch = $m[1] . '.' . $m[2];
    $short  = $m[1] . '.' . $m[2] . '.' . $m[3];
    $fixed  = php2shell_fixed_releases();

    if (!isset($fixed[$branch])) {
        //  Newer than anything this file knows about is not a
        //  problem to report — it is this file being out of date.
        if (version_compare($short, '8.5.12', '>=')) {
            return ['state' => 'patched', 'needed' => null,
                    'detail' => $short . ' is newer than every branch this check knows about.'];
        }
        return ['state' => 'unsupported', 'needed' => null,
                'detail' => 'PHP ' . $branch . ' reached end of life before these flaws were '
                          . 'disclosed, so php.net will not fix it. Either move to a supported '
                          . 'branch, or confirm with your distribution that it has backported '
                          . 'the fix into the package you are running.'];
    }

    $needed = $fixed[$branch];
    if (version_compare($short, $needed, '>=')) {
        return ['state' => 'patched', 'needed' => $needed,
                'detail' => $short . ' is at or above ' . $needed . '.'];
    }

    return ['state' => 'vulnerable', 'needed' => $needed,
            'detail' => $short . ' is below ' . $needed . ', the first ' . $branch
                      . ' release carrying the fix.'];
}

//  Running it directly prints a verdict and sets an exit code, so
//  a deploy script can stop on it. Including it just defines the
//  two functions above.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $version = $argv[1] ?? PHP_VERSION;
    $v       = php2shell_verdict($version);
    $distro  = isset($argv[1]) ? null : php2shell_distro_package();

    $upload = ini_get('session.upload_progress.enabled');
    $uploadOn = $upload !== false && (string) $upload !== '0' && strtolower((string) $upload) !== 'off';

    echo "\n  PHP2Shell — CVE-2026-109618 and CVE-2026-109619\n";
    echo "  ────────────────────────────────────────────────────────\n";
    echo '  running  ' . $version . "\n";
    echo '  sapi     ' . PHP_SAPI . "\n\n";

    /*  A distribution build cannot be judged on the upstream number
     *  alone, so say what is actually known rather than guessing in
     *  either direction. */
    if ($distro !== null && $v['state'] !== 'patched') {
        echo '  package  ' . $distro['package'] . ' ' . $distro['version']
           . ' (' . $distro['manager'] . ")\n\n";
        echo "  ! CANNOT TELL FROM HERE\n";
        echo '    ' . $v['detail'] . "\n";
        echo "    But this is a distribution package, and Debian, Ubuntu and Red Hat\n";
        echo "    backport security fixes WITHOUT changing the upstream version\n";
        echo "    number. So 8.3.6 here may be an unpatched 8.3.6 or a patched one,\n";
        echo "    and nothing visible from inside PHP distinguishes them.\n\n";
        echo "    Settle it against your distribution, not against php.net:\n\n";
        echo $distro['manager'] === 'dpkg'
            ? "      apt-get update && apt-get install --only-upgrade "
              . $distro['package'] . "\n"
              . "      # then confirm the version changed, and check the vendor notice:\n"
              . "      #   https://ubuntu.com/security/CVE-2026-109618\n"
            : "      dnf update " . $distro['package'] . "\n"
              . "      # then check your vendor's advisory for these CVE numbers\n";
        echo "\n    If your vendor has not shipped a fix yet, treat this as VULNERABLE.\n";
    } else {
        echo match ($v['state']) {
            'patched'     => "  ✓ PATCHED      " . $v['detail'] . "\n",
            'vulnerable'  => "  ✗ VULNERABLE   " . $v['detail'] . "\n",
            'unsupported' => "  ✗ UNSUPPORTED  " . $v['detail'] . "\n",
            default       => "  ! UNKNOWN      " . $v['detail'] . "\n",
        };
    }

    if ($v['state'] !== 'patched') {
        echo "\n  These flaws run code on this machine without anybody signing in,\n";
        echo "  and were being exploited before the patch was published. Upgrading\n";
        echo "  PHP is the only complete fix; there is no setting that closes both.\n";
    }

    echo "\n  session.upload_progress.enabled is "
       . ($uploadOn ? "ON\n" : "off\n");
    if ($uploadOn) {
        echo "    Turning it off closes one of the two paths (CVE-2026-109619)\n";
        echo "    and nothing in this application uses it. It is worth doing as a\n";
        echo "    stop-gap while an upgrade is arranged — it is NOT a substitute\n";
        echo "    for one, and it does nothing about CVE-2026-109618.\n";
        echo "    Set  session.upload_progress.enabled = 0  in php.ini, or in the\n";
        echo "    php-fpm pool, then restart PHP.\n";
    }
    echo "\n";

    exit($v['state'] === 'patched' ? 0 : 1);
}
