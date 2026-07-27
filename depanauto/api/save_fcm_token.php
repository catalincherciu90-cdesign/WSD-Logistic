<?php
// api/save_fcm_token.php
// Salveaza token-ul FCM al depanatorului in baza de date
// POST: token, fcm_token

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Metoda nepermisa', 405);

$token    = getToken();
$fcmToken = trim($_POST['fcm_token'] ?? '');

if (empty($fcmToken)) jsonError('FCM token lipsa');

$db = getDB();

// Coloana fcm_token exista in schema (database.sql) - nu mai rulam ALTER la fiecare cerere.
$stmt = $db->prepare("UPDATE users SET fcm_token = ? WHERE token = ?");
$stmt->execute([$fcmToken, $token]);

if ($stmt->rowCount() === 0) jsonError('Token user negasit');

jsonResponse(['success' => true]);
?>
