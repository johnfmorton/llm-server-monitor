<?php

namespace Tests\Feature;

use App\Http\Requests\StoreSummaryRequest;
use App\Models\DailySummary;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SummaryIngestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-08 00:20:00');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'schema' => 1,
            'collector_version' => '0.1.0',
            'server' => 'jfm',
            'date' => '2026-11-07',
            'auth' => ['ssh' => ['failed' => ['total' => 895]]],
        ], $overrides);
    }

    public function test_a_server_with_a_valid_token_can_store_its_summary(): void
    {
        $server = Server::factory()->create(['name' => 'jfm']);
        $token = $server->issueToken();

        $response = $this->withToken($token)->postJson(route('api.summaries.store'), $this->payload());

        $response->assertStatus(202)
            ->assertJsonPath('data.server', 'jfm')
            ->assertJsonPath('data.date', '2026-11-07')
            ->assertJsonPath('data.replaced', false);
        $summary = DailySummary::sole();
        $this->assertSame(895, $summary->payload['auth']['ssh']['failed']['total']);
        $this->assertSame('0.1.0', $summary->collector_version);
        $this->assertNotNull($server->fresh()->last_report_at);
    }

    public function test_sending_the_same_day_again_replaces_it(): void
    {
        $server = Server::factory()->create();
        $token = $server->issueToken();

        $this->withToken($token)->postJson(route('api.summaries.store'), $this->payload());
        $response = $this->withToken($token)->postJson(route('api.summaries.store'), $this->payload([
            'auth' => ['ssh' => ['failed' => ['total' => 3]]],
        ]));

        $response->assertStatus(202)->assertJsonPath('data.replaced', true);
        $this->assertSame(3, DailySummary::sole()->payload['auth']['ssh']['failed']['total']);
    }

    public function test_the_summary_is_filed_under_the_token_owner_not_the_name_in_the_payload(): void
    {
        $sender = Server::factory()->create(['name' => 'sender']);
        Server::factory()->create(['name' => 'victim']);

        $this->withToken($sender->issueToken())
            ->postJson(route('api.summaries.store'), $this->payload(['server' => 'victim']))
            ->assertStatus(202);

        $this->assertSame($sender->id, DailySummary::sole()->server_id);
    }

    public function test_requests_without_a_valid_token_are_rejected(): void
    {
        Server::factory()->create()->issueToken();

        $this->postJson(route('api.summaries.store'), $this->payload())->assertUnauthorized();
        $this->withToken('lw_wrong')->postJson(route('api.summaries.store'), $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('daily_summaries', 0);
    }

    public function test_a_rotated_token_stops_working(): void
    {
        $server = Server::factory()->create();
        $old = $server->issueToken();
        $server->issueToken();

        $this->withToken($old)->postJson(route('api.summaries.store'), $this->payload())->assertUnauthorized();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing schema' => [['schema' => null], 'schema'],
            'unknown schema' => [['schema' => 2], 'schema'],
            'bad date' => [['date' => '07/11/2026'], 'date'],
            'future date' => [['date' => '2026-11-09'], 'date'],
            'older than retention' => [['date' => '2026-10-01'], 'date'],
            'missing collector version' => [['collector_version' => null], 'collector_version'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_summaries_are_rejected(array $overrides, string $field): void
    {
        $token = Server::factory()->create()->issueToken();

        $this->withToken($token)
            ->postJson(route('api.summaries.store'), $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('daily_summaries', 0);
    }

    public function test_oversized_payloads_are_rejected_before_authentication(): void
    {
        $token = Server::factory()->create()->issueToken();

        $this->withToken($token)
            ->postJson(route('api.summaries.store'), $this->payload(['padding' => str_repeat('x', StoreSummaryRequest::MAX_BYTES)]))
            ->assertStatus(413);
        $this->assertDatabaseCount('daily_summaries', 0);
    }
}
