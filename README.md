# cockpit
Admin panel con Laravel + Filament: gestione utenti, ruoli e impostazioni — scaffolding rapido senza sacrificare controllo sui dati.

## Avvio rapido

Requisiti: Docker con Compose e `make`.

```bash
git clone <repo> && cd cockpit
make up
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan cockpit:create-admin tu@example.test --name="Il tuo nome"
```

`make up` crea `.env` da `.env.example` se manca e avvia app, web, database
PostgreSQL e Mailpit. Il comando `cockpit:create-admin` chiede la password
(minimo 12 caratteri) e crea il primo utente con il ruolo Amministratore.

Il pannello è su <http://localhost:8080/admin>, Mailpit su
<http://localhost:8025>. Le porte si cambiano con `WEB_PORT` e `MAILPIT_PORT`
in `.env` (da impostare prima di `make up` se 8080 o 8025 sono occupate).

```bash
make test   # suite Pest
make lint   # Pint (--test) e PHPStan/Larastan
make down   # ferma i container
```

## Documentazione

Decisioni architetturali, principi e panoramica del sistema sono in
[docs/](docs/README.md).
