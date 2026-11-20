<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateUser extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    protected $signature = 'app:create-user
        {--platform-admin : Let the account run the service: create, suspend and reopen companies}';

    protected $description = 'Create a user who can sign in to Procurement Hyperspace';

    public function handle(): int
    {
        $input = [
            'name' => text('Name', required: true),
            'email' => Str::lower(text('Email', required: true)),
            'password' => password('Password', required: true),
            'password_confirmation' => password('Confirm password', required: true),
        ];

        $validator = Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->create($validator->safe()->only(['name', 'email', 'password']));

        if ($this->option('platform-admin')) {
            // Not fillable, so no form can ever make someone a platform admin.
            $user->forceFill(['is_platform_admin' => true])->save();
            $this->components->info("Platform admin {$user->email} created. Sign in, turn on two-factor authentication in the security settings, and then open /platform/companies.");

            return self::SUCCESS;
        }

        $this->components->info("Account {$user->email} created. It belongs to no company yet.");

        return self::SUCCESS;
    }
}
