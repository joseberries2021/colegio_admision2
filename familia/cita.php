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
$error = '';

// ============================================
// OBTENER DATOS DE CITA PSICOPEDAGÓGICA
// ============================================
$cita_psico = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'psicopedagogica' ORDER BY id DESC LIMIT 1", [$postulante_id]);

// ============================================
// PROCESAR SOLICITUD DE CITA PSICOPEDAGÓGICA
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agendar_psico'])) {
    $fecha = $_POST['fecha'] ?? '';
    $hora = $_POST['hora'] ?? '';
    
    if (empty($fecha) || empty($hora)) {
        $error = "❌ Por favor selecciona una fecha y hora.";
    } else {
        $existe = fetchOne("SELECT id FROM citas WHERE fecha = ? AND hora = ? AND tipo = 'psicopedagogica' AND estado != 'cancelada'", 
                           [$fecha, $hora]);
        
        if ($existe) {
            $error = "❌ Este horario ya está ocupado. Por favor, elige otro.";
        } else {
            $insertado = insert(
                "INSERT INTO citas (id_postulante, tipo, fecha, hora, estado) VALUES (?, 'psicopedagogica', ?, ?, 'pendiente')",
                [$postulante_id, $fecha, $hora]
            );
            
            if ($insertado) {
                query("UPDATE postulantes SET estado_proceso = 'cita_pendiente' WHERE id = ?", [$postulante_id]);
                $mensaje = "✅ Cita psicopedagógica reservada exitosamente. Espera la confirmación del administrador.";
                $cita_psico = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'psicopedagogica' ORDER BY id DESC LIMIT 1", [$postulante_id]);
            } else {
                $error = "❌ Error al agendar la cita. Intenta nuevamente.";
            }
        }
    }
}

// ============================================
// PROCESAR CANCELACIÓN DE CITA
// ============================================
if (isset($_GET['cancelar']) && isset($_GET['id'])) {
    $cita_id = (int)$_GET['cancelar'];
    $cita = fetchOne("SELECT tipo, id_postulante FROM citas WHERE id = ? AND id_postulante = ?", [$cita_id, $postulante_id]);
    
    if ($cita && $cita['tipo'] == 'psicopedagogica') {
        query("UPDATE citas SET estado = 'cancelada' WHERE id = ?", [$cita_id]);
        query("UPDATE postulantes SET estado_proceso = 'pago_verificado' WHERE id = ?", [$postulante_id]);
        $cita_psico = null;
        $mensaje = "✅ Cita psicopedagógica cancelada. Puedes agendar una nueva.";
    }
}

// ============================================
// AJAX - OBTENER HORAS OCUPADAS
// ============================================
if (isset($_GET['action']) && $_GET['action'] == 'horas') {
    header('Content-Type: application/json');
    $fecha = $_GET['fecha'] ?? '';
    $tipo = $_GET['tipo'] ?? '';
    
    if (empty($fecha) || empty($tipo)) {
        echo json_encode([]);
        exit;
    }
    
    $citas = fetchAll("SELECT hora FROM citas WHERE fecha = ? AND tipo = ? AND estado != 'cancelada'", [$fecha, $tipo]);
    $horas_ocupadas = array_column($citas, 'hora');
    echo json_encode($horas_ocupadas);
    exit;
}

// ============================================
// DETERMINAR PASO ACTUAL
// ============================================
$paso_actual = 4;
$psico_completada = false;

if ($cita_psico && ($cita_psico['estado'] == 'confirmada' || $cita_psico['estado'] == 'realizada')) {
    $psico_completada = true;
}

// Si la cita psicopedagógica está completada, redirigir a detalle
if ($psico_completada) {
    header('Location: detalle.php?id=' . $postulante_id . '&msg=psico_completada');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cita Psicopedagógica - Admisión 2027</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    <link rel="icon" href="../assets/img/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <style>
        body { font-family: 'Montserrat', sans-serif; background: #f5f5f5; }
        .btn-primary { background: #1a3a6b; border: none; }
        .btn-primary:hover { background: #2d6bb8; }
        .btn-success { background: #2e7d32; border: none; }
        .btn-success:hover { background: #388e3c; }
        .btn-outline-danger:hover { background: #c62828; color: white; }
        .text-primary-dark { color: #1a3a6b; }
        .card-cita { background: white; border-radius: 16px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); margin-bottom: 20px; }
        .calendario-container { background: #f8f9fa; border-radius: 16px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .hora-btn { padding: 10px; border-radius: 8px; border: 2px solid #dee2e6; background: white; text-align: center; font-weight: 600; cursor: pointer; transition: all 0.3s; }
        .hora-btn:hover:not(.ocupada) { transform: scale(1.05); }
        .hora-btn.disponible { border-color: #2e7d32; color: #2e7d32; }
        .hora-btn.disponible:hover { background: #e8f5e9; }
        .hora-btn.ocupada { border-color: #c62828; color: #c62828; background: #ffebee; cursor: not-allowed; opacity: 0.7; }
        .hora-btn.seleccionada { background: #1a3a6b; color: white; border-color: #1a3a6b; }
        .hora-btn .duracion { font-size: 10px; font-weight: normal; opacity: 0.7; display: block; }
        .horas-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 15px; }
        .paso-activo { background: #1a3a6b; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .paso-completado { background: #2e7d32; color: white; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .paso-inactivo { background: #e0e0e0; color: #999; border-radius: 50%; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; font-weight: bold; }
        .timeline { display: flex; justify-content: space-between; align-items: center; margin: 30px 0; position: relative; }
        .timeline::before { content: ''; position: absolute; top: 16px; left: 0; right: 0; height: 3px; background: #e0e0e0; z-index: 0; }
        .timeline-item { display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1; }
        .timeline-item .circle { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .timeline-item .label { font-size: 10px; margin-top: 5px; text-align: center; color: #666; }
        .timeline-item .label.active { color: #1a3a6b; font-weight: 700; }
        .confirmacion-cita {
            background: #e8f5e9;
            border-left: 4px solid #2e7d32;
            padding: 20px;
            border-radius: 8px;
        }
        .confirmacion-cita .detalle { margin: 5px 0; }
        .flatpickr-day.ocupado { background: #ffebee !important; color: #c62828 !important; cursor: not-allowed !important; }
        .flatpickr-day.available { background: #e8f5e9 !important; color: #2e7d32 !important; cursor: pointer !important; }
        .flatpickr-day.available:hover { background: #c8e6c9 !important; }
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
            .horas-grid { grid-template-columns: repeat(2, 1fr); }
            .timeline { flex-wrap: wrap; gap: 10px; }
            .timeline::before { display: none; }
            .estado-pasos { grid-template-columns: repeat(4, 1fr); }
        }
        .info-label { font-weight: 600; color: #1a3a6b; width: 140px; display: inline-block; }
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
            <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card" style="max-width: 850px; margin: 0 auto;">
        <div class="card-body p-4">

            <!-- ========================================== -->
            <!-- ESTADO DE PASOS -->
            <!-- ========================================== -->
            <h5 class="text-primary-dark text-center mb-3">FLUJO DEL PROCESO DE ADMISIÓN 2027</h5>
            <div class="estado-pasos">
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>FICHA TÉCNICA</div>
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>DOCUMENTOS</div>
                <div class="estado-paso hecho"><i class="bi bi-check-circle"></i><br>PAGO DE ADMISIÓN</div>
                <div class="estado-paso actual"><i class="bi bi-arrow-right-circle"></i><br>CITA PSICOPEDAGÓGICA</div>
                <div class="estado-paso bloqueado"><i class="bi bi-lock"></i><br>EVALUACIÓN ACADÉMICA</div>
                <div class="estado-paso bloqueado"><i class="bi bi-lock"></i><br>PAGO DE MATRÍCULA</div>
                <div class="estado-paso bloqueado"><i class="bi bi-lock"></i><br>MATRICULADO 2027</div>
            </div>

            <!-- ========================================== -->
            <!-- INFORMACIÓN DEL POSTULANTE -->
            <!-- ========================================== -->
            <div class="card-cita">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="text-primary-dark"><?php echo $postulante['nombres'] . ' ' . $postulante['apellido_paterno']; ?></h5>
                        <p class="text-muted">
                            <span class="info-label">DNI:</span> <?php echo $postulante['dni']; ?><br>
                            <span class="info-label">Grado:</span> <?php echo $postulante['grado']; ?> - <?php echo $postulante['sede']; ?>
                        </p>
                    </div>
                    <div>
                        <span class="badge bg-<?php echo $postulante['estado_proceso'] == 'cita_pendiente' ? 'warning' : 'secondary'; ?>">
                            <?php echo str_replace('_', ' ', $postulante['estado_proceso']); ?>
                        </span>
                    </div>
                </div>
            </div>

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

            <!-- ========================================== -->
            <!-- CITA PSICOPEDAGÓGICA -->
            <!-- ========================================== -->
            <div class="card-cita">
                <h5 class="text-primary-dark"><i class="bi bi-calendar-heart"></i> CITA PSICOPEDAGÓGICA</h5>
                <p class="text-muted">Entrevista psicológica oficial. Se realiza de <strong>Lunes a Viernes</strong>.</p>
                <hr>

                <?php if ($cita_psico && $cita_psico['estado'] != 'cancelada'): ?>
                    <!-- CITA YA RESERVADA -->
                    <div class="confirmacion-cita">
                        <h5><i class="bi bi-check-circle-fill text-success"></i> CITA RESERVADA EXITOSAMENTE</h5>
                        <p class="detalle"><strong><?php echo date('l, d \d\e F', strtotime($cita_psico['fecha'])); ?></strong></p>
                        <p class="detalle"><strong>Horario reservado:</strong> <?php echo date('h:i A', strtotime($cita_psico['hora'])); ?></p>
                        <p class="detalle"><strong>Modalidad:</strong> Entrevista Psicopedagógica Virtual (Vía Zoom)</p>
                        <p class="detalle"><strong>Psicóloga asignada:</strong> Lic. Ana Sofía Martínez (Colegiatura N° 4519-COP)</p>
                        <div class="mt-3">
                            <span class="badge bg-<?php echo $cita_psico['estado'] == 'confirmada' ? 'success' : 'warning'; ?>">
                                <?php echo $cita_psico['estado'] == 'confirmada' ? '✅ Confirmada' : '⏳ En revisión'; ?>
                            </span>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <?php if ($cita_psico['estado'] == 'pendiente'): ?>
                            <a href="cita.php?id=<?php echo $postulante_id; ?>&cancelar=<?php echo $cita_psico['id']; ?>" 
                               class="btn btn-outline-danger" onclick="return confirm('¿Cancelar esta cita?')">
                                <i class="bi bi-x-circle"></i> Cancelar Cita
                            </a>
                            <a href="cita.php?id=<?php echo $postulante_id; ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-clockwise"></i> Reagendar Cita
                            </a>
                        <?php endif; ?>
                        <?php if ($cita_psico['estado'] == 'confirmada'): ?>
                            <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-success">
                                <i class="bi bi-arrow-right"></i> Ver Progreso
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- AGENDAR NUEVA CITA -->
                    <form method="POST">
                        <h6 class="text-primary-dark">1. SELECCIONE UNA FECHA DE ENTREVISTA</h6>
                        <div class="calendario-container">
                            <input type="text" id="calendarioPsico" class="form-control" placeholder="Selecciona una fecha..." readonly>
                            <small class="text-muted">Horario: 9:00 - 12:00 y 14:00 - 17:00 (Duración: 30 min)</small>
                        </div>

                        <div class="mt-3" id="horasPsicoContainer" style="display: none;">
                            <h6 class="text-primary-dark">2. SELECCIONE UN HORARIO DISPONIBLE</h6>
                            <div class="horas-grid" id="horasPsicoGrid">
                                <!-- Se llena con JavaScript -->
                            </div>
                            <input type="hidden" name="fecha" id="fechaSeleccionada" value="">
                            <input type="hidden" name="hora" id="horaSeleccionada" value="">
                            <button type="submit" name="agendar_psico" class="btn btn-success mt-3 w-100" id="btnAgendarPsico" disabled>
                                <i class="bi bi-calendar-check"></i> Reservar Cita Psicopedagógica
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>

            <!-- ========================================== -->
            <!-- BOTÓN VOLVER -->
            <!-- ========================================== -->
            <div class="mt-3">
                <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>
            </div>

        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // CONFIGURACIÓN DE FLATPICKR PARA PSICOPEDAGÓGICA (LUNES A VIERNES)
    // ============================================
    <?php if (!$cita_psico || $cita_psico['estado'] == 'cancelada'): ?>
    flatpickr("#calendarioPsico", {
        locale: "es",
        dateFormat: "Y-m-d",
        minDate: "today",
        maxDate: new Date().fp_incr(30),
        disable: [
            function(date) {
                return date.getDay() === 6 || date.getDay() === 0;
            }
        ],
        onChange: function(selectedDates, dateStr, instance) {
            if (dateStr) {
                document.getElementById('fechaSeleccionada').value = dateStr;
                cargarHorasPsico(dateStr);
            }
        }
    });

    function cargarHorasPsico(fecha) {
        const container = document.getElementById('horasPsicoContainer');
        const grid = document.getElementById('horasPsicoGrid');
        const btn = document.getElementById('btnAgendarPsico');
        
        container.style.display = 'block';
        grid.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div> Cargando horarios...</div>';
        btn.disabled = true;
        
        const horas = ['09:00:00', '10:00:00', '11:00:00', '14:00:00', '15:00:00', '16:00:00'];
        
        fetch('cita.php?id=<?php echo $postulante_id; ?>&action=horas&fecha=' + fecha + '&tipo=psicopedagogica')
            .then(response => response.json())
            .then(ocupadas => {
                grid.innerHTML = '';
                let hayDisponibles = false;
                
                horas.forEach(hora => {
                    const ocupada = ocupadas.includes(hora);
                    const div = document.createElement('div');
                    div.className = 'hora-btn ' + (ocupada ? 'ocupada' : 'disponible');
                    const horaLabel = hora.substring(0, 5);
                    const horaFin = new Date('2000-01-01 ' + hora);
                    horaFin.setHours(horaFin.getHours() + 1);
                    const horaFinLabel = horaFin.toTimeString().substring(0, 5);
                    div.innerHTML = horaLabel + ' - ' + horaFinLabel;
                    if (!ocupada) {
                        div.innerHTML += '<span class="duracion">DISPONIBLE</span>';
                    } else {
                        div.innerHTML += '<span class="duracion">OCUPADO</span>';
                    }
                    div.dataset.hora = hora;
                    if (!ocupada) {
                        hayDisponibles = true;
                        div.onclick = function() {
                            document.querySelectorAll('.hora-btn.disponible').forEach(el => el.classList.remove('seleccionada'));
                            this.classList.add('seleccionada');
                            document.getElementById('horaSeleccionada').value = hora;
                            btn.disabled = false;
                        };
                    }
                    grid.appendChild(div);
                });
                if (!hayDisponibles) {
                    grid.innerHTML = '<div class="text-center py-3 text-muted">No hay horarios disponibles para esta fecha</div>';
                    btn.disabled = true;
                }
            })
            .catch(() => {
                grid.innerHTML = '<div class="text-center py-3 text-danger">Error al cargar horarios</div>';
            });
    }
    <?php endif; ?>
});
</script>

</body>
</html>