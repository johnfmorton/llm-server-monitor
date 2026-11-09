<?php

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;

#[Signature('logwatch:server-config {name : The server}
    {--notes-file= : Replace the notes on what is normal for this server with this file ("-" for stdin)}
    {--allow-key=* : Allow an SSH key to log in from outside the tailnet (SHA256 fingerprint or key comment)}
    {--remove-allow-key=* : Remove a key from the allowlist}
    {--known-failed-unit=* : A systemd unit known to be broken, so it does not alert every night}
    {--remove-known-failed-unit=* : Alert on this unit again}
    {--client : The server hosts client sites}
    {--not-client : The server does not host client sites}')]
#[Description('Show or change a server\'s review notes and hard-rule exceptions')]
class ConfigureServer extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $server = Server::firstWhere('name', (string) $this->argument('name'));
        if ($server === null) {
            $this->components->error('No server with that name.');

            return self::FAILURE;
        }

        if (($file = $this->option('notes-file')) !== null) {
            $notes = $file === '-' ? stream_get_contents($this->stdin()) : @file_get_contents($file);
            if ($notes === false) {
                $this->components->error("Can't read {$file}.");

                return self::FAILURE;
            }
            $server->notes = trim($notes) !== '' ? trim($notes) : null;
        }

        $server->ssh_allowlist = $this->edit($server->ssh_allowlist, $this->option('allow-key'), $this->option('remove-allow-key'));
        $server->known_failed_units = $this->edit($server->known_failed_units, $this->option('known-failed-unit'), $this->option('remove-known-failed-unit'));

        if ($this->option('client')) {
            $server->is_client = true;
        }
        if ($this->option('not-client')) {
            $server->is_client = false;
        }

        $changed = $server->isDirty();
        $server->save();

        if ($changed) {
            $this->components->info("Server [{$server->name}] updated.");
        }

        $this->components->twoColumnDetail('Client server', $server->is_client ? 'yes' : 'no');
        $this->components->twoColumnDetail('SSH allowlist (this server)', implode(', ', $server->ssh_allowlist ?? []) ?: '–');
        $this->components->twoColumnDetail('SSH allowlist (all servers)', implode(', ', config('logwatch.rules.ssh_allowlist', [])) ?: '–');
        $this->components->twoColumnDetail('Known failed units', implode(', ', $server->known_failed_units ?? []) ?: '–');
        $this->newLine();
        $this->line('Notes:');
        $this->line($server->notes ?? '(none)');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>|null  $current
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>|null
     */
    private function edit(?array $current, array $add, array $remove): ?array
    {
        $values = array_values(array_diff(array_unique([...($current ?? []), ...array_map('trim', $add)]), $remove, ['']));

        return $values === [] ? null : $values;
    }

    /**
     * @return resource
     */
    private function stdin()
    {
        return $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null
            ? $this->input->getStream()
            : STDIN;
    }
}
