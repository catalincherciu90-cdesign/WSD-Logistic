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

    // Actualizeaza nume/telefon in users (cu limite de lungime - Val4 #7)
    $name  = mb_substr(trim($_POST['name'] ?? ''), 0, 120);
    $phone = mb_substr(trim($_POST['phone'] ?? ''), 0, 30);
    if (!empty($name)) {
        $db->prepare("UPDATE users SET name = ?, phone = ? WHERE token = ?")->execute([$name, $phone, $token]);
    }

    if ($user['role'] === 'client') {
        $plate = strtoupper(trim($_POST['car_plate'] ?? ''));
        $brand = trim($_POST['car_brand'] ?? '');
        $model = trim($_POST['car_model'] ?? '');
        $year  = trim($_POST['car_year'] ?? '');

        // Upsert: creeaza randul daca nu exista (ex. cont via register.php) - I10.
        $stmt = $db->prepare("
            INSERT INTO client_profiles (user_token, car_plate, car_brand, car_model, car_year)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE car_plate = VALUES(car_plate), car_brand = VALUES(car_brand),
                car_model = VALUES(car_model), car_year = VALUES(car_year)
        ");
        $stmt->execute([$token, $plate, $brand, $model, $year]);

    } else {
        $license   = trim($_POST['license_number'] ?? '');
        $vPlate    = strtoupper(trim($_POST['vehicle_plate'] ?? ''));
        $vType     = trim($_POST['vehicle_type'] ?? '');
        $vBrand    = trim($_POST['vehicle_brand'] ?? '');
        $vCapacity = trim($_POST['vehicle_capacity'] ?? '');
        $bio       = trim($_POST['bio'] ?? '');

        // Upsert: creeaza randul daca nu exista (ex. cont via register.php) - I10.
        $stmt = $db->prepare("
            INSERT INTO depanator_profiles (user_token, license_number, vehicle_plate, vehicle_type, vehicle_brand, vehicle_capacity, bio)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), vehicle_plate = VALUES(vehicle_plate),
                vehicle_type = VALUES(vehicle_type), vehicle_brand = VALUES(vehicle_brand),
                vehicle_capacity = VALUES(vehicle_capacity), bio = VALUES(bio)
        ");
        $stmt->execute([$token, $license, $vPlate, $vType, $vBrand, $vCapacity, $bio]);
    }

    jsonResponse(['success' => true]);
}
?>
