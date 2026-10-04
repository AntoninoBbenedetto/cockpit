# CLAUDE.md

Cockpit: admin panel Laravel 13 + Filament 5 (utenti, ruoli/permessi, impostazioni, audit log). Progetto portfolio pensato per essere letto oltre che eseguito.

Stack: PHP 8.4 (solo in Docker), Laravel 13, Filament 5 / Livewire 4, PostgreSQL 18, spatie/laravel-permission, activitylog e settings, Pest 5, Larastan (livello 5, solo `app/`), Pint. Niente Node/Vite, niente API REST. CI: GitHub Actions (`.github/workflows/ci.yml`) lancia Pint, Larastan e Pest su PR verso `main` e push su `main`, con gli stessi comandi del Makefile ma su runner nativo (non Docker): tenere allineate le versioni di PHP e Postgres a `docker/php/Dockerfile` e `compose.yaml`.

## Comandi

PHP e Composer **non sono sull'host**: tutto passa dai container (`docker compose exec app ...`).

```
make up        # crea .env da .env.example se manca, avvia i container
make down
make shell     # sh nel container app
make test      # pest
make lint      # pint --test + phpstan (non scrive)
```

- Singolo test: `docker compose exec app ./vendor/bin/pest tests/Feature/Audit/AuditLogTest.php` (o `--filter="..."`).
- Correggere lo stile: `docker compose exec app ./vendor/bin/pint`.
- Setup da clone pulito: `make up`, poi `composer install`, `php artisan key:generate`, `php artisan migrate`, `php artisan cockpit:create-admin tu@example.test --name="Nome"` (tutti via `docker compose exec app`).
- Pannello: http://localhost:8080/admin. Mailpit: :8025. Porte in `.env` (`WEB_PORT`, `MAILPIT_PORT`).

## Architettura (`app/`)

- `Models/`: `User` (FilamentUser, HasRoles, LogsActivity; `status` non è mass-assignable), `Role`.
- `Enums/`: `Permission` (fonte di verità dei permessi, formato `risorsa.azione`), `UserStatus`.
- `Actions/`: regole di dominio (`SuspendUser`, `DeleteUser`, `SyncUserRoles`, `UpdateRolePermissions`, `DeleteRole`, ...), metodo `handle()`.
- `Policies/`: `UserPolicy`, `RolePolicy`, `ActivityPolicy`, registrate a mano con `Gate::policy` in `AppServiceProvider`.
- `Filament/`: un solo pannello `admin` (`Providers/Filament/AdminPanelProvider`); risorse `Users`, `Roles`, `Activities` (sola lettura) con struttura `Resource` + `Pages/`, `Schemas/`, `Tables/`; pagina `ManageGeneral`.
- `Settings/GeneralSettings`: gruppo `general` (`app_name`, `support_email`).
- `Console/Commands/CreateAdminUser`: `cockpit:create-admin`, rieseguibile per altri admin.
- Un solo guard (`web`). Rotte custom solo `/` e `/up`.

## Regole da non violare

- **Solo permessi, mai ruoli**: usare `$user->can(Permission::X->value)`. Mai `hasRole()`, mai `Gate::before`, nessun super-admin con bypass (ADR-002).
- **Mutazioni sensibili** (ruoli, permessi, sospensione, eliminazione) vivono in `app/Actions` e vanno dentro `LastRolesManagerGuard::protect(Closure)` (anti-lockout con advisory lock Postgres): la voce di audit si scrive nella stessa transazione. Il blocco scatta solo sul passaggio da 1 a 0 utenti attivi con `roles.manage`.
- **Privilege-up**: `UserPolicy::outranks` — update/suspend/delete su un utente solo se i suoi permessi sono un sottoinsieme di quelli dell'actor, oppure l'actor ha `roles.manage`. Suspend/delete su sé stessi vietati. Assegnare ruoli richiede `roles.manage`, verificato in `SyncUserRoles`, non solo nel form. `roles.manage` equivale di fatto ad amministrazione completa (scelta dichiarata).
- **Audit log**: `logOnly` esplicito, mai `logAll`; password e `remember_token` mai nei log. Log name `rbac` (`roles.synced`, `permissions.synced`, `role.deleted`) e `settings`. Nessuna voce se l'insieme non cambia. Le voci non sono modificabili/eliminabili (policy).
- **Nuova risorsa o permesso**: caso in `Permission` → policy scritta a mano → registrazione in `AppServiceProvider` → test di policy. `UpdateRolePermissions` rifiuta nomi non presenti nell'enum.
- **Seeder idempotente**: `RolesAndPermissionsSeeder` assegna tutti i permessi ad `Amministratore` solo quando crea il ruolo; non riallinea se esiste già.
- **Sospensione**: l'utente sospeso conserva i permessi; l'accesso è negato da `User::canAccessPanel` (status `active`).
- Password: minimo 12 caratteri (form utente e `cockpit:create-admin`).
- Complessità solo se il problema la giustifica (`docs/ARCHITECTURE_PRINCIPLES.md`): niente code, Redis, API, i18n finché non servono.

## Test

- Pest 5 + plugin Livewire: nei test Filament usare `livewire(...)`. Helper globale `userWith(Permission ...$permissions)` in `tests/Pest.php`. `RefreshDatabase` su tutta `tests/Feature`.
- `tests/Arch`: regole architetturali (Pest arch + scansione sorgenti + cablaggio policy/permessi), girano con `make test`. Una nuova regola da non violare va codificata lì.
- I test girano solo nel container (PostgreSQL host `db`, database `cockpit_test`). Il DB di test lo crea `docker/postgres/init-test-db.sh` solo alla prima inizializzazione del volume `db-data`: se manca va creato a mano.

## Convenzioni

- In **italiano**: documentazione, testi UI, messaggi di dominio/eccezioni, descrizioni di audit, commenti nel codice.
- In **inglese**: identificatori, nomi dei test, messaggi di commit (conventional: `feat:`, `fix:`, `docs:`).
- Formattazione: `.editorconfig` + Pint (stile Laravel di default, nessun `pint.json`).

## Documentazione

- Indice in `docs/README.md`. Se un documento contraddice il codice o un altro documento, vincono spec/ADR: correggere quello che ha fatto drift.
- `docs/architecture/overview.md`: aggiornare quando cambia l'architettura, non a ogni commit.
- ADR in `docs/adr/` (`ADR-NNN-titolo-kebab.md`, numeri mai riusati, template `ADR-000`): solo per decisioni difficili da invertire.
- Spec e piani in `docs/superpowers/specs|plans/` con data `YYYY-MM-DD`.

## Limiti noti

Modifica utente non atomica con la sync dei ruoli; un ruolo eliminato viene loggato due volte (evento del modello + `role.deleted`); le modifiche ai permessi fatte dal seeder non sono loggate; i permessi assegnati direttamente a un utente non sono coperti dall'anti-lockout.
