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

// --- Tarifare & rutare (valori implicite; pot fi suprascrise din config.local / env) ---
// Trebuie sa fie identice cu seed-ul din schema.sql si cu PRICE_DEFAULTS din Worker.
if (!defined('PRICE_PER_KM'))    define('PRICE_PER_KM',    (float)(getenv('PRICE_PER_KM')    ?: 4.5));
if (!defined('PRICE_FIXED'))     define('PRICE_FIXED',     (float)(getenv('PRICE_FIXED')     ?: 10));
if (!defined('PRICE_MIN'))       define('PRICE_MIN',       (float)(getenv('PRICE_MIN')       ?: 25));
// Factor linie dreapta -> drum real (folosit cand ORS nu raspunde).
if (!defined('ROUTE_FACTOR'))    define('ROUTE_FACTOR',    (float)(getenv('ROUTE_FACTOR')    ?: 1.28));
// Plafon de siguranta: peste atata = GPS aberant, refuzam cursa.
if (!defined('MAX_DISTANCE_KM')) define('MAX_DISTANCE_KM', (float)(getenv('MAX_DISTANCE_KM') ?: 300));
// Viteza medie presupusa pt. ETA cand nu avem durata reala (km/h).
if (!defined('AVG_SPEED_KMH'))   define('AVG_SPEED_KMH',   (float)(getenv('AVG_SPEED_KMH')   ?: 40));

// Nu expune erorile PHP catre client (ar rupe raspunsul JSON si ar scurge detalii).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// Orice exceptie / eroare necaptata -> raspuns JSON 500 curat (nu stack trace).
set_exception_handler(function ($e) {
    error_log('DepanAuto uncaught: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: ' . (defined('ALLOWED_ORIGIN') ? ALLOWED_ORIGIN : '*'));
    }
    echo json_encode(['success' => false, 'error' => 'Eroare interna de server']);
    exit;
});

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
    } else {
        // Nu putem scrie (director ne-scriabil / open_basedir): rate-limiting ar fi
        // dezactivat silentios. Logam, ca sa fie vizibil in loc de "fail-open" tacut (I3).
        error_log("DepanAuto rateLimit: nu pot scrie in $file - rate limiting inactiv pentru '$bucket'");
    }

    if ($data['count'] > $maxRequests) {
        header('Retry-After: ' . max(1, $data['reset'] - $now));
        jsonError('Prea multe cereri. Incearca din nou mai tarziu.', 429);
    }
}

// ---------- Geo, tarifare & rutare ----------

// Distanta ortodromica (linie dreapta) in km.
function haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// Coordonata plauzibila: in limite si nu 0,0 ("Null Island" = GPS lipsa).
function validCoord($lat, $lng) {
    if (!is_numeric($lat) || !is_numeric($lng)) return false;
    $lat = (float)$lat; $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
    if (abs($lat) < 1e-4 && abs($lng) < 1e-4) return false;
    return true;
}

// Setarile de tarifare, cu valori implicite garantate (niciun tarif <= 0).
function getSettings($db) {
    $defaults = [
        'price_per_km' => PRICE_PER_KM,
        'price_fixed'  => PRICE_FIXED,
        'price_min'    => PRICE_MIN,
    ];
    $out = $defaults;
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
        foreach ($stmt->fetchAll() as $r) {
            if (is_numeric($r['setting_value'])) $out[$r['setting_key']] = (float)$r['setting_value'];
        }
    } catch (Exception $e) {
        error_log('DepanAuto settings error: ' . $e->getMessage());
    }
    foreach ($defaults as $k => $def) {
        if (!isset($out[$k]) || !is_numeric($out[$k]) || $out[$k] <= 0) $out[$k] = $def;
    }
    return $out;
}

// Pretul cursei pentru o distanta data (km) si setarile de tarifare.
function computePrice($distanceKm, $settings) {
    return max($settings['price_min'], $settings['price_fixed'] + $distanceKm * $settings['price_per_km']);
}

// Distanta A -> B: incearca drumul real (OpenRouteService), cu fallback la
// Haversine * ROUTE_FACTOR. Sursa unica pentru harta SI pentru tarifare.
// Returneaza ['distance_km','duration_min','polyline','source'] sau null (coord. invalide).
function routeDistance($fromLat, $fromLng, $toLat, $toLng) {
    if (!validCoord($fromLat, $fromLng) || !validCoord($toLat, $toLng)) return null;
    $fromLat = (float)$fromLat; $fromLng = (float)$fromLng;
    $toLat   = (float)$toLat;   $toLng   = (float)$toLng;

    $fallback = function ($source = 'haversine') use ($fromLat, $fromLng, $toLat, $toLng) {
        $dist = haversine($fromLat, $fromLng, $toLat, $toLng) * ROUTE_FACTOR;
        return [
            'distance_km'  => round($dist, 2),
            'duration_min' => (int)round(($dist / AVG_SPEED_KMH) * 60),
            'polyline'     => [],
            'source'       => $source,
        ];
    };

    if (!ORS_KEY || !function_exists('curl_init')) return $fallback();

    $url = "https://api.openrouteservice.org/v2/directions/driving-car?api_key=" . ORS_KEY
         . "&start={$fromLng},{$fromLat}&end={$toLng},{$toLat}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/geo+json, application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return $fallback();
    $data = json_decode($response, true);
    $segment = $data['features'][0]['properties']['segments'][0] ?? null;
    if (!$segment || !isset($segment['distance'])) return $fallback();

    $geometry = $data['features'][0]['geometry']['coordinates'] ?? [];
    $polyline = array_map(fn($c) => [$c[1], $c[0]], $geometry); // [lng,lat] -> [lat,lng]

    return [
        'distance_km'  => round($segment['distance'] / 1000, 2),
        'duration_min' => (int)round(($segment['duration'] ?? 0) / 60),
        'polyline'     => $polyline,
        'source'       => 'ors',
    ];
}

// Gestioneaza OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    corsHeaders();
    http_response_code(204);
    exit;
}
