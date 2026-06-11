<?php
require_once __DIR__ . '/config/database.php';

$hash = password_hash('password', PASSWORD_DEFAULT);

$db = getDB();
$db->prepare("UPDATE users SET password=? WHERE email='ricbarri@gmail.com'")
   ->execute([$hash]);

echo "✅ Contraseña actualizada.<br>";
echo "Hash generado: " . $hash;
?>