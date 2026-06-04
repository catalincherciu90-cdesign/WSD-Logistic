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

// Adauga coloana fcm_token daca nu exista (prima rulare)
try {
    $db->exec("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {
    // Coloana exista deja, ignoram eroarea
}

$stmt = $db->prepare("UPDATE users SET fcm_token = ? WHERE token = ?");
$stmt->execute([$fcmToken, $token]);

if ($stmt->rowCount() === 0) jsonError('Token user negasit');

jsonResponse(['success' => true]);
?>
