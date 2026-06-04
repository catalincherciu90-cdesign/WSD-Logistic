<?php
// api/profile.php
// Citeste si actualizeaza profilul unui utilizator
// GET: token  -> returneaza profilul
// POST: token, [campuri profil] -> actualizeaza profilul

require_once 'config.php';

$db = getDB();
$token = getToken();

$stmt = $db->prepare("SELECT * FROM users WHERE token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) jsonError('Token invalid');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($user['role'] === 'client') {
        $stmt = $db->prepare("SELECT * FROM client_profiles WHERE user_token = ?");
        $stmt->execute([$token]);
        $profile = $stmt->fetch() ?: [];
    } else {
        $stmt = $db->prepare("SELECT * FROM depanator_profiles WHERE user_token = ?");
        $stmt->execute([$token]);
        $profile = $stmt->fetch() ?: [];
    }

    jsonResponse([
        'success' => true,
        'role'    => $user['role'],
        'name'    => $user['name'],
        'email'   => $user['email'],
        'phone'   => $user['phone'],
        'profile' => $profile,
    ]);

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Actualizeaza nume/telefon in users
    $name  = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    if (!empty($name)) {
        $db->prepare("UPDATE users SET name = ?, phone = ? WHERE token = ?")->execute([$name, $phone, $token]);
    }

    if ($user['role'] === 'client') {
        $plate = strtoupper(trim($_POST['car_plate'] ?? ''));
        $brand = trim($_POST['car_brand'] ?? '');
        $model = trim($_POST['car_model'] ?? '');
        $year  = trim($_POST['car_year'] ?? '');

        $stmt = $db->prepare("
            UPDATE client_profiles
            SET car_plate = ?, car_brand = ?, car_model = ?, car_year = ?
            WHERE user_token = ?
        ");
        $stmt->execute([$plate, $brand, $model, $year, $token]);

    } else {
        $license   = trim($_POST['license_number'] ?? '');
        $vPlate    = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
        $vType     = trim($_POST['vehicle_type'] ?? '');
        $vBrand    = trim($_POST['vehicle_brand'] ?? '');
        $vCapacity = trim($_POST['vehicle_capacity'] ?? '');
        $bio       = trim($_POST['bio'] ?? '');

        $stmt = $db->prepare("
            UPDATE depanator_profiles
            SET license_number = ?, vehicle_plate = ?, vehicle_type = ?,
                vehicle_brand = ?, vehicle_capacity = ?, bio = ?
            WHERE user_token = ?
        ");
        $stmt->execute([$license, $vPlate, $vType, $vBrand, $vCapacity, $bio, $token]);
    }

    jsonResponse(['success' => true]);
}
?>
