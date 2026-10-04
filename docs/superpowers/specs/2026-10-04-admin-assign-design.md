# Permesso `admin.assign` e ruoli privilegiati — design

Data: 2026-10-04
Stato: bozza in revisione

## Obiettivo

Chi gestisce utenti e ruoli ma non è "l'amministratore" non deve poter assegnare o revocare accessi di livello amministrativo. Si introduce il permesso `admin.assign`, che spezza `roles.manage` in due livelli:

- `roles.manage`: assegnare e gestire ruoli ordinari;
- `admin.assign`: toccare tutto ciò che è privilegiato (assegnare o revocare ruoli privilegiati, modificarne i permessi, eliminarli, agire sugli utenti che li hanno).

`admin.assign` è un'autorizzazione in più, non un sostituto: chi ha `admin.assign` ma non `roles.manage` non gestisce comunque i ruoli.

## Vincoli

- Resta valido ADR-002: solo permessi, mai `hasRole()`, nessun `Gate::before`, nessun bypass per nome di ruolo.
- Le regole vivono nelle Action, dentro `LastRolesManagerGuard::protect`, non solo nei form.
- Nessuna voce di audit per i tentativi negati (come oggi).
- Complessità minima: nessuna colonna nuova, nessuna nuova tabella.

## Definizioni

- **Ruolo privilegiato**: ruolo che contiene `roles.manage` o `admin.assign`. Calcolato in un solo punto (`Role::isPrivileged()`).
- **Utente privilegiato**: utente con almeno un ruolo privilegiato. I permessi assegnati direttamente all'utente non sono considerati (limite noto già dichiarato).

## Modello

- Nuovo caso `Permission::AdminAssign = 'admin.assign'`.
- Nuova policy/guardia di dominio unica che risponde a "l'actor può toccare l'accesso privilegiato?" ovvero `can('admin.assign')`. Tutte le Action la usano, la regola non è duplicata.

## Regole per operazione

| Operazione | Serve `admin.assign` quando |
|---|---|
| `SyncUserRoles` | il diff (ruoli aggiunti o rimossi) contiene un ruolo privilegiato. Cambiare solo ruoli ordinari resta con `roles.manage`. |
| `UpdateRolePermissions` | il ruolo è già privilegiato, oppure il diff aggiunge o toglie `roles.manage` o `admin.assign`. |
| `DeleteRole` | il ruolo è privilegiato. |
| `UserPolicy::outranks` | il target è un utente privilegiato. La scorciatoia `roles.manage` diventa `admin.assign`; resta la regola del sottoinsieme di permessi. |

In caso di violazione: `AuthorizationException` con messaggio in italiano, nessuna modifica, nessuna voce di audit.

Anche la creazione di un ruolo con permessi privilegiati passa dallo stesso controllo (stessa regola del diff, partendo dall'insieme vuoto).

### Cambio di firma

`UpdateRolePermissions::handle` e `DeleteRole::handle` oggi usano `auth()->user()` e non verificano nulla. Prendono `User $actor` come primo argomento, come `SyncUserRoles`, e applicano la regola da sole.

## Installazioni esistenti

- Migrazione dati `grant_admin_assign_to_roles_managers`: `findOrCreate` del permesso `admin.assign` (guard `web`) e assegnazione a ogni ruolo che ha già `roles.manage`. Idempotente. `down()` rimuove solo il permesso.
- Nessuna voce di audit (riallineamento di sistema); va aggiunto ai limiti noti, come le modifiche del seeder.
- Il seeder continua ad assegnare tutti i permessi ad `Amministratore` solo alla creazione del ruolo, quindi anche `admin.assign` sulle installazioni nuove. Non riallinea se il ruolo esiste.
- Dopo la migrazione nulla cambia per gli admin attuali: la restrizione scatta solo per i ruoli futuri con `roles.manage` senza `admin.assign`.

## UI

- Form utente: i ruoli privilegiati sono disabilitati o nascosti se l'actor non ha `admin.assign`. È solo un aiuto: l'applicazione della regola resta nell'Action.
- `RoleForm`: stesso trattamento per le checkbox `roles.manage` e `admin.assign`.
- Modifica, sospensione ed eliminazione utente seguono `outranks` tramite policy, senza lavoro aggiuntivo.

## Audit

Invariato: `roles.synced`, `permissions.synced`, `role.deleted`.

## Test

- Policy: `outranks` con target privilegiato, con e senza `admin.assign`.
- Action: per ogni riga della tabella, caso negato e caso consentito; inoltre il diff solo su ruoli ordinari passa con `roles.manage`.
- Migrazione: idempotenza e assegnazione ai soli ruoli con `roles.manage`.
- Arch: `admin.assign` presente nell'enum e coperto dalla policy; le Action sensibili ricevono l'actor.

## Documentazione

- ADR-004 (`admin.assign` e ruoli privilegiati): precisa ADR-002 senza contraddirlo.
- Aggiornare `CLAUDE.md` (regola privilege-up) e `docs/architecture/overview.md`.
- Limiti noti: migrazione senza audit; permessi diretti non coperti.

## Fuori scope

- Flag `protected` sui ruoli o protezione per nome.
- Permessi separati per assegnare e revocare.
- Copertura dei permessi assegnati direttamente all'utente.
