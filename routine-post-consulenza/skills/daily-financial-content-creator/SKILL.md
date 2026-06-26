---
name: daily-financial-content-creator
description: Orchestratrice della routine giornaliera. Genera un contenuto finanziario LinkedIn on-brand per Domenico Mosca, partendo da fatti di mercato verificati e senza ripetere temi già trattati. Usare quando si esegue la routine schedulata "Post-Consulenza".
---

# Daily Financial Content Creator

Skill orchestratrice. Coordina le sub-skill per produrre **un** contenuto pronto alla revisione.

## Input
- `config.yaml` (lookback, fonti, output)
- `covered_topics.json` (storico dedup)
- Sub-skill: `investing-master-analyst`, `domenico-marketing-engine`, `brand-identity`

## Procedura

1. **Carica config.** Leggi `config.yaml`. Se mancano fonti reali (placeholder), segnalalo e usa solo `web_search`.
2. **Raccogli i fatti.** Invoca `investing-master-analyst` per ottenere 3–5 fatti di mercato verificati entro la finestra di `lookback`. Ogni fatto deve avere una fonte. **Scarta** qualsiasi numero non verificabile.
3. **Filtra i duplicati.** Leggi `covered_topics.json`. Escludi gli angle trattati negli ultimi `dedup.cooldown_days` giorni.
4. **Scegli l'angle e scrivi.** Invoca `domenico-marketing-engine` passando i fatti residui: produce hook (3 varianti), corpo del post, CTA e disclaimer.
5. **Applica il brand.** Invoca `brand-identity` per validare voce, claim e (se si generano grafiche) i parametri visivi.
6. **Registra il tema.** Appendi a `covered_topics.json` un oggetto: `{ "date", "angle", "summary", "sources" }`.
7. **Output finale.** Restituisci: post completo, 3 hook alternativi, fonti usate, e il diff di `covered_topics.json`.

## Regole
- Mai inventare numeri, rendimenti o eventi. Se non c'è abbastanza materiale verificato, **fermati e dillo** invece di riempire.
- Un solo contenuto per run, salvo richiesta diversa.
- Tono e claim sempre conformi a `brand-identity`.

## Output atteso
Un blocco markdown con: `## Post`, `## Hook alternativi`, `## Fonti`, `## Aggiornamento dedup`.
