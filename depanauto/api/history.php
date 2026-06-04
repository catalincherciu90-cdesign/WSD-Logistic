<?php
require_once 'config.php';

$token  = getToken();
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;
$db     = getDB();

$stmt = $db->prepare("SELECT role, name FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) jsonError('Token invalid');

if ($user['role'] === 'client') {
    $stmt = $db->prepare("
        SELECT r.*,
               u.name AS depanator_name, u.phone AS depanator_phone, u.token AS depanator_token,
               dp.vehicle_plate, dp.vehicle_type, dp.vehicle_brand,
               rt.stars AS my_rating
        FROM requests r
        LEFT JOIN users u ON u.token = r.depanator_token
        LEFT JOIN depanator_profiles dp ON dp.user_token = r.depanator_token
        LEFT JOIN ratings rt ON rt.request_id = r.id
        WHERE r.client_token = ?
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$token, $limit, $offset]);
} else {
    $stmt = $db->prepare("
        SELECT r.*,
               u.name AS client_name, u.phone AS client_phone,
               cp.car_plate, cp.car_brand, cp.car_model,
               rt.stars AS my_rating
        FROM requests r
        LEFT JOIN users u ON u.token = r.client_token
        LEFT JOIN client_profiles cp ON cp.user_token = r.client_token
        LEFT JOIN ratings rt ON rt.request_id = r.id
        WHERE r.depanator_token = ?
        ORDER BY r.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$token, $limit, $offset]);
}

$rows = $stmt->fetchAll();

if ($user['role'] === 'client') {
    $total = $db->prepare("SELECT COUNT(*) FROM requests WHERE client_token = ?");
    $stats = $db->prepare("SELECT COUNT(*) as total, SUM(price_ron) as total_spent FROM requests WHERE client_token = ? AND status = 'completed'");
} else {
    $total = $db->prepare("SELECT COUNT(*) FROM requests WHERE depanator_token = ?");
    $stats = $db->prepare("SELECT COUNT(*) as total, SUM(price_ron) as total_earned FROM requests WHERE depanator_token = ? AND status = 'completed'");
}
$total->execute([$token]);
$stats->execute([$token]);

jsonResponse([
    'success' => true,
    'role'    => $user['role'],
    'name'    => $user['name'],
    'history' => $rows,
    'total'   => (int)$total->fetchColumn(),
    'page'    => $page,
    'stats'   => $stats->fetch(),
]);
?>
