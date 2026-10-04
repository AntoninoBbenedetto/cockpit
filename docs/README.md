# Documentazione

Indice della documentazione tecnica di Cockpit. Questa cartella esiste per
separare tre cose che altrimenti finiscono mescolate nel codice o perse in
una chat: **perché** il sistema è fatto così, **come** funziona oggi, e
**quali decisioni puntuali** sono state prese e perché.

## Dove va cosa

- **[ARCHITECTURE_PRINCIPLES.md](ARCHITECTURE_PRINCIPLES.md)** — le
  convinzioni stabili che rendono le decisioni prevedibili invece che
  arbitrarie. Non descrive il funzionamento del sistema né singole
  decisioni: è il livello sopra entrambi.
- **[adr/](adr/)** — una decisione architetturale per file (*Architecture
  Decision Record*): contesto, decisione, conseguenze accettate. Usala
  quando una scelta è significativa e difficile da invertire (es. scelta di
  un framework, modello di autorizzazione, strategia di persistenza) — non
  per decisioni minori reversibili con un normale PR.
- **[architecture/overview.md](architecture/overview.md)** — come il
  sistema funziona oggi: moduli, flussi principali, stack. Si aggiorna
  quando l'architettura cambia, non quando cambia una riga di codice.
- **[glossary.md](glossary.md)** — i termini di dominio usati nel codice e
  nei documenti, con definizione univoca.

## Spec e piani di implementazione

Le spec di design (output della skill `brainstorming` per i cambiamenti
architetturali) e i piani di implementazione (output della skill
`writing-plans`) non vengono create a mano in anticipo: si generano al
primo uso in:

- `docs/superpowers/specs/YYYY-MM-DD-<argomento>-design.md`
- `docs/superpowers/plans/YYYY-MM-DD-<argomento>-plan.md`

Se in futuro esiste una spec o un ADR che è in conflitto con questo indice
o con il codice, vince il documento normativo (spec/ADR): va corretto il
documento che ha fatto drift, non riscritta la decisione.
