# Vantaggio Pensione

Calcolatore previdenziale lead-gated:

1. **Calcolo** — l'utente compila il form (profilo dipendente/professionista, età, reddito, contributi) e vede **solo** il numero del Vantaggio Netto Stimato. Il resto dell'analisi è oscurato dietro un'anteprima sfumata.
2. **Form lead** — per sbloccare il dettaglio l'utente inserisce nome, cognome, cellulare, email e accetta la privacy.
3. **Sblocco + email** — al submit:
   - viene generato lato client un **PDF personalizzato** (jsPDF) con header gradient, confronto scenari, donut delle componenti del vantaggio e profilo;
   - il PDF viene inviato al backend (`/api/send-report`) che lo recapita all'email del cliente via SMTP;
   - la pagina sblocca grafici Chart.js, l'analisi AI Gemini e il pannello rendita/capitale.

## Architettura

- **Frontend**: `static/index.html` (Tailwind CDN, Chart.js, jsPDF) — pagina singola con tre stati (`idle` → `calculated` → `unlocked`).
- **Backend**: FastAPI (`app/main.py`) con due endpoint:
  - `POST /api/gemini` — proxy verso Gemini (`{ message }` → formato `{ candidates: [...] }`);
  - `POST /api/send-report` — riceve dati lead + PDF base64, invia email con allegato via SMTP.

## Setup locale

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env  # poi compila le variabili
export $(grep -v '^#' .env | xargs)
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

Apri `http://localhost:8000`.

## Variabili d'ambiente

| Variabile | Obbligatoria | Descrizione |
|---|---|---|
| `GEMINI_API_KEY` | per AI | Chiave Google Gemini. Senza, l'analisi AI restituisce errore ma il calcolo e il PDF restano operativi. |
| `SMTP_HOST` | per email | Host SMTP (es. `smtps.aruba.it`). |
| `SMTP_PORT` | per email | Porta (default `587`). |
| `SMTP_USER` / `SMTP_PASS` | per email | Credenziali. |
| `SMTP_SSL` | no | `true` per SSL diretto (porta 465); altrimenti STARTTLS. |
| `SMTP_FROM` | no | Mittente (default = `SMTP_USER`). |
| `SMTP_FROM_NAME` | no | Nome visualizzato (default `Vantaggio Pensione`). |
| `SMTP_OWNER_EMAIL` | no | Bcc del consulente. |

Senza configurazione SMTP l'app continua a funzionare: il modale segnala l'errore di invio e l'utente può comunque scaricare il PDF dal pulsante.

## Deploy (Render)

- **Build**: `pip install -r requirements.txt`
- **Start**: `uvicorn app.main:app --host 0.0.0.0 --port $PORT`
- Imposta le variabili d'ambiente sopra in **Environment**.

## Container

```bash
docker build -t vantaggio-pensione .
docker run -p 8000:8000 --env-file .env vantaggio-pensione
```

## Altri tool nel repo

- [`whisper-transcriber/`](./whisper-transcriber/README.md) — web app locale (FastAPI + [faster-whisper](https://github.com/SYSTRAN/faster-whisper)) per trascrivere file audio in testo in italiano, interamente offline. Indipendente dall'app Vantaggio Pensione, gira sulla porta 8001.
