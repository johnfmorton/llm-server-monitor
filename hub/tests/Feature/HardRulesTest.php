<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Monitoring\HardRules;
use Tests\TestCase;

class HardRulesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        return json_decode(file_get_contents(base_path("tests/fixtures/collector/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{rule: string, title: string, evidence: array<string, mixed>}>
     */
    private function hits(array $payload, ?Server $server = null): array
    {
        $hits = (new HardRules)->evaluate($server ?? Server::make(['name' => 'test']), $payload);

        return array_column($hits, null, 'rule');
    }

    public function test_the_planted_changes_in_the_collector_fixture_all_hit(): void
    {
        $hits = $this->hits(self::fixture('day-with-changes'));

        $this->assertEqualsCanonicalizing([
            HardRules::NEW_ACCOUNT,
            HardRules::OOM_KILL,
            HardRules::FAILED_UNIT,
            HardRules::NEW_PORT,
            HardRules::ACCESS_CHANGE,
            HardRules::SCHEDULE_CHANGE,
            HardRules::FIREWALL_CHANGE,
            HardRules::WEBROOT_CODE,
        ], array_keys($hits));

        $this->assertSame('New listening port: tcp/4444 all nc', $hits[HardRules::NEW_PORT]['title']);
        $this->assertSame('New user, group or sudoer: backdoor', $hits[HardRules::NEW_ACCOUNT]['title']);
        $this->assertSame('SSH key added: intruder@laptop', $hits[HardRules::ACCESS_CHANGE]['title']);
        $this->assertSame('Cron or timer changed: /etc/cron.d/evil', $hits[HardRules::SCHEDULE_CHANGE]['title']);
        $this->assertSame('Firewall rules changed: +6379 | ALLOW | Anywhere', $hits[HardRules::FIREWALL_CHANGE]['title']);
        $this->assertStringContainsString('example.com/public/new.php', $hits[HardRules::WEBROOT_CODE]['title']);
        $this->assertSame(['horizon.service'], $hits[HardRules::FAILED_UNIT]['evidence']['failed_now']);
        $this->assertTrue($hits[HardRules::WEBROOT_CODE]['evidence']['sites']['example.com']['head_moved_within_release']);
        $this->assertSame(['/home/forge/.ssh/authorized_keys'], $hits[HardRules::ACCESS_CHANGE]['evidence']['changed_files']);
    }

    public function test_a_known_broken_unit_does_not_hit(): void
    {
        $server = Server::make(['name' => 'test', 'known_failed_units' => ['horizon.service']]);

        $this->assertArrayNotHasKey(HardRules::FAILED_UNIT, $this->hits(self::fixture('day-with-changes'), $server));
    }

    public function test_a_login_from_outside_the_tailnet_with_an_unlisted_key_hits(): void
    {
        $hits = $this->hits(self::fixture('baseline-day'));

        $this->assertArrayHasKey(HardRules::SSH_LOGIN_OUTSIDE_TAILNET, $hits);
        $hit = $hits[HardRules::SSH_LOGIN_OUTSIDE_TAILNET];
        $this->assertSame('SSH login from outside the tailnet: root via publickey from a public address', $hit['title']);
        $this->assertSame(1, $hit['evidence']['count'], 'the tailnet login is not counted');
    }

    public function test_an_allowlisted_key_may_log_in_from_outside_the_tailnet(): void
    {
        $byComment = Server::make(['name' => 'test', 'ssh_allowlist' => ['worker@forge']]);
        $byFingerprint = Server::make(['name' => 'test', 'ssh_allowlist' => ['SHA256:10drkYVFlmQFGiXMd05vONaEEFco01R5vivAFOnWwrc']]);

        $this->assertArrayNotHasKey(HardRules::SSH_LOGIN_OUTSIDE_TAILNET, $this->hits(self::fixture('baseline-day'), $byComment));
        $this->assertArrayNotHasKey(HardRules::SSH_LOGIN_OUTSIDE_TAILNET, $this->hits(self::fixture('baseline-day'), $byFingerprint));
    }

    public function test_the_global_allowlist_applies_to_every_server(): void
    {
        config(['logwatch.rules.ssh_allowlist' => ['worker@forge']]);

        $this->assertArrayNotHasKey(HardRules::SSH_LOGIN_OUTSIDE_TAILNET, $this->hits(self::fixture('baseline-day')));
    }

    public function test_a_password_login_from_outside_the_tailnet_is_never_allowlisted(): void
    {
        $server = Server::make(['name' => 'test', 'ssh_allowlist' => ['worker@forge']]);
        $payload = ['auth' => ['ssh' => ['logins' => [
            ['user' => 'root', 'method' => 'password', 'source' => ['ip' => 'abc', 'scope' => 'public'], 'key_comment' => 'worker@forge', 'count' => 1],
        ]]]];

        $this->assertArrayHasKey(HardRules::SSH_LOGIN_OUTSIDE_TAILNET, $this->hits($payload, $server));
    }

    public function test_the_baseline_day_has_no_state_changes_to_report(): void
    {
        $hits = $this->hits(self::fixture('baseline-day'), Server::make(['name' => 'test', 'ssh_allowlist' => ['worker@forge']]));

        foreach ([HardRules::NEW_PORT, HardRules::ACCESS_CHANGE, HardRules::SCHEDULE_CHANGE, HardRules::FIREWALL_CHANGE, HardRules::WEBROOT_CODE] as $rule) {
            $this->assertArrayNotHasKey($rule, $hits, $rule);
        }
    }

    public function test_accounts_created_in_auth_log_hit_even_without_a_previous_snapshot(): void
    {
        $hit = $this->hits(self::fixture('baseline-day'))[HardRules::NEW_ACCOUNT];

        $this->assertSame(['backdoor'], $hit['evidence']['users_added']);
        $this->assertSame(['backdoor +sudo'], $hit['evidence']['admin_group_membership']);
        $this->assertSame('New user, group or sudoer: backdoor, backdoor +sudo', $hit['title']);
    }

    public function test_a_disk_over_the_limit_hits(): void
    {
        $payload = ['system' => ['disk' => [
            ['mount' => '/', 'used_pct' => 90, 'inodes_used_pct' => 10],
            ['mount' => '/data', 'used_pct' => 40, 'inodes_used_pct' => 95],
        ]]];

        $hit = $this->hits($payload)[HardRules::DISK_FULL];

        $this->assertSame('Disk over 90%: /data at 95%', $hit['title']);
        $this->assertCount(1, $hit['evidence']['disks'], '90% exactly is not over the limit');
    }

    public function test_an_inactive_firewall_hits(): void
    {
        $payload = ['state' => ['firewall_rules' => ['status' => 'inactive', 'status_changed' => true, 'added' => [], 'removed' => []]]];

        $this->assertSame('Firewall is inactive', $this->hits($payload)[HardRules::FIREWALL_CHANGE]['title']);
    }

    public function test_an_empty_payload_has_no_hits(): void
    {
        $this->assertSame([], $this->hits([]));
    }
}
