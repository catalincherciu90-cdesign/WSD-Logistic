<?php
// api/get_route.php
// Calculeaza distanta reala pe sosea (OpenRouteService), cu fallback la Haversine.
// Logica e centralizata in config.php (routeDistance) ca sa fie identica cu tarifarea.
// GET: from_lat, from_lng, to_lat, to_lng

require_once 'config.php';

// Endpoint public care atinge un API extern platit (ORS) - limitam abuzul (I9).
rateLimit('get_route', 60, 60);

$fromLat = filter_var($_GET['from_lat'] ?? '', FILTER_VALIDATE_FLOAT);
$fromLng = filter_var($_GET['from_lng'] ?? '', FILTER_VALIDATE_FLOAT);
$toLat   = filter_var($_GET['to_lat'] ?? '', FILTER_VALIDATE_FLOAT);
$toLng   = filter_var($_GET['to_lng'] ?? '', FILTER_VALIDATE_FLOAT);

$route = routeDistance($fromLat, $fromLng, $toLat, $toLng);
if ($route === null) {
    jsonError('Coordonate invalide');
}

jsonResponse(['success' => true] + $route);
?>
