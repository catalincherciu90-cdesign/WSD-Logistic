<?php
// DepanAuto - Secrete locale (TEMPLATE)
// Copiaza acest fisier in `config.local.php` si completeaza valorile reale.
// `config.local.php` este ignorat de Git si NU trebuie comis niciodata.
//
// Alternativ, poti seta aceleasi valori ca variabile de mediu pe server
// (DB_HOST, DB_NAME, DB_USER, DB_PASS, ORS_KEY, ALLOWED_ORIGIN) si poti
// renunta la acest fisier.

define('DB_HOST', 'localhost');
define('DB_NAME', 'depanauto');
define('DB_USER', 'utilizatorul_tau_mysql');
define('DB_PASS', 'parola_ta_mysql');

// Cheia OpenRouteService (https://openrouteservice.org/dev/#/signup)
define('ORS_KEY', 'cheia_ta_openrouteservice');

// Domeniul de pe care se acceseaza API-ul (pentru CORS). Fara '/' la final.
define('ALLOWED_ORIGIN', 'https://wsdlogistics.ro');
