<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] != 'familia') {
    header('Location: ../login.php');
    exit;
}
require_once '../config/database.php';
require_once '../includes/functions.php';

$user_id = $_SESSION['user_id'];
$postulante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verificar que el postulante pertenece al padre
$postulante = fetchOne("
    SELECT p.*, g.nombre as grado, g.id as grado_id, n.nombre as nivel, n.id as nivel_id, s.nombre as sede
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN niveles n ON p.id_nivel = n.id
    JOIN sedes s ON p.id_sede = s.id
    WHERE p.id = ? AND p.id_usuario_padre = ?
", [$postulante_id, $user_id]);

if (!$postulante) {
    header('Location: index.php');
    exit;
}

$mensaje = '';
$error = '';

// ============================================
// OBTENER ASIGNACIÓN DE MATRÍCULA
// ============================================
$asignacion = fetchOne("SELECT * FROM matricula_asignacion WHERE id_postulante = ? ORDER BY id DESC LIMIT 1", [$postulante_id]);

// ============================================
// PROCESAR SUBIDA DE VOUCHER DE MATRÍCULA
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['subir_voucher_matricula'])) {
    // Verificar si tiene asignación
    if (!$asignacion) {
        $error = "❌ Aún no tienes un código de matrícula asignado. Espera la asignación del administrador.";
    } elseif ($asignacion['estado'] == 'verificado') {
        $error = "❌ Tu matrícula ya ha sido verificada.";
    } else {
        // Verificar que se haya subido un archivo
        if (isset($_FILES['voucher_matricula']) && $_FILES['voucher_matricula']['error'] == UPLOAD_ERR_OK) {
            $archivo = $_FILES['voucher_matricula'];
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $extensiones_permitidas = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($extension, $extensiones_permitidas)) {
                $error = "❌ Formato no permitido. Usa PDF, JPG o PNG.";
            } elseif ($archivo['size'] > 5 * 1024 * 1024) {
                $error = "❌ El archivo es demasiado grande. Máximo 5MB.";
            } else {
                // Crear carpeta si no existe
                $carpeta = '../uploads/vouchers_matricula/';
                if (!file_exists($carpeta)) {
                    mkdir($carpeta, 0777, true);
                }
                
                $nombre_archivo = 'voucher_matricula_' . $postulante_id . '_' . time() . '.' . $extension;
                $ruta_completa = $carpeta . $nombre_archivo;
                
                if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
                    // ✅ Actualizar la asignación con el voucher
                    query("UPDATE matricula_asignacion SET voucher = ?, estado = 'pagado', fecha_pago = NOW() WHERE id = ?", 
                          [$nombre_archivo, $asignacion['id']]);
                    
                    // ✅ 🔥 ACTUALIZAR ESTADO DEL POSTULANTE A 'voucher_subido'
                    query("UPDATE postulantes SET estado_proceso = 'voucher_subido' WHERE id = ?", [$postulante_id]);
                    
                    $mensaje = "✅ Voucher de matrícula subido correctamente. Espera la validación del administrador.";
                    
                    // ✅ Recargar datos después de la actualización
                    $asignacion = fetchOne("SELECT * FROM matricula_asignacion WHERE id_postulante = ? ORDER BY id DESC LIMIT 1", [$postulante_id]);
                    $postulante = fetchOne("
                        SELECT p.*, g.nombre as grado, g.id as grado_id, n.nombre as nivel, n.id as nivel_id, s.nombre as sede
                        FROM postulantes p
                        JOIN grados g ON p.id_grado = g.id
                        JOIN niveles n ON p.id_nivel = n.id
                        JOIN sedes s ON p.id_sede = s.id
                        WHERE p.id = ? AND p.id_usuario_padre = ?
                    ", [$postulante_id, $user_id]);
                    
                    // ✅ Mostrar mensaje de éxito sin redirigir
                } else {
                    $error = "❌ Error al subir el voucher. Intenta nuevamente.";
                }
            }
        } else {
            $error = "❌ Por favor selecciona un archivo válido.";
        }
    }
}

// ============================================
// DETERMINAR PASO ACTUAL
// ============================================
$grado_numero = 0;
if (preg_match('/(\d+)/', $postulante['grado'], $matches)) {
    $grado_numero = (int)$matches[1];
}

$es_inicial = ($postulante['id_nivel'] <= 2);
$es_primaria = ($postulante['id_nivel'] >= 3);
$grado_suficiente = ($grado_numero >= 5);
$requiere_evaluacion = ($es_primaria && $grado_suficiente);

$paso_actual = 6;
$progreso = round(($paso_actual / 7) * 100);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Matrícula - Admisión 2027</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="icon" href="../assets/img/favicon.ico" type="image/x-icon">
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #f5f5f5; }
        .btn-primary { background: #1a3a6b; border: none; }
        .btn-primary:hover { background: #2d6bb8; }
        .btn-success { background: #2e7d32; border: none; }
        .btn-success:hover { background: #388e3c; }
        .btn-outline-danger:hover { background: #c62828; color: white; }
        .text-primary-dark { color: #1a3a6b; }
        .card-matricula { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .info-label { font-weight: 600; color: #1a3a6b; width: 120px; display: inline-block; }
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
        .estado-pasos {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
            margin-bottom: 20px;
        }
        .estado-paso {
            text-align: center;
            padding: 8px 4px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 600;
            background: #e9ecef;
            color: #6c757d;
        }
        .estado-paso.hecho { background: #2e7d32; color: white; }
        .estado-paso.pendiente { background: #f57c00; color: white; }
        .estado-paso.no-aplica { background: #e0e0e0; color: #999; }
        .estado-paso.bloqueado { background: #e9ecef; color: #adb5bd; }
        .estado-paso.actual { background: #1a3a6b; color: white; }
        @media (max-width: 768px) {
            .estado-pasos { grid-template-columns: repeat(4, 1fr); }
        }
        .voucher-subido {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 15px 20px;
            border-radius: 8px;
        }
        .voucher-pendiente {
            background: #fff3e0;
            border-left: 4px solid #f57c00;
            padding: 15px 20px;
            border-radius: 8px;
        }
        .alert-asignacion {
            background: #fff3e0;
            border-left: 4px solid #f57c00;
            padding: 15px 20px;
            border-radius: 8px;
        }
        .alert-verificado {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 15px 20px;
            border-radius: 8px;
        }
        .btn-volver {
            background: #6c757d;
            color: white;
            border: none;
        }
        .btn-volver:hover {
            background: #5a6268;
            color: white;
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
            <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-volver btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card" style="max-width: 850px; margin: 0 auto;">
        <div class="card-body p-4">

            <!-- ========================================== -->
            <!-- TÍTULO Y ESTADO DE PASOS -->
            <!-- ========================================== -->
            <h5 class="text-primary-dark text-center mb-3">FLUJO DEL PROCESO DE ADMISIÓN 2027</h5>
            <div class="estado-pasos">
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>FICHA TÉCNICA</div>
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>DOCUMENTOS</div>
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>PAGO DE ADMISIÓN</div>
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>CITA PSICOPEDAGÓGICA</div>
                <div class="estado-paso <?php echo $requiere_evaluacion ? 'hecho' : 'no-aplica'; ?>">
                    <?php if ($requiere_evaluacion): ?>
                        <i class="bi bi-check-circle"></i><br>EVALUACIÓN ACADÉMICA
                    <?php else: ?>
                        <i class="bi bi-dash-circle"></i><br>EVALUACIÓN ACADÉMICA<br><small>No aplica</small>
                    <?php endif; ?>
                </div>
                <div class="estado-paso actual"><i class="bi bi-arrow-right-circle"></i><br>MATRÍCULA</div>
                <div class="estado-paso bloqueado"><i class="bi bi-lock"></i><br>BIENVENIDO</div>
            </div>

            <!-- ========================================== -->
            <!-- INFORMACIÓN DEL POSTULANTE -->
            <!-- ========================================== -->
            <div class="card-matricula">
                <h4 class="text-primary-dark"><?php echo $postulante['nombres'] . ' ' . $postulante['apellido_paterno']; ?></h4>
                <p class="text-muted">
                    <span class="info-label">DNI:</span> <?php echo $postulante['dni']; ?><br>
                    <span class="info-label">Grado:</span> <?php echo $postulante['grado']; ?> - <?php echo $postulante['sede']; ?>
                </p>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show">
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

            <!-- ========================================== -->
            <!-- MOSTRAR CÓDIGO Y MONTO SI EXISTE ASIGNACIÓN -->
            <!-- ========================================== -->
            <?php if ($asignacion): ?>
                <div class="card-matricula">
                    <h5 class="text-primary-dark"><i class="bi bi-cash-stack"></i> Detalles de Matrícula</h5>
                    <hr>
                    
                    <div class="codigo-box">
                        <div class="mb-2">
                            <span class="text-muted">Código de Matrícula</span>
                            <div class="codigo"><?php echo $asignacion['codigo_matricula']; ?></div>
                        </div>
                        <div>
                            <span class="text-muted">Monto a pagar</span>
                            <div class="monto">S/. <?php echo number_format($asignacion['monto'], 2); ?></div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <p><strong>Estado de la matrícula:</strong></p>
                        <span class="badge bg-<?php 
                            echo $asignacion['estado'] == 'verificado' ? 'success' : 
                                ($asignacion['estado'] == 'rechazado' ? 'danger' : 
                                ($asignacion['estado'] == 'pagado' ? 'info' : 'warning')); 
                        ?>">
                            <?php 
                            $estados_texto = [
                                'pendiente' => '⏳ Esperando pago',
                                'pagado' => '💳 Voucher subido, espera validación',
                                'verificado' => '✅ ¡Matrícula confirmada!',
                                'rechazado' => '❌ Voucher rechazado'
                            ];
                            echo $estados_texto[$asignacion['estado']] ?? $asignacion['estado'];
                            ?>
                        </span>
                    </div>

                    <?php if ($asignacion['estado'] == 'verificado'): ?>
                        <div class="mt-3">
                            <div class="alert-verificado">
                                <i class="bi bi-check-circle-fill text-success"></i> 
                                <strong>¡Matrícula confirmada!</strong> Bienvenido a la familia Juventud Científica.
                                <div class="mt-2">
                                    <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-success">
                                        <i class="bi bi-arrow-right"></i> Ir a mi panel
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- No hay asignación -->
                <div class="card-matricula">
                    <div class="alert-asignacion">
                        <i class="bi bi-clock-history" style="font-size: 30px;"></i>
                        <h6 class="mt-2">Esperando asignación de matrícula</h6>
                        <p class="text-muted">El administrador asignará un código de matrícula y el monto a pagar. 
                        Una vez asignado, podrás ver los detalles aquí.</p>
                        <p class="text-muted small">Tu estado actual es: <strong><?php echo str_replace('_', ' ', $postulante['estado_proceso']); ?></strong></p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- SUBIR VOUCHER (SOLO SI ESTÁ ASIGNADO Y NO VERIFICADO) -->
            <!-- ========================================== -->
            <?php if ($asignacion && $asignacion['estado'] != 'verificado' && $asignacion['estado'] != 'rechazado'): ?>
                <div class="card-matricula">
                    <h5 class="text-primary-dark"><i class="bi bi-upload"></i> Subir voucher de pago</h5>
                    <hr>
                    <form method="POST" enctype="multipart/form-data" action="">
                        <div class="mb-3">
                            <label class="form-label">Selecciona el archivo de tu voucher</label>
                            <input type="file" name="voucher_matricula" class="form-control" accept=".jpg,.jpeg,.png,.pdf" required>
                            <small class="text-muted">Formatos: JPG, PNG, PDF (máx. 5MB)</small>
                        </div>
                        <button type="submit" name="subir_voucher_matricula" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Subir Voucher
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            <!-- ========================================== -->
            <!-- PROGRESO -->
            <!-- ========================================== -->
            <div class="card-matricula">
                <div class="d-flex justify-content-between">
                    <span>Progreso</span>
                    <span><strong><?php echo $progreso; ?>%</strong></span>
                </div>
                <div class="progress" style="height: 10px; border-radius: 10px;">
                    <div class="progress-bar bg-primary" style="width: <?php echo $progreso; ?>%;"></div>
                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>