# Cockpit: stack e fondamenta — Piano di implementazione

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mettere in piedi Cockpit da zero: ambiente Docker, Laravel 13 + Filament 5, permessi a grana fine con policy a mano, impostazioni tipizzate e audit log, tutto coperto da test.

**Architecture:** Un solo pannello Filament sopra Laravel/Livewire/PostgreSQL. Le regole di dominio (sospensione, anti-lockout, assegnazione ruoli) vivono in classi `app/Actions/` che le risorse Filament richiamano. L'autorizzazione è per permesso (enum + policy scritte a mano su `spatie/laravel-permission`), senza bypass.

**Tech Stack:** PHP 8.4, Laravel 13, Filament 5, Livewire 4, PostgreSQL 18, spatie/laravel-permission 8, spatie/laravel-settings 3, spatie/laravel-activitylog 5, Pest 5, Larastan, Pint, Docker Compose (PHP-FPM + Nginx).

**Spec:** [docs/superpowers/specs/2026-10-03-stack-e-fondamenta-design.md](../specs/2026-10-03-stack-e-fondamenta-design.md). ADR collegati: [ADR-001](../../adr/ADR-001-filament-per-admin-panel.md), [ADR-002](../../adr/ADR-002-permessi-a-grana-fine-spatie-e-policy.md), [ADR-003](../../adr/ADR-003-ambiente-sviluppo-docker-compose.md).

## Global Constraints

Valori copiati dalla spec. Ogni task li rispetta implicitamente.

- PHP 8.4 (vincolo di `spatie/laravel-activitylog` 5 e Pest 5). Nessun PHP né Composer sull'host: tutto passa dai container.
- Versioni: `laravel/framework` 13.x, `filament/filament` 5.x, `spatie/laravel-permission` 8.x, `spatie/laravel-settings` 3.x + `filament/spatie-laravel-settings-plugin` 5.x, `spatie/laravel-activitylog` 5.x, `pestphp/pest` 5.x + `pestphp/pest-plugin-livewire` 5.x, `larastan/larastan` 3.x, `laravel/pint` 1.x. Database PostgreSQL 18.
- Immagini: `php:8.4-fpm-alpine`, `nginx:1.28-alpine`, `postgres:18-alpine`, `axllent/mailpit`.
- Il codice controlla solo permessi (`$user->can(...)`), mai `hasRole()`. Nessun `Gate::before` e nessun super-admin con bypass.
- Permessi nel formato `risorsa.azione`, definiti in `app/Enums/Permission.php`.
- Un solo guard: `web`.
- Policy scritte a mano in `app/Policies/`. Nessun Filament Shield.
- Regole di dominio in `app/Actions/`, non nelle risorse Filament.
- Password, token e campi riservati non finiscono mai nell'audit log (elenco esplicito degli attributi registrati).
- Test su PostgreSQL (`cockpit_test`) con `RefreshDatabase`. Larastan a livello 5.
- Nessun servizio Node, Redis o code. Nessuna CI. Nessun `.env` committato (solo `.env.example`).
- Documentazione in italiano. Messaggi di commit in inglese, terminano con `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## Review Focus

Casi che la spec implica ma che sono facili da dimenticare. Ciascuno ha un test nel task indicato.

1. **Sospensione con sessione aperta:** un utente già autenticato e poi sospeso deve ricevere 403 alla richiesta successiva, non solo al login (Task 5).
2. **Ultimo `roles.manage` perso per una via laterale:** non solo modificando il ruolo, ma anche togliendo il ruolo all'utente, sospendendolo o eliminandolo, o eliminando il ruolo (Task 4).
3. **Richiesta diretta senza permesso:** chiamare l'azione Livewire senza il permesso deve fallire anche se il pulsante è nascosto (Task 6).
4. **Ruolo con un nome "da admin" ma senza permessi:** non concede nulla (Task 4, sezione 4c).
5. **Password nell'audit log:** né in chiaro né come hash, né alla creazione né alla modifica (Task 8).

---

## Convenzioni dei comandi

Dopo il Task 1, i comandi PHP girano nel container `app`:

```bash
docker compose exec app <comando>
```

Negli esempi sotto, `art` significa `docker compose exec app php artisan`. Il container si avvia con `make up`.

## File Structure

| Percorso | Responsabilità |
|---|---|
| `docker/php/Dockerfile` | immagine `app` (PHP 8.4-FPM, Composer, estensioni) |
| `docker/nginx/default.conf` | Nginx → PHP-FPM |
| `docker/postgres/init-test-db.sh` | crea `cockpit_test` all'avvio |
| `compose.yaml`, `Makefile`, `.env.example` | orchestrazione e comandi |
| `app/Enums/Permission.php` | elenco dei permessi |
| `app/Enums/UserStatus.php` | `active` / `suspended` |
| `app/Models/User.php`, `app/Models/Role.php` | modelli (ruolo esteso per l'audit) |
| `app/Policies/UserPolicy.php`, `RolePolicy.php`, `ActivityPolicy.php` | autorizzazione per risorsa |
| `app/Actions/*` | regole di dominio |
| `app/Exceptions/LockoutException.php` | violazione dell'anti-lockout |
| `app/Settings/GeneralSettings.php` | impostazioni tipizzate |
| `app/Filament/...` | pannello, risorse, pagina impostazioni |
| `database/seeders/RolesAndPermissionsSeeder.php` | sincronizza permessi, crea il ruolo di partenza |
| `app/Console/Commands/CreateAdminUser.php` | crea il primo utente |
| `tests/Feature/...` | test per ogni area |

---

### Task 1: Ambiente Docker

**Files:**
- Create: `docker/php/Dockerfile`, `docker/nginx/default.conf`, `docker/postgres/init-test-db.sh`, `compose.yaml`, `Makefile`, `.env.example`, `.dockerignore`
- Modify: `.gitignore` (crea se manca)

**Interfaces:**
- Produces: servizi `app`, `web`, `db`, `mailpit`; target `make up|down|shell|test|lint`; variabili `.env` `APP_UID`, `APP_GID`, `WEB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

- [ ] **Step 1: Dockerfile dell'app**

`docker/php/Dockerfile`:

```dockerfile
FROM php:8.4-fpm-alpine

RUN apk add --no-cache git unzip icu-dev libzip-dev postgresql-dev \
    && docker-php-ext-install intl zip bcmath pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG APP_UID=1000
ARG APP_GID=1000
RUN addgroup -g ${APP_GID} app && adduser -D -u ${APP_UID} -G app app

USER app
WORKDIR /var/www/html
```

- [ ] **Step 2: Configurazione Nginx**

`docker/nginx/default.conf`:

```nginx
server {
    listen 80;
    root /var/www/html/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

- [ ] **Step 3: Script del database di test**

`docker/postgres/init-test-db.sh`:

```bash
#!/bin/sh
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -c "CREATE DATABASE cockpit_test;"
```

Poi: `chmod +x docker/postgres/init-test-db.sh`.

- [ ] **Step 4: `compose.yaml`**

```yaml
services:
  app:
    build:
      context: ./docker/php
      args:
        APP_UID: ${APP_UID:-1000}
        APP_GID: ${APP_GID:-1000}
    volumes:
      - .:/var/www/html
    depends_on:
      db:
        condition: service_healthy

  web:
    image: nginx:1.28-alpine
    ports:
      - "${WEB_PORT:-8080}:80"
    volumes:
      - .:/var/www/html:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on:
      - app

  db:
    image: postgres:18-alpine
    environment:
      POSTGRES_DB: ${DB_DATABASE:-cockpit}
      POSTGRES_USER: ${DB_USERNAME:-cockpit}
      POSTGRES_PASSWORD: ${DB_PASSWORD:-cockpit-dev-password}
    volumes:
      - db-data:/var/lib/postgresql
      - ./docker/postgres/init-test-db.sh:/docker-entrypoint-initdb.d/init-test-db.sh:ro
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME:-cockpit} -d ${DB_DATABASE:-cockpit}"]
      interval: 5s
      timeout: 5s
      retries: 10

  mailpit:
    image: axllent/mailpit
    ports:
      - "${MAILPIT_PORT:-8025}:8025"

volumes:
  db-data:
```

Nota: l'immagine `postgres:18` usa `/var/lib/postgresql` come punto di mount (non `/var/lib/postgresql/data`). Lo verifica lo Step 7.

- [ ] **Step 5: `.env.example` iniziale, `Makefile`, `.dockerignore`, `.gitignore`**

`.env.example` (il Task 2 lo unisce a quello di Laravel):

```dotenv
# Docker
APP_UID=1000
APP_GID=1000
WEB_PORT=8080
MAILPIT_PORT=8025

# Valori di sviluppo, non segreti
DB_DATABASE=cockpit
DB_USERNAME=cockpit
DB_PASSWORD=cockpit-dev-password
```

`Makefile` (le righe di ricetta iniziano con un carattere TAB):

```makefile
COMPOSE = docker compose

.PHONY: up down shell test lint

up:
	@test -f .env || cp .env.example .env
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

shell:
	$(COMPOSE) exec app sh

test:
	$(COMPOSE) exec app ./vendor/bin/pest

lint:
	$(COMPOSE) exec app ./vendor/bin/pint --test
	$(COMPOSE) exec app ./vendor/bin/phpstan analyse --memory-limit=1G
```

`.dockerignore`:

```
.git
vendor
node_modules
```

`.gitignore`:

```
/.env
/vendor
/node_modules
```

- [ ] **Step 6: Avviare e verificare**

Run: `make up`
Expected: build riuscita, quattro servizi `running`; `docker compose ps` mostra `db` come `healthy`.

- [ ] **Step 7: Verificare database, estensioni e Nginx**

Run: `docker compose exec db psql -U cockpit -d cockpit -c "SELECT version();" && docker compose exec db psql -U cockpit -d cockpit -tc "SELECT datname FROM pg_database WHERE datname='cockpit_test';"`
Expected: `PostgreSQL 18.x` e una riga `cockpit_test`.

Run: `docker compose exec app php -v && docker compose exec app php -m | grep -E "intl|zip|bcmath|pdo_pgsql|mbstring|tokenizer|xml"`
Expected: `PHP 8.4.x` e tutte e sette le estensioni elencate.

Se `cockpit_test` manca perché il volume esisteva già: `docker compose down -v && make up`.

- [ ] **Step 8: Commit**

```bash
git add docker compose.yaml Makefile .env.example .dockerignore .gitignore docs/superpowers
git commit -m "feat: add Docker Compose development environment

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Bootstrap di Laravel e dipendenze

**Files:**
- Create: skeleton Laravel nella radice; `phpunit.xml` (generato)
- Modify: `.env.example`, `.gitignore`, `composer.json`

**Interfaces:**
- Consumes: ambiente del Task 1 (`make up`).
- Produces: applicazione Laravel funzionante su `http://localhost:8080`, connessa a PostgreSQL, con tutte le dipendenze di produzione installate.

- [ ] **Step 1: Creare il progetto in una cartella temporanea**

La radice non è vuota (README, docs), quindi `create-project` va fatto in una sottocartella e poi spostato.

Run: `docker compose exec app composer create-project laravel/laravel .laravel-tmp "^13.0" --prefer-dist`
Expected: installazione completata. Se la versione `^13.0` non è risolvibile, fermarsi e riportarlo: la spec assume Laravel 13.

- [ ] **Step 2: Spostare lo skeleton nella radice senza sovrascrivere i file nostri**

```bash
cd .laravel-tmp
rm -rf .git
# i file nostri restano: README.md, .gitignore, .env.example
rm -f README.md
cat .gitignore >> ../.gitignore
mv .env.example ../.env.laravel.example
rm -f .gitignore
cp -rn . ..
cd ..
rm -rf .laravel-tmp
```

Poi unire a mano `.env.laravel.example` dentro `.env.example`: tenere le variabili Docker già presenti, aggiungere tutte quelle di Laravel, e rimuovere `.env.laravel.example`. Deduplicare `.gitignore`.

- [ ] **Step 3: Configurare PostgreSQL in `.env.example`**

Nella sezione database di `.env.example` impostare:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=cockpit
DB_USERNAME=cockpit
DB_PASSWORD=cockpit-dev-password

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```

(`DB_*` compaiono una volta sola: sono usati sia da Laravel sia da `compose.yaml`.)

Run: `cp .env.example .env && docker compose exec app php artisan key:generate`

- [ ] **Step 4: Migrare e verificare l'app**

Run: `docker compose exec app php artisan migrate --force`
Expected: migrazioni di default eseguite su PostgreSQL.

Run: `curl -sI http://localhost:8080 | head -1`
Expected: `HTTP/1.1 200 OK`.

- [ ] **Step 5: Installare le dipendenze di produzione**

```bash
docker compose exec app composer require filament/filament:"^5.0" spatie/laravel-permission:"^8.0" spatie/laravel-settings:"^3.0" filament/spatie-laravel-settings-plugin:"^5.0" spatie/laravel-activitylog:"^5.0"
```

Expected: tutti installati senza conflitti. In caso di conflitto di versioni, fermarsi e riportare l'output di Composer: la compatibilità è stata verificata solo sui vincoli dichiarati.

- [ ] **Step 6: Leggere gli upgrade guide**

Prima di usare `spatie/laravel-permission` 8 e `spatie/laravel-activitylog` 5, leggere i rispettivi upgrade guide (in `vendor/spatie/*/UPGRADING.md` o sul sito della documentazione) e annotare in fondo a questo piano, sotto "Note emerse", ogni differenza di API rispetto agli esempi dei task seguenti.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: bootstrap Laravel 13 with Filament, Spatie packages and PostgreSQL

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Test, stile e analisi statica

**Files:**
- Create: `phpstan.neon`, `tests/Pest.php` (se non esiste già), `tests/Feature/SmokeTest.php`
- Modify: `phpunit.xml`, `composer.json`

**Interfaces:**
- Produces: `make test` e `make lint` funzionanti; i test girano su `cockpit_test`; helper Pest `userWith(Permission ...$permissions): User` (definito nel Task 4).

- [ ] **Step 1: Installare gli strumenti di sviluppo**

```bash
docker compose exec app composer require --dev pestphp/pest:"^5.0" pestphp/pest-plugin-laravel pestphp/pest-plugin-livewire:"^5.0" larastan/larastan:"^3.0" -W
docker compose exec app composer show laravel/pint | head -3
```

Se `laravel/pint` non è già presente: `composer require --dev laravel/pint`. Se Pest non è ancora inizializzato: `docker compose exec app php artisan pest:install`.

- [ ] **Step 2: Puntare i test a PostgreSQL**

In `phpunit.xml`, dentro `<php>`, impostare (sostituendo eventuali righe SQLite):

```xml
<env name="DB_CONNECTION" value="pgsql"/>
<env name="DB_HOST" value="db"/>
<env name="DB_DATABASE" value="cockpit_test"/>
<env name="MAIL_MAILER" value="array"/>
```

- [ ] **Step 3: Configurare `tests/Pest.php`**

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

(Se Pest ha generato un file diverso, mantenere quello e assicurarsi che `Feature` usi `RefreshDatabase`.)

- [ ] **Step 4: Scrivere uno smoke test**

`tests/Feature/SmokeTest.php`:

```php
<?php

use Illuminate\Support\Facades\DB;

it('runs against the PostgreSQL test database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('cockpit_test');
});
```

Run: `make test`
Expected: 1 test PASS. Se fallisce con un database diverso, `phpunit.xml` non è stato letto: verificare Step 2.

- [ ] **Step 5: Configurare Larastan**

`phpstan.neon`:

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    paths:
        - app
    level: 5
```

- [ ] **Step 6: Eseguire lint**

Run: `make lint`
Expected: Pint e PHPStan passano sull'app di base. Correggere solo ciò che segnalano, senza abbassare il livello.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: add Pest, Pint and Larastan with PostgreSQL test database

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Modello di dominio, permessi, policy e azioni

Questo task contiene la parte che protegge i dati sensibili. Ogni sotto-blocco segue test → fallimento → implementazione → successo.

**Files:**
- Create: `app/Enums/Permission.php`, `app/Enums/UserStatus.php`, `app/Exceptions/LockoutException.php`, `app/Policies/UserPolicy.php`, `app/Policies/RolePolicy.php`, `app/Actions/LastRolesManagerGuard.php`, `app/Actions/SuspendUser.php`, `app/Actions/ReactivateUser.php`, `app/Actions/DeleteUser.php`, `app/Actions/SyncUserRoles.php`, `app/Actions/UpdateRolePermissions.php`, `app/Actions/DeleteRole.php`, `database/seeders/RolesAndPermissionsSeeder.php`, migrazione `add_status_to_users_table`
- Modify: `app/Models/User.php`, `app/Providers/AppServiceProvider.php`, `database/factories/UserFactory.php`, `tests/Pest.php`
- Test: `tests/Feature/Permissions/RolesAndPermissionsSeederTest.php`, `tests/Feature/Policies/UserPolicyTest.php`, `tests/Feature/Policies/RolePolicyTest.php`, `tests/Feature/Actions/*Test.php`

**Interfaces:**
- Produces:
  - `App\Enums\Permission` (backed string enum) con casi `UsersView='users.view'`, `UsersCreate='users.create'`, `UsersUpdate='users.update'`, `UsersSuspend='users.suspend'`, `UsersDelete='users.delete'`, `RolesManage='roles.manage'`, `SettingsGeneralUpdate='settings.general.update'`, `AuditView='audit.view'`; metodo statico `values(): array<string>`.
  - `App\Enums\UserStatus` con `Active='active'`, `Suspended='suspended'`.
  - `User`: colonna `status` (cast a `UserStatus`, default `active`), trait `HasRoles`.
  - `App\Exceptions\LockoutException extends DomainException`.
  - `LastRolesManagerGuard::protect(Closure $change): mixed` (statico, transazionale).
  - `SuspendUser::handle(User $actor, User $target): void`, `ReactivateUser::handle(User $target): void`, `DeleteUser::handle(User $actor, User $target): void`, `SyncUserRoles::handle(User $target, array $roleNames): void`, `UpdateRolePermissions::handle(Role $role, array $permissionNames): void`, `DeleteRole::handle(Role $role): void`.
  - Helper Pest globale `userWith(Permission ...$permissions): User`.
  - Seeder `RolesAndPermissionsSeeder` che crea tutti i permessi dell'enum e il ruolo `Amministratore` con tutti i permessi assegnati esplicitamente.

#### 4a. Enum, colonna `status` e spatie/permission

- [ ] **Step 1: Pubblicare e migrare spatie/permission**

```bash
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```

Verificare in `config/permission.php` che il guard predefinito sia `web` e che `teams` sia `false`.

- [ ] **Step 2: Creare gli enum**

`app/Enums/Permission.php`:

```php
<?php

namespace App\Enums;

enum Permission: string
{
    case UsersView = 'users.view';
    case UsersCreate = 'users.create';
    case UsersUpdate = 'users.update';
    case UsersSuspend = 'users.suspend';
    case UsersDelete = 'users.delete';
    case RolesManage = 'roles.manage';
    case SettingsGeneralUpdate = 'settings.general.update';
    case AuditView = 'audit.view';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
```

`app/Enums/UserStatus.php`:

```php
<?php

namespace App\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
}
```

- [ ] **Step 3: Migrazione dello stato utente**

Run: `docker compose exec app php artisan make:migration add_status_to_users_table --table=users`

Contenuto:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->string('status')->default('active')->after('email');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropColumn('status');
    });
}
```

- [ ] **Step 4: Aggiornare il modello `User`**

In `app/Models/User.php` aggiungere `use Spatie\Permission\Traits\HasRoles;` e `use App\Enums\UserStatus;`, il trait `HasRoles` nella lista dei trait, e nel metodo `casts()` (o `$casts`) la riga:

```php
'status' => UserStatus::class,
```

Nella `UserFactory`, nello stato di default, aggiungere `'status' => UserStatus::Active,`.

- [ ] **Step 5: Helper Pest**

In `tests/Pest.php` aggiungere:

```php
use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;

function userWith(Permission ...$permissions): User
{
    $user = User::factory()->create();

    foreach ($permissions as $permission) {
        $user->givePermissionTo(PermissionModel::findOrCreate($permission->value, 'web'));
    }

    return $user;
}
```

- [ ] **Step 6: Migrare**

Run: `docker compose exec app php artisan migrate`
Expected: migrazioni di permission e `status` applicate.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: add permission and user status enums, spatie permission tables

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

#### 4b. Seeder

- [ ] **Step 1: Scrivere il test**

`tests/Feature/Permissions/RolesAndPermissionsSeederTest.php`:

```php
<?php

use App\Enums\Permission;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

it('creates every permission defined in the enum', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('gives the starting role every permission explicitly', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $role = Role::findByName('Amministratore', 'web');

    expect($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect(Permission::values())->sort()->values()->all());
});

it('is idempotent', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class);

    expect(PermissionModel::count())->toBe(count(Permission::cases()))
        ->and(Role::count())->toBe(1);
});
```

- [ ] **Step 2: Eseguire e verificare che fallisca**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Permissions`
Expected: FAIL (classe `RolesAndPermissionsSeeder` non trovata).

- [ ] **Step 3: Implementare il seeder**

`database/seeders/RolesAndPermissionsSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Enums\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(Permission::cases())
            ->map(fn (Permission $permission) => PermissionModel::findOrCreate($permission->value, 'web'));

        $role = Role::findOrCreate('Amministratore', 'web');
        $role->syncPermissions($permissions);
    }
}
```

(Se in `config/permission.php` il ruolo è esteso nel Task 8, il seeder userà il modello configurato tramite `findOrCreate` della classe: nessuna modifica necessaria.)

- [ ] **Step 4: Verificare che passi**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Permissions`
Expected: 3 test PASS.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: add roles and permissions seeder

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

#### 4c. Policy

- [ ] **Step 1: Scrivere i test delle policy**

`tests/Feature/Policies/UserPolicyTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('denies every user ability without the permission', function (string $ability) {
    $user = User::factory()->create();
    $target = User::factory()->create();

    expect($user->can($ability, $target))->toBeFalse();
})->with(['view', 'update', 'delete', 'suspend']);

it('denies class-level abilities without the permission', function (string $ability) {
    expect(User::factory()->create()->can($ability, User::class))->toBeFalse();
})->with(['viewAny', 'create']);

it('grants each ability with exactly its permission', function (string $ability, Permission $permission) {
    $user = userWith($permission);
    $target = User::factory()->create();

    expect($user->can($ability, $target))->toBeTrue();
})->with([
    ['view', Permission::UsersView],
    ['update', Permission::UsersUpdate],
    ['delete', Permission::UsersDelete],
    ['suspend', Permission::UsersSuspend],
]);

it('does not grant an ability with a different permission', function () {
    $user = userWith(Permission::UsersView);
    $target = User::factory()->create();

    expect($user->can('update', $target))->toBeFalse();
});

it('does not let a user delete or suspend themselves', function () {
    $user = userWith(Permission::UsersDelete, Permission::UsersSuspend);

    expect($user->can('delete', $user))->toBeFalse()
        ->and($user->can('suspend', $user))->toBeFalse();
});

it('never grants bulk delete', function () {
    $user = userWith(Permission::UsersDelete);

    expect($user->can('deleteAny', User::class))->toBeFalse();
});

it('does not grant access because of a role name', function () {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Amministratore', 'web'));
    $target = User::factory()->create();

    expect($user->can('viewAny', User::class))->toBeFalse()
        ->and($user->can('update', $target))->toBeFalse()
        ->and($user->can('suspend', $target))->toBeFalse();
});
```

`tests/Feature/Policies/RolePolicyTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('denies every role ability without roles.manage', function (string $ability) {
    $user = userWith(Permission::UsersView, Permission::UsersUpdate);
    $role = Role::findOrCreate('Operatore', 'web');

    expect($user->can($ability, $role))->toBeFalse();
})->with(['view', 'update', 'delete']);

it('denies class-level role abilities without roles.manage', function (string $ability) {
    expect(User::factory()->create()->can($ability, Role::class))->toBeFalse();
})->with(['viewAny', 'create']);

it('grants every role ability with roles.manage', function (string $ability) {
    $user = userWith(Permission::RolesManage);
    $role = Role::findOrCreate('Operatore', 'web');

    expect($user->can($ability, $role))->toBeTrue();
})->with(['view', 'update', 'delete']);
```

- [ ] **Step 2: Eseguire e verificare che falliscano**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Policies`
Expected: FAIL (nessuna policy registrata: le abilità sono negate, quindi i test "grants" falliscono).

- [ ] **Step 3: Implementare `UserPolicy`**

`app/Policies/UserPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function view(User $user, User $target): bool
    {
        return $user->can(Permission::UsersView->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::UsersCreate->value);
    }

    public function update(User $user, User $target): bool
    {
        return $user->can(Permission::UsersUpdate->value);
    }

    public function suspend(User $user, User $target): bool
    {
        return $user->can(Permission::UsersSuspend->value) && $user->isNot($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->can(Permission::UsersDelete->value) && $user->isNot($target);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

`app/Policies/RolePolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    public function create(User $user): bool
    {
        return $this->manage($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, Role $role): bool
    {
        return $this->manage($user);
    }

    private function manage(User $user): bool
    {
        return $user->can(Permission::RolesManage->value);
    }
}
```

- [ ] **Step 4: Registrare le policy**

In `app/Providers/AppServiceProvider.php`, nel metodo `boot()`:

```php
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

Gate::policy(User::class, UserPolicy::class);
Gate::policy(Role::class, RolePolicy::class);
```

Non aggiungere `Gate::before`.

- [ ] **Step 5: Verificare che passino**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Policies`
Expected: tutti PASS.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: add user and role policies tied to permissions

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

#### 4d. Guardia anti-lockout e azioni di dominio

- [ ] **Step 1: Scrivere i test**

`tests/Feature/Actions/LockoutProtectionTest.php`:

```php
<?php

use App\Actions\DeleteRole;
use App\Actions\DeleteUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

/**
 * Crea un unico gestore di ruoli (tramite il ruolo "Gestori") e un secondo
 * utente con altri permessi, che agisce come attore.
 */
function lastManagerSetup(): array
{
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));

    $manager = User::factory()->create();
    $manager->assignRole($role);

    $actor = userWith(Permission::UsersSuspend, Permission::UsersDelete);

    return [$role, $manager, $actor];
}

it('blocks removing roles.manage from the role of the last manager', function () {
    [$role] = lastManagerSetup();

    expect(fn () => app(UpdateRolePermissions::class)->handle($role, []))
        ->toThrow(LockoutException::class);

    expect($role->fresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
});

it('blocks deleting the role of the last manager', function () {
    [$role] = lastManagerSetup();

    expect(fn () => app(DeleteRole::class)->handle($role))->toThrow(LockoutException::class);

    expect(Role::where('name', 'Gestori')->exists())->toBeTrue();
});

it('blocks removing the role from the last manager', function () {
    [, $manager] = lastManagerSetup();

    expect(fn () => app(SyncUserRoles::class)->handle($manager, []))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->hasRole('Gestori'))->toBeTrue();
});

it('blocks suspending the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect($manager->fresh()->status)->toBe(UserStatus::Active);
});

it('blocks deleting the last manager', function () {
    [, $manager, $actor] = lastManagerSetup();

    expect(fn () => app(DeleteUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);

    expect(User::find($manager->id))->not->toBeNull();
});

it('allows the same changes when another manager remains', function () {
    [$role, $manager, $actor] = lastManagerSetup();
    $other = User::factory()->create();
    $other->assignRole($role);

    app(SuspendUser::class)->handle($actor, $manager);

    expect($manager->fresh()->status)->toBe(UserStatus::Suspended);
});

it('does not count suspended users as managers', function () {
    [$role, $manager, $actor] = lastManagerSetup();
    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $suspended->assignRole($role);

    expect(fn () => app(SuspendUser::class)->handle($actor, $manager))
        ->toThrow(LockoutException::class);
});
```

`tests/Feature/Actions/UserActionsTest.php`:

```php
<?php

use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Actions\SyncUserRoles;
use App\Actions\UpdateRolePermissions;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Models\User;
use Spatie\Permission\Models\Role;

it('suspends and reactivates a user', function () {
    $actor = userWith(Permission::UsersSuspend);
    $target = User::factory()->create();

    app(SuspendUser::class)->handle($actor, $target);
    expect($target->fresh()->status)->toBe(UserStatus::Suspended);

    app(ReactivateUser::class)->handle($target);
    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('refuses to suspend or delete oneself', function () {
    $user = userWith(Permission::UsersSuspend, Permission::UsersDelete);

    expect(fn () => app(SuspendUser::class)->handle($user, $user))->toThrow(DomainException::class)
        ->and(fn () => app(DeleteUser::class)->handle($user, $user))->toThrow(DomainException::class);

    expect($user->fresh()->status)->toBe(UserStatus::Active);
});

it('syncs the roles of a user', function () {
    Role::findOrCreate('Operatore', 'web');
    Role::findOrCreate('Lettore', 'web');
    $target = User::factory()->create();

    app(SyncUserRoles::class)->handle($target, ['Operatore', 'Lettore']);

    expect($target->fresh()->roles->pluck('name')->sort()->values()->all())
        ->toBe(['Lettore', 'Operatore']);
});

it('updates the permissions of a role', function () {
    $role = Role::findOrCreate('Operatore', 'web');

    app(UpdateRolePermissions::class)->handle($role, [Permission::UsersView->value]);

    expect($role->fresh()->permissions->pluck('name')->all())->toBe([Permission::UsersView->value]);
});

it('rejects permission names that are not in the enum', function () {
    $role = Role::findOrCreate('Operatore', 'web');

    expect(fn () => app(UpdateRolePermissions::class)->handle($role, ['users.fly']))
        ->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Eseguire e verificare che falliscano**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Actions`
Expected: FAIL (classi azione non trovate).

- [ ] **Step 3: Implementare eccezione e guardia**

`app/Exceptions/LockoutException.php`:

```php
<?php

namespace App\Exceptions;

use DomainException;

class LockoutException extends DomainException
{
    public static function lastRolesManager(): self
    {
        return new self('Operazione non consentita: non resterebbe nessun utente attivo con il permesso roles.manage.');
    }
}
```

`app/Actions/LastRolesManagerGuard.php`:

```php
<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Exceptions\LockoutException;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as PermissionModel;

final class LastRolesManagerGuard
{
    /**
     * Esegue la modifica in transazione. Se prima c'era almeno un utente
     * attivo con roles.manage e dopo non ce n'è nessuno, annulla tutto.
     */
    public static function protect(Closure $change): mixed
    {
        return DB::transaction(function () use ($change) {
            $before = self::managers();
            $result = $change();

            if ($before > 0 && self::managers() === 0) {
                throw LockoutException::lastRolesManager();
            }

            return $result;
        });
    }

    private static function managers(): int
    {
        PermissionModel::findOrCreate(Permission::RolesManage->value, 'web');

        return User::query()
            ->where('status', UserStatus::Active->value)
            ->permission(Permission::RolesManage->value)
            ->count();
    }
}
```

- [ ] **Step 4: Implementare le azioni**

`app/Actions/SuspendUser.php`:

```php
<?php

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;
use DomainException;

class SuspendUser
{
    public function handle(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new DomainException('Non puoi sospendere il tuo stesso account.');
        }

        LastRolesManagerGuard::protect(
            fn () => $target->update(['status' => UserStatus::Suspended])
        );
    }
}
```

`app/Actions/ReactivateUser.php`:

```php
<?php

namespace App\Actions;

use App\Enums\UserStatus;
use App\Models\User;

class ReactivateUser
{
    public function handle(User $target): void
    {
        $target->update(['status' => UserStatus::Active]);
    }
}
```

`app/Actions/DeleteUser.php`:

```php
<?php

namespace App\Actions;

use App\Models\User;
use DomainException;

class DeleteUser
{
    public function handle(User $actor, User $target): void
    {
        if ($actor->is($target)) {
            throw new DomainException('Non puoi eliminare il tuo stesso account.');
        }

        LastRolesManagerGuard::protect(fn () => $target->delete());
    }
}
```

`app/Actions/SyncUserRoles.php`:

```php
<?php

namespace App\Actions;

use App\Models\User;

class SyncUserRoles
{
    /** @param  array<int, string>  $roleNames */
    public function handle(User $target, array $roleNames): void
    {
        $before = $target->roles()->pluck('name')->sort()->values()->all();

        LastRolesManagerGuard::protect(fn () => $target->syncRoles($roleNames));

        activity('rbac')
            ->performedOn($target)
            ->event('roles.synced')
            ->withProperties(['old' => $before, 'attributes' => collect($roleNames)->sort()->values()->all()])
            ->log('Ruoli utente aggiornati');
    }
}
```

`app/Actions/UpdateRolePermissions.php`:

```php
<?php

namespace App\Actions;

use App\Enums\Permission;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

class UpdateRolePermissions
{
    /** @param  array<int, string>  $permissionNames */
    public function handle(Role $role, array $permissionNames): void
    {
        $unknown = array_diff($permissionNames, Permission::values());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Permessi sconosciuti: '.implode(', ', $unknown));
        }

        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        LastRolesManagerGuard::protect(fn () => $role->syncPermissions($permissionNames));

        activity('rbac')
            ->performedOn($role)
            ->event('permissions.synced')
            ->withProperties(['old' => $before, 'attributes' => collect($permissionNames)->sort()->values()->all()])
            ->log('Permessi del ruolo aggiornati');
    }
}
```

`app/Actions/DeleteRole.php`:

```php
<?php

namespace App\Actions;

use Spatie\Permission\Models\Role;

class DeleteRole
{
    public function handle(Role $role): void
    {
        $name = $role->name;

        LastRolesManagerGuard::protect(fn () => $role->delete());

        activity('rbac')
            ->event('role.deleted')
            ->withProperties(['name' => $name])
            ->log('Ruolo eliminato');
    }
}
```

Se `spatie/laravel-permission` 8 cambia la firma di `syncPermissions`/`syncRoles` o di `permission()`, adeguare e annotare in "Note emerse". Se la funzione `activity()` ha un'altra firma in activitylog 5, adeguare: i test dell'audit (Task 8) sono l'oracolo.

- [ ] **Step 5: Verificare che passino**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Actions`
Expected: tutti PASS. Se i test che chiamano `activity()` falliscono perché la tabella `activity_log` non esiste ancora, eseguire ora il publish/migrate di activitylog descritto nel Task 8 (Step 1) e ripetere.

- [ ] **Step 6: Lint e commit**

Run: `make lint`
Expected: PASS.

```bash
git add -A
git commit -m "feat: add domain actions with last-roles-manager protection

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Pannello Filament, accesso e primo utente

**Files:**
- Create: `app/Providers/Filament/AdminPanelProvider.php` (generato), `app/Console/Commands/CreateAdminUser.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Panel/PanelAccessTest.php`, `tests/Feature/Console/CreateAdminUserTest.php`

**Interfaces:**
- Consumes: `UserStatus`, `RolesAndPermissionsSeeder`, `HasRoles`.
- Produces: pannello su `/admin`; `User implements FilamentUser` con `canAccessPanel(Panel $panel): bool`; comando `cockpit:create-admin {email} {--name=}`.

- [ ] **Step 1: Generare il pannello**

Run: `docker compose exec app php artisan filament:install --panels`
Quando richiesto l'id del pannello, usare `admin`. Verificare che `bootstrap/providers.php` includa `AdminPanelProvider` e che nel provider sia presente `->login()`.

- [ ] **Step 2: Scrivere i test di accesso**

`tests/Feature/Panel/PanelAccessTest.php`:

```php
<?php

use App\Enums\UserStatus;
use App\Models\User;

it('redirects guests to the login page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets an active user reach the panel', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertSuccessful();
});

it('denies a suspended user', function () {
    $this->actingAs(User::factory()->create(['status' => UserStatus::Suspended]))
        ->get('/admin')
        ->assertForbidden();
});

it('denies a user suspended while their session was already open', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertSuccessful();

    $user->update(['status' => UserStatus::Suspended]);

    $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
});
```

- [ ] **Step 3: Eseguire e verificare che i test di sospensione falliscano**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Panel`
Expected: FAIL sui due test "suspended" (restituiscono 200 perché il pannello non controlla lo stato).

- [ ] **Step 4: Implementare `canAccessPanel`**

In `app/Models/User.php`:

```php
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class User extends Authenticatable implements FilamentUser
{
    // ... trait e casts esistenti

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === UserStatus::Active;
    }
}
```

Filament verifica `canAccessPanel` ad ogni richiesta nel suo middleware di autenticazione, quindi la sospensione ha effetto sulle sessioni già aperte. Il quarto test lo dimostra.

**Se il quarto test fallisce** (Filament non ricontrolla a ogni richiesta), creare un middleware `EnsureUserIsActive` che chiama `abort(403)` per un utente non attivo e registrarlo in `AdminPanelProvider` con `->authMiddleware([...])`, come previsto dalla spec.

- [ ] **Step 5: Verificare che passino**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Panel`
Expected: 4 test PASS.

- [ ] **Step 6: Test del comando per il primo utente**

`tests/Feature/Console/CreateAdminUserTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Models\User;

it('creates the first administrator with every permission', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test', '--name' => 'Admin'])
        ->expectsQuestion('Password', 'a-long-test-password')
        ->assertSuccessful();

    $user = User::where('email', 'admin@example.test')->firstOrFail();

    expect($user->hasRole('Amministratore'))->toBeTrue();

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue();
    }
});

it('refuses a password that is too short', function () {
    $this->artisan('cockpit:create-admin', ['email' => 'admin@example.test'])
        ->expectsQuestion('Password', 'short')
        ->assertFailed();

    expect(User::where('email', 'admin@example.test')->exists())->toBeFalse();
});
```

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Console`
Expected: FAIL (comando inesistente).

- [ ] **Step 7: Implementare il comando**

`app/Console/Commands/CreateAdminUser.php`:

```php
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
```

(`password` è hashata dal cast `hashed` del modello `User`; verificarlo in `User::casts()`.)

- [ ] **Step 8: Verificare, lint e commit**

Run: `make test && make lint`
Expected: PASS.

```bash
git add -A
git commit -m "feat: add Filament panel with active-user access and admin bootstrap command

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Risorse Filament per utenti e ruoli

Le risorse sono sottili: autorizzano tramite policy e delegano le regole alle azioni del Task 4. Generare le classi con gli stub di Filament e modificarle, mantenendo gli `use` generati (la struttura delle cartelle e gli helper di Filament 5 vanno letti dagli stub, non dalla memoria).

**Files:**
- Create: `app/Filament/Resources/Users/UserResource.php` (+ pagine e tabella/form generati), `app/Filament/Resources/Roles/RoleResource.php` (+ pagine)
- Test: `tests/Feature/Filament/UserResourceTest.php`, `tests/Feature/Filament/RoleResourceTest.php`

**Interfaces:**
- Consumes: policy `UserPolicy`/`RolePolicy`, azioni `SuspendUser`, `ReactivateUser`, `DeleteUser`, `SyncUserRoles`, `UpdateRolePermissions`, `DeleteRole`.
- Produces: risorse con form, tabella e azioni che passano sempre dalle azioni di dominio.

- [ ] **Step 1: Generare la risorsa utenti**

Run: `docker compose exec app php artisan make:filament-resource User --generate`
Se il comando chiede opzioni (soft delete, view page), rifiutare le soft delete e abilitare la pagina di sola vista solo se disponibile senza costi.

- [ ] **Step 2: Scrivere i test della risorsa utenti**

`tests/Feature/Filament/UserResourceTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

it('hides the users list from a user without users.view', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/users')
        ->assertForbidden();
});

it('shows the users list to a user with users.view', function () {
    $this->actingAs(userWith(Permission::UsersView))
        ->get('/admin/users')
        ->assertSuccessful();
});

it('lets a user with users.suspend suspend another user from the table', function () {
    $actor = userWith(Permission::UsersView, Permission::UsersSuspend);
    $target = User::factory()->create();

    $this->actingAs($actor);

    livewire(ListUsers::class)
        ->callAction(TestAction::make('suspend')->table($target))
        ->assertNotified();

    expect($target->fresh()->status)->toBe(UserStatus::Suspended);
});

it('rejects a direct suspend call without users.suspend', function () {
    $actor = userWith(Permission::UsersView);
    $target = User::factory()->create();

    $this->actingAs($actor);

    livewire(ListUsers::class)
        ->callAction(TestAction::make('suspend')->table($target))
        ->assertActionHidden(TestAction::make('suspend')->table($target));

    expect($target->fresh()->status)->toBe(UserStatus::Active);
});

it('rejects a direct delete call without users.delete', function () {
    $actor = userWith(Permission::UsersView);
    $target = User::factory()->create();

    $this->actingAs($actor);

    livewire(ListUsers::class)
        ->callAction(TestAction::make('delete')->table($target));

    expect(User::find($target->id))->not->toBeNull();
});

it('does not update a user without users.update', function () {
    $actor = userWith(Permission::UsersView);
    $target = User::factory()->create(['name' => 'Originale']);

    $this->actingAs($actor)
        ->get("/admin/users/{$target->id}/edit")
        ->assertForbidden();
});

it('assigns roles through the domain action', function () {
    Role::findOrCreate('Operatore', 'web');
    $actor = userWith(Permission::UsersView, Permission::UsersUpdate);
    $target = User::factory()->create();

    $this->actingAs($actor);

    livewire(EditUser::class, ['record' => $target->getKey()])
        ->fillForm(['role_names' => ['Operatore']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($target->fresh()->hasRole('Operatore'))->toBeTrue();
});

it('shows a notification and keeps the roles when it would remove the last roles manager', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate(Permission::RolesManage->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);
    $actor = userWith(Permission::UsersView, Permission::UsersUpdate);

    $this->actingAs($actor);

    livewire(EditUser::class, ['record' => $manager->getKey()])
        ->fillForm(['role_names' => []])
        ->call('save')
        ->assertNotified();

    expect($manager->fresh()->hasRole('Gestori'))->toBeTrue();
});
```

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Filament/UserResourceTest.php`
Expected: FAIL (la risorsa generata non ha le azioni personalizzate né il campo `role_names`).

Nota: i nomi esatti dei metodi di test di Filament (`TestAction`, `assertActionHidden`, `assertNotified`) si verificano nella documentazione di Filament 5 sui test delle tabelle. Se differiscono, adeguare i test mantenendo lo stesso comportamento verificato, e annotare in "Note emerse".

- [ ] **Step 3: Personalizzare la tabella e le azioni**

Nella classe di tabella generata (o in `UserResource::table()`), configurare:

```php
use App\Actions\DeleteUser;
use App\Actions\ReactivateUser;
use App\Actions\SuspendUser;
use App\Enums\UserStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;

// colonne
TextColumn::make('name')->searchable()->sortable(),
TextColumn::make('email')->searchable(),
TextColumn::make('status')->badge()
    ->formatStateUsing(fn (UserStatus $state) => $state === UserStatus::Active ? 'Attivo' : 'Sospeso')
    ->color(fn (UserStatus $state) => $state === UserStatus::Active ? 'success' : 'danger'),
TextColumn::make('roles.name')->badge(),

// azioni di riga
Action::make('suspend')
    ->label('Sospendi')
    ->icon('heroicon-o-no-symbol')
    ->requiresConfirmation()
    ->authorize('suspend')
    ->visible(fn (User $record) => $record->status === UserStatus::Active)
    ->action(function (User $record) {
        try {
            app(SuspendUser::class)->handle(auth()->user(), $record);
            Notification::make()->success()->title('Utente sospeso')->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }),

Action::make('reactivate')
    ->label('Riattiva')
    ->icon('heroicon-o-check-circle')
    ->authorize('suspend')
    ->visible(fn (User $record) => $record->status === UserStatus::Suspended)
    ->action(function (User $record) {
        app(ReactivateUser::class)->handle($record);
        Notification::make()->success()->title('Utente riattivato')->send();
    }),

Action::make('delete')
    ->label('Elimina')
    ->icon('heroicon-o-trash')
    ->color('danger')
    ->requiresConfirmation()
    ->authorize('delete')
    ->action(function (User $record) {
        try {
            app(DeleteUser::class)->handle(auth()->user(), $record);
            Notification::make()->success()->title('Utente eliminato')->send();
        } catch (\DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }),
```

Non includere azioni di eliminazione in blocco (la policy nega `deleteAny`).

- [ ] **Step 4: Personalizzare il form**

Nel form: `name` (obbligatorio, max 255), `email` (obbligatorio, email, univoca ignorando il record), `password` (obbligatoria solo in creazione, `minLength(12)`, `dehydrated(fn ($state) => filled($state))`, non idratata in modifica), e il campo dei ruoli:

```php
use Filament\Forms\Components\Select;
use Spatie\Permission\Models\Role;

Select::make('role_names')
    ->label('Ruoli')
    ->multiple()
    ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name'))
    ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state(
        $record?->roles->pluck('name')->all() ?? []
    ))
    ->dehydrated(false),
```

Non usare `->relationship('roles', ...)`: farebbe `sync` direttamente e aggirerebbe `SyncUserRoles` e la guardia anti-lockout.

- [ ] **Step 5: Delegare ruoli e salvataggio alle azioni**

In `EditUser` (pagina generata):

```php
use App\Actions\SyncUserRoles;
use App\Exceptions\LockoutException;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;

protected function handleRecordUpdate(Model $record, array $data): Model
{
    try {
        app(SyncUserRoles::class)->handle($record, $this->data['role_names'] ?? []);
    } catch (LockoutException $e) {
        Notification::make()->danger()->title($e->getMessage())->send();
        $this->halt();
    }

    $record->update($data);

    return $record;
}
```

In `CreateUser` assegnare i ruoli dopo la creazione:

```php
protected function afterCreate(): void
{
    app(SyncUserRoles::class)->handle($this->record, $this->data['role_names'] ?? []);
}
```

Lasciare che la creazione sia protetta dal permesso `users.create` tramite la policy (Filament la applica da solo alla pagina di creazione).

- [ ] **Step 6: Verificare che i test della risorsa utenti passino**

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Filament/UserResourceTest.php`
Expected: tutti PASS.

- [ ] **Step 7: Commit della risorsa utenti**

```bash
git add -A
git commit -m "feat: add Filament user resource backed by domain actions

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 8: Generare la risorsa ruoli e scrivere i test**

Run: `docker compose exec app php artisan make:filament-resource Role --model=Spatie\\Permission\\Models\\Role`

`tests/Feature/Filament/RoleResourceTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\User;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

it('hides roles from a user without roles.manage', function () {
    $this->actingAs(userWith(Permission::UsersView))
        ->get('/admin/roles')
        ->assertForbidden();
});

it('shows roles to a user with roles.manage', function () {
    $this->actingAs(userWith(Permission::RolesManage))
        ->get('/admin/roles')
        ->assertSuccessful();
});

it('updates the permissions of a role through the domain action', function () {
    $role = Role::findOrCreate('Operatore', 'web');
    $this->actingAs(userWith(Permission::RolesManage));

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permission_names' => [Permission::UsersView->value]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->fresh()->hasPermissionTo(Permission::UsersView->value))->toBeTrue();
});

it('keeps the permissions when it would remove roles.manage from the last manager', function () {
    $role = Role::findOrCreate('Gestori', 'web');
    $role->givePermissionTo(PermissionModel::findOrCreate(Permission::RolesManage->value, 'web'));
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $this->actingAs($manager);

    livewire(EditRole::class, ['record' => $role->getKey()])
        ->fillForm(['permission_names' => []])
        ->call('save')
        ->assertNotified();

    expect($role->fresh()->hasPermissionTo(Permission::RolesManage->value))->toBeTrue();
});
```

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Filament/RoleResourceTest.php`
Expected: FAIL.

- [ ] **Step 9: Implementare la risorsa ruoli**

Form: `name` (obbligatorio, univoco) e un `CheckboxList` `permission_names`:

```php
use App\Enums\Permission;
use Filament\Forms\Components\CheckboxList;

CheckboxList::make('permission_names')
    ->label('Permessi')
    ->options(collect(Permission::cases())->mapWithKeys(fn (Permission $p) => [$p->value => $p->value])->all())
    ->afterStateHydrated(fn (CheckboxList $component, ?Role $record) => $component->state(
        $record?->permissions->pluck('name')->all() ?? []
    ))
    ->dehydrated(false),
```

In `EditRole::handleRecordUpdate` e `CreateRole::afterCreate`, chiamare `UpdateRolePermissions` con la stessa gestione di `LockoutException` mostrata nello Step 5 del flusso utenti. Per l'eliminazione, usare un'azione di riga personalizzata che chiama `DeleteRole` (come per gli utenti), non il `DeleteAction` predefinito.

- [ ] **Step 10: Verificare, lint e commit**

Run: `make test && make lint`
Expected: PASS.

```bash
git add -A
git commit -m "feat: add Filament role resource backed by domain actions

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Impostazioni

**Files:**
- Create: `app/Settings/GeneralSettings.php`, `database/settings/<timestamp>_create_general_settings.php`, `app/Filament/Pages/ManageGeneral.php`
- Test: `tests/Feature/Settings/GeneralSettingsTest.php`

**Interfaces:**
- Consumes: `Permission::SettingsGeneralUpdate`.
- Produces: `GeneralSettings` con `string $app_name` e `string $support_email`; pagina `ManageGeneral` accessibile con `settings.general.update`.

Le due proprietà sono un esempio minimo per far esistere il meccanismo: la spec non fissa l'elenco delle impostazioni.

- [ ] **Step 1: Pubblicare e migrare spatie/laravel-settings**

```bash
docker compose exec app php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="migrations"
docker compose exec app php artisan vendor:publish --provider="Spatie\LaravelSettings\LaravelSettingsServiceProvider" --tag="settings"
docker compose exec app php artisan migrate
```

- [ ] **Step 2: Scrivere i test**

`tests/Feature/Settings/GeneralSettingsTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Filament\Pages\ManageGeneral;
use App\Models\User;
use App\Settings\GeneralSettings;

use function Pest\Livewire\livewire;

it('has typed defaults', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->app_name)->toBe('Cockpit')
        ->and($settings->support_email)->toBeString();
});

it('denies the settings page without settings.general.update', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/manage-general')
        ->assertForbidden();
});

it('shows the settings page with settings.general.update', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate))
        ->get('/admin/manage-general')
        ->assertSuccessful();
});

it('saves valid settings', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => 'Nuovo nome', 'support_email' => 'aiuto@example.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(GeneralSettings::class)->app_name)->toBe('Nuovo nome');
});

it('rejects invalid values and keeps the old ones', function (array $data, string $field) {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm($data)
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(app(GeneralSettings::class)->app_name)->toBe('Cockpit');
})->with([
    'empty name' => [['app_name' => '', 'support_email' => 'a@example.test'], 'app_name'],
    'name too long' => [['app_name' => str_repeat('x', 101), 'support_email' => 'a@example.test'], 'app_name'],
    'invalid email' => [['app_name' => 'Cockpit', 'support_email' => 'not-an-email'], 'support_email'],
]);
```

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Settings`
Expected: FAIL.

- [ ] **Step 3: Classe delle impostazioni e migrazione**

`app/Settings/GeneralSettings.php`:

```php
<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public string $app_name;

    public string $support_email;

    public static function group(): string
    {
        return 'general';
    }
}
```

Run: `docker compose exec app php artisan make:settings-migration CreateGeneralSettings` e impostare il contenuto:

```php
public function up(): void
{
    $this->migrator->add('general.app_name', 'Cockpit');
    $this->migrator->add('general.support_email', 'support@example.test');
}
```

Run: `docker compose exec app php artisan migrate`

- [ ] **Step 4: Pagina Filament**

Generare con lo stub del plugin: `docker compose exec app php artisan make:filament-settings-page ManageGeneral GeneralSettings`. Poi in `ManageGeneral`:

```php
use App\Enums\Permission;
use Filament\Forms\Components\TextInput;

public static function canAccess(): bool
{
    return auth()->user()?->can(Permission::SettingsGeneralUpdate->value) ?? false;
}

// nel metodo che definisce lo schema del form
TextInput::make('app_name')->label('Nome applicazione')->required()->maxLength(100),
TextInput::make('support_email')->label('Email di supporto')->required()->email()->maxLength(255),
```

Se lo slug generato è diverso da `manage-general`, adeguare gli URL nei test.

- [ ] **Step 5: Verificare, lint e commit**

Run: `make test && make lint`
Expected: PASS.

```bash
git add -A
git commit -m "feat: add typed general settings with validated Filament page

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Audit log

**Files:**
- Create: `app/Models/Role.php`, `app/Policies/ActivityPolicy.php`, `app/Filament/Resources/Activities/ActivityResource.php` (+ pagina elenco)
- Modify: `app/Models/User.php`, `app/Filament/Pages/ManageGeneral.php`, `config/permission.php`, `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/Audit/AuditLogTest.php`, `tests/Feature/Filament/ActivityResourceTest.php`

**Interfaces:**
- Consumes: `activity()` già usata dalle azioni del Task 4.
- Produces: log automatico di `User` e `Role` con attributi espliciti; log esplicito per ruoli/permessi (già nelle azioni) e per le impostazioni; risorsa di sola lettura protetta da `audit.view`.

- [ ] **Step 1: Pubblicare e migrare activitylog**

```bash
docker compose exec app php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
docker compose exec app php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-config"
docker compose exec app php artisan migrate
```

Se i tag cambiano in activitylog 5, usare quelli indicati dal suo upgrade guide.

- [ ] **Step 2: Scrivere i test dell'audit**

`tests/Feature/Audit/AuditLogTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Filament\Pages\ManageGeneral;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

it('logs user creation without the password', function () {
    $actor = userWith(Permission::UsersCreate);
    $this->actingAs($actor);

    $user = User::factory()->create(['password' => 'secret-password-123']);

    $json = Activity::all()->toJson();

    expect(Activity::where('subject_id', $user->id)->exists())->toBeTrue()
        ->and($json)->not->toContain('secret-password-123')
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain('remember_token');
});

it('logs user updates without the password or its hash', function () {
    $user = User::factory()->create();
    $user->update(['name' => 'Nuovo Nome', 'password' => 'another-secret-456']);

    $json = Activity::all()->toJson();

    expect($json)->toContain('Nuovo Nome')
        ->and($json)->not->toContain('another-secret-456')
        ->and($json)->not->toContain('"password"')
        ->and($json)->not->toContain($user->fresh()->password);
});

it('logs status changes', function () {
    $user = User::factory()->create();
    $user->update(['status' => \App\Enums\UserStatus::Suspended]);

    expect(Activity::all()->toJson())->toContain('suspended');
});

it('logs role creation and deletion', function () {
    $role = Role::create(['name' => 'Operatore', 'guard_name' => 'web']);
    $role->delete();

    expect(Activity::where('subject_type', Role::class)->count())->toBeGreaterThanOrEqual(1);
});

it('logs a role assignment with old and new values', function () {
    Role::findOrCreate('Operatore', 'web');
    $target = User::factory()->create();

    app(\App\Actions\SyncUserRoles::class)->handle($target, ['Operatore']);

    $activity = Activity::where('event', 'roles.synced')->firstOrFail();

    expect($activity->properties['attributes'])->toBe(['Operatore'])
        ->and($activity->properties['old'])->toBe([]);
});

it('logs a settings change with old and new values', function () {
    $this->actingAs(userWith(Permission::SettingsGeneralUpdate));

    livewire(ManageGeneral::class)
        ->fillForm(['app_name' => 'Nuovo nome', 'support_email' => 'a@example.test'])
        ->call('save');

    $activity = Activity::where('event', 'settings.general.updated')->firstOrFail();

    expect($activity->properties['old']['app_name'])->toBe('Cockpit')
        ->and($activity->properties['attributes']['app_name'])->toBe('Nuovo nome');
});
```

`tests/Feature/Filament/ActivityResourceTest.php`:

```php
<?php

use App\Enums\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

it('denies the audit log without audit.view', function () {
    $this->actingAs(User::factory()->create())
        ->get('/admin/activities')
        ->assertForbidden();
});

it('shows the audit log with audit.view', function () {
    $this->actingAs(userWith(Permission::AuditView))
        ->get('/admin/activities')
        ->assertSuccessful();
});

it('never allows creating, editing or deleting activities', function () {
    $user = userWith(Permission::AuditView);
    $activity = Activity::create(['description' => 'test']);

    expect($user->can('create', Activity::class))->toBeFalse()
        ->and($user->can('update', $activity))->toBeFalse()
        ->and($user->can('delete', $activity))->toBeFalse();
});
```

Run: `docker compose exec app ./vendor/bin/pest tests/Feature/Audit tests/Feature/Filament/ActivityResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Registrare `User` con attributi espliciti**

In `app/Models/User.php`:

```php
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

// trait: aggiungere LogsActivity

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->useLogName('user')
        ->logOnly(['name', 'email', 'status'])
        ->logOnlyDirty()
        ->dontSubmitEmptyLogs();
}
```

L'elenco è esplicito: `password` e `remember_token` non vi compaiono e non devono mai esservi aggiunti.

- [ ] **Step 4: Estendere il modello `Role` per l'audit**

`app/Models/Role.php`:

```php
<?php

namespace App\Models;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('role')
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
```

In `config/permission.php` impostare `'models' => ['role' => App\Models\Role::class, ...]`. Sostituire in `AppServiceProvider` e nelle risorse/azioni/test gli import `Spatie\Permission\Models\Role` con `App\Models\Role` (policy inclusa: `Gate::policy(Role::class, RolePolicy::class)`). Eseguire `make test` per verificare che nulla si sia rotto.

- [ ] **Step 5: Log delle impostazioni**

In `ManageGeneral`, catturare i valori prima e dopo il salvataggio:

```php
use App\Settings\GeneralSettings;

protected array $settingsBefore = [];

protected function beforeSave(): void
{
    $this->settingsBefore = app(GeneralSettings::class)->toArray();
}

protected function afterSave(): void
{
    activity('settings')
        ->event('settings.general.updated')
        ->withProperties([
            'old' => $this->settingsBefore,
            'attributes' => app(GeneralSettings::class)->toArray(),
        ])
        ->log('Impostazioni generali aggiornate');
}
```

Se `beforeSave`/`afterSave` non esistono con questi nomi nella pagina di impostazioni del plugin, usare gli hook equivalenti indicati dalla documentazione del plugin (il test `logs a settings change` è l'oracolo).

- [ ] **Step 6: Policy e risorsa di sola lettura**

`app/Policies/ActivityPolicy.php`:

```php
<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->can(Permission::AuditView->value);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
```

In `AppServiceProvider::boot()`: `Gate::policy(Activity::class, ActivityPolicy::class);`.

Generare la risorsa: `docker compose exec app php artisan make:filament-resource Activity --model=Spatie\\Activitylog\\Models\\Activity` e ridurla a sola lettura: solo la pagina `ListActivities`, nessuna azione di modifica o eliminazione, `canCreate()` che restituisce `false`, tabella con colonne `created_at` (ordinamento decrescente predefinito), `causer.name`, `log_name`, `event`, `description`, `subject_type`, `subject_id`, e `properties` mostrata come testo.

- [ ] **Step 7: Verificare, lint e commit**

Run: `make test && make lint`
Expected: PASS.

```bash
git add -A
git commit -m "feat: add audit log with explicit attributes and read-only resource

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Documentazione e verifica finale

**Files:**
- Modify: `README.md`, `docs/architecture/overview.md`, `docs/glossary.md` (solo se compaiono termini nuovi), questo piano (sezione "Note emerse")

**Interfaces:**
- Consumes: tutto il sistema.
- Produces: README eseguibile da un clone pulito; `overview.md` con moduli e flussi reali.

- [ ] **Step 1: Aggiornare il README della radice**

Aggiungere una sezione "Avvio rapido" con i comandi esatti verificati:

```bash
git clone <repo> && cd cockpit
make up
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan cockpit:create-admin tu@example.test --name="Il tuo nome"
```

Aprire `http://localhost:8080/admin`. Aggiungere anche `make test` e `make lint`.

- [ ] **Step 2: Scrivere `docs/architecture/overview.md`**

Sostituire i segnaposto con: i moduli reali (Utenti, Ruoli, Impostazioni, Audit log e le azioni di dominio), i due flussi principali (creazione utente con assegnazione ruolo; modifica di un'impostazione con validazione e audit) e lo stack con rimando agli ADR. Descrivere solo ciò che esiste nel codice.

- [ ] **Step 3: Verificare i criteri di successo della spec**

Da un clone pulito (cartella nuova, senza `.env` né `vendor`):

1. `make up` → il pannello risponde su `http://localhost:8080/admin` (criterio 1).
2. Creare un admin con `cockpit:create-admin`, accedere, creare un utente, assegnargli un ruolo, sospenderlo, verificare che perda subito l'accesso (criterio 2; coperto da `PanelAccessTest`).
3. Verificare con un utente privo di `users.update` che `/admin/users/{id}/edit` risponda 403 (criterio 3; coperto da `UserResourceTest`).
4. Verificare l'assenza di `hasRole(` e `Gate::before` nel codice di dominio:

   Run: `grep -rn "hasRole(\|Gate::before" app/`
   Expected: nessun risultato.
5. Modificare utente, ruolo, permessi e impostazione e controllare che compaiano in `/admin/activities`, senza password (criterio 5; coperto da `AuditLogTest`).
6. `make test && make lint` (criterio 6).

- [ ] **Step 4: Compilare "Note emerse" in questo piano**

In fondo a questo file, sotto il titolo `## Note emerse`, elencare ogni differenza di API incontrata rispetto agli esempi (Spatie permission 8, activitylog 5, Filament 5, Pest 5), con la correzione applicata.

- [ ] **Step 5: Commit finale**

```bash
git add -A
git commit -m "docs: add quick start and architecture overview

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>"
```

---

## Note emerse

Differenze rispetto agli esempi del piano, emerse durante l'esecuzione, raggruppate per area.

**spatie/laravel-permission 8.3**
- Tag di pubblicazione: `permission-migrations` e `permission-config` (non `laravel-permission-*`).
- `name` di Role/Permission accetta un `BackedEnum`; `givePermissionTo`, `syncRoles`, `findOrCreate`, `User::permission()` invariati.
- `syncPermissions` con nomi stringa richiede che i permessi esistano: `UpdateRolePermissions` fa `findOrCreate` su ciascun nome (già validato contro l'enum) prima di sincronizzare.
- Dopo una transazione annullata la cache del `PermissionRegistrar` resta sporca: `LastRolesManagerGuard` chiama `forgetCachedPermissions()` in caso di eccezione.

**spatie/laravel-activitylog 5.1**
- Namespace: `Spatie\Activitylog\Support\LogOptions` e trait `Spatie\Activitylog\Models\Concerns\LogsActivity`.
- `dontSubmitEmptyLogs()` diventa `dontLogEmptyChanges()`.
- I cambi automatici del modello (old/new) stanno nella colonna `attribute_changes`; `properties` contiene solo i dati di `withProperties()`. I cast enum sono registrati come valore (`suspended`). L'evento `deleted` ha solo `old`.
- Tag di pubblicazione: `activitylog-migrations` e `activitylog-config`; la tabella è `activity_log`.
- `activity()->performedOn()->event()->withProperties()->log()` e `causedBy()` invariati.

**Filament 5.9**
- Azioni di riga con `->recordActions([...])` e bulk con `->toolbarActions()` (non `actions()`); `Filament\Actions\Action`, `Filament\Schemas\Schema` per i form, componenti in `Filament\Forms\Components`.
- `Action::authorize('ability')` nasconde l'azione e ne impedisce il mount.
- Test: `TestAction::make('x')->table($record)`; `callAction` verifica prima la visibilità, quindi i test negativi usano `assertActionHidden` più `mountAction`/`callMountedAction`; il bulk delete si verifica con `getTable()->getBulkActions()`.
- L'harness `livewire()` non esegue i middleware del pannello: il caso "sospeso dopo l'autenticazione" è testato via HTTP reale sull'URL `Livewire::getUpdateUri()` con header `X-Livewire` (route `default-livewire.update`).
- `make:filament-resource` usa `--model-namespace=... --not-embedded --record-title-attribute=name`; `make:filament-settings-page` richiede il FQCN della classe settings e `--panel=admin`.
- Il provider generato usa `PreventRequestForgery` (Laravel 13); `filament:install` aggiunge `filament:upgrade` a `post-autoload-dump` e voci a `.gitignore`.
- Pagina impostazioni: base `Filament\Pages\SettingsPage`, hook `beforeValidate/afterValidate/beforeSave/afterSave`, salvataggio in transazione; l'istanza dei settings è cachata nel container (nei test usare `->refresh()`).

**spatie/laravel-settings (plugin Filament)**
- Il tag `settings` non esiste: si pubblicano `migrations` e `config`.

**Pest 5 e test**
- `phpunit/phpunit` rimosso da `require-dev` (arriva in modo transitivo con Pest 5).
- Senza `FilamentUser` il risultato in ambiente di test è 403 per tutti, non 200 come nel RED atteso dal brief.

**Docker / Composer**
- `make` non era installato sull'host; il progetto compose omonimo di un vecchio stack andava rimosso. `composer install` da un clone pulito funziona senza `COMPOSER_HOME` dedicato.
- Rimossi i file Node dello skeleton (`package.json`, vite, `CLAUDE.md`, `AGENTS.md`) e gli script npm di `composer.json`.

**Deviazioni principali dal piano**
- `status` non è assegnabile in massa (`#[Fillable]` = name, email, password): `SuspendUser`/`ReactivateUser` usano `forceFill()->save()`; i test usano le azioni o `forceFill`.
- `SyncUserRoles::handle(User $actor, User $target, array $roleNames)`: l'actor è obbligatorio e deve avere `roles.manage`; il causer dell'audit è l'actor.
- La voce `roles.synced`/`permissions.synced` è scritta dentro la transazione della guardia e saltata se l'insieme non cambia.
- Sottoclasse `App\Models\Role` e `Amministratore` come unico ruolo del seeder.
- Cambio password: voce `password.changed` priva di valori; audit delle impostazioni con elenco esplicito di chiavi.
- `cockpit:create-admin`: utente, ruolo e voce di audit in una sola transazione.
