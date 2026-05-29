<?php
// api/auth.php
// Inregistrare si login pentru clienti si depanatori
// POST: action (register|login), role, email, password, [name, phone]

require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Metoda nepermisa', 405);

$action = $_POST['action'] ?? '';
$db = getDB();

// Anti brute-force / spam pe autentificare: max 10 incercari / minut / IP.
rateLimit('auth_' . $action, 10, 60);

switch ($action) {

    case 'register':
        $role     = $_POST['role'] ?? '';
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phone    = trim($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!in_array($role, ['client', 'depanator'])) jsonError('Rol invalid');
        if (empty($name)) jsonError('Numele este obligatoriu');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalid');
        if (strlen($password) < 6) jsonError('Parola trebuie sa aiba minim 6 caractere');

        // Verifica email unic
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) jsonError('Acest email este deja inregistrat');

        $token = bin2hex(random_bytes(32));
        $hash  = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (token, role, name, email, phone, password_hash, online, last_seen)
            VALUES (?, ?, ?, ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$token, $role, $name, $email, $phone, $hash]);

        // Creeaza profil gol
        if ($role === 'client') {
            $db->prepare("INSERT INTO client_profiles (user_token) VALUES (?)")->execute([$token]);
        } else {
            $db->prepare("INSERT INTO depanator_profiles (user_token) VALUES (?)")->execute([$token]);
        }

        jsonResponse([
            'success' => true,
            'token'   => $token,
            'role'    => $role,
            'name'    => $name,
        ]);
        break;

    case 'login':
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) jsonError('Email si parola sunt obligatorii');

        $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonError('Email sau parola incorecta');
        }

        // Actualizeaza last_seen
        $db->prepare("UPDATE users SET last_seen = NOW() WHERE token = ?")->execute([$user['token']]);

        jsonResponse([
            'success' => true,
            'token'   => $user['token'],
            'role'    => $user['role'],
            'name'    => $user['name'],
        ]);
        break;

    default:
        jsonError('Actiune necunoscuta');
}
?>
