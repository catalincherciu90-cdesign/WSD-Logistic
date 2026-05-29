<?php
// api/update_location.php
// Telefonul trimite locatia GPS la server
// POST: token, lat, lng

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Metoda nepermisa', 405);
}

$token = getToken();
$lat = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);

if ($lat === false || $lng === false) {
    jsonError('Coordonate GPS invalide');
}

if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    jsonError('Coordonate GPS in afara limitelor');
}

$db = getDB();

// Actualizeaza locatia si marcheaza ca online
$stmt = $db->prepare("
    UPDATE users 
    SET lat = ?, lng = ?, online = 1, last_seen = NOW()
    WHERE token = ?
");
$result = $stmt->execute([$lat, $lng, $token]);

if ($stmt->rowCount() === 0) {
    jsonError('Token negasit. Inregistreaza-te mai intai.');
}

// Daca e client cu cerere activa, actualizeaza si cererea
$stmt2 = $db->prepare("
    UPDATE requests 
    SET client_lat = ?, client_lng = ?
    WHERE client_token = ? AND status IN ('waiting', 'accepted')
");
$stmt2->execute([$lat, $lng, $token]);

jsonResponse(['success' => true]);
?>
