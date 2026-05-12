# Vantaggio Pensione — Bundle Portabile (HTML + PHP)

Versione **standalone** del calcolatore: nessun Python, nessun Render. Funziona su qualsiasi hosting che supporti **PHP 7.4+** (Aruba, SiteGround, Keliweb, IONOS, hosting condiviso, ecc.) e si integra in **WordPress** come pagina dedicata.

---

## Cosa contiene

| File | Funzione |
|---|---|
| `index.html` | Calcolatore + form lead + modale conferma. Genera il PDF nel browser via jsPDF. |
| `invia-report.php` | Riceve dati lead + PDF base64 → invia email con PDF allegato. |
| `gemini-proxy.php` | Proxy verso Google Gemini per l'analisi AI (chiave segreta lato server). |
| `config.php.example` | Template di configurazione (rinomina in `config.php` e compila). |
| `composer.json` | Dipendenza opzionale **PHPMailer** per SMTP autenticato (Gmail, Aruba SMTP, ecc.). |
| `.htaccess` | Blocca accesso web ai file sensibili (config, log). |

---

## Setup in 5 minuti (Aruba + WordPress)

### Step 1 — Carica i file via FTP

Crea su Aruba una sottocartella, ad esempio `httpdocs/calcolatore/` (o `public_html/calcolatore/`), e carica al suo interno:
- `index.html`
- `invia-report.php`
- `gemini-proxy.php`
- `config.php.example`
- `.htaccess`
- `composer.json`

### Step 2 — Configura `config.php`

Sul tuo PC (o via editor del pannello Aruba), copia `config.php.example` come `config.php` e compila i campi:

```php
'GEMINI_API_KEY' => 'AIza...',                  // dalla tua console Google AI Studio
'SMTP_HOST'      => 'smtps.aruba.it',           // o 'smtp.gmail.com'
'SMTP_PORT'      => 587,
'SMTP_USER'      => 'info@tuodominio.it',
'SMTP_PASS'      => 'la_tua_password',          // Gmail: usa App Password
'MITTENTE_EMAIL' => 'info@tuodominio.it',       // deve appartenere al tuo dominio Aruba
'BCC_CONSULENTE' => 'domenico.mosca@pfafineco.it',
```

Ricarica `config.php` (compilato) nella stessa cartella.

### Step 3 — Installa PHPMailer (consigliato)

PHPMailer è quello che permette l'invio SMTP autenticato (necessario con Gmail e con Aruba SMTP).

**Opzione A — via Composer (se hai SSH su Aruba):**
```bash
cd /home/.../calcolatore/
composer require phpmailer/phpmailer
```

**Opzione B — manuale (senza Composer, sempre funziona):**
1. Scarica https://github.com/PHPMailer/PHPMailer/archive/refs/heads/master.zip
2. Estrai e rinomina la cartella in `PHPMailer/`
3. Carica `PHPMailer/` dentro `calcolatore/` (deve esistere il file `calcolatore/PHPMailer/src/PHPMailer.php`)

> Se salti questo step, lo script userà `mail()` di PHP — funziona ma la deliverability è inferiore e Gmail non ti farà autenticare. Consigliato installare PHPMailer.

### Step 4 — Test rapido

Apri nel browser:
```
https://www.tuodominio.it/calcolatore/
```

- Compila il calcolatore → vedi il numerone verde
- Compila il form lead (nome, cognome, email, privacy) e premi "Sblocca Analisi"
- Devi ricevere l'email con il PDF allegato

Se l'email non arriva: apri lo stesso URL con `?debug=1`:
```
https://www.tuodominio.it/calcolatore/?debug=1
```
e nel modale comparirà l'errore esatto (auth, sender refused, ecc.).

I log del server sono in `invia-report.log` (visibile solo via FTP, bloccato dal `.htaccess`).

### Step 5 — Linka da WordPress

Nel pannello WordPress:

1. **Aspetto → Menu** → aggiungi un **Link personalizzato** con URL `https://www.tuodominio.it/calcolatore/` e label "Calcolatore Pensione" → salva.
2. Oppure crea una pagina WP "Calcolatore" con un blocco **HTML personalizzato** contenente:
   ```html
   <iframe src="/calcolatore/" style="width:100%;height:1400px;border:0" loading="lazy"></iframe>
   ```
   (l'iframe mantiene header/footer del tema WP attorno al calcolatore).

---

## Personalizzazione

### Branding consulente
Modifica in `config.php`:
```php
'ADVISOR_NAME'  => 'Mario Rossi',
'ADVISOR_TITLE' => 'Consulente Finanziario XYZ',
'ADVISOR_PHONE' => '333 1234567',
'ADVISOR_EMAIL' => 'mario.rossi@example.it',
```
Compaiono nell'header del PDF, nel footer del PDF e nella firma dell'email HTML.

### Colori
Apri `index.html` e cerca `#003B5C` (blu scuro) e `#005A78` (teal): sono usati ovunque per pulsanti, header e accenti.

### Testi
Cerca stringhe italiane nel `index.html` (es. "Vantaggio Netto Stimato") e modificale a piacere.

---

## Sicurezza

- Il file `config.php` contiene segreti (chiavi API, password SMTP). Il `.htaccess` blocca l'accesso diretto via web.
- Su nginx (raro su Aruba) aggiungi una regola equivalente:
  ```nginx
  location ~ ^/calcolatore/(config\.php|.*\.log)$ { deny all; return 404; }
  ```
- Non committare `config.php` su Git: c'è già un `.gitignore` che lo esclude.

---

## Troubleshooting

| Sintomo | Causa probabile | Fix |
|---|---|---|
| Modale: "Invio email temporaneamente non disponibile" | SMTP rifiuta autenticazione | Apri con `?debug=1` per vedere l'errore esatto. Verifica `SMTP_USER`/`SMTP_PASS` in `config.php`. |
| Errore Gmail `535 Username and Password not accepted` | Non hai usato App Password | Su `myaccount.google.com/apppasswords` genera App Password e mettila in `SMTP_PASS`. |
| Errore `554 5.7.1 sender refused` | `MITTENTE_EMAIL` non appartiene al dominio autenticato | Imposta `MITTENTE_EMAIL` = `SMTP_USER` (es. tutti e due `info@tuodominio.it`). |
| AI Gemini non risponde | Chiave assente o modello sbagliato | In `config.php` verifica `GEMINI_API_KEY` valida e `GEMINI_MODEL = 'gemini-2.5-flash'`. |
| `mail()` ritorna false | PHPMailer mancante e sendmail di Aruba bloccato | Installa PHPMailer (Step 3) e configura SMTP. |
| Pagina bianca / 500 server error | PHP < 7.4 o errore di sintassi in `config.php` | Controlla i log del pannello Aruba. PHP 7.4+ richiesto. |

---

## Differenze rispetto alla versione FastAPI/Render

| | FastAPI (Render) | Bundle PHP (Aruba) |
|---|---|---|
| Backend | Python + FastAPI | PHP 7.4+ (puro) |
| Email | `smtplib` + `email.message` | PHPMailer + SMTP (o `mail()`) |
| Deploy | Push Git → auto-deploy | Upload FTP |
| Costo hosting | Render (free/paid) | Compreso nel piano Aruba |
| Performance | Server dedicato | Shared hosting |
| Funzionalità | Identiche | Identiche |
