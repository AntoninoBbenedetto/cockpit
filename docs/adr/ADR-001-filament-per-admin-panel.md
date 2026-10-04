# ADR-001: Filament come panel builder per l'admin panel

Status: Accepted
Date: 2026-10-03

## Context

Cockpit deve offrire gestione utenti, ruoli/permessi e impostazioni di
sistema. È un problema standard: quasi ogni prodotto B2B ha bisogno delle
stesse cose, e scriverle da zero raramente è differenziante.

Il progetto ha due obiettivi che condizionano la scelta:

- **Verificare con giudizio dove lo scaffolding basta e dove serve
  intervenire a mano**, soprattutto perché i dati gestiti sono sensibili e
  il controllo degli accessi non può essere lasciato ai default.
- **Essere un progetto portfolio**: deve mostrare competenza nello stack
  Laravel, Filament, Livewire e PostgreSQL. Lo stack è quindi un obiettivo
  oltre che un mezzo, e il repository è pensato per essere letto oltre che
  eseguito.

Alternative considerate:

- **Admin panel custom** (Blade/Livewire scritto a mano): massimo controllo,
  ma form, tabelle, filtri e validazione si ripetono quasi identici in ogni
  progetto e non aggiungono valore dimostrativo.
- **Admin panel con API REST separata e frontend dedicato**: aggiunge un
  confine da mantenere in sync con il modello dati, senza un consumer reale
  che lo giustifichi.
- **Altri panel builder per Laravel** (es. Nova, Backpack): non valutati nel
  dettaglio. Filament è stato scelto perché resta dentro Laravel e Livewire,
  senza introdurre un framework parallelo.

Senza una decisione esplicita, il rischio è scegliere per inerzia e poi
scambiare i default di autorizzazione dello scaffolding per un modello di
sicurezza completo.

## Decision

Usiamo **Filament** come panel builder per l'interfaccia di amministrazione,
sopra Laravel e Livewire, con PostgreSQL come persistenza.

Non esiste un'API REST separata per l'admin: l'interattività è gestita lato
server da Livewire. Un'API verrà introdotta solo se compare un consumer
reale.

Filament è usato così com'è per il CRUD standard. Autorizzazioni e
validazione delle impostazioni vengono definite esplicitamente, non lasciate
ai default.

## Consequences

**Positive:**
- Meno codice ripetitivo per form, tabelle, filtri e risorse.
- Nessun secondo framework da imparare e nessuna API da tenere in sync con
  il modello dati.
- Lo stack è parte dell'obiettivo del progetto: Filament mostra come uno
  strumento standard viene usato con giudizio, cioè cosa si prende così
  com'è e cosa si personalizza.

**Negative / accepted trade-offs:**
- Accoppiamento a Filament, alle sue convenzioni e al suo ciclo di release.
- Il valore dimostrato sta nelle parti personalizzate (permessi,
  validazione delle impostazioni), non nel CRUD generato. Se il progetto
  restasse solo scaffolding, dimostrerebbe poco.
- Il controllo degli accessi resta una responsabilità nostra: i default di
  Filament non sono un modello di sicurezza sufficiente per dati sensibili.

**Revisit if:**
- compare un consumer esterno che richiede un'API per le stesse funzioni
  dell'admin;
- una personalizzazione necessaria richiede di combattere Filament invece di
  estenderlo;
- le parti personalizzate restano troppo poche per mostrare giudizio sullo
  stack.
