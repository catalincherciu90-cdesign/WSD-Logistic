<?php
// api/get_status.php
// Returneaza statusul complet al platformei pentru un token dat
// GET: token
// Acesta este endpoint-ul de polling - apelat la fiecare 3 secunde

require_once 'config.php';

$token = getToken();

$db = getDB();

// Gaseste userul curent
$stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('Token negasit');
}

// Marcheaza ca online
$db->prepare("UPDATE users SET last_seen = NOW(), online = 1 WHERE token = ?")->execute([$token]);

$response = [
    'success' => true,
    'role' => $user['role'],
    'my_location' => ['lat' => $user['lat'], 'lng' => $user['lng']],
    'active_request' => null,
    'other_location' => null,
    'depanatori_online' => 0,
    'settings' => getSettings($db),
];

// Numara depanatori online (vazuti in ultimele 30 secunde)
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM users 
    WHERE role = 'depanator' AND online = 1 
    AND last_seen > DATE_SUB(NOW(), INTERVAL 30 SECOND)
");
$stmt->execute();
$response['depanatori_online'] = (int)$stmt->fetch()['cnt'];

if ($user['role'] === 'client') {
    // Cererea activa a clientului
    $stmt = $db->prepare("
        SELECT r.*, u.lat as dep_lat, u.lng as dep_lng,
               u.name as dep_name, u.phone as dep_phone,
               dp.vehicle_plate, dp.vehicle_type, dp.vehicle_brand,
               dp.license_number, dp.rating, dp.total_jobs
        FROM requests r
        LEFT JOIN users u ON u.token = r.depanator_token
        LEFT JOIN depanator_profiles dp ON dp.user_token = r.depanator_token
        WHERE r.client_token = ? AND r.status IN ('waiting', 'accepted')
        ORDER BY r.created_at DESC LIMIT 1
    ");
    $stmt->execute([$token]);
    $request = $stmt->fetch();

    if ($request) {
        $response['active_request'] = [
            'id' => $request['id'],
            'status' => $request['status'],
            'price_ron' => $request['price_ron'],
            'distance_km' => $request['distance_km'],
        ];
        if ($request['status'] === 'accepted') {
            if ($request['dep_lat']) {
                $response['other_location'] = [
                    'lat' => (float)$request['dep_lat'],
                    'lng' => (float)$request['dep_lng'],
                ];
            }
            $response['depanator_info'] = [
                'name'          => $request['dep_name'] ?? '—',
                'phone'         => $request['dep_phone'] ?? '—',
                'vehicle_plate' => $request['vehicle_plate'] ?? '—',
                'vehicle_type'  => $request['vehicle_type'] ?? '—',
                'vehicle_brand' => $request['vehicle_brand'] ?? '—',
                'rating'        => $request['rating'] ? round((float)$request['rating'], 1) : null,
                'total_jobs'    => (int)($request['total_jobs'] ?? 0),
            ];
        }
    }
} else {
    // Depanator: cereri in asteptare + cererea acceptata de el
    $stmt = $db->prepare("
        SELECT r.*, u.lat as client_lat2, u.lng as client_lng2
        FROM requests r
        LEFT JOIN users u ON u.token = r.client_token
        WHERE r.status = 'waiting'
        ORDER BY r.created_at DESC LIMIT 5
    ");
    $stmt->execute();
    $response['waiting_requests'] = $stmt->fetchAll();

    // Cererea pe care o are acceptata
    $stmt = $db->prepare("
        SELECT r.*, u.lat as client_lat2, u.lng as client_lng2
        FROM requests r
        LEFT JOIN users u ON u.token = r.client_token
        WHERE r.depanator_token = ? AND r.status = 'accepted'
        ORDER BY r.accepted_at DESC LIMIT 1
    ");
    $stmt->execute([$token]);
    $accepted = $stmt->fetch();

    if ($accepted) {
        $response['active_request'] = [
            'id' => $accepted['id'],
            'status' => $accepted['status'],
            'price_ron' => $accepted['price_ron'],
            'distance_km' => $accepted['distance_km'],
        ];
        if ($accepted['client_lat2']) {
            $response['other_location'] = [
                'lat' => (float)$accepted['client_lat2'],
                'lng' => (float)$accepted['client_lng2'],
            ];
        }
    }
}

jsonResponse($response);

function getSettings($db) {
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['setting_key']] = (float)$r['setting_value'];
    }
    return $out;
}
?>
