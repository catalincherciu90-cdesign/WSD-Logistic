<?php
// DepanAuto - Configurare
// ATENTIE: nu pune secrete in acest fisier (este versionat in Git).
// Optiuni de configurare a secretelor:
//   1. Copiaza config.local.example.php in config.local.php si completeaza valorile
//      (config.local.php este ignorat de Git).
//   2. SAU seteaza variabile de mediu (DB_HOST, DB_NAME, DB_USER, DB_PASS,
//      ORS_KEY, ALLOWED_ORIGIN) la nivel de server / panou hosting.

// Incarca config local (secrete) daca exista
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// Fallback la variabile de mediu (daca nu au fost deja definite in config.local.php)
if (!defined('DB_HOST'))        define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_NAME'))        define('DB_NAME', getenv('DB_NAME') ?: 'depanauto');
if (!defined('DB_USER'))        define('DB_USER', getenv('DB_USER') ?: '');
if (!defined('DB_PASS'))        define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_CHARSET'))     define('DB_CHARSET', 'utf8mb4');
if (!defined('ORS_KEY'))        define('ORS_KEY', getenv('ORS_KEY') ?: '');
// Origine permisa pentru CORS. NU folosi '*' in productie.
if (!defined('ALLOWED_ORIGIN')) define('ALLOWED_ORIGIN', getenv('ALLOWED_ORIGIN') ?: 'https://wsdlogistics.ro');

// Conectare la baza de date
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            // Nu expunem detaliile interne ale erorii catre client; le logam.
            error_log('DepanAuto DB error: ' . $e->getMessage());
            jsonError('Eroare interna de server', 500);
        }
    }
    return $pdo;
}

// Trimite header-ele CORS (origine restransa, nu '*')
function corsHeaders() {
    header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
}

// Raspuns JSON standardizat
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    corsHeaders();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError($message, $status = 400) {
    jsonResponse(['success' => false, 'error' => $message], $status);
}

// Valideaza si returneaza token-ul din request
function getToken() {
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
        jsonError('Token invalid');
    }
    return $token;
}

// Rate limiting simplu, per-IP, bazat pe fisiere (fara dependente externe).
// $bucket = numele actiunii; arunca 429 daca se depaseste limita in fereastra data.
function rateLimit($bucket, $maxRequests = 30, $windowSeconds = 60) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $bucket . '_' . $ip);
    $dir = sys_get_temp_dir() . '/depanauto_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . $key . '.json';
    $now  = time();

    $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    $fp = @fopen($file, 'c+');
    if ($fp && flock($fp, LOCK_EX)) {
        $raw    = stream_get_contents($fp);
        $stored = json_decode($raw, true);
        if (is_array($stored) && isset($stored['reset'], $stored['count']) && $stored['reset'] > $now) {
            $data = $stored;
        }
        $data['count']++;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    if ($data['count'] > $maxRequests) {
        header('Retry-After: ' . max(1, $data['reset'] - $now));
        jsonError('Prea multe cereri. Incearca din nou mai tarziu.', 429);
    }
}

// Gestioneaza OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    corsHeaders();
    http_response_code(204);
    exit;
}
