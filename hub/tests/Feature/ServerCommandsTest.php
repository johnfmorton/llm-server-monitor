<?php

namespace Tests\Feature;

use App\Console\Commands\CreateUser;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ServerCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_server_prints_a_token_that_authenticates_it(): void
    {
        $exitCode = Artisan::call('logwatch:server-add', ['name' => 'jfm', '--client' => true]);

        $this->assertSame(0, $exitCode);
        preg_match('/lw_[A-Za-z0-9]{48}/', Artisan::output(), $match);
        $server = Server::sole();
        $this->assertSame('jfm', $server->name);
        $this->assertTrue($server->is_client);
        $this->assertNotEmpty($match);
        $this->assertTrue($server->is(Server::findByToken($match[0])));
        $this->assertNotSame($match[0], $server->token_hash);
    }

    public function test_server_names_must_be_unique_and_simple(): void
    {
        Server::factory()->create(['name' => 'jfm']);

        $this->artisan('logwatch:server-add', ['name' => 'jfm'])->assertFailed();
        $this->artisan('logwatch:server-add', ['name' => 'has spaces'])->assertFailed();
        $this->assertSame(1, Server::count());
    }

    public function test_rotating_a_token_replaces_the_old_one(): void
    {
        $server = Server::factory()->create(['name' => 'jfm']);
        $old = $server->issueToken();

        $this->artisan('logwatch:server-token', ['name' => 'jfm'])->assertSuccessful();

        $this->assertNull(Server::findByToken($old));
        $this->artisan('logwatch:server-token', ['name' => 'missing'])->assertFailed();
    }

    public function test_a_dashboard_user_can_be_created_and_their_password_reset(): void
    {
        $this->artisan('logwatch:user', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'a long enough password')
            ->assertSuccessful();
        $this->artisan('logwatch:user', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'another long password')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('me', $user->name);
        $this->assertTrue(Hash::check('another long password', $user->password));
    }

    public function test_the_password_can_be_read_from_stdin_for_scripted_setup(): void
    {
        $command = $this->app->make(CreateUser::class);
        $command->setLaravel($this->app);
        $input = new ArrayInput(['email' => 'scripted@example.com', '--password-stdin' => true]);
        $input->setStream(fopen('data://text/plain,'.rawurlencode("a long enough password\n"), 'r'));

        $exitCode = $command->run($input, new BufferedOutput);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check('a long enough password', User::sole()->password));
    }

    public function test_short_passwords_are_refused(): void
    {
        $this->artisan('logwatch:user', ['email' => 'me@example.com'])
            ->expectsQuestion('Password', 'short')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }
}
