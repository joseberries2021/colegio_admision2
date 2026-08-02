<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] != 'familia') {
    header('Location: ../login.php');
    exit;
}
require_once '../config/database.php';

$postulante_id = isset($_POST['postulante_id']) ? (int)$_POST['postulante_id'] : 0;
$pago_id = isset($_POST['pago_id']) ? (int)$_POST['pago_id'] : 0;

if ($postulante_id == 0 || $pago_id == 0) {
    header('Location: pago.php?id=' . $postulante_id . '&error=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['voucher'])) {
    $archivo = $_FILES['voucher'];
    
    // Validar archivo
    if ($archivo['error'] != UPLOAD_ERR_OK) {
        header('Location: pago.php?id=' . $postulante_id . '&error=upload');
        exit;
    }
    
    $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $extensiones_permitidas = ['pdf', 'jpg', 'jpeg', 'png'];
    
    if (!in_array($extension, $extensiones_permitidas)) {
        header('Location: pago.php?id=' . $postulante_id . '&error=formato');
        exit;
    }
    
    if ($archivo['size'] > 5 * 1024 * 1024) {
        header('Location: pago.php?id=' . $postulante_id . '&error=tamano');
        exit;
    }
    
    // Crear carpeta si no existe
    $carpeta = '../uploads/vouchers/';
    if (!file_exists($carpeta)) {
        mkdir($carpeta, 0777, true);
    }
    
    // Generar nombre único
    $nombre_archivo = 'voucher_' . $postulante_id . '_' . time() . '.' . $extension;
    $ruta_completa = $carpeta . $nombre_archivo;
    
    if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
        // Guardar en base de datos
        query("UPDATE pagos SET voucher = ?, estado = 'pendiente' WHERE id = ?", 
              [$nombre_archivo, $pago_id]);
        
        query("UPDATE postulantes SET estado_proceso = 'pago_pendiente' WHERE id = ?", 
              [$postulante_id]);
        
        header('Location: pago.php?id=' . $postulante_id . '&success=1');
        exit;
    } else {
        header('Location: pago.php?id=' . $postulante_id . '&error=mover');
        exit;
    }
}
?>