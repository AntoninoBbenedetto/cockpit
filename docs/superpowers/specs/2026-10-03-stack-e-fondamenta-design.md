# Cockpit: stack e fondamenta — Design

Data: 2026-10-03
Stato: In revisione

## 1. Intento

Cockpit è un progetto portfolio che mostra competenza nello stack Laravel,
Filament, Livewire e PostgreSQL. Vuole verificare con giudizio dove lo
scaffolding di Filament basta e dove serve intervenire a mano, soprattutto
perché i dati gestiti sono sensibili. Il repository è pensato per essere
letto oltre che eseguito.

Questa spec fissa **le fondamenta**: le librerie, l'ambiente di sviluppo e
il modello di permessi, impostazioni e audit log. Non descrive le funzioni
di prodotto oltre quanto serve a queste fondamenta.

**Criteri di successo:**

1. `make up` avvia l'ambiente da un clone pulito e il pannello risponde in
   locale.
2. Un utente può essere creato, ricevere un ruolo, essere sospeso e perdere
   subito l'accesso, anche con sessione aperta.
3. Un utente senza `users.update` non può modificare utenti, né
   dall'interfaccia né con una richiesta diretta.
4. Nessun codice di dominio usa `hasRole()` e nessun super-admin ha un
   bypass.
5. Ogni modifica a utenti, ruoli, permessi e impostazioni compare
   nell'audit log, senza password né dati riservati.
6. `make test` e `make lint` passano.

**Principi di riferimento:** [Architecture Principles](../../ARCHITECTURE_PRINCIPLES.md)
(in particolare 1, 2, 3, 4 e 5) e [ADR-001](../../adr/ADR-001-filament-per-admin-panel.md).
Termini usati: vedi il [glossario](../../glossary.md).

## 2. Fuori scope

Non fanno parte di questa spec. Si aggiungono quando il bisogno compare
(principio 2), con un ADR se la decisione è difficile da invertire:

- autenticazione a due fattori e SSO;
- code e Redis;
- CI;
- internazionalizzazione;
- multi-tenancy;
- un tema grafico personalizzato (e quindi un servizio Node/Vite);
- l'elenco concreto delle impostazioni;
- la politica di conservazione dei log di audit.

## 3. Stack e versioni

Versioni stabili verificate su Packagist il 2026-10-03. Sono stati verificati
i vincoli dichiarati dai pacchetti, non il funzionamento insieme: lo
verifica la prima installazione.

| Componente | Pacchetto | Versione | Requisito principale |
|---|---|---|---|
| Framework | `laravel/framework` | 13.x (13.34.0) | PHP ≥ 8.3 |
| Panel builder | `filament/filament` | 5.x (5.9.0) | Laravel 11–13, Livewire ^4.4.2 |
| Permessi | `spatie/laravel-permission` | 8.x (8.3.0) | Laravel 12–13, PHP ≥ 8.3 |
| Impostazioni | `spatie/laravel-settings` + `filament/spatie-laravel-settings-plugin` | 3.9.0 + 5.9.0 | Laravel 11–13 |
| Audit log | `spatie/laravel-activitylog` | 5.x (5.1.1) | Laravel 12–13, PHP ≥ 8.4 |
| Test | `pestphp/pest` + `pestphp/pest-plugin-livewire` | 5.3.0 + 5.0.0 | PHP ≥ 8.4, Livewire ^4.3.3 |
| Analisi statica | `larastan/larastan` | 3.12.x | Laravel 11–13 |
| Stile | `laravel/pint` | 1.32.x | PHP ≥ 8.3 |
| Database | PostgreSQL | 18 | — |

**PHP 8.4** è il vincolo più stretto, imposto da `activitylog` 5 e da Pest 5.

`spatie/laravel-permission` 8 e `spatie/laravel-activitylog` 5 sono major
recenti: prima di usarli si leggono i rispettivi upgrade guide, perché le
istruzioni trovate online possono riferirsi a versioni precedenti.

**Scelte di libreria scartate** (reversibili con un normale PR, quindi senza
ADR dedicato):

- Permessi: Filament Shield (genera permessi e policy come scaffolding, in
  contrasto con i principi 1 e 3); solo policy e gate senza package (lavoro
  ripetitivo su tabelle e cache).
- Impostazioni: tabella chiave/valore a mano (rischio di tornare a valori
  senza vincoli); `config/` e `.env` (non modificabili dal pannello).
- Audit log: plugin Filament di terze parti (dipendenza aggiuntiva sulla
  parte sensibile, non valutato nel dettaglio); observer scritti a mano
  (un audit log fatto male è peggio di nessuno).
- Test: PHPUnit puro (test più verbosi).

## 4. Ambiente di sviluppo

Docker Compose scritto a mano (ADR-003). Sull'host non ci sono PHP né
Composer: tutti i comandi passano dai container.

| Servizio | Immagine | Ruolo |
|---|---|---|
| `app` | build da `docker/php/Dockerfile`, base `php:8.4-fpm-alpine` | PHP-FPM con Composer ed estensioni |
| `web` | `nginx:1.28-alpine` | serve `public/` e inoltra ad `app` |
| `db` | `postgres:18-alpine` | PostgreSQL con volume nominato |
| `mailpit` | `axllent/mailpit` | intercetta le email in sviluppo |

Scelte:

- Codice montato come bind mount in `app` e `web`.
- Utente non root nel container `app`, con UID/GID configurabili da `.env`,
  per evitare file creati come root nel repository.
- Due database PostgreSQL: `cockpit` per lo sviluppo e `cockpit_test` per i
  test, creato all'avvio.
- Un `Makefile` con pochi target: `up`, `down`, `shell`, `test`, `lint`.
- Credenziali in `.env` (non versionato) e un `.env.example` committato con
  valori di sviluppo dichiaratamente non segreti.
- Nessun servizio Node: Filament non richiede una build frontend.
- Il bootstrap di Laravel usa `composer create-project` dentro un container
  temporaneo, perché sull'host non c'è `laravel new`. Il passo esatto è
  descritto nel piano di implementazione.

**Trade-off accettati:** configurazione Docker da mantenere e aggiornare a
mano (immagini, estensioni PHP), e un bootstrap iniziale non standard.

## 5. Permessi

- **Nomi nel formato `risorsa.azione`**: `users.view`, `users.update`,
  `users.suspend`, `roles.manage`, `settings.general.update`, `audit.view`. Sono
  definiti in un enum PHP (`app/Enums/Permission.php`), quindi versionati e
  usabili senza stringhe sparse.
- **Il codice controlla solo permessi**: `$user->can('users.update')`, mai
  `hasRole()`. Un ruolo è solo un contenitore di permessi.
- **Policy scritte a mano**, una per risorsa (`UserPolicy`, `RolePolicy`),
  in `app/Policies/` e registrate in Filament. Ogni metodo corrisponde a un
  permesso. Senza il permesso, l'accesso è negato.
- **Nessun super-admin con bypass** (`Gate::before`): sarebbe il modello
  "admin vede tutto" escluso dal principio 1. Il ruolo di partenza ha tutti
  i permessi, assegnati esplicitamente.
- **Permessi nel codice, ruoli modificabili dal pannello.** Un seeder
  sincronizza i permessi dall'enum. I ruoli si creano e si modificano
  dall'interfaccia, protetti da `roles.manage`.
- **Anti-lockout:** non si può sospendere né eliminare sé stessi, e non si
  può togliere `roles.manage` all'ultimo utente che lo possiede.
- **Utente sospeso:** `canAccessPanel()` nega l'accesso se lo stato non è
  attivo. Un middleware controlla anche le sessioni già aperte, così la
  sospensione ha effetto subito.
- **Guard:** un solo guard, `web`.

**Rischio:** ruoli modificabili dal pannello permettono di concedere
permessi per errore. Mitigazione: l'audit log registra ogni modifica e i
test sulle policy verificano che un permesso mancante neghi l'accesso.

## 6. Impostazioni

- Una classe `Settings` tipizzata per gruppo in `app/Settings/`, con una
  pagina Filament dedicata. Si parte con un solo gruppo, `GeneralSettings`.
- Le regole di validazione sono dichiarate nella pagina Filament, in modo
  esplicito (principio 4).
- Un permesso per gruppo, per esempio `settings.general.update`, non un
  permesso unico per tutte le impostazioni.
- Il contenuto effettivo delle impostazioni non fa parte di questa spec.

**Trade-off accettato:** `spatie/laravel-settings` salva i valori come JSON
nel database e le classi sono la fonte di verità. Cambiare tipo o nome di
una proprietà richiede una migrazione delle impostazioni, non solo del
codice.

## 7. Audit log

- `spatie/laravel-activitylog` sui modelli `User` e `Role`, con **elenco
  esplicito degli attributi registrati**. Password, token e campi riservati
  restano fuori. È un requisito, non un dettaglio.
- Due casi che il package non copre da solo e che si registrano
  esplicitamente: le modifiche all'assegnazione di ruoli e permessi (tabelle
  pivot) e il salvataggio delle impostazioni, che non sono modelli
  Eloquent.
- Una risorsa Filament **di sola lettura** per consultare il log, protetta
  da `audit.view`, senza modifica né cancellazione dall'interfaccia.
- Politica di conservazione: punto aperto, fuori scope (vedi sezione 2).

## 8. Struttura del progetto

- Un solo pannello Filament (`AdminPanelProvider`) con tre aree (Utenti,
  Ruoli, Impostazioni) più l'audit log come risorsa di sola lettura.
- Cartelle standard di Laravel e Filament, senza moduli o layer aggiuntivi
  (principio 2). Le uniche aggiunte sono `app/Enums/`, `app/Policies/`,
  `app/Settings/` e `app/Actions/`.
- **Le regole di dominio vivono in azioni** (`app/Actions/`), non nelle
  risorse Filament. Operazioni come sospendere un utente, assegnare un ruolo
  o proteggere l'ultimo `roles.manage` si richiamano dalle risorse e si
  testano senza passare dall'interfaccia.
- Il codice vive alla radice del repository. `docs/` resta com'è. Docker in
  `docker/`, con `compose.yaml` e `Makefile` alla radice.

## 9. Test e qualità

Pest con il plugin Livewire, su PostgreSQL (`cockpit_test`) con
`RefreshDatabase`.

- **Policy:** per ogni risorsa, accesso negato senza permesso e concesso con
  il permesso.
- **Azioni di dominio:** sospensione, anti-lockout, nessuna auto-sospensione.
- **Pagine Filament:** la lista si carica, il form valida, un utente senza
  permesso non vede l'azione.
- **Audit:** i dati sensibili non finiscono nel log; le modifiche a ruoli e
  impostazioni vengono registrate.
- **Qualità:** `make lint` esegue Pint e Larastan, con livello iniziale 5,
  da alzare nel tempo.

## 10. Documentazione collegata

- **ADR-002:** permessi a grana fine con `spatie/laravel-permission` e
  policy scritte a mano.
- **ADR-003:** ambiente di sviluppo con Docker Compose scritto a mano
  (PHP-FPM + Nginx).
- Le altre scelte di libreria restano in questa spec, senza un ADR ciascuna,
  perché sono reversibili con un normale PR.
- `architecture/overview.md` si scrive dopo la prima installazione, quando
  ci sono moduli e flussi reali da descrivere.
