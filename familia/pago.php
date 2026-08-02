<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] != 'familia') {
    header('Location: ../login.php');
    exit;
}
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$postulante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que el postulante pertenece al padre
$postulante = fetchOne("SELECT p.*, g.nombre as grado, s.nombre as sede
                        FROM postulantes p
                        JOIN grados g ON p.id_grado = g.id
                        JOIN sedes s ON p.id_sede = s.id
                        WHERE p.id = ? AND p.id_usuario_padre = ?", [$postulante_id, $user_id]);

if (!$postulante) {
    header('Location: index.php');
    exit;
}

$mensaje = '';
$error = '';

// ============================================
// 🔥 ASIGNAR CÓDIGO AUTOMÁTICAMENTE
// ============================================
function asignarCodigoAutomatico($postulante_id) {
    // Buscar el primer código disponible
    $codigo = fetchOne("SELECT * FROM codigos_pago WHERE usado = 0 ORDER BY id LIMIT 1");
    
    if (!$codigo) {
        return ['success' => false, 'message' => 'No hay códigos de pago disponibles. Contacta con administración.'];
    }
    
    // Marcar código como usado
    query("UPDATE codigos_pago SET usado = 1, id_postulante = ?, fecha_asignacion = NOW() WHERE id = ?", 
          [$postulante_id, $codigo['id']]);
    
    // Crear registro de pago
    insert("INSERT INTO pagos (id_postulante, id_codigo_pago, estado) VALUES (?, ?, 'pendiente')", 
           [$postulante_id, $codigo['id']]);
    
    // Actualizar estado del postulante
    query("UPDATE postulantes SET estado_proceso = 'pago_pendiente' WHERE id = ?", [$postulante_id]);
    
    return ['success' => true, 'codigo' => $codigo];
}

// ============================================
// VERIFICAR SI YA TIENE PAGO ASIGNADO
// ============================================
$pago = fetchOne("SELECT * FROM pagos WHERE id_postulante = ?", [$postulante_id]);
$codigo_asignado = null;

// Si no tiene pago, asignar automáticamente
if (!$pago) {
    $resultado = asignarCodigoAutomatico($postulante_id);
    if ($resultado['success']) {
        $codigo_asignado = $resultado['codigo'];
        $mensaje = "✅ Código de pago asignado automáticamente.";
        // Recargar datos
        $pago = fetchOne("SELECT * FROM pagos WHERE id_postulante = ?", [$postulante_id]);
    } else {
        $error = $resultado['message'];
    }
} else {
    // Si ya tiene pago, obtener el código asociado
    if ($pago['id_codigo_pago']) {
        $codigo_asignado = fetchOne("SELECT * FROM codigos_pago WHERE id = ?", [$pago['id_codigo_pago']]);
    }
}

// ============================================
// RECARGAR DATOS DESPUÉS DE POSIBLE ASIGNACIÓN
// ============================================
$postulante = fetchOne("SELECT p.*, g.nombre as grado, s.nombre as sede
                        FROM postulantes p
                        JOIN grados g ON p.id_grado = g.id
                        JOIN sedes s ON p.id_sede = s.id
                        WHERE p.id = ? AND p.id_usuario_padre = ?", [$postulante_id, $user_id]);

$pago = fetchOne("SELECT * FROM pagos WHERE id_postulante = ?", [$postulante_id]);
$estado = $postulante['estado_proceso'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pago - Admisión 2027</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="icon" href="../assets/img/favicon.ico" type="image/x-icon">
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f5f5f5;
        }
        .btn-primary {
            background: #1a3a6b;
            border: none;
        }
        .btn-primary:hover {
            background: #2d6bb8;
        }
        .btn-success {
            background: #2e7d32;
            border: none;
        }
        .btn-success:hover {
            background: #388e3c;
        }
        .text-primary-dark {
            color: #1a3a6b;
        }
        .card-pago {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
        }
        .codigo-box {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 2px dashed #1a3a6b;
        }
        .codigo-box .codigo {
            font-size: 28px;
            font-weight: 900;
            color: #1a3a6b;
            letter-spacing: 3px;
            font-family: 'Courier New', monospace;
        }
        .codigo-box .monto {
            font-size: 22px;
            font-weight: 700;
            color: #2e7d32;
        }
        .info-label {
            font-weight: 600;
            color: #1a3a6b;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark" style="background: #1a3a6b;">
    <div class="container">
        <a class="navbar-brand" href="index.php">
            <img src="../assets/img/LOGO%201000X1000%20EN%20BLANCO.png" alt="Logo" height="40" class="d-inline-block align-text-top">
            <span class="ms-2">Portal del Padre</span>
        </a>
        <div class="ms-auto">
            <a href="index.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card" style="max-width: 600px; margin: 0 auto;">
        <div class="card-body">
            <h4 class="text-primary-dark"><i class="bi bi-credit-card"></i> Pago de Admisión</h4>
            <p class="text-muted">
                <?php echo $postulante['nombres'] . ' ' . $postulante['apellido_paterno']; ?> - 
                <?php echo $postulante['grado']; ?>
            </p>
            <hr>

            <?php if ($mensaje): ?>
                <div class="alert alert-<?php echo strpos($mensaje, '✅') !== false ? 'success' : 'danger'; ?> alert-dismissible fade show">
                    <?php echo $mensaje; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($pago): ?>
                <!-- ========================================== -->
                <!-- YA TIENE PAGO ASIGNADO -->
                <!-- ========================================== -->
                <div class="card-pago">
                    <h6 class="text-primary-dark"><i class="bi bi-check-circle"></i> Código de Pago Asignado</h6>
                    <hr>
                    
                    <?php if ($codigo_asignado): ?>
                        <div class="codigo-box">
                            <div class="mb-2">
                                <span class="text-muted">Código de Pago</span>
                                <div class="codigo"><?php echo $codigo_asignado['codigo']; ?></div>
                            </div>
                            <div>
                                <span class="text-muted">Monto a pagar</span>
                                <div class="monto">S/. <?php echo number_format($codigo_asignado['monto'], 2); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <p><strong>Estado del pago:</strong></p>
                        <span class="badge bg-<?php 
                            echo $pago['estado'] == 'verificado' ? 'success' : 
                                ($pago['estado'] == 'rechazado' ? 'danger' : 'warning'); 
                        ?>">
                            <?php echo strtoupper($pago['estado']); ?>
                        </span>
                        <?php if ($pago['voucher']): ?>
                            <div class="mt-2">
                                <a href="../uploads/vouchers/<?php echo $pago['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye"></i> Ver Voucher
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($pago['estado'] == 'pendiente'): ?>
                    <!-- ========================================== -->
                    <!-- SUBIR VOUCHER -->
                    <!-- ========================================== -->
                    <div class="card-pago">
                        <h6 class="text-primary-dark"><i class="bi bi-upload"></i> Subir voucher de pago</h6>
                        <hr>
                        <form method="POST" enctype="multipart/form-data" action="subir_voucher.php">
                            <input type="hidden" name="postulante_id" value="<?php echo $postulante_id; ?>">
                            <input type="hidden" name="pago_id" value="<?php echo $pago['id']; ?>">
                            <div class="mb-3">
                                <label class="form-label">Selecciona el archivo de tu voucher</label>
                                <input type="file" name="voucher" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                                <small class="text-muted">Formatos: JPG, PNG, PDF (máx. 5MB)</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-upload"></i> Subir Voucher
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <?php if ($pago['estado'] == 'verificado'): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle-fill"></i> 
                        <strong>¡Pago verificado!</strong> Ya puedes continuar con el proceso.
                        <a href="cita.php?id=<?php echo $postulante_id; ?>" class="btn btn-success mt-2">
                            <i class="bi bi-arrow-right"></i> Agendar Cita
                        </a>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <!-- ========================================== -->
                <!-- NO HAY PAGO (NO DEBERÍA OCURRIR) -->
                <!-- ========================================== -->
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> 
                    No se pudo asignar un código de pago. Contacta con administración.
                </div>
            <?php endif; ?>

            <div class="mt-3">
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>
            </div>
        </div>
    </div>
</div>

</body>
</html>