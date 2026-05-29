<?php
// api/rating.php
// POST: token, request_id, stars (1-5)
// GET:  token -> returneaza ratingul mediu al depanatorului

require_once 'config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token     = getToken();
    $requestId = (int)($_POST['request_id'] ?? 0);
    $stars     = (int)($_POST['stars'] ?? 0);

    if ($stars < 1 || $stars > 5) jsonError('Rating invalid. Valori acceptate: 1-5');

    // Verifica ca e client si cererea ii apartine
    $stmt = $db->prepare("
        SELECT r.*, u.role FROM requests r
        JOIN users u ON u.token = ?
        WHERE r.id = ? AND r.client_token = ? AND r.status = 'completed'
    ");
    $stmt->execute([$token, $requestId, $token]);
    $request = $stmt->fetch();

    if (!$request) jsonError('Cerere negasita sau nu poti evalua aceasta cursa');
    if ($request['role'] !== 'client') jsonError('Doar clientii pot da rating');

    // Verifica sa nu fi dat deja rating
    $stmt = $db->prepare("SELECT id FROM ratings WHERE request_id = ?");
    $stmt->execute([$requestId]);
    if ($stmt->fetch()) jsonError('Ai evaluat deja aceasta cursa');

    // Salveaza ratingul
    $stmt = $db->prepare("
        INSERT INTO ratings (request_id, client_token, depanator_token, stars)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$requestId, $token, $request['depanator_token'], $stars]);

    // Actualizeaza media in depanator_profiles
    $stmt = $db->prepare("
        SELECT AVG(stars) as avg_stars, COUNT(*) as total
        FROM ratings WHERE depanator_token = ?
    ");
    $stmt->execute([$request['depanator_token']]);
    $avg = $stmt->fetch();

    $db->prepare("
        UPDATE depanator_profiles
        SET rating = ?, total_jobs = ?
        WHERE user_token = ?
    ")->execute([round($avg['avg_stars'], 2), $avg['total'], $request['depanator_token']]);

    jsonResponse(['success' => true, 'new_avg' => round($avg['avg_stars'], 2)]);

} else {
    // GET - returneaza ratingul unui depanator
    $token = getToken();
    $stmt = $db->prepare("SELECT rating, total_jobs FROM depanator_profiles WHERE user_token = ?");
    $stmt->execute([$token]);
    $profile = $stmt->fetch();
    jsonResponse([
        'success'    => true,
        'rating'     => $profile ? (float)$profile['rating'] : 5.0,
        'total_jobs' => $profile ? (int)$profile['total_jobs'] : 0,
    ]);
}
?>
