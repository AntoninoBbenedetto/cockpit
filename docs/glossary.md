# Glossario

Termini di dominio usati nel codice, nelle ADR e nelle spec. Un termine va
qui la prima volta che rischia di essere interpretato in modo diverso da
persone diverse (o da codice e documentazione) — non è un dizionario
generico di Laravel/Filament.

| Termine | Definizione |
|---|---|
| **Utente** | Persona con un account che può accedere al pannello di amministrazione. Ha uno stato e uno o più ruoli. |
| **Stato utente** | Condizione dell'account: *attivo* (può accedere) o *sospeso* (account conservato ma accesso negato). Non è una cancellazione. |
| **Ruolo** | Etichetta assegnata a un utente che raggruppa un insieme di permessi. Non concede accesso da sola: lo concedono i permessi che contiene. |
| **Permesso** | Singola autorizzazione a compiere un'azione su una risorsa (es. vedere o modificare gli utenti). È l'unità di controllo degli accessi: si verifica il permesso, non il nome del ruolo. Vedi [Architecture Principles](ARCHITECTURE_PRINCIPLES.md). |
| **Impostazione** | Valore di configurazione a livello di applicazione, con tipo e regole di validazione esplicite. Non è un valore libero in `.env` o in una tabella senza vincoli. |
| **Audit log** | Registro delle modifiche a utenti, ruoli, permessi e impostazioni, con chi le ha fatte e i valori prima/dopo. Non contiene mai password né hash. Consultabile in sola lettura nel pannello (`/admin/activities`): dal pannello non si può modificare né cancellare. Non c'è una protezione a livello di database. |

Regole:

- Un termine, una definizione. Se il codice usa una parola diversa dal
  glossario per lo stesso concetto, è un segnale di drift da correggere nel
  codice o nel glossario — non da ignorare.
- Se un termine ha un significato specifico definito in un ADR o in una
  spec, linka quel documento invece di duplicarne la definizione qui.
