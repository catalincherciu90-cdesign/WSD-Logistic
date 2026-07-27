<?php
// api/admin.php
// API pentru panoul de administrare
require_once 'config.php';

$db = getDB();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Autentificare admin
function getAdminSession($db) {
    $sessionToken = $_POST['admin_token'] ?? $_GET['admin_token'] ?? '';
    if (empty($sessionToken)) return null;
    $stmt = $db->prepare("SELECT * FROM admins WHERE password_hash = ?");
    // Folosim un token de sesiune stocat in cookie/localStorage
    $stmt = $db->prepare("SELECT * FROM admin_sessions WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch();
    if (!$session) return null;
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$session['admin_id']]);
    return $stmt->fetch();
}

// Creeaza tabela sesiuni admin daca nu exista
try {
    $db->exec("CREATE TABLE IF NOT EXISTS admin_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        token VARCHAR(64) UNIQUE NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch(PDOException $e) {}

switch ($action) {

    case 'login':
        // Anti brute-force pe parola de admin: max 10 incercari / minut / IP (I1).
        rateLimit('admin_login', 10, 60);
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        if (empty($email) || empty($password)) jsonError('Email si parola sunt obligatorii');

        $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            jsonError('Email sau parola incorecta');
        }

        // Creeaza sesiune
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+8 hours'));
        $db->prepare("INSERT INTO admin_sessions (admin_id, token, expires_at) VALUES (?,?,?)")
           ->execute([$admin['id'], $token, $expires]);
        $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);

        jsonResponse([
            'success' => true,
            'token'   => $token,
            'name'    => $admin['name'],
            'is_super'=> (bool)$admin['is_super'],
        ]);
        break;

    case 'logout':
        $sessionToken = $_POST['admin_token'] ?? '';
        $db->prepare("DELETE FROM admin_sessions WHERE token = ?")->execute([$sessionToken]);
        jsonResponse(['success' => true]);
        break;

    case 'get_stats':
        $admin = getAdminSession($db);
        if (!$admin) jsonError('Neautorizat', 401);

        $stats = [];
        $stats['total_clients']   = $db->query("SELECT COUNT(*) FROM users WHERE role='client'")->fetchColumn();
        $stats['total_depanatori']= $db->query("SELECT COUNT(*) FROM users WHERE role='depanator'")->fetchColumn();
        $stats['pending_depanatori'] = $db->query("SELECT COUNT(*) FROM users WHERE role='depanator' AND status='pending'")->fetchColumn();
        $stats['total_requests']  = $db->query("SELECT COUNT(*) FROM requests")->fetchColumn();
        $stats['completed_requests'] = $db->query("SELECT COUNT(*) FROM requests WHERE status='completed'")->fetchColumn();
        $stats['total_revenue']   = $db->query("SELECT COALESCE(SUM(price_ron),0) FROM requests WHERE status='completed'")->fetchColumn();
        $stats['active_today']    = $db->query("SELECT COUNT(*) FROM users WHERE DATE(last_seen) = CURDATE()")->fetchColumn();

        jsonResponse(['success' => true, 'stats' => $stats]);
        break;

    case 'get_users':
        $admin = getAdminSession($db);
        if (!$admin) jsonError('Neautorizat', 401);

        $role   = $_GET['role'] ?? 'all';
        $status = $_GET['status'] ?? 'all';
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        if ($role !== 'all') { $where[] = 'u.role = ?'; $params[] = $role; }
        if ($status !== 'all') { $where[] = 'u.status = ?'; $params[] = $status; }
        if (!empty($search)) {
            $where[] = '(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)';
            $s = '%'.$search.'%';
            $params = array_merge($params, [$s, $s, $s]);
        }

        $whereStr = implode(' AND ', $where);

        $stmt = $db->prepare("
            SELECT u.*,
                   cp.car_plate, cp.car_brand, cp.car_model,
                   dp.vehicle_plate, dp.vehicle_type, dp.license_number, dp.rating, dp.total_jobs,
                   (SELECT COUNT(*) FROM requests r WHERE r.client_token = u.token OR r.depanator_token = u.token) as total_requests
            FROM users u
            LEFT JOIN client_profiles cp ON cp.user_token = u.token
            LEFT JOIN depanator_profiles dp ON dp.user_token = u.token
            WHERE $whereStr
            ORDER BY u.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        // Total
        $countParams = array_slice($params, 0, -2);
        $countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereStr");
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();

        jsonResponse(['success' => true, 'users' => $users, 'total' => $total, 'page' => $page]);
        break;

    case 'update_user_status':
        $admin = getAdminSession($db);
        if (!$admin) jsonError('Neautorizat', 401);

        $userToken = $_POST['user_token'] ?? '';
        $status    = $_POST['status'] ?? '';

        if (!in_array($status, ['active','pending','suspended'])) jsonError('Status invalid');

        $db->prepare("UPDATE users SET status = ? WHERE token = ?")->execute([$status, $userToken]);
        jsonResponse(['success' => true]);
        break;

    case 'delete_user':
        $admin = getAdminSession($db);
        if (!$admin || !$admin['is_super']) jsonError('Neautorizat', 401);

        $userToken = $_POST['user_token'] ?? '';
        $db->prepare("UPDATE users SET status = 'suspended' WHERE token = ?")->execute([$userToken]);
        jsonResponse(['success' => true]);
        break;

    case 'get_admins':
        $admin = getAdminSession($db);
        if (!$admin || !$admin['is_super']) jsonError('Neautorizat', 401);

        $stmt = $db->query("SELECT id, name, email, is_super, created_at, last_login FROM admins ORDER BY created_at ASC");
        jsonResponse(['success' => true, 'admins' => $stmt->fetchAll()]);
        break;

    case 'add_admin':
        $admin = getAdminSession($db);
        if (!$admin || !$admin['is_super']) jsonError('Neautorizat', 401);

        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $isSuper  = (int)($_POST['is_super'] ?? 0);

        if (empty($name)||empty($email)||strlen($password)<6) jsonError('Date incomplete sau parola prea scurta');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonError('Email invalid');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $db->prepare("INSERT INTO admins (name, email, password_hash, is_super) VALUES (?,?,?,?)")
               ->execute([$name, $email, $hash, $isSuper]);
            jsonResponse(['success' => true]);
        } catch(PDOException $e) {
            jsonError('Email deja folosit');
        }
        break;

    case 'delete_admin':
        $admin = getAdminSession($db);
        if (!$admin || !$admin['is_super']) jsonError('Neautorizat', 401);

        $adminId = (int)($_POST['admin_id'] ?? 0);
        if ($adminId === $admin['id']) jsonError('Nu te poti sterge pe tine insuti');
        $db->prepare("DELETE FROM admins WHERE id = ?")->execute([$adminId]);
        jsonResponse(['success' => true]);
        break;


    case 'get_settings':
        $admin = getAdminSession($db);
        if (!$admin) jsonError('Neautorizat', 401);
        $stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $out = [];
        foreach ($rows as $r) $out[$r['setting_key']] = (float)$r['setting_value'];
        jsonResponse(['success' => true, 'settings' => $out]);
        break;

    case 'update_settings':
        $admin = getAdminSession($db);
        if (!$admin) jsonError('Neautorizat', 401);
        $fields = ['price_per_km', 'price_fixed', 'price_min'];
        $stmt = $db->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?");
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $val = filter_var($_POST[$field], FILTER_VALIDATE_FLOAT);
                if ($val !== false && $val >= 0) $stmt->execute([round($val, 2), $field]);
            }
        }
        jsonResponse(['success' => true]);
        break;

    default:
        jsonError('Actiune necunoscuta');
}
?>
