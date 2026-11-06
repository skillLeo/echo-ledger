<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Workspaces\Workspaces;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class MakeAdmin extends Command
{
    protected $signature = 'echoledger:make-admin {--email=} {--name=} {--password=}';

    protected $description = 'Create or promote the EchoLedger super admin';

    public function handle(Workspaces $workspaces): int
    {
        $email = (string) ($this->option('email') ?: text('Email', required: true));
        $name = (string) ($this->option('name') ?: text('Name', default: 'Owner', required: true));
        $password = (string) ($this->option('password') ?: password('Password (min 12 chars)', required: true));

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:12'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'password' => $password]);
        $user->is_super_admin = true; // never mass-assignable
        // The operator typed the address on the server: it is confirmed (people-only share links open only for that).
        $user->email_verified_at ??= now();
        // The operator set the server up: there is no sign-up after which to show the recording-consent notice.
        $user->consent_seen_at ??= now();
        $user->save();
        // Every user works inside a workspace (B20); a promoted user keeps the ones they have.
        if (! $user->workspaces()->exists()) {
            $workspaces->createFor($user);
        }

        $this->info("Super admin ready: {$email}. Sign in at /admin.");
        $this->line('Two-factor sign-in is optional and recommended. Set it up on your profile page.');

        return self::SUCCESS;
    }
}
