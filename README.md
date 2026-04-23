# Vantaggio Pensione - Dashboard + Backend

Applicazione web completa per:
- simulare il capitale pensionistico,
- visualizzarlo in una dashboard con grafico,
- scaricare un **report PDF reale** con commento AI (Gemini).

---

## 1) Cosa hai già pronto
Questa repo contiene già tutto:
- backend FastAPI (`app/main.py`),
- frontend dashboard (`static/`),
- endpoint PDF (`POST /api/report`),
- container (`Dockerfile`).

Il bottone **"Scarica Report PDF"** chiama il backend e scarica il file PDF generato al momento.

---

## 2) Setup locale (super facile)
Apri terminale dentro la repo e fai questi comandi in ordine:

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
export GEMINI_API_KEY="INCOLLA_QUI_LA_TUA_CHIAVE_GEMINI"
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

Poi apri nel browser:

```text
http://localhost:8000
```

### Test rapido
1. Inserisci i valori nel form.
2. Clicca **Calcola Dashboard** (devi vedere metriche + grafico aggiornato).
3. Clicca **Scarica Report PDF** (deve partire il download del PDF).

---

## 3) Deploy reale (consigliato: Render)
Qui trovi i passaggi esatti, uno per uno.

### Step A — prepara GitHub
1. Crea un nuovo repository su GitHub.
2. Pusha questo progetto su GitHub.

### Step B — crea servizio su Render
1. Vai su [https://render.com](https://render.com).
2. Login / registrazione.
3. Clicca **New +** → **Web Service**.
4. Seleziona il repository GitHub del progetto.

### Step C — configura il servizio
Compila così:
- **Runtime**: Python 3
- **Build Command**:
  ```bash
  pip install -r requirements.txt
  ```
- **Start Command**:
  ```bash
  uvicorn app.main:app --host 0.0.0.0 --port $PORT
  ```

### Step D — inserisci la chiave Gemini
Nella sezione **Environment Variables** aggiungi:
- `GEMINI_API_KEY` = la tua chiave Gemini

### Step E — deploy
1. Clicca **Create Web Service**.
2. Aspetta il completamento build/deploy.
3. Render ti dà un URL pubblico (esempio: `https://nome-app.onrender.com`).

### Step F — verifica in produzione
1. Apri URL pubblico.
2. Compila il form.
3. Clicca **Calcola Dashboard**.
4. Clicca **Scarica Report PDF**.
5. Controlla che il PDF venga scaricato davvero.

---

## 4) Come funziona il PDF (dietro le quinte)
Quando clicchi il bottone:
1. Frontend invia i dati a `POST /api/report`.
2. Backend calcola la proiezione.
3. Backend chiede a Gemini il testo del report.
4. Backend crea PDF con ReportLab.
5. Browser riceve e scarica il file.

---

## 5) Errori comuni e soluzione immediata

### Errore: "Analisi automatica non disponibile"
Causa: manca `GEMINI_API_KEY`.
Soluzione: aggiungi la variabile ambiente (locale o Render) e riavvia il servizio.

### Errore in deploy: app non parte
Controlla che lo Start Command sia ESATTAMENTE:

```bash
uvicorn app.main:app --host 0.0.0.0 --port $PORT
```

### Il PDF non si scarica
- Apri DevTools → tab Network e controlla `/api/report`.
- Se status non è `200`, leggi log backend su Render.

---

## 6) Sicurezza (importante)
- Non salvare la chiave Gemini direttamente nel codice.
- Usa solo variabili d'ambiente.
- Se una chiave è stata condivisa pubblicamente, rigenerala dal provider.

---

## 7) Comandi utili veloci

Eseguire in locale:
```bash
uvicorn app.main:app --host 0.0.0.0 --port 8000 --reload
```

Build container locale:
```bash
docker build -t vantaggio-pensione .
```

Run container locale:
```bash
docker run -p 8000:8000 -e GEMINI_API_KEY="LA_TUA_CHIAVE" vantaggio-pensione
```


## 8) Perché su Render vedevi una pagina sbagliata / senza frontend
Cause tipiche:
1. Hai creato un servizio **Static Site** invece di **Web Service**.
2. Start command sbagliato (es. non usa `uvicorn app.main:app ...`).
3. Deploy partito da branch diversa o commit vecchio.
4. Variabile `PORT` non usata correttamente nel comando di avvio.

### Fix veloce
- Elimina il servizio errato su Render.
- Ricrea da `render.yaml` (Blueprint) oppure Web Service manuale con i parametri già indicati.
- Verifica URL:
  - `/healthz` deve rispondere `{"status":"ok"}`
  - `/` deve mostrare dashboard con form e grafico.
