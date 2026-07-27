<?php
// api/register.php
// Inregistreaza un user nou si returneaza token-ul sau
// POST: role (client|depanator)

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Metoda nepermisa', 405);
}

$role = $_POST['role'] ?? '';
if (!in_array($role, ['client', 'depanator'])) {
    jsonError('Rol invalid. Foloseste: client sau depanator');
}

$token = bin2hex(random_bytes(32)); // 64 caractere hex

// Depanatorii noi asteapta aprobarea adminului; clientii sunt activi imediat.
$status = $role === 'depanator' ? 'pending' : 'active';
$db = getDB();
$stmt = $db->prepare("INSERT INTO users (token, role, status, online, last_seen) VALUES (?, ?, ?, 1, NOW())");
$stmt->execute([$token, $role, $status]);

jsonResponse([
    'success' => true,
    'token' => $token,
    'role' => $role,
    'message' => 'Inregistrat cu succes'
]);
?>
