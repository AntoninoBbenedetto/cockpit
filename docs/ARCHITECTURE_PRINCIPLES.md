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

## Esempio (rimuovi questa sezione quando aggiungi i principi reali)

## 0. Permessi a grana fine, non ruoli fissi

I dati gestiti includono informazioni sensibili: "admin vede tutto" non è un
modello accettabile di default.

**Perché:** il controllo degli accessi non può essere un dettaglio lasciato
allo scaffolding — va deciso esplicitamente per ogni risorsa (vedi
[ADR-000](adr/ADR-000-template.md) come traccia, da sostituire con l'ADR
reale quando la decisione viene formalizzata).

**Trade-off accettato:** più configurazione di permessi da mantenere rispetto
a un modello admin/utente binario. Accettabile perché il problema che il
progetto vuole dimostrare è proprio questo controllo.
