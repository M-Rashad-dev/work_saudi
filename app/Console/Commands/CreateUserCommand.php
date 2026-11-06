<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates (or resets the password of) the single login user. Registration is disabled.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'outreach:create-user
                            {--name= : Display name}
                            {--email= : Login email}
                            {--password= : Password (prompted if omitted; avoid passing it on shared shells)}';

    protected $description = 'Create the dashboard login user, or reset its password if the email exists';

    public function handle(): int
    {
        $email = $this->option('email') ?: text('Email', required: true, validate: ['email' => 'email']);
        $existing = User::query()->where('email', $email)->first();

        $name = $this->option('name') ?: ($existing?->name ?? text('Name', default: 'Admin', required: true));
        $plain = $this->option('password') ?: password('Password (min 8 chars)', required: true, validate: ['password' => 'min:8']);

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $plain],
            ['email' => 'required|email|max:255', 'name' => 'required|string|max:255', 'password' => 'required|string|min:8'],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $others = User::query()->where('email', '!=', $email)->count();
        if (! $existing && $others > 0 && $this->input->isInteractive()
            && ! confirm("{$others} other user(s) already exist. This is a single-user app — create another anyway?", default: false)) {
            return self::FAILURE;
        }

        ($existing ?? new User)->forceFill([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make($plain),
            'email_verified_at' => now(),
        ])->save();

        $this->info($existing ? "Password updated for {$email}." : "User {$email} created. You can log in now.");

        return self::SUCCESS;
    }
}
