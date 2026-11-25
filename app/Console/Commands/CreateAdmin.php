<?php

namespace App\Console\Commands;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Enums\AdminRole;
use Illuminate\Console\Command;

class CreateAdmin extends Command
{
    protected $signature = 'fishdex:create-admin {email} {--name=} {--role=super_admin}';

    protected $description = 'Create or update a staff account for the admin panel';

    public function handle(): int
    {
        $role = AdminRole::tryFrom($this->option('role'));
        if (! $role) {
            $this->error('Role must be one of: '.implode(', ', array_column(AdminRole::cases(), 'value')));

            return self::FAILURE;
        }

        $password = $this->secret('Password (min 12 characters)');
        if (strlen((string) $password) < 12) {
            $this->error('Password must be at least 12 characters.');

            return self::FAILURE;
        }

        Admin::query()->updateOrCreate(['email' => strtolower($this->argument('email'))], [
            'name' => $this->option('name') ?: $this->argument('email'),
            'password' => $password,
            'role' => $role,
            'is_active' => true,
        ]);

        $this->info("Admin {$this->argument('email')} saved with role {$role->value}.");

        return self::SUCCESS;
    }
}
