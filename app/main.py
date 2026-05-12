import io
import os
import smtplib
from datetime import datetime
from email.message import EmailMessage
from pathlib import Path
from typing import List

import google.generativeai as genai
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, HTMLResponse, StreamingResponse
from fastapi.staticfiles import StaticFiles
from pydantic import BaseModel, Field
from reportlab.lib.pagesizes import A4
from reportlab.pdfgen import canvas

BASE_DIR = Path(__file__).resolve().parent.parent
STATIC_DIR = BASE_DIR / "static"
INDEX_FILE = STATIC_DIR / "index.html"


class PensionInput(BaseModel):
    current_age: int = Field(..., ge=18, le=75)
    retirement_age: int = Field(..., ge=45, le=80)
    monthly_contribution: float = Field(..., gt=0)
    annual_return_rate: float = Field(..., ge=0, le=20)
    current_capital: float = Field(0, ge=0)


class ProjectionPoint(BaseModel):
    age: int
    estimated_capital: float


class ProjectionResult(BaseModel):
    projected_capital: float
    years_to_retirement: int
    points: List[ProjectionPoint]


class ReportEmailRequest(PensionInput):
    recipient_email: str = Field(..., min_length=5, max_length=254)


class ReportEmailResponse(BaseModel):
    status: str
    recipient_email: str


app = FastAPI(title="Vantaggio Pensione API", version="1.1.0")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

if not STATIC_DIR.exists():
    raise RuntimeError(f"Static directory non trovata: {STATIC_DIR}")

app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")


def project_capital(payload: PensionInput) -> ProjectionResult:
    years = payload.retirement_age - payload.current_age
    if years <= 0:
        raise HTTPException(status_code=400, detail="L'età pensionabile deve essere maggiore dell'età attuale.")

    annual_contrib = payload.monthly_contribution * 12
    capital = payload.current_capital
    points: list[ProjectionPoint] = []

    for i in range(1, years + 1):
        capital = (capital + annual_contrib) * (1 + payload.annual_return_rate / 100)
        points.append(ProjectionPoint(age=payload.current_age + i, estimated_capital=round(capital, 2)))

    return ProjectionResult(
        projected_capital=round(capital, 2),
        years_to_retirement=years,
        points=points,
    )


def generate_commentary_with_gemini(payload: PensionInput, projection: ProjectionResult) -> str:
    api_key = os.getenv("GEMINI_API_KEY")
    if not api_key:
        return (
            "Analisi automatica non disponibile: imposta GEMINI_API_KEY per ricevere una sintesi AI. "
            "La proiezione numerica è comunque stata calcolata correttamente."
        )

    genai.configure(api_key=api_key)
    model = genai.GenerativeModel("gemini-1.5-flash")

    prompt = f"""
    Genera un breve report in italiano (max 220 parole) su questa simulazione pensionistica.
    Usa tono professionale e chiaro.

    Dati cliente:
    - Età attuale: {payload.current_age}
    - Età pensionamento: {payload.retirement_age}
    - Contributo mensile: {payload.monthly_contribution:.2f} EUR
    - Rendimento annuo stimato: {payload.annual_return_rate:.2f}%
    - Capitale attuale: {payload.current_capital:.2f} EUR

    Risultati simulazione:
    - Anni alla pensione: {projection.years_to_retirement}
    - Capitale stimato finale: {projection.projected_capital:.2f} EUR

    Struttura il testo in:
    1) Sintesi
    2) Punti di forza
    3) Rischi/attenzioni
    4) Azioni consigliate
    """

    try:
        response = model.generate_content(prompt)
        return (response.text or "Nessun testo restituito dal modello.").strip()
    except Exception as exc:
        return f"Errore durante la generazione AI: {exc}"


def build_pdf(payload: PensionInput, projection: ProjectionResult, ai_commentary: str) -> io.BytesIO:
    buffer = io.BytesIO()
    pdf = canvas.Canvas(buffer, pagesize=A4)

    x_margin = 50
    y = 800

    pdf.setFont("Helvetica-Bold", 18)
    pdf.drawString(x_margin, y, "Report Previdenziale - Vantaggio Pensione")
    y -= 28

    pdf.setFont("Helvetica", 10)
    pdf.drawString(x_margin, y, f"Data generazione: {datetime.utcnow().strftime('%Y-%m-%d %H:%M UTC')}")
    y -= 30

    lines = [
        f"Età attuale: {payload.current_age}",
        f"Età pensionamento: {payload.retirement_age}",
        f"Contributo mensile: EUR {payload.monthly_contribution:,.2f}",
        f"Rendimento annuo stimato: {payload.annual_return_rate:.2f}%",
        f"Capitale attuale: EUR {payload.current_capital:,.2f}",
        f"Anni alla pensione: {projection.years_to_retirement}",
        f"Capitale finale stimato: EUR {projection.projected_capital:,.2f}",
    ]

    pdf.setFont("Helvetica-Bold", 12)
    pdf.drawString(x_margin, y, "Riepilogo numerico")
    y -= 20

    pdf.setFont("Helvetica", 11)
    for line in lines:
        pdf.drawString(x_margin, y, line)
        y -= 18

    y -= 10
    pdf.setFont("Helvetica-Bold", 12)
    pdf.drawString(x_margin, y, "Commento AI (Gemini)")
    y -= 20
    pdf.setFont("Helvetica", 10)

    for paragraph in ai_commentary.split("\n"):
        if not paragraph.strip():
            y -= 10
            continue

        words = paragraph.split()
        current_line = ""
        for word in words:
            candidate = f"{current_line} {word}".strip()
            if pdf.stringWidth(candidate, "Helvetica", 10) < 500:
                current_line = candidate
            else:
                pdf.drawString(x_margin, y, current_line)
                y -= 14
                current_line = word
                if y < 70:
                    pdf.showPage()
                    pdf.setFont("Helvetica", 10)
                    y = 800

        if current_line:
            pdf.drawString(x_margin, y, current_line)
            y -= 14

    pdf.save()
    buffer.seek(0)
    return buffer


def require_smtp_setting(name: str) -> str:
    value = os.getenv(name)
    if not value:
        raise HTTPException(status_code=500, detail=f"Variabile ambiente SMTP mancante: {name}")
    return value


def send_report_email(recipient_email: str, pdf_bytes: bytes) -> None:
    smtp_server = require_smtp_setting("SMTP_SERVER").strip()
    smtp_user = require_smtp_setting("SMTP_USER").strip()
    smtp_password = require_smtp_setting("SMTP_PASSWORD").replace(" ", "")
    smtp_port = int(os.getenv("SMTP_PORT", "587"))

    message = EmailMessage()
    message["Subject"] = "Il tuo report Vantaggio Pensione"
    message["From"] = smtp_user
    message["To"] = recipient_email
    message.set_content(
        "Ciao,\n\n"
        "in allegato trovi il report previdenziale generato da Vantaggio Pensione.\n\n"
        "A presto.\n"
    )
    message.add_attachment(
        pdf_bytes,
        maintype="application",
        subtype="pdf",
        filename="report-previdenziale.pdf",
    )

    try:
        if smtp_port == 465:
            with smtplib.SMTP_SSL(smtp_server, smtp_port, timeout=30) as server:
                server.login(smtp_user, smtp_password)
                server.send_message(message)
        else:
            with smtplib.SMTP(smtp_server, smtp_port, timeout=30) as server:
                server.starttls()
                server.login(smtp_user, smtp_password)
                server.send_message(message)
    except smtplib.SMTPAuthenticationError as exc:
        raise HTTPException(
            status_code=502,
            detail="Autenticazione SMTP fallita: verifica SMTP_USER e SMTP_PASSWORD/app password.",
        ) from exc
    except OSError as exc:
        raise HTTPException(status_code=502, detail=f"Connessione SMTP fallita: {exc}") from exc
    except smtplib.SMTPException as exc:
        raise HTTPException(status_code=502, detail=f"Invio email fallito: {exc}") from exc


@app.get("/healthz")
def healthz() -> dict[str, str]:
    return {"status": "ok"}


@app.get("/", response_class=HTMLResponse)
def index() -> FileResponse:
    if not INDEX_FILE.exists():
        raise HTTPException(status_code=500, detail=f"File index non trovato: {INDEX_FILE}")
    return FileResponse(str(INDEX_FILE))


@app.post("/api/projection", response_model=ProjectionResult)
def projection(payload: PensionInput) -> ProjectionResult:
    return project_capital(payload)


@app.post("/api/report")
def report(payload: PensionInput) -> StreamingResponse:
    projection_data = project_capital(payload)
    commentary = generate_commentary_with_gemini(payload, projection_data)
    pdf_bytes = build_pdf(payload, projection_data, commentary)
    return StreamingResponse(
        pdf_bytes,
        media_type="application/pdf",
        headers={"Content-Disposition": "attachment; filename=report-previdenziale.pdf"},
    )


@app.post("/api/report/send", response_model=ReportEmailResponse)
def report_send(payload: ReportEmailRequest) -> ReportEmailResponse:
    projection_data = project_capital(payload)
    commentary = generate_commentary_with_gemini(payload, projection_data)
    pdf_bytes = build_pdf(payload, projection_data, commentary).getvalue()
    send_report_email(payload.recipient_email, pdf_bytes)
    return ReportEmailResponse(status="sent", recipient_email=payload.recipient_email)
