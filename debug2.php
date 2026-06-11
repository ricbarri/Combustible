<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

$email    = 'ricbarri@gmail.com';
$password = 'password';

$db   = getDB();

// Simular exactamente lo que hace loginLocal
$stmt = $db->prepare("
    SELECT u.*, b.name AS branch_name, c.auth_method
    FROM users u
    JOIN branches b  ON u.branch_id  = b.id
    JOIN companies c ON u.company_id = c.id
    WHERE u.email = ? AND u.active = 1 AND c.auth_method = 'local'
");
$stmt->execute([strtolower(trim($email))]);
$user = $stmt->fetch();

if (!$user) {
    echo "❌ Query de loginLocal NO retorna usuario.<br><br>";
    
    // Diagnosticar por qué
    $s2 = $db->prepare("SELECT id, email, active, company_id, branch_id FROM users WHERE email=?");
    $s2->execute([$email]);
    $u = $s2->fetch();
    echo "Usuario en BD: <pre>"; print_r($u); echo "</pre>";
    
    if ($u) {
        $s3 = $db->prepare("SELECT id, name, auth_method FROM companies WHERE id=?");
        $s3->execute([$u['company_id']]);
        echo "Empresa: <pre>"; print_r($s3->fetch()); echo "</pre>";
        
        $s4 = $db->prepare("SELECT id, name FROM branches WHERE id=?");
        $s4->execute([$u['branch_id']]);
        echo "Sucursal: <pre>"; print_r($s4->fetch()); echo "</pre>";
    }
} else {
    echo "✅ loginLocal encontró al usuario: " . $user['name'] . "<br>";
    echo "password_verify: " . (password_verify($password, $user['password']) ? '✅ TRUE' : '❌ FALSE');
}
?>