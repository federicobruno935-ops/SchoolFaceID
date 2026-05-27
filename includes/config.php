<?php
// Legge tutte le credenziali da variabili d'ambiente; i valori hardcoded sono solo fallback per sviluppo locale.
define('SCHOOLFACEID_API_KEY', getenv('API_KEY')        ?: 'REDACTED_API_KEY');
define('BASE_URL',             getenv('BASE_URL')        ?: 'https://macarena.altervista.org/registro');
define('MAIL_FROM',            getenv('SMTP_USER')       ?: 'federicobruno935@gmail.com');
define('MAIL_FROM_NAME', 'SchoolFaceID');
define('SMTP_HOST',            getenv('SMTP_HOST')       ?: 'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',            getenv('SMTP_USER')       ?: 'federicobruno935@gmail.com');
define('SMTP_PASS',            getenv('SMTP_PASS')       ?: 'REDACTED_SMTP_PASS');
