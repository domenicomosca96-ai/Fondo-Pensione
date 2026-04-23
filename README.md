# Vantaggio Pensione - Dashboard + Backend

Applicazione full-stack per simulare una proiezione pensionistica, visualizzarla in dashboard e scaricare un **report PDF** generato dal backend con commento AI (Gemini).

## Stack
- FastAPI (backend API + hosting pagina)
- Chart.js (grafico dashboard)
- ReportLab (generazione PDF)
- Gemini API (testo del report)

## Avvio locale
```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
export GEMINI_API_KEY="<inserisci-la-tua-chiave>"
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

Apri: `http://localhost:8000`

## Endpoint principali
- `POST /api/projection` → ritorna proiezione numerica per dashboard.
- `POST /api/report` → ritorna il PDF scaricabile.

## Come funziona il PDF
1. Frontend invia i dati utente a `/api/report`.
2. Backend calcola la proiezione.
3. Backend chiede a Gemini un commento testuale.
4. Backend compone il PDF e lo invia in download.

## Deploy reale (Render esempio)
1. Crea un nuovo Web Service da repo Git.
2. Build command:
   ```bash
   pip install -r requirements.txt
   ```
3. Start command:
   ```bash
   uvicorn app.main:app --host 0.0.0.0 --port $PORT
   ```
4. Environment Variable:
   - `GEMINI_API_KEY=<chiave>`

L'app sarà disponibile con URL pubblico, e il bottone "Scarica Report PDF" funzionerà anche in produzione.

## Note sicurezza
- Non hardcodare la chiave API nel codice.
- Usa variabili d'ambiente nel provider cloud.
