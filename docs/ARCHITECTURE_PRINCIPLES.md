# Architecture Principles

Questo documento spiega **perché** il sistema è costruito nel modo in cui è
costruito. Non descrive come il sistema funziona — per questo vedi
[architecture/overview.md](architecture/overview.md) — né registra singole
decisioni con le alternative considerate — per questo vedi [adr/](adr/).
Questo documento è il livello sopra entrambi: le convinzioni stabili che
rendono quelle decisioni prevedibili invece che arbitrarie.

## Come usare questo documento

Un principio va qui quando guida *più di una* decisione futura — non per
registrare una scelta isolata (quella è un ADR) e non per spiegare un
meccanismo (quello va in `architecture/overview.md`). Ogni principio segue
questo formato:

```
## N. Titolo del principio

Una o due frasi che enunciano il principio.

**Perché:** la motivazione — quale problema concreto evita o quale priorità
riflette (vedi anche l'ADR correlato, se esiste).

**Trade-off accettato:** cosa si rinuncia esplicitamente accettando questo
principio, e perché è accettabile adesso.
```

## 1. Permessi a grana fine, non ruoli fissi

I dati gestiti includono informazioni sensibili: "admin vede tutto" non è un
modello accettabile di default. L'accesso si decide per permesso, non per
nome del ruolo.

**Perché:** il controllo degli accessi non può essere un dettaglio lasciato
allo scaffolding: va deciso esplicitamente per ogni risorsa. Vedi anche le
definizioni di *Ruolo* e *Permesso* nel [glossario](glossary.md).

**Trade-off accettato:** più configurazione di permessi da mantenere
rispetto a un modello admin/utente binario. Accettabile perché il problema
che il progetto vuole dimostrare è proprio questo controllo.

## 2. La complessità si introduce quando il problema la giustifica, non prima

Non si aggiunge un livello, un'API o un'astrazione per un bisogno che non
esiste ancora. Quando il bisogno compare, si introduce allora.

**Perché:** ogni componente in più va mantenuto, spiegato e tenuto coerente
col resto. Per questo non esiste un'API REST separata per l'admin: senza un
consumer reale sarebbe solo complessità (vedi
[ADR-001](adr/ADR-001-filament-per-admin-panel.md)).

**Trade-off accettato:** se il bisogno compare, il costo di introdurre
quel componente si paga in un secondo momento, e può essere più alto che
averlo previsto. Accettabile finché il bisogno resta ipotetico.

## 3. Scaffolding per ciò che è standard, intervento manuale dove servono giudizio e controllo

Per ogni parte del sistema si decide esplicitamente se prenderla così com'è,
personalizzarla o non costruirla perché il problema non lo richiede. Il CRUD
standard resta allo scaffolding; ciò che riguarda dati sensibili o regole di
dominio si controlla a mano.

**Perché:** il valore del progetto non sta nel generare un pannello, ma nel
giudizio su dove lo scaffolding basta e dove no. Vedi
[ADR-001](adr/ADR-001-filament-per-admin-panel.md).

**Trade-off accettato:** le parti personalizzate sono codice da mantenere e
da testare, mentre lo scaffolding si aggiorna con il framework. Accettabile
solo dove la personalizzazione protegge qualcosa che conta.

## 4. I dati si validano ai confini, in modo esplicito

I valori che entrano nel sistema, in particolare le impostazioni, hanno tipo
e regole di validazione dichiarati. Non sono valori liberi in un `.env` o in
una tabella senza vincoli.

**Perché:** una configurazione non validata sposta l'errore da un controllo
immediato a un malfunzionamento a runtime, spesso lontano dalla causa. Vedi
la definizione di *Impostazione* nel [glossario](glossary.md).

**Trade-off accettato:** ogni nuova impostazione richiede di definire tipo e
regole prima di poterla usare. Accettabile perché quel costo evita valori
non validi in produzione.

## 5. Le azioni sensibili sono tracciabili

Le azioni che modificano utenti, ruoli, permessi o impostazioni lasciano
traccia: chi ha fatto cosa e quando.

**Perché:** con dati sensibili e permessi a grana fine, poter ricostruire
cosa è successo è parte del controllo degli accessi, non un extra.

**Trade-off accettato:** più scritture e un volume di log da gestire, con
una politica di conservazione ancora da definire. Accettabile perché
l'alternativa è non poter rispondere a "chi ha modificato questo".
