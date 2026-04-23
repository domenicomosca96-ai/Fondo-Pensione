# Overwrite totale di `main` con il branch Codex (risoluzione merge conflicts)

Se vuoi che il codice del branch Codex diventi **identico** a `main` (sovrascrivendo modifiche manuali fatte su `main`), usa questi comandi dal tuo PC locale:

```bash
git fetch origin

git checkout main
git pull origin main

git checkout codex/create-backend-code-for-vantaggio-pensione-19mfuq
git pull origin codex/create-backend-code-for-vantaggio-pensione-19mfuq

# Torna su main e sostituisci completamente il contenuto con il branch Codex
git checkout main
git reset --hard origin/codex/create-backend-code-for-vantaggio-pensione-19mfuq

# Push forzato (attenzione: riscrive la history di main)
git push origin main --force-with-lease
```

## Verifica immediata
Dopo il push, controlla:

```bash
curl -s https://TUO-SITO-RENDER/healthz
```

Deve restituire:

```json
{"status":"ok"}
```

Poi apri la root del sito e verifica la dashboard frontend.

## Nota importante
- Questo approccio sovrascrive la storia di `main`.
- Usalo solo se sei sicuro di voler rendere definitivo il branch Codex.
