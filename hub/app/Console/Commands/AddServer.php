<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('logwatch:server-add {name : Short server name, e.g. jfm} {--client : The server hosts client sites}')]
#[Description('Register a server and print its collector API token (shown once)')]
class AddServer extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = (string) $this->argument('name');

        $validator = Validator::make(['name' => $name], [
            'name' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9._-]*$/i', 'unique:servers,name'],
        ]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('name'));

            return self::FAILURE;
        }

        $server = Server::make(['name' => $name, 'is_client' => (bool) $this->option('client')]);
        $token = $server->issueToken();

        $this->components->info("Server [{$server->name}] added.");
        $this->line('Token (store it on the server in /etc/logwatch/token, root only; it is not shown again):');
        $this->line($token);

        return self::SUCCESS;
    }
}
