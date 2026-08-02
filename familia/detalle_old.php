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

// ============================================
// DETERMINAR SI REQUIERE EVALUACIÓN ACADÉMICA
// ============================================
$grado_numero = 0;
if (preg_match('/(\d+)/', $postulante['grado'], $matches)) {
    $grado_numero = (int)$matches[1];
}

$es_inicial = ($postulante['nivel_id'] <= 2);
$es_primaria = ($postulante['nivel_id'] >= 3);
$grado_suficiente = ($grado_numero >= 5);
$requiere_evaluacion = ($es_primaria && $grado_suficiente);

// ============================================
// FUNCIÓN PARA REDIRIGIR SEGÚN EL ESTADO
// ============================================
function redirigirSegunEstado($postulante) {
    global $postulante_id, $requiere_evaluacion;
    
    // Si ya está matriculado o finalizado, no hacer nada
    if ($postulante['estado_proceso'] == 'matriculado' || $postulante['estado_proceso'] == 'finalizado') {
        return;
    }
    
    // Verificar citas
    $cita_psico = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'psicopedagogica' ORDER BY id DESC LIMIT 1", [$postulante_id]);
    $cita_academica = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'academica' ORDER BY id DESC LIMIT 1", [$postulante_id]);
    
    // CASO 1: Cita psicopedagógica confirmada
    if ($cita_psico && $cita_psico['estado'] == 'confirmada') {
        
        // 1A: NO requiere evaluación (Inicial) → ir a MATRÍCULA
        if (!$requiere_evaluacion) {
            if ($postulante['estado_proceso'] != 'cita_aprobada') {
                query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$postulante_id]);
            }
            header('Location: matricula.php?id=' . $postulante_id);
            exit;
        }
        
        // 1B: Requiere evaluación (5to Primaria en adelante)
        if ($requiere_evaluacion) {
            // Verificar si ya tiene cita académica
            if ($cita_academica) {
                // Si la cita académica está confirmada → ir a MATRÍCULA
                if ($cita_academica['estado'] == 'confirmada') {
                    if ($postulante['estado_proceso'] != 'cita_aprobada') {
                        query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$postulante_id]);
                    }
                    header('Location: matricula.php?id=' . $postulante_id);
                    exit;
                }
                // Si la cita académica está pendiente → ir a CITAS (paso 5)
                if ($cita_academica['estado'] == 'pendiente') {
                    if ($postulante['estado_proceso'] != 'cita_pendiente') {
                        query("UPDATE postulantes SET estado_proceso = 'cita_pendiente' WHERE id = ?", [$postulante_id]);
                    }
                    header('Location: cita.php?id=' . $postulante_id);
                    exit;
                }
            } else {
                // No tiene cita académica → mostrar calendario para agendar (paso 5)
                if ($postulante['estado_proceso'] != 'cita_aprobada') {
                    query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$postulante_id]);
                }
                header('Location: cita.php?id=' . $postulante_id);
                exit;
            }
        }
    }
    
    // CASO 2: Cita académica confirmada → ir a MATRÍCULA
    if ($cita_academica && $cita_academica['estado'] == 'confirmada') {
        if ($postulante['estado_proceso'] != 'cita_aprobada') {
            query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$postulante_id]);
        }
        header('Location: matricula.php?id=' . $postulante_id);
        exit;
    }
}

// ============================================
// EJECUTAR REDIRECCIÓN
// ============================================
redirigirSegunEstado($postulante);

// ============================================
// ACTUALIZAR POSTULANTE DESPUÉS DE POSIBLE REDIRECCIÓN
// ============================================
$postulante = fetchOne("
    SELECT p.*, g.nombre as grado, g.id as grado_id, n.nombre as nivel, n.id as nivel_id, s.nombre as sede
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN niveles n ON p.id_nivel = n.id
    JOIN sedes s ON p.id_sede = s.id
    WHERE p.id = ? AND p.id_usuario_padre = ?
", [$postulante_id, $user_id]);

// ============================================
// OBTENER DATOS PARA MOSTRAR
// ============================================
$cita_psico = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'psicopedagogica' ORDER BY id DESC LIMIT 1", [$postulante_id]);
$cita_academica = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'academica' ORDER BY id DESC LIMIT 1", [$postulante_id]);

// Determinar paso actual
$paso_actual = 1;
$estados_pasos = [
    'registrado' => 1,
    'documentos_pendientes' => 2,
    'documentos_revisados' => 3,
    'pago_pendiente' => 3,
    'pago_verificado' => 4,
    'cita_pendiente' => 4,
    'cita_confirmada' => 4,
    'cita_aprobada' => $requiere_evaluacion ? 5 : 6,
    'evaluacion_pendiente' => 5,
    'evaluacion_aprobada' => 6,
    'matriculado' => 6,
    'finalizado' => 7
];

$paso_actual = $estados_pasos[$postulante['estado_proceso']] ?? 1;

// Calcular progreso
$progreso = round(($paso_actual / 7) * 100);

// Determinar mensaje de estado
$mensaje_estado = '';
$color_estado = 'secondary';

switch ($postulante['estado_proceso']) {
    case 'registrado':
        $mensaje_estado = '📝 Registro completado. Sube tus documentos.';
        $color_estado = 'info';
        break;
    case 'documentos_pendientes':
        $mensaje_estado = '📄 Documentos subidos. Espera revisión.';
        $color_estado = 'warning';
        break;
    case 'documentos_revisados':
        $mensaje_estado = '📋 Documentos revisados. Realiza el pago.';
        $color_estado = 'success';
        break;
    case 'pago_pendiente':
        $mensaje_estado = '💳 Pago pendiente. Realiza el pago para continuar.';
        $color_estado = 'warning';
        break;
    case 'pago_verificado':
        $mensaje_estado = '✅ Pago verificado. Agenda tu cita psicopedagógica.';
        $color_estado = 'success';
        break;
    case 'cita_pendiente':
        $mensaje_estado = '⏳ Cita psicopedagógica pendiente de confirmación.';
        $color_estado = 'warning';
        break;
    case 'cita_confirmada':
        $mensaje_estado = '✅ Cita psicopedagógica confirmada. Espera aprobación.';
        $color_estado = 'success';
        break;
    case 'cita_aprobada':
        if ($requiere_evaluacion) {
            $mensaje_estado = '📝 Cita psicopedagógica aprobada. Agenda tu evaluación académica.';
        } else {
            $mensaje_estado = '🎓 Cita psicopedagógica aprobada. ¡Ya puedes matricularte!';
        }
        $color_estado = 'success';
        break;
    case 'evaluacion_pendiente':
        $mensaje_estado = '📝 Evaluación académica pendiente de confirmación.';
        $color_estado = 'warning';
        break;
    case 'evaluacion_aprobada':
        $mensaje_estado = '✅ Evaluación aprobada. ¡Ya puedes matricularte!';
        $color_estado = 'success';
        break;
    case 'matriculado':
        $mensaje_estado = '🎓 ¡Matriculado! Bienvenido a la familia Juventud Científica.';
        $color_estado = 'success';
        break;
    case 'finalizado':
        $mensaje_estado = '🏁 Proceso de admisión completado.';
        $color_estado = 'success';
        break;
    default:
        $mensaje_estado = '📌 Proceso en curso.';
        $color_estado = 'secondary';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Postulante - Admisión 2027</title>
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
        .text-primary-dark { color: #1a3a6b; }
        .card-detalle { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .timeline { display: flex; justify-content: space-between; align-items: center; margin: 30px 0; position: relative; }
        .timeline::before { content: ''; position: absolute; top: 16px; left: 0; right: 0; height: 3px; background: #e0e0e0; z-index: 0; }
        .timeline-item { display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1; }
        .timeline-item .circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .timeline-item .label { font-size: 10px; margin-top: 5px; text-align: center; color: #666; }
        .timeline-item .label.active { color: #1a3a6b; font-weight: 700; }
        .paso-activo { background: #1a3a6b; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .paso-completado { background: #2e7d32; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .paso-inactivo { background: #e0e0e0; color: #999; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .estado-badge { font-size: 14px; padding: 8px 20px; border-radius: 20px; }
        .progreso-bar { height: 10px; border-radius: 10px; }
        .info-label { font-weight: 600; color: #1a3a6b; width: 120px; display: inline-block; }
        @media (max-width: 768px) {
            .timeline { flex-wrap: wrap; gap: 10px; }
            .timeline::before { display: none; }
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
    <div class="card" style="max-width: 850px; margin: 0 auto;">
        <div class="card-body p-4">

            <!-- ========================================== -->
            <!-- INFORMACIÓN DEL POSTULANTE -->
            <!-- ========================================== -->
            <div class="card-detalle">
                <h4 class="text-primary-dark"><?php echo $postulante['nombres'] . ' ' . $postulante['apellido_paterno']; ?></h4>
                <p class="text-muted">
                    <span class="info-label">DNI:</span> <?php echo $postulante['dni']; ?><br>
                    <span class="info-label">Grado:</span> <?php echo $postulante['grado']; ?> - <?php echo $postulante['sede']; ?>
                </p>
            </div>

            <!-- ========================================== -->
            <!-- TIMELINE - SIEMPRE MUESTRA TODOS LOS PASOS -->
            <!-- ========================================== -->
            <div class="timeline">
                <?php 
                $pasos = [
                    1 => 'Registro',
                    2 => 'Documentos',
                    3 => 'Pago',
                    4 => 'Cita Psic.',
                    5 => 'Evaluación',
                    6 => 'Matrícula',
                    7 => 'Finalizado'
                ];
                
                foreach ($pasos as $num => $label): 
                    $completado = $num < $paso_actual;
                    $activo = $num == $paso_actual;
                ?>
                    <div class="timeline-item">
                        <div class="circle <?php echo $completado ? 'paso-completado' : ($activo ? 'paso-activo' : 'paso-inactivo'); ?>">
                            <?php echo $completado ? '<i class="bi bi-check"></i>' : $num; ?>
                        </div>
                        <div class="label <?php echo $activo ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ========================================== -->
            <!-- ESTADO ACTUAL -->
            <!-- ========================================== -->
            <div class="card-detalle">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Estado actual</strong><br>
                        <span class="badge estado-badge bg-<?php echo $color_estado; ?>">
                            <?php echo $mensaje_estado; ?>
                        </span>
                    </div>
                    <div>
                        <span class="text-muted">Paso <?php echo $paso_actual; ?> de 7</span>
                    </div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- PROGRESO -->
            <!-- ========================================== -->
            <div class="card-detalle">
                <div class="d-flex justify-content-between">
                    <span>Progreso</span>
                    <span><strong><?php echo $progreso; ?>%</strong></span>
                </div>
                <div class="progress progreso-bar">
                    <div class="progress-bar bg-primary" style="width: <?php echo $progreso; ?>%;"></div>
                </div>
            </div>

            <!-- ========================================== -->
            <!-- ACCIONES RÁPIDAS -->
            <!-- ========================================== -->
            <div class="card-detalle">
                <h6 class="text-primary-dark"><i class="bi bi-lightning"></i> Acciones</h6>
                <hr>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($postulante['estado_proceso'] == 'registrado'): ?>
                        <a href="documentos.php?id=<?php echo $postulante_id; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-files"></i> Subir Documentos
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'documentos_pendientes' || $postulante['estado_proceso'] == 'documentos_revisados'): ?>
                        <a href="documentos.php?id=<?php echo $postulante_id; ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-files"></i> Ver Documentos
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'pago_pendiente' || $postulante['estado_proceso'] == 'pago_verificado'): ?>
                        <a href="pago.php?id=<?php echo $postulante_id; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-credit-card"></i> Realizar Pago
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'cita_pendiente' || $postulante['estado_proceso'] == 'cita_confirmada'): ?>
                        <a href="cita.php?id=<?php echo $postulante_id; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-calendar"></i> Ver Cita
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'cita_aprobada'): ?>
                        <?php if ($requiere_evaluacion): ?>
                            <a href="cita.php?id=<?php echo $postulante_id; ?>" class="btn btn-primary btn-sm">
                                <i class="bi bi-calendar"></i> Agendar Evaluación
                            </a>
                        <?php else: ?>
                            <a href="matricula.php?id=<?php echo $postulante_id; ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-pencil"></i> Matrícula
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'evaluacion_pendiente'): ?>
                        <a href="cita.php?id=<?php echo $postulante_id; ?>" class="btn btn-primary btn-sm">
                            <i class="bi bi-calendar"></i> Ver Evaluación
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'evaluacion_aprobada'): ?>
                        <a href="matricula.php?id=<?php echo $postulante_id; ?>" class="btn btn-success btn-sm">
                            <i class="bi bi-pencil"></i> Matrícula
                        </a>
                    <?php endif; ?>

                    <?php if ($postulante['estado_proceso'] == 'matriculado'): ?>
                        <a href="#" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-file-pdf"></i> Descargar Constancia
                        </a>
                    <?php endif; ?>

                    <!-- Botón de actualizar estado (forzar redirección) -->
                    <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-clockwise"></i> Actualizar Estado
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

</body>
</html>