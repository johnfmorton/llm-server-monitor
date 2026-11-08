<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Input\StreamableInputInterface;

use function Laravel\Prompts\password;

#[Signature('logwatch:user {email} {--name= : Display name (defaults to the part of the email before @)} {--password-stdin : Read the password from standard input instead of prompting}')]
#[Description('Create a dashboard user, or reset an existing user\'s password')]
class CreateUser extends Command
{
    /**
     * There is no sign-up page: dashboard users are created here.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $plain = $this->option('password-stdin')
            ? rtrim((string) fgets($this->stdin()), "\r\n")
            : password(label: 'Password', required: true);

        $validator = Validator::make(['email' => $email, 'password' => $plain], [
            'email' => ['required', 'email'],
            'password' => ['required', Password::min(12)],
        ]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $user->name = (string) ($this->option('name') ?: ($user->name ?? strstr($email, '@', true)));
        $user->password = $plain;
        $user->save();

        $this->components->info($existed ? "Password reset for {$email}." : "User {$email} created.");

        return self::SUCCESS;
    }

    /**
     * Standard input, or the stream set on the console input (used by tests).
     *
     * @return resource
     */
    private function stdin()
    {
        return $this->input instanceof StreamableInputInterface && $this->input->getStream() !== null
            ? $this->input->getStream()
            : STDIN;
    }
}
