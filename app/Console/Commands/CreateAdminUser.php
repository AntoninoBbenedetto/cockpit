<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateAdminUser extends Command
{
    protected $signature = 'cockpit:create-admin {email} {--name=Admin}';

    protected $description = 'Crea un utente con il ruolo Amministratore (si può rieseguire per aggiungerne altri)';

    public function handle(): int
    {
        $password = $this->secret('Password');

        if (strlen((string) $password) < 12) {
            $this->error('La password deve avere almeno 12 caratteri.');

            return self::FAILURE;
        }

        $validator = Validator::make(
            ['email' => $this->argument('email')],
            ['email' => ['required', 'email', 'max:255', 'unique:users,email']],
        );

        if ($validator->fails()) {
            $this->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        // Utente, ruolo e voce di audit nella stessa transazione. Nessun causer:
        // da CLI non c'è un utente autenticato.
        $user = DB::transaction(function () use ($password) {
            $user = User::create([
                'name' => $this->option('name'),
                'email' => $this->argument('email'),
                'password' => $password,
            ]);
            $user->assignRole('Amministratore');

            activity('rbac')
                ->performedOn($user)
                ->event('roles.synced')
                ->withProperties(['old' => [], 'attributes' => ['Amministratore']])
                ->log('Ruoli utente aggiornati');

            return $user;
        });

        $this->info("Utente {$user->email} creato.");

        return self::SUCCESS;
    }
}
