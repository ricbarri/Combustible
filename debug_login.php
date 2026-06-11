<?php
require_once __DIR__ . '/config/database.php';

$email    = 'ricbarri@gmail.com';
$password = 'password';

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND active = 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    echo "❌ Usuario NO encontrado en la BD con ese email y active=1";
    exit;
}

echo "✅ Usuario encontrado:<br>";
echo "ID: "         . $user['id']         . "<br>";
echo "Nombre: "     . $user['name']       . "<br>";
echo "Email: "      . $user['email']      . "<br>";
echo "Profile: "    . $user['profile']    . "<br>";
echo "Active: "     . $user['active']     . "<br>";
echo "Company ID: " . $user['company_id'] . "<br>";
echo "Password hash: " . $user['password'] . "<br><br>";

$verify = password_verify($password, $user['password']);
echo "¿password_verify('password', hash) = " . ($verify ? '✅ TRUE' : '❌ FALSE') . "<br><br>";

// Probar también con loginLocal
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';

$method = getCompanyAuthMethod($email);
echo "Método detectado: " . $method . "<br>";
?>