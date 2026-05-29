<?php
// api/get_route.php
// Calculeaza distanta reala pe sosea folosind OpenRouteService
// GET: from_lat, from_lng, to_lat, to_lng

require_once 'config.php';

// Cheia OpenRouteService vine din config.local.php / variabila de mediu ORS_KEY.
$ORS_KEY = ORS_KEY;
if (empty($ORS_KEY)) {
    jsonError('Serviciul de rutare nu este configurat', 503);
}

$fromLat = filter_var($_GET['from_lat'] ?? '', FILTER_VALIDATE_FLOAT);
$fromLng = filter_var($_GET['from_lng'] ?? '', FILTER_VALIDATE_FLOAT);
$toLat   = filter_var($_GET['to_lat'] ?? '', FILTER_VALIDATE_FLOAT);
$toLng   = filter_var($_GET['to_lng'] ?? '', FILTER_VALIDATE_FLOAT);

if ($fromLat === false || $fromLng === false || $toLat === false || $toLng === false) {
    jsonError('Coordonate invalide');
}

$url = "https://api.openrouteservice.org/v2/directions/driving-car?api_key={$ORS_KEY}"
     . "&start={$fromLng},{$fromLat}&end={$toLng},{$toLat}";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/geo+json, application/json',
        'Content-Type: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    // Fallback la Haversine daca ORS nu raspunde
    $dist = haversine($fromLat, $fromLng, $toLat, $toLng) * 1.28;
    jsonResponse([
        'success'     => true,
        'distance_km' => round($dist, 2),
        'duration_min'=> round(($dist / 40) * 60),
        'source'      => 'haversine'
    ]);
}

$data = json_decode($response, true);

if (!isset($data['features'][0]['properties']['segments'][0])) {
    $dist = haversine($fromLat, $fromLng, $toLat, $toLng) * 1.28;
    jsonResponse([
        'success'     => true,
        'distance_km' => round($dist, 2),
        'duration_min'=> round(($dist / 40) * 60),
        'source'      => 'haversine'
    ]);
}

$segment  = $data['features'][0]['properties']['segments'][0];
$distKm   = round($segment['distance'] / 1000, 2);
$durationMin = round($segment['duration'] / 60);

// Extrage coordonatele rutei pentru hartă
$geometry = $data['features'][0]['geometry']['coordinates'] ?? [];
$polyline = array_map(fn($c) => [$c[1], $c[0]], $geometry); // [lng,lat] -> [lat,lng]

jsonResponse([
    'success'     => true,
    'distance_km' => $distKm,
    'duration_min'=> $durationMin,
    'polyline'    => $polyline,
    'source'      => 'ors'
]);

function haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2)*sin($dLat/2) + cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLng/2)*sin($dLng/2);
    return $R * 2 * atan2(sqrt($a), sqrt(1-$a));
}
?>
