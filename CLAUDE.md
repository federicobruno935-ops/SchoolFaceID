# SchoolFaceID — Contesto Progetto per Claude Code

## Panoramica
Sistema automatico di rilevazione presenze scolastiche basato su riconoscimento facciale.
Un Raspberry Pi 4 con webcam USB rileva i volti degli studenti, li confronta con un database
di encoding facciali e registra automaticamente entrate, uscite e ritardi su un server PHP/MySQL.

---

## Stack Tecnologico

| Componente        | Tecnologia                          |
|-------------------|-------------------------------------|
| Hardware          | Raspberry Pi 4 + Webcam USB         |
| Riconoscimento    | Python 3.13 + face_recognition + OpenCV |
| API Raspberry     | HTTP POST JSON (requests)           |
| Backend           | PHP 8.2                             |
| Database          | MySQL (XAMPP)                       |
| Frontend          | PHP puro (no framework)             |
| Aggiornamento RT  | Server-Sent Events (SSE)            |
| Avvio automatico  | systemd (Raspberry)                 |

---

## Architettura

```
[Webcam USB]
     ↓
[Raspberry Pi 4]
[Python: face_recognition + OpenCV]
[Ambiente virtuale: ~/registro_env]
[Script: ~/registro_facce/riconoscimento.py]
     ↓ HTTP POST JSON
[Mac con XAMPP]
[PHP + MySQL]
[Server locale: http://localhost o http://172.20.10.2]
     ↑
[Browser — Frontend PHP]
```

---

## Struttura File Progetto

```
/Applications/XAMPP/xamppfiles/htdocs/registro/
├── login.php                  ← login docenti/admin
├── dashboard.php              ← dashboard presenze real-time (SSE)
├── registro.php               ← registro presenze con filtri
├── studenti.php               ← gestione studenti + upload foto
├── modifica_presenza.php      ← modifica manuale presenza
├── export.php                 ← export CSV presenze
├── logout.php
│
├── studenti_area/             ← area dedicata agli studenti
│   ├── login.php              ← login studenti
│   ├── dashboard.php          ← dashboard personale studente
│   └── logout.php
│
├── api/
│   ├── presenza.php           ← riceve POST dal Raspberry (principale)
│   ├── studenti.php           ← restituisce lista studenti al Raspberry
│   ├── stats.php              ← restituisce dati dashboard in JSON
│   └── eventi.php             ← stream SSE per aggiornamenti real-time
│
├── includes/
│   └── db.php                 ← connessione PDO MySQL
│
├── uploads/
│   └── studenti/              ← foto studenti
│       ├── 1_alessandro_bagni/1.png
│       ├── 2_federico_bruno/1.png
│       ├── 3_giulio_migale/1.png
│       ├── 4_alan_passerini/1.png
│       └── 5_azzurra_dallasta/1.png
│
└── cache/
    └── ultimo_evento.json     ← ultimo riconoscimento (per SSE)
```

---

## Database MySQL

**Nome database:** `registro_facciale`
**Host:** localhost
**User:** root
**Password:** (vuota su XAMPP default)

### Tabelle principali

```sql
-- Utenti (studenti + professori + admin)
CREATE TABLE utenti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100),
  cognome VARCHAR(100),
  email VARCHAR(150) UNIQUE,
  password_hash VARCHAR(255),
  ruolo ENUM('studente','professore','admin'),
  classe_id INT NULL,
  foto_path VARCHAR(255),        -- es. uploads/studenti/1_alessandro_bagni/1.png
  encoding LONGTEXT,             -- encoding facciale JSON (non usato attivamente)
  attivo TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (classe_id) REFERENCES classi(id)
);

-- Presenze
CREATE TABLE presenze (
  id INT AUTO_INCREMENT PRIMARY KEY,
  studente_id INT,
  orario_id INT NULL,            -- nullable
  data DATE,
  ora_entrata TIME,
  ora_uscita TIME,
  stato ENUM('presente','assente','ritardo','uscita_anticipata'),
  rilevato_da ENUM('facciale','manuale') DEFAULT 'facciale',
  note TEXT,
  FOREIGN KEY (studente_id) REFERENCES utenti(id)
);

-- Log riconoscimenti (ogni rilevamento webcam)
CREATE TABLE log_riconoscimenti (
  id INT AUTO_INCREMENT PRIMARY KEY,
  utente_id INT NULL,
  timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  confidenza FLOAT,
  esito ENUM('riconosciuto','sconosciuto'),
  FOREIGN KEY (utente_id) REFERENCES utenti(id)
);

-- Classi
CREATE TABLE classi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(50),
  anno_scolastico VARCHAR(20)
);

-- Materie
CREATE TABLE materie (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(100),
  colore VARCHAR(7)
);

-- Orario
CREATE TABLE orario (
  id INT AUTO_INCREMENT PRIMARY KEY,
  classe_id INT,
  materia_id INT,
  professore_id INT,
  giorno ENUM('lunedi','martedi','mercoledi','giovedi','venerdi'),
  ora_inizio TIME,
  ora_fine TIME
);
```

### Utenti nel sistema
- **admin@scuola.it** — password: `password` — ruolo: admin
- **alessandro.bagni@scuola.it** — studente — id: 1
- **federico.bruno@scuola.it** — studente — id: 2
- **giulio.migale@scuola.it** — studente — id: 3
- **alan.passerini@scuola.it** — studente — id: 4 — password: Alan1234
- **azzurra.dallasta@scuola.it** — studente — id: 5

---

## Raspberry Pi 4

**Hostname:** macarena
**IP locale:** 172.20.10.3
**User SSH:** macarena
**Connessione:** `ssh macarena@172.20.10.3`

### Ambiente Python
```bash
# Attivare ambiente virtuale
source ~/registro_env/bin/activate

# Librerie installate
# face_recognition, opencv-python, fastapi, uvicorn, numpy, requests, pillow
```

### Script sul Raspberry
```
~/registro_facce/
├── riconoscimento.py      ← script principale (gira come servizio systemd)
└── carica_studenti.py     ← sincronizza foto dal server e ricalcola encoding
```

### Servizio systemd
```bash
# Il servizio si chiama schoolfaceid
sudo systemctl status schoolfaceid.service
sudo systemctl restart schoolfaceid.service
sudo systemctl stop schoolfaceid.service

# File servizio
/etc/systemd/system/schoolfaceid.service
```

### Configurazione riconoscimento.py
```python
SERVER_URL     = "http://172.20.10.2/registro/api/presenza.php"
API_KEY        = "REDACTED_API_KEY"
SOGLIA_SECONDI = 30   # ignora stesso studente entro 30 secondi
STUDENTI_DIR   = ~/registro_facce/studenti/
```

### Formato cartelle studenti
```
~/registro_facce/studenti/
├── 1_alessandro_bagni/1.png
├── 2_federico_bruno/1.png
└── ...
```
Il formato è sempre `{id}_{nome}_{cognome}/1.png`

---

## API REST

### POST /registro/api/presenza.php
Riceve riconoscimento dal Raspberry.

**Request:**
```json
{
  "studente_id": 1,
  "confidenza": 0.92,
  "esito": "riconosciuto",
  "timestamp": "2026-04-21 08:45:00",
  "api_key": "REDACTED_API_KEY"
}
```

**Response:**
```json
{
  "stato": "ok",
  "azione": "entrata_registrata",
  "studente": "Alessandro Bagni",
  "stato_pres": "presente",
  "message": "Entrata di Alessandro registrata (presente)"
}
```

**Logica:**
- Prima rilevazione del giorno → entrata
- Seconda rilevazione dopo 30 min → uscita
- Arrivo dopo le 08:15 → ritardo automatico
- Scrive `cache/ultimo_evento.json` per SSE

### GET /registro/api/studenti.php?api_key=...
Restituisce lista studenti per sincronizzazione Raspberry.

### GET /registro/api/stats.php
Restituisce contatori dashboard in JSON (richiede sessione PHP).

### GET /registro/api/eventi.php?since=TIMESTAMP
Stream SSE per aggiornamenti real-time dashboard.

---

## Design System

**Tema:** Dark navy
**Font:** Sora (testo) + JetBrains Mono (codice/badge)
**Colori:**
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

**Pattern ricorrenti:**
- Card con `border-radius: 16px` e bordo sottile
- Barra colorata in cima alle stat card (`height: 2px`)
- Badge arrotondati per stati presenze
- Animazione `fadeUp` all'ingresso delle sezioni
- Footer navbar con logo scuola + numero slide
- Griglia `grid-template-columns: 1fr 380px` per layout principale

---

## Flusso Completo

```
1. Raspberry si avvia → systemd lancia riconoscimento.py
2. Script carica encoding studenti da ~/registro_facce/studenti/
3. Webcam si attiva → loop acquisizione frame
4. Volto rilevato → confronto encoding
5. Match trovato → POST a /api/presenza.php con studente_id
6. PHP salva presenza in MySQL + scrive cache/ultimo_evento.json
7. Dashboard PHP ascolta SSE → riceve evento → aggiorna badge studente
8. Nessun reload pagina — solo AJAX via /api/stats.php
```

---

## Aggiungere un Nuovo Studente

1. Inserire in phpMyAdmin:
```sql
INSERT INTO utenti (nome, cognome, email, ruolo, foto_path, attivo)
VALUES ('Nome', 'Cognome', 'email@scuola.it', 'studente', 'uploads/studenti/6_nome_cognome/1.png', 1);
```

2. Creare cartella e mettere foto:
```
htdocs/registro/uploads/studenti/6_nome_cognome/1.png
```

3. Sul Raspberry sincronizzare:
```bash
source ~/registro_env/bin/activate
python3 ~/registro_facce/carica_studenti.py
sudo systemctl restart schoolfaceid.service
```

---

## Problemi Noti e Soluzioni

| Problema | Soluzione |
|----------|-----------|
| `pkg_resources` not found | Riscritto `face_recognition_models/__init__.py` con `importlib.resources` |
| Display HDMI non funziona via SSH | `export DISPLAY=:0` prima di avviare lo script |
| Webcam occupata da altro processo | `kill $(pgrep ffplay)` — rimuovere `~/.config/autostart/camera.desktop` |
| `face_recognition_models` non funziona | `pip install --force-reinstall face_recognition` + fix __init__.py |
| Permessi cartella cache | `chmod 777 htdocs/registro/cache/` |
| Login non funziona | Verificare `password_hash` in DB — usare `password_hash()` PHP per generarlo |

---

## Cosa Manca / TODO

- [ ] Mettere online su Railway o hosting esterno
- [ ] Notifiche email automatiche per assenze
- [ ] Pagina statistiche con grafici
- [ ] Gestione classi dal frontend
- [ ] Aggiornamento automatico studenti Raspberry senza script manuale
- [ ] Sistema giustificazioni studenti
- [ ] Supporto multi-aula

---

## Note Importanti

- L'API key è `REDACTED_API_KEY` — stessa in PHP e Python
- Il Raspberry usa l'IP `172.20.10.2` per raggiungere il Mac con XAMPP
- La cartella `cache/` deve avere permessi 777 per la scrittura PHP
- Il servizio systemd si chiama `schoolfaceid` e parte dopo 10 secondi dal boot
- `orario_id` nella tabella `presenze` è nullable (modificato con ALTER TABLE)
- Python gira nell'ambiente virtuale `registro_env` — sempre attivare prima
