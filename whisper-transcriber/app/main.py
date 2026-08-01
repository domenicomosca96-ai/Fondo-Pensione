import logging
import os
import tempfile
from pathlib import Path
from typing import Optional

from fastapi import FastAPI, File, HTTPException, UploadFile
from fastapi.responses import FileResponse, JSONResponse

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
log = logging.getLogger("whisper-transcriber")

BASE_DIR = Path(__file__).resolve().parent.parent
STATIC_DIR = BASE_DIR / "static"

MODEL_SIZE = os.getenv("WHISPER_MODEL_SIZE", "small")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")

ALLOWED_EXTENSIONS = {".mp3", ".wav", ".m4a", ".ogg", ".flac", ".webm", ".mp4", ".aac", ".wma"}
MAX_UPLOAD_BYTES = 200 * 1024 * 1024  # 200 MB

app = FastAPI(title="Trascrizione Audio Locale", version="1.0.0")

_model = None


def get_model():
    """Carica il modello faster-whisper una sola volta (lazy, alla prima richiesta)."""
    global _model
    if _model is None:
        from faster_whisper import WhisperModel

        log.info(
            "Carico il modello faster-whisper '%s' (device=%s, compute_type=%s)...",
            MODEL_SIZE,
            DEVICE,
            COMPUTE_TYPE,
        )
        _model = WhisperModel(MODEL_SIZE, device=DEVICE, compute_type=COMPUTE_TYPE)
        log.info("Modello caricato.")
    return _model


@app.get("/")
def index():
    return FileResponse(STATIC_DIR / "index.html")


@app.get("/api/health")
def health():
    return {"status": "ok", "model": MODEL_SIZE}


@app.post("/api/transcribe")
async def transcribe(file: UploadFile = File(...)):
    filename = file.filename or "audio"
    suffix = Path(filename).suffix.lower()
    if suffix not in ALLOWED_EXTENSIONS:
        raise HTTPException(
            status_code=400,
            detail=f"Formato '{suffix or 'sconosciuto'}' non supportato. Formati ammessi: {', '.join(sorted(ALLOWED_EXTENSIONS))}.",
        )

    data = await file.read()
    if not data:
        raise HTTPException(status_code=400, detail="Il file caricato è vuoto.")
    if len(data) > MAX_UPLOAD_BYTES:
        raise HTTPException(status_code=413, detail="File troppo grande (limite 200 MB).")

    tmp_path: Optional[str] = None
    try:
        with tempfile.NamedTemporaryFile(suffix=suffix, delete=False) as tmp:
            tmp.write(data)
            tmp_path = tmp.name

        model = get_model()
        segments, info = model.transcribe(tmp_path, language="it", vad_filter=True)
        text = "".join(segment.text for segment in segments).strip()
    except HTTPException:
        raise
    except Exception as exc:
        log.exception("Errore durante la trascrizione di %s", filename)
        raise HTTPException(status_code=500, detail=f"Errore durante la trascrizione: {exc}") from exc
    finally:
        if tmp_path:
            os.unlink(tmp_path)

    return JSONResponse(
        {
            "text": text,
            "language": info.language,
            "duration": round(info.duration, 2) if info.duration else None,
        }
    )
