<?php
// ============================================
// DETALLE DE POSTULANTE - ADMIN
// ============================================
require_once '../config/database.php';
require_once '../includes/functions.php';

// ============================================
// PROCESAR ACCIONES
// ============================================
$mensaje = '';
$error = '';

$postulante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($postulante_id == 0) {
    header('Location: postulantes.php');
    exit;
}

// Obtener datos del postulante
$postulante = fetchOne("
    SELECT p.*, 
           g.nombre as grado, 
           s.nombre as sede,
           s.direccion as sede_direccion,
           n.nombre as nivel,
           u.id as usuario_id,
           u.nombres as padre_nombre,
           u.apellidos as padre_apellidos,
           u.email as padre_email,
           u.telefono as padre_telefono,
           u.dni as padre_dni
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN niveles n ON p.id_nivel = n.id
    LEFT JOIN usuarios u ON p.id_usuario_padre = u.id
    WHERE p.id = ?
", [$postulante_id]);

if (!$postulante) {
    header('Location: postulantes.php');
    exit;
}

// Obtener documentos del postulante
$documentos = fetchAll("
    SELECT ds.*, cd.nombre_documento
    FROM documentos_subidos ds
    JOIN config_documentos cd ON ds.id_documento_requerido = cd.id
    WHERE ds.id_postulante = ?
    ORDER BY ds.fecha_subida DESC
", [$postulante_id]);

// Obtener pagos de admisión
$pagos_admision = fetchAll("
    SELECT * FROM pagos 
    WHERE id_postulante = ? 
    AND (tipo_pago IS NULL OR tipo_pago = 'admission' OR tipo_pago = '')
    ORDER BY fecha_pago DESC
", [$postulante_id]);

// Obtener pagos de matrícula
$pagos_matricula = fetchAll("
    SELECT * FROM pagos 
    WHERE id_postulante = ? 
    AND tipo_pago = 'matricula'
    ORDER BY fecha_pago DESC
", [$postulante_id]);

// Obtener citas
$citas = fetchAll("SELECT * FROM citas WHERE id_postulante = ? ORDER BY fecha DESC", [$postulante_id]);

// ============================================
// DETERMINAR TIPO DE ALUMNO
// ============================================
$tipo_alumno = $postulante['tipo_alumno'] ?? 'nuevo';

// ============================================
// DETERMINAR SI REQUIERE EVALUACIÓN ACADÉMICA
// ============================================
$grado_numero = 0;
if (preg_match('/(\d+)/', $postulante['grado'], $matches)) {
    $grado_numero = (int)$matches[1];
}

$es_inicial = ($postulante['id_nivel'] <= 2);
$es_primaria = ($postulante['id_nivel'] >= 3);
$grado_suficiente = ($grado_numero >= 5);
$requiere_evaluacion = ($es_primaria && $grado_suficiente);

// ============================================
// OBTENER CITAS PARA VERIFICAR ESTADOS
// ============================================
$cita_psico = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'psicopedagogica' ORDER BY id DESC LIMIT 1", [$postulante_id]);
$cita_academica = fetchOne("SELECT * FROM citas WHERE id_postulante = ? AND tipo = 'academica' ORDER BY id DESC LIMIT 1", [$postulante_id]);

// ============================================
// LÓGICA: LA CITA ACADÉMICA SOLO SE MUESTRA CUANDO LA PSICO ESTÁ CONFIRMADA O REALIZADA
// ============================================
$psico_completada = false;
if ($cita_psico && ($cita_psico['estado'] == 'confirmada' || $cita_psico['estado'] == 'realizada')) {
    $psico_completada = true;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// Estados con colores
$colores_estado = [
    'registrado' => 'secondary',
    'documentos_pendientes' => 'warning',
    'documentos_revisados' => 'info',
    'pago_pendiente' => 'warning',
    'pago_verificado' => 'success',
    'cita_pendiente' => 'warning',
    'cita_confirmada' => 'success',
    'cita_aprobada' => 'success',
    'evaluacion_pendiente' => 'warning',
    'evaluacion_aprobada' => 'success',
    'matricula_pendiente' => 'warning',
    'voucher_subido' => 'warning',
    'voucher_pendiente' => 'warning',
    'voucher_verificado' => 'success',
    'voucher_rechazado' => 'danger',
    'matricula_confirmada' => 'success',
    'matriculado' => 'success',
    'finalizado' => 'success'
];

$estados_texto = [
    'registrado' => '📝 Registrado',
    'documentos_pendientes' => '📄 Documentos Pendientes',
    'documentos_revisados' => '📋 Documentos Revisados',
    'pago_pendiente' => '💳 Pago Pendiente',
    'pago_verificado' => '✅ Pago Verificado',
    'cita_pendiente' => '⏳ Cita Pendiente',
    'cita_confirmada' => '✅ Cita Confirmada',
    'cita_aprobada' => '✅ Cita Aprobada',
    'evaluacion_pendiente' => '📝 Evaluación Pendiente',
    'evaluacion_aprobada' => '✅ Evaluación Aprobada',
    'matricula_pendiente' => '📋 Matrícula Pendiente',
    'voucher_subido' => '📄 Voucher Subido',
    'voucher_pendiente' => '⏳ Voucher Pendiente',
    'voucher_verificado' => '✅ Voucher Verificado',
    'voucher_rechazado' => '❌ Voucher Rechazado',
    'matricula_confirmada' => '✅ Matrícula Confirmada',
    'matriculado' => '🎓 Matriculado',
    'finalizado' => '🏁 Finalizado'
];

// ============================================
// DEFINIR PASOS PARA LÍNEA DE TIEMPO
// ============================================
$pasos_timeline = [
    1 => ['nombre' => 'Registro', 'icono' => '📄'],
    2 => ['nombre' => 'Documentos', 'icono' => '📎'],
    3 => ['nombre' => 'Pago', 'icono' => '💳'],
    4 => ['nombre' => 'Cita Psic.', 'icono' => '🧠'],
    5 => ['nombre' => 'Evaluación', 'icono' => '📝'],
    6 => ['nombre' => 'Matrícula', 'icono' => '✅'],
    7 => ['nombre' => 'Finalizado', 'icono' => '🎓']
];

// Determinar paso actual
$estado_actual = $postulante['estado_proceso'];
$paso_actual = 1;
$estados_pasos = [
    'registrado' => 1,
    'documentos_pendientes' => 2,
    'documentos_revisados' => 2,
    'pago_pendiente' => 3,
    'pago_verificado' => 3,
    'cita_pendiente' => 4,
    'cita_confirmada' => 4,
    'cita_aprobada' => 4,
    'evaluacion_pendiente' => 5,
    'evaluacion_aprobada' => 5,
    'matricula_pendiente' => 6,
    'voucher_subido' => 6,
    'voucher_pendiente' => 6,
    'voucher_verificado' => 6,
    'voucher_rechazado' => 6,
    'matricula_confirmada' => 6,
    'matriculado' => 7,
    'finalizado' => 7
];

// 🔥 CORRECCIÓN: SI ESTÁ EN CITA_APROBADA Y LA PSICO NO ESTÁ COMPLETADA, EL PASO ES 4
if ($estado_actual == 'cita_aprobada') {
    if ($requiere_evaluacion) {
        if ($psico_completada) {
            $paso_actual = 5; // Evaluación
        } else {
            $paso_actual = 4; // Cita Psicopedagógica
        }
    } else {
        $paso_actual = 6; // Matrícula
    }
} else {
    $paso_actual = $estados_pasos[$estado_actual] ?? 1;
}

if ($estado_actual == 'evaluacion_pendiente' && $psico_completada) {
    $paso_actual = 5;
}

if ($estado_actual == 'evaluacion_aprobada') {
    $paso_actual = 6;
}

if ($cita_academica && ($cita_academica['estado'] == 'confirmada' || $cita_academica['estado'] == 'realizada')) {
    $paso_actual = 6;
}
?>
<div class="row">
    <div class="col-md-12">
        <div class="d-flex justify-content-between align-items-center">
            <h4><i class="bi bi-person"></i> Detalle del Postulante</h4>
            <a href="postulantes.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Volver
            </a>
        </div>
        <hr>

        <!-- ========================================== -->
        <!-- LÍNEA DE TIEMPO (7 PASOS) -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-clock-history"></i> Proceso de Admisión</h6>
            <hr>
            <div class="timeline" style="display: flex; justify-content: space-between; align-items: center; margin: 20px 0; position: relative;">
                <?php foreach ($pasos_timeline as $num => $paso): 
                    $completado = $num < $paso_actual;
                    $activo = $num == $paso_actual;
                    // Ocultar paso 5 si no requiere evaluación
                    if ($num == 5 && !$requiere_evaluacion) {
                        continue;
                    }
                ?>
                    <div style="display: flex; flex-direction: column; align-items: center; position: relative; z-index: 1; flex: 1; text-align: center;">
                        <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo $completado ? '#2e7d32' : ($activo ? '#1a3a6b' : '#e0e0e0'); ?>; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 16px; border: 3px solid <?php echo $completado ? '#2e7d32' : ($activo ? '#1a3a6b' : '#e0e0e0'); ?>;">
                            <?php echo $completado ? '<i class="bi bi-check"></i>' : $num; ?>
                        </div>
                        <div style="font-size: 10px; margin-top: 5px; text-align: center; color: <?php echo $activo ? '#1a3a6b' : '#666'; ?>; font-weight: <?php echo $activo ? '700' : '400'; ?>;">
                            <?php echo $paso['icono']; ?> <?php echo $paso['nombre']; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="text-center">
                <span class="badge bg-<?php echo $colores_estado[$estado_actual] ?? 'secondary'; ?>">
                    <?php echo $estados_texto[$estado_actual] ?? $estado_actual; ?>
                </span>
                <span class="text-muted ms-2">Paso <?php echo $paso_actual; ?> de 7</span>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- INFORMACIÓN PERSONAL -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-person-badge"></i> Información Personal</h6>
            <hr>
            <div class="row">
                <div class="col-md-4">
                    <p><strong>Nombres:</strong> <?php echo $postulante['nombres']; ?></p>
                    <p><strong>Apellidos:</strong> <?php echo $postulante['apellido_paterno'] . ' ' . $postulante['apellido_materno']; ?></p>
                    <p><strong>DNI:</strong> <?php echo $postulante['dni']; ?></p>
                    <p><strong>Tipo Alumno:</strong> <?php echo ucfirst($tipo_alumno); ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Fecha Nacimiento:</strong> <?php echo $postulante['fecha_nacimiento'] ? date('d/m/Y', strtotime($postulante['fecha_nacimiento'])) : 'No registrada'; ?></p>
                    <p><strong>Nivel:</strong> <?php echo $postulante['nivel']; ?></p>
                    <p><strong>Grado:</strong> <?php echo $postulante['grado']; ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Sede:</strong> <?php echo $postulante['sede']; ?></p>
                    <p><strong>Dirección:</strong> <?php echo $postulante['sede_direccion'] ?? 'No registrada'; ?></p>
                    <p><strong>Estado:</strong> <span class="badge bg-<?php echo $colores_estado[$postulante['estado_proceso']] ?? 'secondary'; ?>"><?php echo $estados_texto[$postulante['estado_proceso']] ?? $postulante['estado_proceso']; ?></span></p>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- INFORMACIÓN DEL APODERADO -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-person"></i> Apoderado</h6>
            <hr>
            <div class="row">
                <div class="col-md-4">
                    <p><strong>Nombre:</strong> <?php echo ($postulante['padre_nombre'] ?? '') . ' ' . ($postulante['padre_apellidos'] ?? ''); ?></p>
                    <p><strong>DNI:</strong> <?php echo $postulante['padre_dni'] ?? 'No registrado'; ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Email:</strong> <?php echo $postulante['padre_email'] ?? 'No registrado'; ?></p>
                    <p><strong>Teléfono:</strong> <?php echo $postulante['padre_telefono'] ?? 'No registrado'; ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Usuario:</strong> <?php 
                        $usuario = fetchOne("SELECT usuario FROM usuarios WHERE id = ?", [$postulante['usuario_id']]);
                        echo $usuario['usuario'] ?? 'No registrado';
                    ?></p>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- DOCUMENTOS -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-files"></i> Documentos Subidos</h6>
            <hr>
            <?php if (empty($documentos)): ?>
                <p class="text-muted text-center">No hay documentos subidos</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Archivo</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos as $doc): ?>
                                <tr>
                                    <td><?php echo $doc['nombre_documento']; ?></td>
                                    <td>
                                        <?php if ($doc['ruta']): ?>
                                            <a href="../<?php echo $doc['ruta']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin archivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $doc['estado'] == 'aprobado' ? 'success' : ($doc['estado'] == 'rechazado' ? 'danger' : 'warning'); ?>">
                                            <?php echo ucfirst($doc['estado']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($doc['fecha_subida'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- PAGOS DE ADMISIÓN -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-credit-card"></i> Pagos de Admisión</h6>
            <hr>
            <?php if (empty($pagos_admision)): ?>
                <p class="text-muted text-center">No hay pagos de admisión registrados</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_admision as $p): ?>
                                <tr>
                                    <td>
                                        <?php if ($p['voucher']): ?>
                                            <a href="../uploads/vouchers/<?php echo $p['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin voucher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $p['estado'] == 'verificado' ? 'success' : ($p['estado'] == 'rechazado' ? 'danger' : 'warning'); ?>">
                                            <?php echo ucfirst($p['estado']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- PAGOS DE MATRÍCULA -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-cash-stack"></i> Pagos de Matrícula</h6>
            <hr>
            <?php if (empty($pagos_matricula)): ?>
                <p class="text-muted text-center">No hay pagos de matrícula registrados</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Voucher</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_matricula as $p): ?>
                                <tr>
                                    <td>
                                        <?php if ($p['voucher']): ?>
                                            <?php 
                                            $carpeta_voucher = ($p['tipo_pago'] ?? '') == 'matricula' ? 'vouchers_matricula/' : 'vouchers/';
                                            ?>
                                            <a href="../uploads/<?php echo $carpeta_voucher . $p['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin voucher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $p['estado'] == 'verificado' ? 'success' : ($p['estado'] == 'rechazado' ? 'danger' : 'warning'); ?>">
                                            <?php echo ucfirst($p['estado']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- CITAS -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-calendar"></i> Citas</h6>
            <hr>
            <?php if (empty($citas)): ?>
                <p class="text-muted text-center">No hay citas registradas</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($citas as $c): ?>
                                <tr>
                                    <td><?php echo $c['tipo'] == 'psicopedagogica' ? '🧠 Psicopedagógica' : '📝 Académica'; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($c['fecha'])); ?></td>
                                    <td><?php echo date('H:i', strtotime($c['hora'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $c['estado'] == 'confirmada' ? 'success' : ($c['estado'] == 'cancelada' ? 'danger' : ($c['estado'] == 'realizada' ? 'info' : 'warning')); ?>">
                                            <?php echo ucfirst($c['estado']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- BOTÓN VOLVER -->
        <!-- ========================================== -->
        <a href="postulantes.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver a Postulantes
        </a>

    </div>
</div>

<?php include 'footer.php'; ?>