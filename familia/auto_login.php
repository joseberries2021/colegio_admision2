<?php
session_start();
require_once '../config/database.php';

// Verificar que venga del registro exitoso
if (!isset($_GET['user_id']) || !isset($_SESSION['registro_completo'])) {
    header('Location: ../login.php');
    exit;
}

$user_id = (int)$_GET['user_id'];

// Obtener datos del usuario
$user = fetchOne("SELECT * FROM usuarios WHERE id = ?", [$user_id]);

if ($user) {
    // Establecer sesión
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_tipo'] = $user['tipo'];
    $_SESSION['user_nombre'] = $user['nombres'];
    $_SESSION['user_email'] = $user['email'];
    
    // Redirigir al panel familiar
    header('Location: index.php');
    exit;
} else {
    header('Location: ../login.php');
    exit;
}
?>