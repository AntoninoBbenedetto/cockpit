# ADR-002: Permessi a grana fine con spatie/laravel-permission e policy scritte a mano

Status: Accepted
Date: 2026-10-03

## Context

Cockpit gestisce dati sensibili. Il principio 1 di
[Architecture Principles](../ARCHITECTURE_PRINCIPLES.md) stabilisce che
"admin vede tutto" non è un modello accettabile: l'accesso si decide per
permesso, non per nome del ruolo, e va definito esplicitamente per ogni
risorsa.

Serve quindi un modello di autorizzazione che sia:

- a grana fine, con permessi assegnati ai ruoli e ruoli assegnati agli
  utenti;
- verificabile con test, perché dimostrare che il controllo degli accessi
  funziona è uno degli obiettivi del progetto;
- coerente con il principio 3: il lavoro standard (tabelle, assegnazione,
  cache) allo scaffolding, la parte che protegge i dati sensibili scritta a
  mano.

Alternative considerate:

- **`spatie/laravel-permission` + policy Laravel scritte a mano.** Il package
  gestisce tabelle, assegnazione e cache dei permessi. Le policy per
  ogni risorsa restano nostre.
- **`spatie/laravel-permission` + Filament Shield.** Shield genera
  automaticamente permessi e policy per ogni risorsa. È più veloce, ma
  permessi e policy generati sono scaffolding proprio dove il progetto vuole
  mostrare giudizio.
- **Solo policy e gate Laravel, senza package.** Nessuna dipendenza, ma
  tabelle, assegnazione e cache dei permessi andrebbero costruite a mano:
  lavoro ripetitivo e non differenziante (principio 2).

## Decision

Usiamo **`spatie/laravel-permission`** per ruoli e permessi, con **policy
Laravel scritte a mano**, una per risorsa.

Regole del modello:

- I permessi hanno il formato `risorsa.azione` (per esempio `users.update`)
  e sono definiti in un enum PHP, versionato nel codice.
- Il codice controlla solo permessi (`$user->can(...)`), mai ruoli
  (`hasRole()`). Un ruolo è un contenitore di permessi e non concede accesso
  da solo.
- Non esiste un super-admin con bypass (`Gate::before`). Il ruolo di partenza
  riceve tutti i permessi, ma assegnati esplicitamente.
- I permessi vivono nel codice e un seeder li sincronizza. I ruoli si creano
  e si modificano dal pannello, protetti dal permesso `roles.manage`.
- Protezioni anti-lockout: non si può sospendere né eliminare sé stessi, e
  non si può togliere `roles.manage` all'ultimo utente che lo possiede.
- Un solo guard, `web`.

## Consequences

**Positive:**
- Il controllo degli accessi è esplicito e leggibile: ogni metodo di policy
  corrisponde a un permesso, e si legge nel codice cosa protegge.
- Le policy sono testabili una per una (accesso negato senza permesso,
  concesso con il permesso).
- Tabelle, assegnazione e cache dei permessi sono delegate a un package
  diffuso, senza reinventarle.
- L'assenza di un bypass rende vero il principio 1: nessun utente ha accesso
  implicito.

**Negative / accepted trade-offs:**
- Più codice da scrivere e mantenere rispetto a una soluzione generata: ogni
  nuova risorsa richiede una policy e i suoi permessi.
- Ruoli modificabili dal pannello permettono di concedere permessi per errore.
  Mitigazione: l'audit log registra ogni modifica e i test sulle policy
  verificano che un permesso mancante neghi l'accesso.
- Senza bypass, una policy dimenticata o sbagliata può bloccare anche chi
  dovrebbe avere accesso. L'anti-lockout copre solo `roles.manage`.
- Dipendenza da `spatie/laravel-permission`, in una major recente (8.x): le
  istruzioni trovate online possono riferirsi a versioni precedenti.

**Revisit if:**
- il numero di risorse e di permessi rende la scrittura manuale delle policy
  una fonte ricorrente di errori o di ritardi;
- serve un'autorizzazione basata su attributi o su proprietà dell'oggetto
  (per esempio "solo i propri record") che il modello ruolo-permesso non
  esprime bene;
- compare più di un guard di autenticazione.
