<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    protected $signature = 'cockpit:create-admin {email} {--name=Admin}';

    protected $description = 'Crea il primo utente con il ruolo Amministratore';

    public function handle(): int
    {
        $password = $this->secret('Password');

        if (strlen((string) $password) < 12) {
            $this->error('La password deve avere almeno 12 caratteri.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = User::create([
            'name' => $this->option('name'),
            'email' => $this->argument('email'),
            'password' => $password,
        ]);
        $user->assignRole('Amministratore');

        $this->info("Utente {$user->email} creato.");

        return self::SUCCESS;
    }
}
