<?php
// api/create_request.php
// Clientul trimite o cerere de depanare
// POST: token, lat, lng

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Metoda nepermisa', 405);

$token = getToken();

// Anti-spam: max 10 cereri / minut / IP.
rateLimit('create_request', 10, 60);

$lat = filter_var($_POST['lat'] ?? '', FILTER_VALIDATE_FLOAT);
$lng = filter_var($_POST['lng'] ?? '', FILTER_VALIDATE_FLOAT);

if ($lat === false || $lng === false) jsonError('GPS invalid');
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) jsonError('Coordonate GPS in afara limitelor');

$db = getDB();

// Verifica ca e client
$stmt = $db->prepare("SELECT role FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user || $user['role'] !== 'client') jsonError('Doar clientii pot trimite cereri');

// Nu are deja cerere activa
$stmt = $db->prepare("SELECT id FROM requests WHERE client_token = ? AND status IN ('waiting','accepted')");
$stmt->execute([$token]);
if ($stmt->fetch()) jsonError('Ai deja o cerere activa');

// Tipul problemei (optional, dar recomandat) + descriere scurta
$allowedProblems = ['pana', 'baterie', 'nu_porneste', 'tractare', 'combustibil', 'accident', 'altele'];
$problemType = in_array($_POST['problem_type'] ?? '', $allowedProblems, true) ? $_POST['problem_type'] : null;
$problemDesc = mb_substr(trim($_POST['description'] ?? ''), 0, 300);
if ($problemDesc === '') $problemDesc = null;

// Creeaza cererea
$stmt = $db->prepare("
    INSERT INTO requests (client_token, status, client_lat, client_lng, problem_type, problem_desc, created_at)
    VALUES (?, 'waiting', ?, ?, ?, ?, NOW())
");
$stmt->execute([$token, $lat, $lng, $problemType, $problemDesc]);
$requestId = $db->lastInsertId();

jsonResponse(['success' => true, 'request_id' => $requestId]);
?>
