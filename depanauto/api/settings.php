<?php
// api/settings.php
// Setari publice (preturi) pentru landing page. Doar citire, fara token.
require_once 'config.php';

$db = getDB();
$stmt = $db->prepare("SELECT setting_key, setting_value FROM settings");
$stmt->execute();
$out = [];
foreach ($stmt->fetchAll() as $r) {
    $out[$r['setting_key']] = (float)$r['setting_value'];
}
jsonResponse(['success' => true, 'settings' => $out]);
?>
