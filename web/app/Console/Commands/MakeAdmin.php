<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class MakeAdmin extends Command
{
    protected $signature = 'echoledger:make-admin {--email=} {--name=} {--password=}';

    protected $description = 'Create or promote the EchoLedger super admin';

    public function handle(): int
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
        $user->save();

        $this->info("Super admin ready: {$email}. Log in at /admin — you will be asked to set up 2FA on first login.");

        return self::SUCCESS;
    }
}
