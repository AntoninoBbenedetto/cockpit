# ADR-004: Permesso `admin.assign` e ruoli privilegiati

Status: Accepted
Date: 2026-10-04

## Context

Con [ADR-002](ADR-002-permessi-a-grana-fine-spatie-e-policy.md)
`roles.manage` equivaleva, per scelta dichiarata, ad amministrazione
completa. Il problema pratico: chi lo riceve per gestire i ruoli ordinari
(per esempio un ruolo di assistenza) può anche declassare gli admin,
modificarne i ruoli o resettarne la password. Non esisteva modo di delegare
la gestione dei ruoli senza delegare l'intera amministrazione.

## Decision

Si introduce il permesso `admin.assign` (`Permission::AdminAssign`). Un
**ruolo privilegiato** è un ruolo che contiene `roles.manage` o
`admin.assign` (`Role::isPrivileged()`); un **utente privilegiato** ha
almeno un ruolo privilegiato (`User::hasPrivilegedRole()`). Serve
`admin.assign` per:

- assegnare o revocare ruoli privilegiati a un utente (conta solo il diff:
  cambiare ruoli ordinari resta con `roles.manage`);
- aggiungere o togliere `roles.manage`/`admin.assign` a un ruolo, o
  modificare un ruolo che li contiene già (anche alla creazione di un ruolo);
- eliminare un ruolo privilegiato;
- modificare, sospendere o eliminare un utente privilegiato
  (`UserPolicy::outranks`: senza `admin.assign` un utente privilegiato è
  negato anche se i suoi permessi sono un sottoinsieme di quelli
  dell'actor).

La regola vive in `PrivilegedAccessGuard` ed è chiamata dalle Action
(`SyncUserRoles`, `UpdateRolePermissions`, `DeleteRole`) dentro
`LastRolesManagerGuard::protect`; le Action sensibili prendono `User $actor`
come primo argomento (verificato da un test di architettura). In caso di
violazione si lancia `AuthorizationException`, senza modifiche né voci di
audit. Una migrazione dati assegna `admin.assign` ai ruoli che hanno già
`roles.manage`, così gli admin esistenti non cambiano.

Precisa ADR-002 senza contraddirlo: restano solo permessi, mai nomi di
ruolo, nessun bypass.

Alternative scartate:

- protezione per nome del ruolo: aggirabile con un ruolo clone, e viola
  ADR-002;
- flag `protected` sul ruolo: richiede migrazione e stato in più;
- permessi separati per assegnare e per revocare: complessità non
  giustificata.

## Consequences

**Positive:**
- `roles.manage` non è più amministrazione completa: si può delegare la
  gestione dei ruoli ordinari.
- La regola è in un solo punto e vale anche fuori dal pannello (è nelle
  Action, non nei form).

**Negative / accepted trade-offs:**
- `admin.assign` da solo non gestisce i ruoli: serve anche `roles.manage`.
  L'amministrazione completa è la coppia dei due permessi.
- `admin.assign` insieme a `users.update` (senza `roles.manage`) equivale di
  fatto ad amministrazione completa: può reimpostare la password di un
  amministratore. Va quindi concesso con questa consapevolezza; un ruolo del
  genere può crearlo solo chi ha `admin.assign`.
- L'ultimo titolare attivo di `admin.assign` non si può rimuovere: la guardia
  anti-lockout protegge entrambi i livelli (`roles.manage` e `admin.assign`),
  ciascuno con il proprio conteggio, altrimenti i ruoli privilegiati
  resterebbero intoccabili senza intervento sul database.
- Un utente può sempre modificare sé stesso (`outranks`), anche con un ruolo
  privilegiato e senza `admin.assign`: i ruoli passano comunque dalle Action,
  e sospendere o eliminare sé stessi resta vietato.
- La migrazione dati non scrive voci di audit.
- I permessi assegnati direttamente all'utente non sono coperti: non
  rendono privilegiato un utente (limite noto già dichiarato).
- Un ruolo privilegiato non è modificabile, nemmeno con un salvataggio senza
  cambiamenti, da chi non ha `admin.assign`.
- Nei form Filament le opzioni privilegiate non sono disabilitate né
  nascoste: in Filament 5 `disableOptionWhen` le toglie dalla regola di
  validazione `in`, e il form fallirebbe con un errore generico prima che la
  pagina possa intercettare il rifiuto. Il rifiuto arriva quindi dopo il
  tentativo, come notifica di errore; è la stessa Action a garantire che
  nulla venga modificato.

**Revisit if:** servono più di due livelli di privilegio, oppure i permessi
diretti all'utente diventano assegnabili da interfaccia (andrebbero allora
inclusi nella definizione di utente privilegiato e nell'anti-lockout).
