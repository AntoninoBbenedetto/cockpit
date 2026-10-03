# Architecture Decision Records

Una ADR registra una decisione architetturale significativa e difficile da
invertire: il contesto in cui è stata presa, cosa è stato deciso, e le
conseguenze (incluse quelle negative) accettate consapevolmente.

## Quando scriverne una

Scrivi un ADR quando la decisione:

- è difficile o costosa da invertire in seguito, oppure
- stabilisce un vincolo che altre parti del codice daranno per scontato,
  oppure
- sacrifica esplicitamente qualcosa (performance, flessibilità, semplicità)
  a favore di qualcos'altro.

Non scrivere un ADR per decisioni reversibili con un normale PR, o per
dettagli implementativi senza impatto architetturale: quelli vanno nel
codice stesso o, se serve spiegarli, in `architecture/overview.md`.

## Come scriverne una

1. Copia [ADR-000-template.md](ADR-000-template.md).
2. Rinominalo `ADR-NNN-titolo-breve-in-kebab-case.md`, dove `NNN` è il
   prossimo numero libero (non riutilizzare numeri, anche se un ADR viene
   superseduto).
3. Compila tutte le sezioni. Una sezione "Consequences" senza lato negativo
   è quasi sempre incompleta: ogni decisione reale ha un trade-off.
4. Se l'ADR supera o modifica una decisione precedente, aggiorna lo status
   dell'ADR vecchio a `Superseded by ADR-NNN` invece di modificarne il
   contenuto.

## Status possibili

- `Proposed` — in discussione, non ancora vincolante.
- `Accepted` — in vigore.
- `Superseded by ADR-NNN` — sostituita da una decisione successiva.
- `Deprecated` — non più valida, senza un sostituto diretto.
