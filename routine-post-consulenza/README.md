# Routine Claude — Post Consulenza

Bundle di skill e configurazione per la **routine giornaliera di creazione contenuti finanziari** di Domenico Mosca (Consulente Finanziario Fineco).

Questo bundle è pensato per essere clonato/montato dall'ambiente remoto della routine schedulata. Contiene tutto ciò che nella prima esecuzione risultava **mancante** (skill, `config.yaml`, `covered_topics.json`, sub-skill di analisi/marketing/brand).

> NOTA: il bundle è stato creato qui dentro `Fondo-Pensione` perché il repo dedicato
> `Routine-Claude-Post-Consulenza` non era agganciato alla sessione. Va spostato lì (vedi sezione finale).

## Struttura

```
.
├── config.yaml                     # parametri routine: lookback, fonti, output
├── covered_topics.json             # storico anti-duplicazione (dedup)
└── skills/
    ├── daily-financial-content-creator/   # skill orchestratrice
    ├── investing-master-analyst/          # analisi macro/mercati
    ├── domenico-marketing-engine/         # angle + copy LinkedIn
    └── brand-identity/                     # voce, colori, lockup
```

## Come la routine usa questi file

1. Legge `config.yaml` → finestra di lookback e fonti news da consultare.
2. `investing-master-analyst` raccoglie e sintetizza i fatti di mercato verificati.
3. `domenico-marketing-engine` sceglie l'angle e scrive il post, **escludendo** i temi già presenti in `covered_topics.json`.
4. `brand-identity` impone voce, claim e parametri grafici.
5. Il tema trattato viene **appeso** a `covered_topics.json` per evitare ripetizioni.

## Cosa resta da completare (vedi TODO nei file)

- Fonti news reali in `config.yaml` (al momento placeholder).
- Conferma dei codici colore brand esatti in `skills/brand-identity/brand.yaml`.
- Meccanismo di pubblicazione/bozza su LinkedIn (manuale vs API).
- Eventuali chiavi API (es. ricerca news) come secrets dell'ambiente, **mai** nel repo.

## Spostare nel repo dedicato

Per portarlo in `domenicomosca96-ai/Routine-Claude-Post-Consulenza`:

```bash
# dalla cartella routine-post-consulenza/
git clone https://github.com/domenicomosca96-ai/Routine-Claude-Post-Consulenza.git dest
cp -r config.yaml covered_topics.json skills dest/
cd dest && git add . && git commit -m "Init routine bundle" && git push
```

Oppure aggiungi quel repo allo scope della sessione Claude e lo pusho io direttamente.
