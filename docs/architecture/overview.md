# Architecture Overview

Come il sistema funziona oggi. Si aggiorna quando cambia l'architettura
(nuovo modulo, nuovo confine, nuovo flusso principale), non a ogni commit —
per i cambiamenti di dettaglio basta il codice stesso.

Un solo pannello Filament (`admin`, su `/admin`). Le risorse Filament sono
sottili: leggono e mostrano, ma ogni modifica passa da un'azione di dominio
in `app/Actions`, che è l'unico punto che applica regole e scrive l'audit.

## Moduli

- **Utenti** (`/admin/users`) — elenco, creazione, modifica, sospensione,
  riattivazione ed eliminazione. L'utente ha uno stato (`UserStatus`: attivo
  o sospeso); un utente sospeso perde subito l'accesso al pannello perché
  `canAccessPanel` viene rivalutato a ogni richiesta. `status` non è
  assegnabile in massa: cambia solo tramite le azioni `SuspendUser` e
  `ReactivateUser`. Non ci sono azioni di eliminazione in blocco.
- **Ruoli** (`/admin/roles`) — creazione, modifica dei permessi ed
  eliminazione di ruoli. Tutte le pagine richiedono `roles.manage`.
- **Impostazioni** (pagina `ManageGeneral`) — impostazioni generali tipizzate
  (`App\Settings\GeneralSettings`: `app_name`, `support_email`), con
  validazione nel form. Richiede `settings.general.update`.
- **Audit log** (`/admin/activities`) — risorsa di sola lettura sul registro
  delle attività, visibile con `audit.view`. Nessuna creazione, modifica o
  eliminazione dal pannello.
- **Azioni di dominio** (`app/Actions`): `SuspendUser`, `ReactivateUser`,
  `DeleteUser`, `SyncUserRoles(actor, target, ruoli)`,
  `UpdateRolePermissions`, `DeleteRole`, e `LastRolesManagerGuard`, la guardia
  anti-lockout: in una transazione (con lock advisory PostgreSQL) impedisce
  qualsiasi modifica che lasci il sistema senza utenti attivi con
  `roles.manage`, e annulla la modifica con `LockoutException`.
- **Permessi** — enum `App\Enums\Permission` (`users.view`, `users.create`,
  `users.update`, `users.suspend`, `users.delete`, `roles.manage`,
  `settings.general.update`, `audit.view`), policy in `app/Policies`, seeder
  `RolesAndPermissionsSeeder` che crea i permessi e il ruolo `Amministratore`.
  Il codice verifica sempre permessi (`can()`), mai nomi di ruolo (vedi
  [ADR-002](../adr/ADR-002-permessi-a-grana-fine-spatie-e-policy.md)).
- **Comando `cockpit:create-admin`** — crea il primo utente con il ruolo
  `Amministratore`; utente, ruolo e voce di audit stanno in un'unica
  transazione.

## Flussi principali

### Creare un utente e assegnargli un ruolo

1. L'actor apre `/admin/users/create` (richiede `users.create`).
2. Il campo dei ruoli è visibile solo se l'actor ha `roles.manage`. Senza,
   l'utente viene creato senza ruoli e il campo non può essere forzato:
   `SyncUserRoles` rifiuta con `AuthorizationException` un actor privo di
   `roles.manage`.
3. La password è obbligatoria (minimo 12 caratteri) e salvata come hash.
4. La creazione registra una voce di audit sul modello; se i ruoli sono
   stati assegnati, `SyncUserRoles` registra anche `roles.synced` con valori
   prima/dopo, nella stessa transazione della guardia anti-lockout. Se la
   modifica non cambia l'insieme dei ruoli, nessuna voce.

### Modificare un'impostazione

1. L'utente con `settings.general.update` apre la pagina Impostazioni.
2. Il form valida i campi (`app_name` obbligatorio, max 100 caratteri;
   `support_email` obbligatoria, email valida, max 255). Se la validazione
   fallisce non viene salvato né registrato nulla.
3. Il salvataggio avviene in transazione; viene scritta una voce di audit con
   i valori precedenti e nuovi delle sole chiavi `app_name` e
   `support_email`, e l'actor come causer.

## Stack

Versioni installate (da `composer.lock`):

| Componente | Versione | Riferimento |
|---|---|---|
| PHP | 8.4 (immagine `php:8.4-fpm-alpine`) | [ADR-003](../adr/ADR-003-ambiente-sviluppo-docker-compose.md) |
| Laravel | 13.34 | |
| Filament | 5.9 (+ `filament/spatie-laravel-settings-plugin` 5.9) | [ADR-001](../adr/ADR-001-filament-per-admin-panel.md) |
| spatie/laravel-permission | 8.3 | [ADR-002](../adr/ADR-002-permessi-a-grana-fine-spatie-e-policy.md) |
| spatie/laravel-activitylog | 5.1 | audit log |
| Pest | 5.3 | test |
| PHPStan (Larastan) / Pint | 2.2 (3.12) / 1.32 | `make lint` |
| PostgreSQL / Nginx / Mailpit | 18 / 1.28 / ultima | Docker Compose, [ADR-003](../adr/ADR-003-ambiente-sviluppo-docker-compose.md) |

Ambiente: `make up` avvia i servizi `app` (PHP-FPM), `web` (Nginx), `db` e
`mailpit`; i test usano un database PostgreSQL separato.

## Modello di sicurezza e limiti noti

- **`roles.manage` equivale, di fatto, ad amministrazione completa — per
  scelta.** Chi lo possiede può assegnare qualsiasi permesso a qualsiasi
  ruolo. È protetto dalla guardia anti-lockout, che impedisce di restare
  senza utenti attivi con questo permesso, ma non limita ciò che un suo
  titolare può concedere.
- **Assegnare ruoli richiede `roles.manage`.** Si riusa questo permesso
  invece di crearne uno dedicato; il controllo è applicato nell'azione
  `SyncUserRoles`, non solo nel form.
- **LIMITE NOTO, decisione pendente con il responsabile del progetto:
  `users.update` consente di cambiare email e password di QUALSIASI utente,
  inclusi i titolari di `roles.manage`** (presa di controllo dell'account).
  Di fatto `users.update` va trattato come un permesso amministrativo. Il
  cambio password viene registrato nell'audit come `password.changed`, senza
  alcun valore, quindi resta tracciabile.
- **Retention dell'audit log non definita**: le voci non vengono mai
  cancellate né archiviate; la politica è fuori scopo per ora.
- Altri limiti minori:
  - la modifica dell'utente non è atomica con la sincronizzazione dei ruoli
    (stesso discorso per la modifica del ruolo);
  - rinominare un ruolo non produce una voce di audit dedicata;
  - l'eliminazione di un ruolo è registrata due volte (evento del modello e
    voce `role.deleted`);
  - le modifiche ai permessi fatte dal seeder non sono registrate;
  - la guardia anti-lockout non copre i permessi assegnati direttamente a un
    utente (nessun percorso di interfaccia lo consente oggi);
  - un utente sospeso mantiene i permessi Spatie: l'accesso è negato dal
    controllo di accesso al pannello, non dalla rimozione dei permessi.
