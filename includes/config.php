<?php
/**
 * Configurazione centralizzata — NON versionare con credenziali reali in produzione.
 * In produzione spostare in variabili d'ambiente o fuori dalla webroot.
 */
define('SCHOOLFACEID_API_KEY', 'REDACTED_API_KEY');
define('BASE_URL', 'http://localhost/registro');
define('MAIL_FROM',      'federicobruno935@gmail.com');
define('MAIL_FROM_NAME', 'SchoolFaceID');
define('SMTP_HOST',      'smtp.gmail.com');
define('SMTP_PORT',      587);
define('SMTP_USER',      'federicobruno935@gmail.com');
define('SMTP_PASS',      'REDACTED_SMTP_PASS');
