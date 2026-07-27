<?php
// api/message.php
// Trimite mesaj de la depanator la client
// POST: token, request_id, message
// GET: token, request_id -> citeste mesajele neseen

require_once 'config.php';
require_once 'send_notification.php';

$db = getDB();
$token = getToken();

$stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) jsonError('Token invalid');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestId = (int)($_POST['request_id'] ?? 0);
    $message   = trim($_POST['message'] ?? '');

    if (empty($message)) jsonError('Mesajul este gol');
    if (strlen($message) > 500) jsonError('Mesajul este prea lung');
    if ($user['role'] !== 'depanator') jsonError('Doar depanatorii pot trimite mesaje');

    // Verifica ca cererea ii apartine
    $stmt = $db->prepare("SELECT client_token FROM requests WHERE id = ? AND depanator_token = ? AND status = 'accepted'");
    $stmt->execute([$requestId, $token]);
    $request = $stmt->fetch();
    if (!$request) jsonError('Cerere negasita');

    $db->prepare("INSERT INTO request_messages (request_id, sender_role, message) VALUES (?, 'depanator', ?)")
       ->execute([$requestId, $message]);

    // Notifica clientul ca a primit un mesaj de la depanator (best-effort).
    notifyUserByToken(
        $db,
        $request['client_token'],
        '🔧 Mesaj de la depanator',
        mb_substr($message, 0, 120),
        ['type' => 'message', 'request_id' => (string)$requestId]
    );

    jsonResponse(['success' => true]);

} else {
    // GET - citeste mesajele pentru o cerere
    $requestId = (int)($_GET['request_id'] ?? 0);
    if (!$requestId) jsonError('request_id lipsa');

    // Doar partile din cursa pot citi mesajele (altfel: IDOR pe conversatii private).
    $stmt = $db->prepare("SELECT id FROM requests WHERE id = ? AND (client_token = ? OR depanator_token = ?)");
    $stmt->execute([$requestId, $token, $token]);
    if (!$stmt->fetch()) jsonError('Cerere negasita');

    $stmt = $db->prepare("
        SELECT * FROM request_messages
        WHERE request_id = ? AND seen = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute([$requestId]);
    $messages = $stmt->fetchAll();

    // Marcheaza ca citite
    if (!empty($messages)) {
        $db->prepare("UPDATE request_messages SET seen = 1 WHERE request_id = ? AND seen = 0")
           ->execute([$requestId]);
    }

    jsonResponse(['success' => true, 'messages' => $messages]);
}
?>
