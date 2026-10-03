# ADR-003: Ambiente di sviluppo con Docker Compose scritto a mano (PHP-FPM + Nginx)

Status: Accepted
Date: 2026-10-03

## Context

Cockpit è un progetto portfolio pensato per essere letto ed eseguito da
altri. L'ambiente di sviluppo deve essere riproducibile da un clone pulito e
usare lo stesso PostgreSQL dei test, perché permessi, JSON e vincoli reali
si comportano diversamente su un database diverso.

Si lavora su WSL2. Sulla macchina di sviluppo sono presenti Docker 29.4.0 e
Compose v5.1.1, mentre PHP e Composer non sono installati sull'host.

Alternative considerate:

- **Laravel Sail.** Ambiente Docker già pronto e documentato. Meno
  configurazione da mantenere, ma meno controllo e meno visibile come
  dimostrazione di competenza.
- **PHP, Composer e PostgreSQL installati direttamente su WSL.** Più veloce e
  senza Docker, ma l'ambiente dipende dalla macchina e il README deve
  spiegare i prerequisiti.
- **FrankenPHP o Laravel Octane**, un solo container per server e PHP.
  Più semplice e più veloce, ma meno comune e con più parti specifiche. Per
  un pannello admin le prestazioni non servono (principio 2).

## Decision

Usiamo **Docker Compose scritto a mano**, con **PHP-FPM e Nginx** in
container separati.

Servizi:

- `app`: build da `docker/php/Dockerfile`, base `php:8.4-fpm-alpine`, con
  Composer ed estensioni PHP.
- `web`: `nginx:1.28-alpine`, serve `public/` e inoltra ad `app`.
- `db`: `postgres:18-alpine`, con volume nominato.
- `mailpit`: intercetta le email in sviluppo.

Scelte collegate:

- Sull'host non ci sono PHP né Composer: tutti i comandi passano dai
  container, accorciati da un `Makefile` con pochi target.
- Il codice è montato come bind mount. Il container `app` usa un utente non
  root con UID/GID configurabili da `.env`.
- Due database: `cockpit` per lo sviluppo e `cockpit_test` per i test.
- Nessun servizio Node, Redis o code finché non serve (principio 2).
- Credenziali in `.env` non versionato, con un `.env.example` committato.

## Consequences

**Positive:**
- L'ambiente è riproducibile: chi clona il repository ha lo stesso setup e lo
  stesso PostgreSQL di test e produzione.
- Controllo totale su immagini, estensioni e configurazione, e una
  configurazione leggibile da chi valuta il progetto.
- PHP-FPM + Nginx è la configurazione standard e più documentata per
  Laravel.

**Negative / accepted trade-offs:**
- Dockerfile, `compose.yaml` e configurazione Nginx sono da mantenere e
  aggiornare a mano (immagini, estensioni PHP), cosa che Sail eviterebbe.
- Il bootstrap iniziale non è standard: senza PHP sull'host non c'è
  `laravel new`, quindi si usa `composer create-project` in un container
  temporaneo.
- Dipendenza da Docker su ogni macchina di sviluppo. Il progetto sta nel file
  system Linux di WSL: se venisse spostato su un file system montato da
  Windows, le prestazioni dei bind mount peggiorerebbero.

**Revisit if:**
- la manutenzione della configurazione Docker diventa un costo ricorrente
  senza un beneficio dimostrativo, nel qual caso si passa a Sail;
- serve un servizio aggiuntivo (Redis, code, Node) che rende la
  configurazione a mano sproporzionata;
- le prestazioni dell'ambiente in sviluppo diventano un problema concreto.
