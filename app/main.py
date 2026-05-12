import base64
import binascii
import logging
import os
import re
import smtplib
import ssl
from email.message import EmailMessage
from typing import Optional


# Gmail mostra le App Password come quattro gruppi da 4 caratteri lowercase
# separati da spazi singoli ("mdrs uvqn twty lhnn"). Lato SMTP vanno passate
# senza spazi. Per qualsiasi altro provider la password resta intatta —
# SMTP è opaco e password legittime possono contenere whitespace.
_GMAIL_APP_PASSWORD_RE = re.compile(r"^[a-z]{4} [a-z]{4} [a-z]{4} [a-z]{4}$")


def _normalize_smtp_password(raw: str) -> str:
    if _GMAIL_APP_PASSWORD_RE.match(raw):
        return raw.replace(" ", "")
    return raw

import google.generativeai as genai
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, HTMLResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, EmailStr, Field


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
log = logging.getLogger("vantaggio-pensione")


app = FastAPI(title="Vantaggio Pensione", version="2.1.0")

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

GEMINI_MODEL = os.getenv("GEMINI_MODEL", "gemini-2.5-flash")


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
        model = genai.GenerativeModel(GEMINI_MODEL)
        response = model.generate_content(payload.message)
        text = (response.text or "").strip()
    except Exception as exc:  # pragma: no cover - dipende da rete
        log.exception("Gemini call failed (model=%s)", GEMINI_MODEL)
        return {"error": {"message": f"Errore Gemini ({GEMINI_MODEL}): {exc}"}}

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


def _env(*names: str, default: Optional[str] = None) -> Optional[str]:
    """Restituisce il primo env var non vuoto fra i nomi forniti."""
    for n in names:
        v = os.getenv(n)
        if v:
            return v
    return default


def _smtp_config() -> Optional[dict]:
    # Accetta entrambe le convenzioni di naming (HOST/SERVER, PASS/PASSWORD).
    host = _env("SMTP_HOST", "SMTP_SERVER")
    user = _env("SMTP_USER", "SMTP_USERNAME")
    password = _env("SMTP_PASS", "SMTP_PASSWORD")
    if not (host and user and password):
        return None

    port_raw = _env("SMTP_PORT", default="587")
    try:
        port = int(port_raw)
    except ValueError:
        port = 587

    return {
        "host": host.strip(),
        "port": port,
        "user": user.strip(),
        "password": _normalize_smtp_password(password),
        "use_ssl": (_env("SMTP_SSL", default="false") or "false").lower() in {"1", "true", "yes"},
        "from_addr": (_env("SMTP_FROM") or user).strip(),
        "from_name": _env("SMTP_FROM_NAME", default="Vantaggio Pensione"),
        "owner_bcc": _env("SMTP_OWNER_EMAIL", "SMTP_BCC"),
        "debug": (_env("SMTP_DEBUG", default="false") or "false").lower() in {"1", "true", "yes"},
    }


def _build_email(cfg: dict, payload: SendReportRequest, pdf_bytes: bytes) -> EmailMessage:
    msg = EmailMessage()
    msg["Subject"] = "Il tuo Report Previdenziale personalizzato"
    msg["From"] = f"{cfg['from_name']} <{cfg['from_addr']}>"
    msg["To"] = payload.email
    if cfg.get("owner_bcc"):
        msg["Bcc"] = cfg["owner_bcc"]
    msg["Reply-To"] = cfg["from_addr"]

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


def _send_via_smtp(cfg: dict, msg: EmailMessage) -> None:
    """Invia il messaggio. STARTTLS su porta 587 (default), SSL diretto se cfg.use_ssl."""
    log.info(
        "SMTP connect host=%s port=%s user=%s ssl=%s",
        cfg["host"], cfg["port"], cfg["user"], cfg["use_ssl"],
    )
    context = ssl.create_default_context()

    if cfg["use_ssl"]:
        server = smtplib.SMTP_SSL(cfg["host"], cfg["port"], timeout=30, context=context)
    else:
        server = smtplib.SMTP(cfg["host"], cfg["port"], timeout=30)

    try:
        if cfg["debug"]:
            server.set_debuglevel(1)
        server.ehlo()
        if not cfg["use_ssl"]:
            server.starttls(context=context)
            server.ehlo()
        server.login(cfg["user"], cfg["password"])
        refused = server.send_message(msg)
        if refused:
            raise smtplib.SMTPRecipientsRefused(refused)
    finally:
        try:
            server.quit()
        except Exception:
            server.close()


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
        msg_err = (
            "SMTP non configurato: imposta SMTP_HOST (o SMTP_SERVER), "
            "SMTP_USER, SMTP_PASS (o SMTP_PASSWORD)."
        )
        log.warning(msg_err)
        return {"success": False, "error": msg_err}

    try:
        message = _build_email(cfg, payload, pdf_bytes)
    except Exception as exc:
        log.exception("Errore composizione email")
        return {"success": False, "error": f"Errore composizione email: {exc!r}"}

    try:
        _send_via_smtp(cfg, message)
    except smtplib.SMTPAuthenticationError as exc:
        detail = f"Auth SMTP fallita ({exc.smtp_code}): {exc.smtp_error!r}"
        log.error("SMTPAuthenticationError host=%s user=%s :: %s", cfg["host"], cfg["user"], detail)
        return {"success": False, "error": detail}
    except smtplib.SMTPSenderRefused as exc:
        detail = f"Mittente rifiutato ({exc.smtp_code}): {exc.smtp_error!r} sender={exc.sender}"
        log.error(detail)
        return {"success": False, "error": detail}
    except smtplib.SMTPRecipientsRefused as exc:
        detail = f"Destinatario rifiutato: {exc.recipients!r}"
        log.error(detail)
        return {"success": False, "error": detail}
    except smtplib.SMTPDataError as exc:
        detail = f"SMTPDataError ({exc.smtp_code}): {exc.smtp_error!r}"
        log.error(detail)
        return {"success": False, "error": detail}
    except smtplib.SMTPConnectError as exc:
        detail = f"Connessione SMTP rifiutata ({exc.smtp_code}): {exc.smtp_error!r}"
        log.error(detail)
        return {"success": False, "error": detail}
    except smtplib.SMTPException as exc:
        log.exception("SMTPException")
        return {"success": False, "error": f"SMTPException: {exc!r}"}
    except (TimeoutError, OSError) as exc:
        log.exception("Errore di rete SMTP")
        return {"success": False, "error": f"Errore di rete SMTP: {exc!r}"}
    except Exception as exc:  # pragma: no cover
        log.exception("Errore imprevisto invio email")
        return {"success": False, "error": f"Errore imprevisto: {exc!r}"}

    log.info("Email inviata a %s (bcc=%s)", payload.email, cfg.get("owner_bcc"))
    return {"success": True}
