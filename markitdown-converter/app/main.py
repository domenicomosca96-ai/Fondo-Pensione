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
log = logging.getLogger("markitdown-converter")

BASE_DIR = Path(__file__).resolve().parent.parent
STATIC_DIR = BASE_DIR / "static"

MAX_UPLOAD_BYTES = 200 * 1024 * 1024  # 200 MB

app = FastAPI(title="Convertitore Markdown Locale", version="1.0.0")

_converter = None


def get_converter():
    """Istanzia MarkItDown una sola volta (lazy)."""
    global _converter
    if _converter is None:
        from markitdown import MarkItDown

        log.info("Inizializzo MarkItDown...")
        _converter = MarkItDown()
    return _converter


@app.get("/")
def index():
    return FileResponse(STATIC_DIR / "index.html")


@app.get("/api/health")
def health():
    return {"status": "ok"}


@app.post("/api/convert")
async def convert(file: UploadFile = File(...)):
    filename = file.filename or "documento"
    suffix = Path(filename).suffix

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

        converter = get_converter()
        result = converter.convert(tmp_path)
    except HTTPException:
        raise
    except Exception as exc:
        log.exception("Errore durante la conversione di %s", filename)
        raise HTTPException(status_code=500, detail=f"Errore durante la conversione: {exc}") from exc
    finally:
        if tmp_path:
            os.unlink(tmp_path)

    return JSONResponse(
        {
            "markdown": result.markdown,
            "title": result.title,
        }
    )
