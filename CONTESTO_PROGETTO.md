# SchoolFaceID — Contesto Completo del Progetto
> Documento aggiornato al 18/05/2026 — da passare a Claude per presentazione o redesign

---

## Cos'è il progetto

**SchoolFaceID** è un sistema automatico di rilevazione presenze scolastiche basato su riconoscimento facciale.
Un Raspberry Pi 4 con webcam USB inquadra gli studenti all'ingresso: se il volto viene riconosciuto, la presenza viene registrata automaticamente su un server PHP/MySQL senza che nessuno debba fare niente.

Il sistema distingue automaticamente entrate, uscite anticipate e ritardi in base all'orario di rilevazione.
I docenti vedono tutto in tempo reale su una dashboard web. Gli studenti hanno un'area personale per consultare le proprie presenze.

---

## Stack tecnologico

| Componente | Tecnologia |
|---|---|
| Hardware rilevazione | Raspberry Pi 4 + Webcam USB |
| Riconoscimento facciale | Python 3.13 + `face_recognition` + OpenCV |
| Comunicazione Raspberry→Server | HTTP POST JSON (`requests`) |
| Backend | PHP 8.2 |
| Database | MySQL / MariaDB (XAMPP) |
| Frontend | PHP puro — nessun framework |
| Aggiornamento real-time | Server-Sent Events (SSE) |
| Email | PHPMailer (installato, non ancora usato in produzione) |
| Avvio automatico Raspberry | systemd |

---

## Architettura del sistema

```
[Webcam USB]
     ↓
[Raspberry Pi 4 — hostname: macarena]
[Python: face_recognition + OpenCV]
[Ambiente virtuale: ~/registro_env]
[Script: ~/registro_facce/riconoscimento.py]
     ↓ HTTP POST JSON (ogni rilevamento)
[Mac con XAMPP — IP variabile sulla rete locale]
[PHP 8.2 + MySQL]
[Server locale: http://localhost/registro]
     ↑
[Browser — Frontend PHP]
     ↑ SSE / polling AJAX ogni 15s
[Dashboard real-time]
```

---

## Struttura file del progetto

```
/htdocs/registro/
│
├── index.php                        ← redirect a pages/login.php
│
├── pages/                           ← area docenti/admin
│   ├── login.php                    ← login con CSRF token, session fixation fix
│   ├── logout.php
│   ├── dashboard.php                ← dashboard real-time (SSE + polling 15s)
│   ├── registro.php                 ← registro presenze con filtri data/classe
│   ├── studenti.php                 ← gestione studenti (aggiungi, visualizza)
│   ├── modifica_presenza.php        ← modifica manuale una presenza
│   ├── recupera_password.php        ← richiesta reset password via email
│   └── reset_password.php           ← reset con token
│
├── studenti_area/                   ← area dedicata agli studenti
│   ├── login.php                    ← login studenti
│   ├── dashboard.php                ← dashboard personale (proprie presenze)
│   ├── logout.php
│   ├── recupera_password.php
│   └── reset_password.php
│
├── api/
│   ├── presenza.php                 ← riceve POST dal Raspberry (API principale)
│   ├── studenti.php                 ← lista studenti per sincronizzazione Raspberry
│   ├── stats.php                    ← dati dashboard in JSON (presenti/assenti/ritardi)
│   └── eventi.php                   ← stream SSE aggiornamenti real-time
│
├── includes/
│   ├── db.php                       ← connessione PDO + funzione foto_path_sicuro()
│   ├── config.php
│   └── mailer.php                   ← PHPMailer configurato
│
├── uploads/
│   └── studenti/                    ← foto studenti (formato: {id}_{nome}_{cognome}/1.png)
│       ├── 1_alessandro_bagni/1.png
│       ├── 2_federico_bruno/1.png
│       ├── 3_giulio_migale/1.png    ← nota: id DB=4, cartella con vecchio numero
│       ├── 4_alan_passerini/1.png
│       ├── 6_riccardo_burani/1.png  ← id DB=9
│       ├── 7_valerio_malato/1.png   ← id DB=10
│       ├── 8_marcello_celani/1.png  ← id DB=11
│       ├── 9_alberto_guglielmi/1.png← id DB=12
│       ├── 10_klaudio_dunga/1.png   ← id DB=13
│       └── 15..24_*/1.png           ← studenti fittizi classi 4^A e 3^B
│
├── cache/
│   └── ultimo_evento.json           ← ultimo riconoscimento (alimenta SSE)
│
└── vendor/                          ← PHPMailer (Composer)
```

---

## Database — schema completo

**Nome DB:** `registro_facciale` | **Host:** localhost | **User:** root | **Password:** vuota

```sql
CREATE TABLE utenti (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(100),
  cognome       VARCHAR(100),
  email         VARCHAR(150) UNIQUE,
  password_hash VARCHAR(255),
  ruolo         ENUM('studente','professore','admin'),
  classe_id     INT NULL,
  foto_path     VARCHAR(255),   -- es. uploads/studenti/1_alessandro_bagni/1.png
  encoding      LONGTEXT,       -- non usato attivamente
  attivo        TINYINT(1) DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classe_id) REFERENCES classi(id)
);

CREATE TABLE presenze (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  studente_id   INT,
  orario_id     INT NULL,
  data          DATE,
  ora_entrata   TIME,
  ora_uscita    TIME,
  stato         ENUM('presente','assente','ritardo','uscita_anticipata'),
  rilevato_da   ENUM('facciale','manuale') DEFAULT 'facciale',
  note          TEXT,
  FOREIGN KEY (studente_id) REFERENCES utenti(id)
);

CREATE TABLE log_riconoscimenti (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  utente_id  INT NULL,
  timestamp  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  confidenza FLOAT,
  esito      ENUM('riconosciuto','sconosciuto'),
  FOREIGN KEY (utente_id) REFERENCES utenti(id)
);

CREATE TABLE classi (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(50),
  anno_scolastico VARCHAR(20)
);

CREATE TABLE materie (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(100),
  colore VARCHAR(7)
);

CREATE TABLE orario (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  classe_id     INT,
  materia_id    INT,
  professore_id INT,
  giorno        ENUM('lunedi','martedi','mercoledi','giovedi','venerdi'),
  ora_inizio    TIME,
  ora_fine      TIME
);

CREATE TABLE password_reset_tokens (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  utente_id  INT,
  token      VARCHAR(255),
  expires_at DATETIME,
  FOREIGN KEY (utente_id) REFERENCES utenti(id)
);
```

---

## Trigger SQL attivi

```sql
-- 1. Auto-imposta ritardo se entrata dopo le 08:15
CREATE TRIGGER trg_ritardo_insert
BEFORE INSERT ON presenze FOR EACH ROW
BEGIN
  IF NEW.ora_entrata IS NOT NULL
     AND TIME(NEW.ora_entrata) > '08:15:00'
     AND NEW.stato = 'presente'
  THEN SET NEW.stato = 'ritardo'; END IF;
END;

-- 2. Auto-imposta uscita_anticipata se ora_uscita < 13:30
CREATE TRIGGER trg_uscita_anticipata_update
BEFORE UPDATE ON presenze FOR EACH ROW
BEGIN
  IF NEW.ora_uscita IS NOT NULL
     AND OLD.ora_uscita IS NULL
     AND TIME(NEW.ora_uscita) < '13:30:00'
  THEN SET NEW.stato = 'uscita_anticipata'; END IF;
END;

-- 3. Pulizia token reset quando studente viene disattivato
CREATE TRIGGER trg_cleanup_token_disattivazione
AFTER UPDATE ON utenti FOR EACH ROW
BEGIN
  IF NEW.attivo = 0 AND OLD.attivo = 1 THEN
    DELETE FROM password_reset_tokens WHERE utente_id = NEW.id;
  END IF;
END;
```

---

## Utenti nel sistema

### Admin / Docenti
| Email | Password | Ruolo |
|---|---|---|
| admin@scuola.it | admin1234 | admin |

### Studenti reali (con foto e face encoding)
| ID | Nome | Cognome | Classe | Stato encoding |
|---|---|---|---|---|
| 1 | Alessandro | Bagni | 5^C | ✅ |
| 2 | Federico | Bruno | 5^C | ✅ |
| 4 | Giulio | Migale | 5^C | ✅ |
| 9 | Riccardo | Burani | 5^C | ✅ |
| 10 | Valerio | Malato | 5^C | ✅ |
| 11 | Marcello | Celani | 5^C | ✅ (encoding CNN) |
| 12 | Alberto | Guglielmi | 5^C | ✅ |
| 13 | Klaudio | Dunga | 5^C | ✅ |

### Studenti fittizi (avatar con iniziali, senza face encoding)
**Classe 4^A:** Francesca Esposito, Laura Mancini, Valentina Greco, Sara Gallo, Giulia Gallo
**Classe 3^B:** Simone Esposito, Filippo Ferrari, Lorenzo Esposito, Laura Romano, Giovanni Gallo

Password tutti: `Nome1234` (es. `Francesca1234`)

---

## Classi
| ID | Nome | Anno |
|---|---|---|
| 1 | 5^C | 2025/2026 |
| 2 | 4^A | 2025/2026 |
| 3 | 3^B | 2025/2026 |

---

## API REST

### POST `/registro/api/presenza.php`
Riceve il riconoscimento dal Raspberry. Autenticata con API key.

**Request:**
```json
{
  "studente_id": 1,
  "confidenza": 0.92,
  "esito": "riconosciuto",
  "timestamp": "2026-05-18 08:45:00",
  "api_key": "REDACTED_API_KEY"
}
```

**Logica presenza:**
- Prima rilevazione del giorno → `entrata` (stato: `presente`)
- Entrata dopo le 08:15 → `ritardo` (gestito dal trigger SQL)
- Seconda rilevazione dopo 30+ minuti → `ora_uscita` (trigger imposta `uscita_anticipata` se < 13:30)
- Scrive `cache/ultimo_evento.json` per alimentare SSE

### GET `/registro/api/studenti.php?api_key=...`
Restituisce lista studenti con foto_path per sincronizzazione Raspberry.

### GET `/registro/api/stats.php`
JSON con contatori dashboard (richiede sessione PHP docente).
```json
{
  "totale": 8,
  "presenti": 6,
  "assenti": 1,
  "ritardi": 1,
  "studenti": [...],
  "riconoscimenti": [...]
}
```

### GET `/registro/api/eventi.php?since=TIMESTAMP`
Stream SSE per aggiornamenti real-time dashboard.

---

## Raspberry Pi 4

| Parametro | Valore |
|---|---|
| Hostname | macarena |
| IP (rete scolastica) | variabile — ultima sessione: 192.168.1.156 |
| Utente SSH | macarena |
| Password SSH | Alle07. |
| Servizio | schoolfaceid.service (systemd) |
| API key | REDACTED_API_KEY |
| Soglia anti-duplicato | 30 secondi (stesso studente ignorato) |

### Script sul Raspberry
```
~/registro_facce/
├── riconoscimento.py      ← loop principale (webcam → face_recognition → POST API)
├── carica_studenti.py     ← sincronizza foto dal server e ricalcola encodings.json
└── studenti/              ← foto locali per encoding
    ├── 1_alessandro_bagni/1.png
    ├── 2_federico_bruno/1.png
    └── ...
```

### Comandi utili Raspberry
```bash
sudo systemctl status schoolfaceid.service
sudo systemctl restart schoolfaceid.service
source ~/registro_env/bin/activate
python3 ~/registro_facce/carica_studenti.py   # sincronizza studenti
```

---

## Design system

**Tema:** Dark navy scuro

```css
--bg-deep:   #070d1a;   /* sfondo principale */
--bg-card:   #0e1829;   /* card */
--bg-card2:  #111f33;   /* card secondaria */
--border:    rgba(255,255,255,0.07);
--blue:      #3b82f6;   /* accento principale */
--green:     #22c55e;   /* presente */
--red:       #ef4444;   /* assente */
--orange:    #f97316;   /* ritardo */
--text-white:#f0f6ff;
--text-muted:#6b7fa3;
--text-dim:  #3d5070;
```

**Font:** Sora (UI) + JetBrains Mono (codice, badge, label)

**Pattern UI ricorrenti:**
- Card con `border-radius: 16px` e bordo `rgba(255,255,255,0.07)`
- Barra colorata 2px in cima alle stat card
- Badge arrotondati per stati presenze (presente/assente/ritardo/uscita_anticipata)
- Animazione `fadeUp` all'ingresso delle sezioni
- Layout principale: `grid-template-columns: 1fr 380px`
- Avatar circolari 72px con `object-fit: cover`
- Sfondo con radial-gradient blu scuro + grid di puntini 32px

---

## Funzionalità implementate

### Area docenti
- Login sicuro (CSRF, session regeneration, bcrypt)
- Dashboard real-time con contatori presenti/assenti/ritardi
- Aggiornamento badge studenti via SSE senza reload pagina
- Lista ultimi riconoscimenti (nome + orario)
- Registro presenze con filtri per data e classe
- Modifica manuale presenze
- Gestione studenti: visualizza lista, aggiungi nuovo studente con foto
- Reset password via email (PHPMailer)
- "Sei uno studente? Accedi qui" nel login docente ↔ "Sei un docente?" nel login studente

### Area studenti
- Login separato (`/studenti_area/login.php`)
- Dashboard personale con proprie statistiche
- Reset password

### Sistema
- Cache-busting automatico sulle immagini studenti (`?v=filemtime()`)
- Conteggio assenti con `max(0, totale - presenti)` — non va mai in negativo
- Trigger SQL per ritardi, uscite anticipate e pulizia token

### Rimosso intenzionalmente
- Export CSV (rimosso su richiesta)
- Pulsanti Modifica/Rimuovi studente dalla vista docente

---

## Flusso completo end-to-end

```
1. Raspberry si avvia → systemd lancia riconoscimento.py dopo 10s
2. Script carica encodings.json (encoding facciali pre-calcolati)
3. Webcam attiva → loop acquisizione frame
4. Volto rilevato → confronto con encodings
5. Match trovato → POST a /api/presenza.php con studente_id + confidenza
6. PHP applica logica presenza (entrata/uscita/ritardo)
7. Trigger SQL eventualmente modifica stato (ritardo/uscita_anticipata)
8. Record salvato in MySQL + scritto cache/ultimo_evento.json
9. Dashboard PHP ascolta SSE → riceve evento → aggiorna badge studente in real-time
10. Nessun reload pagina — polling AJAX /api/stats.php ogni 15 secondi
```

---

## Flusso aggiunta nuovo studente

```
1. Docente aggiunge studente da studenti.php (nome, cognome, email, foto)
2. Record inserito in utenti con password_hash(Nome1234)
3. Sul Raspberry: source ~/registro_env/bin/activate
4.               python3 ~/registro_facce/carica_studenti.py
5. Script scarica foto dal server e ricalcola encodings.json
6. sudo systemctl restart schoolfaceid.service
7. Studente riconoscibile dalla webcam
```

---

## Note tecniche importanti

- **API key:** `REDACTED_API_KEY` — stessa in PHP e Python
- **Cartella cache:** deve avere permessi 777 (`chmod 777 htdocs/registro/cache/`)
- **Foto sul server:** formato `uploads/studenti/{id}_{nome}_{cognome}/1.png` — le foto dei vecchi studenti hanno numerazione disallineata rispetto all'ID nel DB (legacy)
- **Encoding:** calcolato con modello HOG di default; Marcello Celani usa CNN (foto di profilo non frontale)
- **Valerio Malato:** encoding presente ma foto originale era ruotata — ora corretta
- **Alan Passerini:** attivo=0 (disattivato)
- **Krrish Kumar:** attivo=0, nessuna foto

---

## Cosa manca / possibili migliorie

- [ ] Auto-rotazione EXIF foto all'upload (evita il problema foto girate)
- [ ] Cartella foto corretta all'aggiunta studente (ora salva in path non standard)
- [ ] Giustificazioni assenze da area studente
- [ ] Notifiche email assenza automatica (PHPMailer già installato)
- [ ] Pagina statistiche con grafici (Chart.js)
- [ ] Messa online su hosting esterno (Railway o VPS)
- [ ] Gestione orario dal frontend
- [ ] Supporto multi-aula
- [ ] Aggiornamento automatico Raspberry senza script manuale
