import base64
import binascii
import os
import smtplib
from email.message import EmailMessage
from typing import Optional

import google.generativeai as genai
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, HTMLResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, EmailStr, Field


app = FastAPI(title="Vantaggio Pensione", version="2.0.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.mount("/static", StaticFiles(directory="static"), name="static")


@app.get("/", response_class=HTMLResponse)
def index() -> FileResponse:
    return FileResponse("static/index.html")


# ===================== GEMINI PROXY =====================

class GeminiRequest(BaseModel):
    message: str = Field(..., min_length=1, max_length=4000)


@app.post("/api/gemini")
def gemini_proxy(payload: GeminiRequest) -> dict:
    """Proxy verso Gemini. Risposta nel formato atteso dal frontend:
    { candidates: [ { content: { parts: [ { text } ] } } ] }
    """
    api_key = os.getenv("GEMINI_API_KEY")
    if not api_key:
        return {"error": {"message": "GEMINI_API_KEY non configurata sul server."}}

    genai.configure(api_key=api_key)
    try:
        model = genai.GenerativeModel("gemini-1.5-flash")
        response = model.generate_content(payload.message)
        text = (response.text or "").strip()
    except Exception as exc:  # pragma: no cover - dipende da rete
        return {"error": {"message": f"Errore Gemini: {exc}"}}

    return {
        "candidates": [
            {"content": {"parts": [{"text": text or "Nessuna risposta."}]}}
        ]
    }


# ===================== INVIO REPORT =====================

class SendReportRequest(BaseModel):
    nome: str = Field(..., min_length=1, max_length=120)
    cognome: str = Field(..., min_length=1, max_length=120)
    telefono: str = Field(..., min_length=4, max_length=40)
    email: EmailStr
    vantaggio: str = Field("", max_length=80)
    pdf: str = Field(..., description="PDF in base64 (senza prefisso data URI)")


def _smtp_config() -> Optional[dict]:
    host = os.getenv("SMTP_HOST")
    user = os.getenv("SMTP_USER")
    password = os.getenv("SMTP_PASS")
    if not (host and user and password):
        return None
    return {
        "host": host,
        "port": int(os.getenv("SMTP_PORT", "587")),
        "user": user,
        "password": password,
        "use_ssl": os.getenv("SMTP_SSL", "false").lower() in {"1", "true", "yes"},
        "from_addr": os.getenv("SMTP_FROM", user),
        "from_name": os.getenv("SMTP_FROM_NAME", "Vantaggio Pensione"),
        "owner_bcc": os.getenv("SMTP_OWNER_EMAIL"),
    }


def _build_email(cfg: dict, payload: SendReportRequest, pdf_bytes: bytes) -> EmailMessage:
    msg = EmailMessage()
    msg["Subject"] = "Il tuo Report Previdenziale personalizzato"
    msg["From"] = f"{cfg['from_name']} <{cfg['from_addr']}>"
    msg["To"] = payload.email
    if cfg.get("owner_bcc"):
        msg["Bcc"] = cfg["owner_bcc"]

    msg.set_content(
        f"Ciao {payload.nome},\n\n"
        f"in allegato trovi il tuo report previdenziale personalizzato.\n"
        f"Vantaggio netto stimato: {payload.vantaggio or 'vedi report'}.\n\n"
        f"Per qualsiasi domanda puoi rispondere a questa email.\n\n"
        f"— Domenico Mosca, Consulente Finanziario FinecoBank"
    )
    msg.add_alternative(
        f"""
        <p>Ciao <strong>{payload.nome}</strong>,</p>
        <p>in allegato trovi il tuo <strong>report previdenziale personalizzato</strong>.</p>
        <p>Vantaggio netto stimato: <strong>{payload.vantaggio or 'vedi report'}</strong>.</p>
        <p>Per qualsiasi domanda puoi rispondere a questa email.</p>
        <p>— Domenico Mosca, Consulente Finanziario FinecoBank</p>
        """,
        subtype="html",
    )

    filename = f"Report_Previdenziale_{payload.nome}_{payload.cognome}.pdf".replace(" ", "_")
    msg.add_attachment(pdf_bytes, maintype="application", subtype="pdf", filename=filename)
    return msg


@app.post("/api/send-report")
def send_report(payload: SendReportRequest) -> dict:
    try:
        pdf_bytes = base64.b64decode(payload.pdf, validate=True)
    except (binascii.Error, ValueError):
        raise HTTPException(status_code=400, detail="PDF non valido (atteso base64).")

    if not pdf_bytes.startswith(b"%PDF"):
        raise HTTPException(status_code=400, detail="L'allegato non è un PDF.")

    cfg = _smtp_config()
    if cfg is None:
        return {
            "success": False,
            "error": "Server email non configurato. Imposta SMTP_HOST, SMTP_USER, SMTP_PASS.",
        }

    msg = _build_email(cfg, payload, pdf_bytes)

    try:
        if cfg["use_ssl"]:
            with smtplib.SMTP_SSL(cfg["host"], cfg["port"], timeout=30) as server:
                server.login(cfg["user"], cfg["password"])
                server.send_message(msg)
        else:
            with smtplib.SMTP(cfg["host"], cfg["port"], timeout=30) as server:
                server.starttls()
                server.login(cfg["user"], cfg["password"])
                server.send_message(msg)
    except Exception as exc:  # pragma: no cover - dipende da rete
        return {"success": False, "error": f"Invio email fallito: {exc}"}

    return {"success": True}
