<?php
// ============================================
// 1. PRIMERO: CONFIGURACIÓN Y PROCESAMIENTO
// ============================================
require_once '../config/database.php';
require_once '../includes/functions.php';

// ============================================
// 2. SEGUNDO: LÓGICA DE NEGOCIO (header() ANTES DE CUALQUIER HTML)
// ============================================
$mensaje = '';
$error = '';

// ============================================
// ELIMINAR POSTULANTE
// ============================================
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    
    // Verificar si tiene documentos asociados
    $docs = fetchOne("SELECT COUNT(*) as total FROM documentos_subidos WHERE id_postulante = ?", [$id]);
    if ($docs['total'] > 0) {
        $error = "❌ No se puede eliminar porque tiene documentos subidos.";
    } else {
        query("DELETE FROM postulantes WHERE id = ?", [$id]);
        registrarAuditoria('postulantes', 'eliminar', 'postulante', $id, 'Postulante eliminado');
        $mensaje = "🗑️ Postulante eliminado correctamente.";
    }
    header('Location: postulantes.php');
    exit;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
// Filtros
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
$filtro_grado = isset($_GET['grado']) ? (int)$_GET['grado'] : 0;
$filtro_sede = isset($_GET['sede']) ? (int)$_GET['sede'] : 0;
$filtro_buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// Construir condiciones WHERE
$where = "1=1";
$params = [];

if ($filtro_estado != 'todos') {
    $where .= " AND p.estado_proceso = ?";
    $params[] = $filtro_estado;
}

if ($filtro_grado > 0) {
    $where .= " AND p.id_grado = ?";
    $params[] = $filtro_grado;
}

if ($filtro_sede > 0) {
    $where .= " AND p.id_sede = ?";
    $params[] = $filtro_sede;
}

if (!empty($filtro_buscar)) {
    $where .= " AND (p.nombres LIKE ? OR p.apellido_paterno LIKE ? OR p.dni LIKE ?)";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
}

// Obtener postulantes
$postulantes = fetchAll("
    SELECT p.*, 
           g.nombre as grado, 
           s.nombre as sede,
           n.nombre as nivel,
           u.nombres as padre_nombre,
           u.apellidos as padre_apellidos
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN niveles n ON p.id_nivel = n.id
    LEFT JOIN usuarios u ON p.id_usuario_padre = u.id
    WHERE $where
    ORDER BY p.fecha_registro DESC
", $params);

// Obtener datos para filtros
$grados = fetchAll("SELECT id, nombre FROM grados WHERE estado = 1 ORDER BY nombre");
$sedes = fetchAll("SELECT id, nombre FROM sedes WHERE estado = 1 ORDER BY nombre");

// Estados para el filtro
$estados = [
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
    'matriculado' => '🎓 Matriculado'
];

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
    'matriculado' => 'success'
];

// Estadísticas
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso = 'registrado') as registrados,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso IN ('documentos_pendientes', 'documentos_revisados')) as documentos,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso IN ('pago_pendiente', 'pago_verificado')) as pagos,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso IN ('cita_pendiente', 'cita_confirmada', 'cita_aprobada')) as citas,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso IN ('evaluacion_pendiente', 'evaluacion_aprobada')) as evaluaciones,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso IN ('matricula_pendiente', 'voucher_subido', 'voucher_pendiente', 'voucher_verificado', 'voucher_rechazado', 'matricula_confirmada')) as matriculas,
        (SELECT COUNT(*) FROM postulantes WHERE estado_proceso = 'matriculado') as matriculados,
        (SELECT COUNT(*) FROM postulantes) as total
");
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-people"></i> Alumnos & Postulantes</h4>
        <p class="text-muted">Gestiona todos los postulantes y su estado en el proceso de admisión.</p>

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
        <!-- ESTADÍSTICAS RÁPIDAS -->
        <!-- ========================================== -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card bg-primary-dark">
                    <div class="number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="label">Total Postulantes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-warning-dark">
                    <div class="number"><?php echo $stats['documentos'] ?? 0; ?></div>
                    <div class="label">📄 Documentos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-info-dark">
                    <div class="number"><?php echo $stats['citas'] ?? 0; ?></div>
                    <div class="label">📅 Citas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success-dark">
                    <div class="number"><?php echo $stats['matriculados'] ?? 0; ?></div>
                    <div class="label">🎓 Matriculados</div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- FILTROS -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <div class="row g-2">
                <form method="GET" class="row g-2">
                    <div class="col-md-2">
                        <select name="estado" class="form-select form-select-sm">
                            <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                            <?php foreach ($estados as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $filtro_estado == $key ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="grado" class="form-select form-select-sm">
                            <option value="0">Todos los grados</option>
                            <?php foreach ($grados as $g): ?>
                                <option value="<?php echo $g['id']; ?>" <?php echo $filtro_grado == $g['id'] ? 'selected' : ''; ?>><?php echo $g['nombre']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select name="sede" class="form-select form-select-sm">
                            <option value="0">Todas las sedes</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $filtro_sede == $s['id'] ? 'selected' : ''; ?>><?php echo $s['nombre']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="buscar" class="form-control form-control-sm" 
                               placeholder="🔍 Buscar por nombre o DNI..." 
                               value="<?php echo htmlspecialchars($filtro_buscar); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-filter"></i> Filtrar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- LISTA DE POSTULANTES -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-list"></i> Postulantes</h6>
                <span class="badge bg-primary"><?php echo count($postulantes); ?> registros</span>
            </div>
            <hr>

            <?php if (empty($postulantes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay postulantes con los filtros seleccionados</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Postulante</th>
                                <th>DNI</th>
                                <th>Grado</th>
                                <th>Sede</th>
                                <th>Padre</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postulantes as $p): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></strong>
                                    </td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
                                    <td><?php echo ($p['padre_nombre'] ?? '') . ' ' . ($p['padre_apellidos'] ?? ''); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $colores_estado[$p['estado_proceso']] ?? 'secondary'; ?>">
                                            <?php echo $estados[$p['estado_proceso']] ?? $p['estado_proceso']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y', strtotime($p['fecha_registro'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="detalle_postulante.php?id=<?php echo $p['id']; ?>" class="btn btn-primary">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="postulantes.php?eliminar=1&id=<?php echo $p['id']; ?>" 
                                               class="btn btn-danger" 
                                               onclick="return confirm('¿Eliminar este postulante?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>