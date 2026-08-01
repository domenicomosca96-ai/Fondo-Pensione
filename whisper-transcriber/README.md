# Trascrizione Audio Locale (faster-whisper)

Web app locale per convertire file audio in testo in lingua italiana usando
[SYSTRAN/faster-whisper](https://github.com/SYSTRAN/faster-whisper). Nessun
audio o testo lascia la macchina: il modello gira interamente in locale
(CPU o GPU) e non ci sono chiamate a servizi esterni.

## Funzionalità

1. Caricamento di un file audio (drag&drop o selezione manuale).
2. Trascrizione tramite faster-whisper, forzata in italiano.
3. Visualizzazione del testo trascritto.
4. Copia del testo negli appunti con un click.
5. Download del testo come file `.txt`.

## Requisiti di sistema

- Python 3.10+
- [`ffmpeg`](https://ffmpeg.org/) installato e presente nel `PATH` (usato da
  faster-whisper per decodificare i formati audio compressi).
  - macOS: `brew install ffmpeg`
  - Debian/Ubuntu: `sudo apt-get install ffmpeg`
  - Windows: scarica il binario da ffmpeg.org e aggiungilo al `PATH`

## Setup locale

```bash
cd whisper-transcriber
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 0.0.0.0 --port 8001 --reload
```

Apri `http://localhost:8001`.

Al primo avvio (o alla prima richiesta di trascrizione se non hai già il
modello in cache) faster-whisper scarica automaticamente i pesi da Hugging
Face e li salva in `~/.cache/huggingface`. Le esecuzioni successive sono
completamente offline.

## Configurazione modello

Variabili d'ambiente opzionali (impostale prima di avviare `uvicorn`):

| Variabile | Default | Descrizione |
|---|---|---|
| `WHISPER_MODEL_SIZE` | `small` | Dimensione modello: `tiny`, `base`, `small`, `medium`, `large-v3`, oppure il path a un modello CTranslate2 già scaricato in locale. |
| `WHISPER_DEVICE` | `cpu` | `cpu` o `cuda` (se disponibile una GPU NVIDIA con driver/CUDA installati). |
| `WHISPER_COMPUTE_TYPE` | `int8` | Tipo di quantizzazione, es. `int8` (CPU, più leggero) o `float16` (GPU). |

Per usare un modello già scaricato manualmente, imposta `WHISPER_MODEL_SIZE`
al percorso della cartella contenente i file del modello CTranslate2.

## Container

```bash
cd whisper-transcriber
docker build -t whisper-transcriber .
docker run -p 8001:8001 whisper-transcriber
```

## Note

- Formati audio supportati: MP3, WAV, M4A, OGG, FLAC, WEBM, AAC, WMA (limite 200 MB per upload).
- La trascrizione è forzata in lingua italiana (`language="it"`).
- Questa app è indipendente dal resto del repository (`Vantaggio Pensione`) e
  gira su una porta separata (`8001`) per non entrare in conflitto.
