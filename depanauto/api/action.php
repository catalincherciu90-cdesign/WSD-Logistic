<?php
// api/action.php
require_once 'config.php';
require_once 'send_notification.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Metoda nepermisa', 405);

$token = getToken();
$action = $_POST['action'] ?? '';

$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) jsonError('Token negasit');

switch ($action) {

    case 'accept_request':
        if ($user['role'] !== 'depanator') jsonError('Doar depanatorii pot accepta cereri');

        $requestId = (int)($_POST['request_id'] ?? 0);
        $depLat = filter_var($_POST['dep_lat'] ?? '', FILTER_VALIDATE_FLOAT);
        $depLng = filter_var($_POST['dep_lng'] ?? '', FILTER_VALIDATE_FLOAT);

        $stmt = $db->prepare("SELECT * FROM requests WHERE id = ? AND status = 'waiting'");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) jsonError('Cerere negasita sau deja acceptata');

        $useLat = ($depLat !== false && $depLat != 0) ? $depLat : $user['lat'];
        $useLng = ($depLng !== false && $depLng != 0) ? $depLng : $user['lng'];

        if (!$useLat || !$useLng || !$request['client_lat'] || !$request['client_lng']) {
            jsonError('GPS indisponibil. Asteapta cateva secunde si incearca din nou.');
        }

        // Aceeasi rutare ca harta -> distanta afisata = distanta facturata.
        $route = routeDistance($useLat, $useLng, $request['client_lat'], $request['client_lng']);
        if ($route === null) {
            jsonError('Coordonate GPS invalide. Asteapta cateva secunde si incearca din nou.');
        }
        if ($route['distance_km'] > MAX_DISTANCE_KM) {
            jsonError('Distanta calculata este neverosimil de mare (GPS instabil). Reincearca.');
        }

        $settings  = getSettings($db);
        $distRoute = $route['distance_km'];
        $price     = computePrice($distRoute, $settings);

        $db->prepare("UPDATE users SET lat = ?, lng = ? WHERE token = ?")->execute([$useLat, $useLng, $token]);

        $stmt = $db->prepare("
            UPDATE requests 
            SET status = 'accepted', depanator_token = ?, distance_km = ?, price_ron = ?, accepted_at = NOW()
            WHERE id = ? AND status = 'waiting'
        ");
        $stmt->execute([$token, round($distRoute, 2), round($price, 2), $requestId]);

        if ($stmt->rowCount() === 0) jsonError('Nu s-a putut accepta cererea');

        // Notifica clientul ca un depanator a acceptat (best-effort).
        notifyUserByToken(
            $db,
            $request['client_token'],
            'Depanator pe drum! 🚗',
            ($user['name'] ?: 'Un depanator') . ' ți-a acceptat cererea și vine spre tine.',
            ['type' => 'accepted', 'request_id' => (string)$requestId]
        );

        jsonResponse(['success' => true, 'distance_km' => round($distRoute, 2), 'price_ron' => round($price, 2)]);
        break;

    case 'cancel_request':
        $reason = trim($_POST['reason'] ?? '');
        if ($user['role'] === 'client') {
            $stmt = $db->prepare("
                UPDATE requests 
                SET status = 'cancelled', cancelled_by = 'client', 
                    cancel_reason = ?, cancelled_at = NOW()
                WHERE client_token = ? AND status IN ('waiting','accepted')
            ");
            $stmt->execute([$reason ?: 'Anulat de client', $token]);
        } else {
            $stmt = $db->prepare("
                UPDATE requests 
                SET status = 'waiting', depanator_token = NULL, accepted_at = NULL,
                    cancelled_by = 'depanator', cancel_reason = ?, cancelled_at = NOW()
                WHERE depanator_token = ? AND status = 'accepted'
            ");
            $stmt->execute([$reason ?: 'Anulat de depanator', $token]);
        }
        jsonResponse(['success' => true]);
        break;

    case 'complete_request':
        if ($user['role'] !== 'depanator') jsonError('Doar depanatorii pot finaliza');
        $stmt = $db->prepare("UPDATE requests SET status = 'completed', completed_at = NOW() WHERE depanator_token = ? AND status = 'accepted'");
        $stmt->execute([$token]);
        jsonResponse(['success' => true]);
        break;

    // NOTA: tarifele se schimba DOAR prin admin.php (cu sesiune de admin).
    // Vechiul 'update_settings' de aici permitea oricarui depanator sa modifice
    // preturile platformei - eliminat din motive de securitate.

    default:
        jsonError('Actiune necunoscuta');
}
// haversine() / getSettings() / computePrice() / routeDistance() sunt definite in config.php
?>
