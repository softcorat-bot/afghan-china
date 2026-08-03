<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * `php artisan acsc:password <email>` — change an account's password.
 *
 * Shared hosting is exactly where you least want to talk someone through
 * `tinker`, and it is exactly where the seeded default password must not
 * survive. This asks for the new one without echoing it, so it never lands in
 * the shell history or the cPanel terminal log.
 */
class SetPassword extends Command
{
    protected $signature = 'acsc:password {email : the account to change} {--password= : skip the prompt (avoid: it is recorded in shell history)}';

    protected $description = 'Set a user password';

    public function handle(): int
    {
        $email = $this->argument('email');

        $user = User::withoutGlobalScopes()->withTrashed()->where('email', $email)->first();

        if (! $user) {
            $this->error("No account with the email {$email}.");
            $known = User::withoutGlobalScopes()->withTrashed()->orderBy('email')->pluck('email');
            if ($known->isNotEmpty()) {
                $this->line('  Accounts on this install: '.$known->implode(', '));
            }

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('New password (min 8 characters)');

        if (strlen((string) $password) < 8) {
            $this->error('Too short — use at least 8 characters.');

            return self::FAILURE;
        }

        if (! $this->option('password') && $this->secret('Type it again') !== $password) {
            $this->error('The two entries did not match. Nothing was changed.');

            return self::FAILURE;
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        $this->info("Password updated for {$user->email}.");

        return self::SUCCESS;
    }
}
