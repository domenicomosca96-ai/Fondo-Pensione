# Convertitore Markdown Locale (MarkItDown)

Web app locale per convertire qualsiasi documento in Markdown usando
[microsoft/markitdown](https://github.com/microsoft/markitdown). Nessun file
lascia la macchina: la conversione avviene interamente in locale, senza
chiamate a servizi esterni.

## Funzionalità

1. Caricamento di un file (drag&drop o selezione manuale).
2. Conversione in Markdown tramite MarkItDown.
3. Visualizzazione del risultato, sia come sorgente Markdown sia in anteprima renderizzata.
4. Copia del Markdown negli appunti con un click.
5. Download del risultato come file `.md`.

## Formati supportati

PDF, Word (`.docx`), Excel (`.xlsx`), PowerPoint (`.pptx`), immagini (con
metadati EXIF/OCR quando disponibile), audio (con trascrizione se
disponibile), HTML, CSV, JSON, XML, ZIP (converte i file al suo interno),
EPub, testo semplice e altro — l'elenco completo dipende dai converter
inclusi in MarkItDown.

## Requisiti di sistema

- Python 3.10+
- [`ffmpeg`](https://ffmpeg.org/) consigliato per i file audio (facoltativo per gli altri formati).

## Setup locale

```bash
cd markitdown-converter
python -m venv .venv
source .venv/bin/activate   # Windows: .venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 0.0.0.0 --port 8002 --reload
```

Apri `http://localhost:8002`.

## Container

```bash
cd markitdown-converter
docker build -t markitdown-converter .
docker run -p 8002:8002 markitdown-converter
```

## Note

- Limite di 200 MB per upload.
- Questa app è indipendente dal resto del repository e gira sulla porta `8002`
  per non entrare in conflitto con `Vantaggio Pensione` (porta `8000`) e
  `whisper-transcriber` (porta `8001`).
