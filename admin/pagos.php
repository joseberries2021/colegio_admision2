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

// Aprobar pago
if (isset($_GET['aprobar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    query("UPDATE pagos SET estado = 'verificado' WHERE id = ?", [$id]);
    $pago = fetchOne("SELECT id_postulante FROM pagos WHERE id = ?", [$id]);
    if ($pago) {
        query("UPDATE postulantes SET estado_proceso = 'pago_verificado' WHERE id = ?", [$pago['id_postulante']]);
        registrarAuditoria('pagos', 'aprobar', 'pago', $id, 'Pago aprobado para postulante ID: ' . $pago['id_postulante']);
    }
    header('Location: pagos.php');
    exit;
}

// Rechazar pago
if (isset($_GET['rechazar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    query("UPDATE pagos SET estado = 'rechazado' WHERE id = ?", [$id]);
    $pago = fetchOne("SELECT id_postulante FROM pagos WHERE id = ?", [$id]);
    if ($pago) {
        query("UPDATE postulantes SET estado_proceso = 'pago_pendiente' WHERE id = ?", [$pago['id_postulante']]);
        registrarAuditoria('pagos', 'rechazar', 'pago', $id, 'Pago rechazado para postulante ID: ' . $pago['id_postulante']);
    }
    header('Location: pagos.php');
    exit;
}

// Eliminar pago
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $pago = fetchOne("SELECT voucher FROM pagos WHERE id = ?", [$id]);
    if ($pago && $pago['voucher'] && file_exists('../uploads/vouchers/' . $pago['voucher'])) {
        unlink('../uploads/vouchers/' . $pago['voucher']);
    }
    query("DELETE FROM pagos WHERE id = ?", [$id]);
    header('Location: pagos.php');
    exit;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'todos';
$filtro_buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';

// Construir condiciones WHERE
$where = "1=1";
$params = [];

if ($filtro_estado != 'todos') {
    $where .= " AND pago.estado = ?";
    $params[] = $filtro_estado;
}

if (!empty($filtro_buscar)) {
    $where .= " AND (post.nombres LIKE ? OR post.apellido_paterno LIKE ? OR post.dni LIKE ?)";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
}

// Obtener pagos pendientes de admisión
$pagos_pendientes = fetchAll("
    SELECT 
        pago.*,
        post.id as postulante_id,
        post.nombres,
        post.apellido_paterno,
        post.apellido_materno,
        post.dni,
        u.nombres as padre_nombre,
        u.apellidos as padre_apellidos,
        u.dni as padre_dni,
        g.nombre as grado,
        s.nombre as sede
    FROM pagos pago
    JOIN postulantes post ON pago.id_postulante = post.id
    JOIN usuarios u ON post.id_usuario_padre = u.id
    JOIN grados g ON post.id_grado = g.id
    JOIN sedes s ON post.id_sede = s.id
    WHERE pago.estado = 'pendiente' 
    AND (pago.tipo_pago IS NULL OR pago.tipo_pago = 'admission' OR pago.tipo_pago = '')
    ORDER BY pago.fecha_pago DESC
");

// Obtener pagos validados de admisión
$pagos_validados = fetchAll("
    SELECT 
        pago.*,
        post.id as postulante_id,
        post.nombres,
        post.apellido_paterno,
        post.apellido_materno,
        post.dni,
        u.nombres as padre_nombre,
        u.apellidos as padre_apellidos,
        u.dni as padre_dni,
        g.nombre as grado,
        s.nombre as sede
    FROM pagos pago
    JOIN postulantes post ON pago.id_postulante = post.id
    JOIN usuarios u ON post.id_usuario_padre = u.id
    JOIN grados g ON post.id_grado = g.id
    JOIN sedes s ON post.id_sede = s.id
    WHERE pago.estado IN ('verificado', 'rechazado')
    AND (pago.tipo_pago IS NULL OR pago.tipo_pago = 'admission' OR pago.tipo_pago = '')
    AND $where
    ORDER BY pago.fecha_pago DESC
", $params);

// ========================================== 
// 🔥 NUEVO: PAGOS DE MATRÍCULA (SOLO VISUALIZACIÓN)
// ========================================== 
$pagos_matricula = fetchAll("
    SELECT 
        pago.*,
        post.id as postulante_id,
        post.nombres,
        post.apellido_paterno,
        post.apellido_materno,
        post.dni,
        u.nombres as padre_nombre,
        u.apellidos as padre_apellidos,
        u.dni as padre_dni,
        g.nombre as grado,
        s.nombre as sede
    FROM pagos pago
    JOIN postulantes post ON pago.id_postulante = post.id
    JOIN usuarios u ON post.id_usuario_padre = u.id
    JOIN grados g ON post.id_grado = g.id
    JOIN sedes s ON post.id_sede = s.id
    WHERE pago.tipo_pago = 'matricula'
    ORDER BY pago.fecha_pago DESC
");

// Estadísticas
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM pagos WHERE estado = 'pendiente' AND (tipo_pago IS NULL OR tipo_pago = 'admission' OR tipo_pago = '')) as pendientes,
        (SELECT COUNT(*) FROM pagos WHERE estado = 'verificado' AND (tipo_pago IS NULL OR tipo_pago = 'admission' OR tipo_pago = '')) as verificados,
        (SELECT COUNT(*) FROM pagos WHERE estado = 'rechazado' AND (tipo_pago IS NULL OR tipo_pago = 'admission' OR tipo_pago = '')) as rechazados,
        (SELECT COUNT(*) FROM pagos WHERE tipo_pago = 'matricula') as matriculas,
        (SELECT COUNT(*) FROM pagos) as total
");

$estados = [
    'todos' => 'Todos',
    'verificado' => '✅ Verificados',
    'rechazado' => '❌ Rechazados'
];
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-credit-card"></i> Validación de Pagos</h4>
        <p class="text-muted">Verifica los vouchers de pago de los postulantes</p>

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
                <div class="stat-card bg-warning-dark">
                    <div class="number"><?php echo $stats['pendientes'] ?? 0; ?></div>
                    <div class="label">⏳ Pendientes</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success-dark">
                    <div class="number"><?php echo $stats['verificados'] ?? 0; ?></div>
                    <div class="label">✅ Verificados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-danger-dark">
                    <div class="number"><?php echo $stats['rechazados'] ?? 0; ?></div>
                    <div class="label">❌ Rechazados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-primary-dark">
                    <div class="number"><?php echo $stats['matriculas'] ?? 0; ?></div>
                    <div class="label">📄 Pagos Matrícula</div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- PAGOS PENDIENTES DE ADMISIÓN -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-clock-history"></i> Pagos de Admisión Pendientes</h6>
            <hr>

            <?php if (empty($pagos_pendientes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle" style="font-size: 40px; color: #2e7d32;"></i>
                    <p>No hay pagos de admisión pendientes de validación</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Postulante</th>
                                <th>DNI</th>
                                <th>Padre</th>
                                <th>Grado</th>
                                <th>Sede</th>
                                <th>Voucher</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_pendientes as $p): ?>
                                <tr>
                                    <td><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['padre_nombre'] . ' ' . $p['padre_apellidos']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
                                    <td>
                                        <?php if ($p['voucher']): ?>
                                            <a href="../uploads/vouchers/<?php echo $p['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin voucher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="pagos.php?aprobar=1&id=<?php echo $p['id']; ?>" 
                                               class="btn btn-success"
                                               onclick="return confirm('¿Aprobar este pago?')">
                                                <i class="bi bi-check"></i> Aprobar
                                            </a>
                                            <a href="pagos.php?rechazar=1&id=<?php echo $p['id']; ?>" 
                                               class="btn btn-danger"
                                               onclick="return confirm('¿Rechazar este pago?')">
                                                <i class="bi bi-x"></i> Rechazar
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

        <!-- ========================================== -->
        <!-- PAGOS DE ADMISIÓN VALIDADOS -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-check-circle"></i> Pagos de Admisión Validados</h6>
                <span class="badge bg-primary"><?php echo count($pagos_validados); ?> registros</span>
            </div>
            <hr>

            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-2">
                    <select name="estado" class="form-select form-select-sm">
                        <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos</option>
                        <option value="verificado" <?php echo $filtro_estado == 'verificado' ? 'selected' : ''; ?>>✅ Verificados</option>
                        <option value="rechazado" <?php echo $filtro_estado == 'rechazado' ? 'selected' : ''; ?>>❌ Rechazados</option>
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
                <div class="col-md-2">
                    <a href="pagos.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-arrow-clockwise"></i> Limpiar
                    </a>
                </div>
            </form>

            <?php if (empty($pagos_validados)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay pagos de admisión validados con los filtros seleccionados</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Postulante</th>
                                <th>DNI</th>
                                <th>Padre</th>
                                <th>Grado</th>
                                <th>Sede</th>
                                <th>Voucher</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_validados as $p): ?>
                                <tr>
                                    <td><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['padre_nombre'] . ' ' . $p['padre_apellidos']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
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
                                        <span class="badge bg-<?php 
                                            echo $p['estado'] == 'verificado' ? 'success' : 'danger'; 
                                        ?>">
                                            <?php echo $p['estado'] == 'verificado' ? '✅ Verificado' : '❌ Rechazado'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($p['fecha_pago'])); ?></td>
                                    <td>
                                        <a href="pagos.php?eliminar=1&id=<?php echo $p['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('¿Eliminar este pago?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- 🔥 NUEVO: PAGOS DE MATRÍCULA (SOLO VISUALIZACIÓN) -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-cash-stack"></i> Pagos de Matrícula</h6>
                <span class="badge bg-primary"><?php echo count($pagos_matricula); ?> registros</span>
            </div>
            <hr>
            <p class="text-muted">Historial de vouchers de pago de matrícula. <strong>No requieren aprobación</strong>, solo visualización.</p>

            <?php if (empty($pagos_matricula)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay pagos de matrícula registrados</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Postulante</th>
                                <th>DNI</th>
                                <th>Padre</th>
                                <th>Grado</th>
                                <th>Sede</th>
                                <th>Voucher</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_matricula as $p): ?>
                                <tr>
                                    <td><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['padre_nombre'] . ' ' . $p['padre_apellidos']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
                                    <td>
                                        <?php if ($p['voucher']): ?>
                                            <a href="../uploads/vouchers_matricula/<?php echo $p['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin voucher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $p['estado'] == 'verificado' ? 'success' : 
                                                ($p['estado'] == 'rechazado' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo $p['estado'] == 'verificado' ? '✅ Verificado' : 
                                                ($p['estado'] == 'rechazado' ? '❌ Rechazado' : '⏳ Pendiente'); ?>
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

    </div>
</div>

<?php include 'footer.php'; ?>