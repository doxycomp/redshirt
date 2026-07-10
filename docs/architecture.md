# Redshirt – Architektur- und Konzeptdokument

> **Status:** Entwurf  
> **Stand:** Juli 2026  
> **Zielgruppe:** Praktikanten, Entwickler, KI-Agenten

---

## 1. Projektübersicht

Redshirt ist ein leichtgewichtiges Audit-Logging- und Heartbeat-Monitoring-System. Es zeichnet Zugriffe und Client-Informationen auf empfängt externe Pings von IoT-Geräten und stellt ein Dashboard zur Einsicht und DSGVO-konformen Anonymisierung bereit.

---

## 2. Tech-Stack

| Komponente  | Technologie                                  |
|-------------|----------------------------------------------|
| Backend     | PHP 8.x (nativ, **kein** Framework)          |
| Datenbank   | MariaDB 10+ via PDO                          |
| Frontend    | Vanilla JavaScript (kein Framework)          |
| Webserver   | Apache / nginx (VHost zeigt auf `public/`)   |

---

## 3. Ordnerstruktur (Security by Design)

```text
redshirt/                      # Git-Root
├── .env                       # Konfiguration (DB, API-Token, DSGVO) – IGNORED
├── .gitignore
├── .gitleaks.toml
├── composer.json              # optional, nur für dev-Tools
├── docs/
│   └── architecture.md        # dieses Dokument
├── private/                   # Nicht öffentlich erreichbar
│   ├── db.php                 # DB-Verbindung (Singleton)
│   └── backend.php            # Dashboard-Logik
└── public/                    # Document-Root des Webservers
    ├── .htaccess              # Rewrite-Regeln (falls Apache)
    ├── index.php              # Frontend (Audit-Erfassung + Heartbeat)
    ├── endpoint.php           # REST-API (externer Ping-Empfang)
    ├── dashboard.php          # Slim-Einstiegsseite (lädt backend.php ein)
    ├── assets/
    │   ├── css/
    │   │   └── style.css
    │   └── js/
    │       └── app.js         # Client-Datenerfassung + Heartbeat
    └── config.php             # .env-Ladung (außerhalb von document-root-safe)
```

### Security-Begründung

- **`public/` als Document-Root** – kein Pfad kann versehentlich `.env` oder interne PHP-Dateien ausliefern.
- **`private/`** – nicht über den Webserver erreichbar; wird per `require_once` in die öffentlichen Skripte eingebunden.
- **`public/config.php`** – lädt die `.env` aus dem Git-Root und definiert Konstanten; ist als erster `require` in jedem öffentlichen Script eingebunden.

---

## 4. Komponenten im Detail

### 4.1 `.env` – Konfiguration

```bash
# .env (wird via .gitignore ausgeschlossen)
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=redshirt
DB_USER=redshirt
DB_PASS=geheimes_passwort

API_TOKEN=supersicherer-bearer-token

# true = IP und User-Agent werden nach 24h auf "[anonymisiert]" gesetzt
ANONYMIZE_AFTER_24H=true
```

### 4.2 `public/config.php`

Lädt die `.env` manuell (ohne Library), definiert Konstanten (`DB_DSN`, `API_TOKEN`, `ANONYMIZE_MODE`). Wird von allen öffentlichen Scripten zu Beginn via `require_once __DIR__.'/config.php'` geladen.

### 4.3 `private/db.php` – Datenbankverbindung

- Stellt über PDO eine Verbindung zur MariaDB her.
- Implementiert ein **Singleton**-Muster, sodass pro Request nur eine Instanz existiert.
- Setzt `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION` und `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC`.
- Funktion: `getDb(): PDO`

### 4.4 `public/index.php` – Frontend (Audit-Erfassung)

**Serverseitig (PHP):**
1. Liest `$_SERVER['REMOTE_ADDR']` (IP) und `$_SERVER['HTTP_USER_AGENT']`.
2. Rendert ein HTML-Formular mit einem **Hidden-Field `client_data`**.
3. Das eingebettete JS (`app.js`) wird ausgeliefert.

**Clientseitig (JavaScript – `assets/js/app.js`):**
- Holt beim Laden der Seite Client-Daten ein:
  - `screen.width` / `screen.height`
  - `Intl.DateTimeFormat().resolvedOptions().timeZone`
- Schreibt die Daten als JSON in das Hidden-Field `client_data`.
- Das Formular wird per JS automatisch abgeschickt (POST an `index.php`).
- PHP speichert IP, User-Agent, Auflösung und Zeitzone in `audit_log`.
- **Heartbeat:** JS startet ein `setInterval` (z. B. alle 30 Sekunden), das einen POST an `index.php?action=heartbeat` sendet. Der Server trägt einen Timestamp in `heartbeat_log` ein und aktualisiert `last_heartbeat` in der zugehörigen `audit_log`-Zeile (Session-basiert).

**Ablaufdiagramm (Konzept):**

```text
Browser                   Server                   Datenbank
  │                         │                         │
  ├─ GET /index.php ────────┤                         │
  │                         ├─ audit_log INSERT       │
  │                         │─ (IP, UA, ohne Client)  │
  │                         │←──────────── HTML ──────┤
  │  JS liest Client-Daten  │                         │
  │  POST (client_data) ────┤                         │
  │                         ├─ audit_log UPDATE       │
  │                         │   (Auflösung, Zeitzone) │
  │                         │←── OK ──────────────────┤
  │  setInterval 30s        │                         │
  │  POST ?action=heartbeat─┤                         │
  │                         ├─ heartbeat_log INSERT   │
  │                         │←── OK ──────────────────┤
```

### 4.5 `public/endpoint.php` – REST-API (externe Pings)

**Endpunkte:**

| Methode | Pfad            | Beschreibung                            |
|---------|-----------------|-----------------------------------------|
| POST    | `endpoint.php`  | Ping eines externen Geräts empfangen    |
| GET     | `endpoint.php`  | Status-Abfrage (z. B. Healthcheck)      |

**Authentifizierung:**
- Header: `Authorization: Bearer <API_TOKEN>`
- Bei Fehlen oder falschem Token → `HTTP 401 Unauthorized`.

**POST-Payload (JSON):**
```json
{
  "device_id": "raspberry-pi-01",
  "hostname": "sensor-keller",
  "ip": "192.168.1.42",
  "timestamp": "2026-07-10T12:00:00Z"
}
```

**Verarbeitung:**
- Validiert `device_id` und `hostname` (Pflichtfelder).
- Speichert den Eintrag in `heartbeat_log` (Device-ID, Hostname, IP, Timestamp).
- Gibt `HTTP 200` mit `{"status": "ok"}` zurück.

### 4.6 `private/backend.php` & `public/dashboard.php` – Dashboard

- `public/dashboard.php` ist ein schlanker Einstieg, der `private/backend.php` einbindet.
- `backend.php`:
  1. Fragt die letzten 50–100 Einträge aus `audit_log` und `heartbeat_log` ab (separat oder JOIN).
  2. Rendert eine einfache HTML-Tabelle (ohne Framework).
  3. **DSGVO-Anonymisierung:** Wenn `ANONYMIZE_AFTER_24H=true`, wird vor der Ausgabe ein UPDATE ausgeführt:
     ```sql
     UPDATE audit_log
     SET ip = '[anonymisiert]',
         user_agent = '[anonymisiert]'
     WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
       AND ip != '[anonymisiert]';
     ```
  4. Zeigt ein manuelles "Jetzt anonymisieren"-Button (POST) für Admins.

---

## 5. Datenbank-Schema (Entwurf)

### 5.1 `audit_log`

```sql
CREATE TABLE audit_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL DEFAULT '',
    user_agent   VARCHAR(512) NOT NULL DEFAULT '',
    resolution   VARCHAR(20)  DEFAULT NULL,
    timezone     VARCHAR(64)  DEFAULT NULL,
    session_id   VARCHAR(64)  DEFAULT NULL,
    last_heartbeat DATETIME   DEFAULT NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_session_id (session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 5.2 `heartbeat_log`

```sql
CREATE TABLE heartbeat_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id    VARCHAR(128) NOT NULL,
    hostname     VARCHAR(255) DEFAULT NULL,
    ip           VARCHAR(45)  NOT NULL DEFAULT '',
    source       ENUM('web','api') NOT NULL DEFAULT 'api',
    audit_log_id INT UNSIGNED DEFAULT NULL COMMENT 'FK auf audit_log bei Web-Heartbeats',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_id (device_id),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_heartbeat_audit
        FOREIGN KEY (audit_log_id) REFERENCES audit_log(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Erläuterung:**
- `audit_log` speichert einen Besuch pro Page-Load. Der zugehörige Web-Heartbeat referenziert via `audit_log_id`.
- `heartbeat_log` nimmt sowohl API-Pings (`source='api'`) als auch Web-Heartbeats (`source='web'`) auf.
- `last_heartbeat` in `audit_log` dient als schneller Indikator, ob der Client noch aktiv ist.

---

## 6. Workflow & Security

### 6.1 Entwicklungsstandard

```text
main  (geschützt – keine Direct-Pushes)
 └── dev  (Integrationsbranch)
      └── feature/*  (z. B. feature/dashboard)
      └── fix/*      (z. B. fix/csrf-protection)
      └── doku       (Dokumentation)
```

- Jede Änderung durchläuft einen **Pull Request** in den `main`-Branch.
- Commits sind klein und atomar mit präzisen Nachrichten (Konvention: `typ(kontext): beschreibung`).
- Für dieses Dokument: Branch `doku` (abgeleitet von `dev`).

### 6.2 Gitleaks als Pre-Commit Hook

Zur Vermeidung von Secrets im Repository wird `gitleaks` als Pre-Commit Hook verwendet.

**Installation (einmalig):**
```bash
# gitleaks installieren (via choco / scoop / manuell)
gitleaks install -f pre-commit
```

**Konfiguration (`.gitleaks.toml`):**
```toml
# Erweiterte Regeln für .env-ähnliche Muster
[allowlist]
  description = "Erlaubte Dateien & Pfade"
  paths = [
    "docs/architecture.md",
    ".gitleaks.toml"
  ]
```

Der Hook untersucht alle staged Dateien auf potenzielle Secrets und verhindert den Commit bei Funden.

### 6.3 Weitere Sicherheitsmaßnahmen

| Maßnahme                   | Umsetzung                                                   |
|----------------------------|-------------------------------------------------------------|
| Keine Secrets im Repo      | `.env` in `.gitignore`, `.env.example` als Vorlage          |
| SQL-Injection-Schutz       | Ausschließlich Prepared Statements via PDO                  |
| XSS-Schutz                 | `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` bei jeder Ausgabe |
| CSRF                       | Token im Dashboard-Formular (bei "Jetzt anonymisieren")     |
| Input-Validierung (API)    | JSON-Decode + Typ-Prüfung vor DB-Insert                     |
| Rate-Limiting (API)        | Max. 60 Requests/Minute pro IP (optional, via DB-Counter)   |

---

## 7. Nächste Implementierungsschritte

1. Git-Repository aufsetzen, `.gitignore`, `.gitleaks.toml`, `.env.example` anlegen
2. `public/config.php` und `private/db.php` implementieren
3. Datenbank mit Migrations-Skript (`schema.sql`) erstellen
4. `public/index.php` + `assets/js/app.js` umsetzen (Audit + Heartbeat)
5. `public/endpoint.php` implementieren (Token-Auth + Ping-Speicherung)
6. `private/backend.php` + `public/dashboard.php` bauen (Dashboard + Anonymisierung)
7. End-to-End-Test: Browser-Click → DB-Eintrag → Dashboard-Anzeige → Anonymisierung
8. Gitleaks-Hook testen und PR-Vorlage (Pull-Request-Template) erstellen

---

## 8. Glossar

| Begriff         | Bedeutung                                                    |
|-----------------|--------------------------------------------------------------|
| Audit-Log       | Erfasst wer (IP) wann (Timestamp) worauf (User-Agent) zugegriffen hat |
| Heartbeat       | Regelmäßiges Lebenszeichen eines Clients oder Geräts         |
| DSGVO / GDPR    | Verpflichtet zur Löschung/Anonymisierung personenbezogener Daten |
| Bearer-Token    | HTTP-Authentifizierung via `Authorization: Bearer <token>`   |
