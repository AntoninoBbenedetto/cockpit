# Architecture Overview

Come il sistema funziona oggi. Si aggiorna quando cambia l'architettura
(nuovo modulo, nuovo confine, nuovo flusso principale), non a ogni commit —
per i cambiamenti di dettaglio basta il codice stesso.

Un solo pannello Filament (`admin`, su `/admin`). Le azioni di dominio in
`app/Actions` contengono le regole per ruoli, permessi dei ruoli, stato ed
eliminazione degli utenti. La creazione e la modifica degli utenti e la
creazione e la rinomina dei ruoli passano invece dalle risorse Filament. Le
voci di audit arrivano sia dalle azioni sia dagli eventi dei modelli
(`User` e `Role` usano `LogsActivity`), e dalla pagina `ManageGeneral`.

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
  anti-lockout: in una transazione (con lock advisory PostgreSQL) blocca solo
  il passaggio da 1 a 0 degli utenti attivi con `roles.manage`, e annulla la
  modifica con `LockoutException`. In più, non si può sospendere né eliminare
  sé stessi (policy e azioni).
- **Permessi** — enum `App\Enums\Permission` (`users.view`, `users.create`,
  `users.update`, `users.suspend`, `users.delete`, `roles.manage`,
  `settings.general.update`, `audit.view`), policy in `app/Policies`, seeder
  `RolesAndPermissionsSeeder` che crea i permessi e il ruolo `Amministratore`.
  Il codice verifica sempre permessi (`can()`), mai nomi di ruolo (vedi
  [ADR-002](../adr/ADR-002-permessi-a-grana-fine-spatie-e-policy.md)).
- **Comando `cockpit:create-admin`** — crea un utente con il ruolo
  `Amministratore` (si può rieseguire per aggiungerne altri). Valida prima
  l'email (formato e unicità), poi esegue il seeder; utente, ruolo e voce di
  audit stanno in un'unica transazione.

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
- **Nessun privilege-up su utenti.** `users.update`, `users.suspend` (anche
  per riattivare) e `users.delete` valgono solo su un utente i cui permessi
  (diretti e da ruoli) sono un sottoinsieme di quelli dell'actor, oppure se
  l'actor ha `roles.manage`. Un utente non può quindi gestire (né cambiarne
  email e password) chi ha permessi che lui non possiede. La pagina di
  modifica ri-autorizza `update` anche al salvataggio. Il cambio password
  resta registrato come `password.changed`, senza alcun valore.
- **Retention dell'audit log non definita**: le voci non vengono mai
  cancellate né archiviate; la politica è fuori scopo per ora.
- **Il seeder non tocca un `Amministratore` esistente**: assegna tutti i
  permessi solo quando crea il ruolo; i permessi modificati dal pannello
  restano come sono.
- **`cockpit:create-admin` può aggiungere amministratori dalla CLI**; la voce
  di audit ha causer nullo (nessun utente autenticato).
- Altri limiti minori:
  - la modifica dell'utente non è atomica con la sincronizzazione dei ruoli
    (stesso discorso per la modifica del ruolo);
  - la rinomina di un ruolo è registrata dall'evento del modello `Role`
    (registro `role`), non da una voce dedicata;
  - l'eliminazione di un ruolo è registrata due volte (evento del modello e
    voce `role.deleted`);
  - le modifiche ai permessi fatte dal seeder non sono registrate;
  - la guardia anti-lockout non copre i permessi assegnati direttamente a un
    utente (nessun percorso di interfaccia lo consente oggi);
  - un utente sospeso mantiene i permessi Spatie: l'accesso è negato dal
    controllo di accesso al pannello, non dalla rimozione dei permessi.
