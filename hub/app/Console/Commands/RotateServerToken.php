<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('logwatch:server-token {name : The server to issue a new token for}')]
#[Description('Replace a server\'s collector API token; the old one stops working at once')]
class RotateServerToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $server = Server::where('name', $this->argument('name'))->first();
        if ($server === null) {
            $this->components->error('No server with that name.');

            return self::FAILURE;
        }

        $token = $server->issueToken();

        $this->components->info("New token for [{$server->name}]. The old token no longer works.");
        $this->line($token);

        return self::SUCCESS;
    }
}
