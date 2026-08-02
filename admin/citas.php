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
// APROBAR CITA PSICOPEDAGÓGICA
// ============================================
if (isset($_GET['aprobar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cita = fetchOne("SELECT id_postulante, tipo FROM citas WHERE id = ?", [$id]);
    
    if ($cita && $cita['tipo'] == 'psicopedagogica') {
        query("UPDATE citas SET estado = 'confirmada' WHERE id = ?", [$id]);
        
        // Verificar si necesita evaluación académica
        $postulante = fetchOne("SELECT id_nivel, id_grado FROM postulantes WHERE id = ?", [$cita['id_postulante']]);
        $grado_info = fetchOne("SELECT nombre FROM grados WHERE id = ?", [$postulante['id_grado']]);
        
        $grado_numero = 0;
        if (preg_match('/(\d+)/', $grado_info['nombre'] ?? '', $matches)) {
            $grado_numero = (int)$matches[1];
        }
        
        if ($postulante['id_nivel'] >= 3 && $grado_numero >= 5) {
            query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$cita['id_postulante']]);
        } else {
            query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$cita['id_postulante']]);
        }
        
        registrarAuditoria('citas_psico', 'aprobar', 'cita', $id, 'Cita psicopedagógica aprobada para postulante ID: ' . $cita['id_postulante']);
        $mensaje = "✅ Cita psicopedagógica aprobada correctamente";
    }
    
    header('Location: citas.php');
    exit;
}

// ============================================
// RECHAZAR CITA PSICOPEDAGÓGICA
// ============================================
if (isset($_GET['rechazar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cita = fetchOne("SELECT id_postulante, tipo FROM citas WHERE id = ?", [$id]);
    
    if ($cita && $cita['tipo'] == 'psicopedagogica') {
        query("UPDATE citas SET estado = 'cancelada' WHERE id = ?", [$id]);
        query("UPDATE postulantes SET estado_proceso = 'pago_verificado' WHERE id = ?", [$cita['id_postulante']]);
        registrarAuditoria('citas_psico', 'rechazar', 'cita', $id, 'Cita psicopedagógica rechazada para postulante ID: ' . $cita['id_postulante']);
        $mensaje = "❌ Cita psicopedagógica rechazada";
    }
    
    header('Location: citas.php');
    exit;
}

// ============================================
// MARCAR CITA PSICOPEDAGÓGICA COMO REALIZADA
// ============================================
if (isset($_GET['realizada']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cita = fetchOne("SELECT id_postulante, tipo FROM citas WHERE id = ?", [$id]);
    
    if ($cita && $cita['tipo'] == 'psicopedagogica') {
        query("UPDATE citas SET estado = 'realizada' WHERE id = ?", [$id]);
        
        // Verificar si necesita evaluación académica
        $postulante = fetchOne("SELECT id_nivel, id_grado FROM postulantes WHERE id = ?", [$cita['id_postulante']]);
        $grado_info = fetchOne("SELECT nombre FROM grados WHERE id = ?", [$postulante['id_grado']]);
        
        $grado_numero = 0;
        if (preg_match('/(\d+)/', $grado_info['nombre'] ?? '', $matches)) {
            $grado_numero = (int)$matches[1];
        }
        
        if ($postulante['id_nivel'] >= 3 && $grado_numero >= 5) {
            query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$cita['id_postulante']]);
        } else {
            query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$cita['id_postulante']]);
        }
        
        registrarAuditoria('citas_psico', 'realizada', 'cita', $id, 'Cita psicopedagógica marcada como realizada para postulante ID: ' . $cita['id_postulante']);
        $mensaje = "✅ Cita psicopedagógica marcada como realizada";
    }
    
    header('Location: citas.php');
    exit;
}

// ============================================
// ELIMINAR CITA PSICOPEDAGÓGICA
// ============================================
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $cita = fetchOne("SELECT id_postulante, tipo FROM citas WHERE id = ?", [$id]);
    if ($cita && $cita['tipo'] == 'psicopedagogica') {
        query("DELETE FROM citas WHERE id = ?", [$id]);
        $citas_restantes = fetchOne("SELECT COUNT(*) as total FROM citas WHERE id_postulante = ? AND estado != 'cancelada' AND tipo = 'psicopedagogica'", [$cita['id_postulante']]);
        if ($citas_restantes['total'] == 0) {
            query("UPDATE postulantes SET estado_proceso = 'pago_verificado' WHERE id = ?", [$cita['id_postulante']]);
        }
        registrarAuditoria('citas_psico', 'eliminar', 'cita', $id, 'Cita psicopedagógica eliminada para postulante ID: ' . $cita['id_postulante']);
        $mensaje = "🗑️ Cita psicopedagógica eliminada correctamente";
    }
    header('Location: citas.php');
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
$where = "1=1 AND c.tipo = 'psicopedagogica'";
$params = [];

if ($filtro_estado != 'todos') {
    $where .= " AND c.estado = ?";
    $params[] = $filtro_estado;
}

if (!empty($filtro_buscar)) {
    $where .= " AND (p.nombres LIKE ? OR p.apellido_paterno LIKE ? OR p.dni LIKE ?)";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
    $params[] = "%$filtro_buscar%";
}

// Obtener citas pendientes (psicopedagógicas)
$citas_pendientes = fetchAll("
    SELECT 
        c.*,
        p.id as postulante_id,
        p.nombres,
        p.apellido_paterno,
        p.apellido_materno,
        p.dni,
        g.nombre as grado,
        s.nombre as sede,
        u.nombres as padre_nombre,
        u.apellidos as padre_apellidos
    FROM citas c
    JOIN postulantes p ON c.id_postulante = p.id
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN usuarios u ON p.id_usuario_padre = u.id
    WHERE c.estado = 'pendiente' AND c.tipo = 'psicopedagogica'
    ORDER BY c.fecha ASC, c.hora ASC
");

// Obtener todas las citas psicopedagógicas (con filtros)
$citas_todas = fetchAll("
    SELECT 
        c.*,
        p.id as postulante_id,
        p.nombres,
        p.apellido_paterno,
        p.apellido_materno,
        p.dni,
        g.nombre as grado,
        s.nombre as sede,
        u.nombres as padre_nombre,
        u.apellidos as padre_apellidos
    FROM citas c
    JOIN postulantes p ON c.id_postulante = p.id
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN usuarios u ON p.id_usuario_padre = u.id
    WHERE $where
    ORDER BY c.fecha DESC, c.hora DESC
", $params);

// Estadísticas
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM citas WHERE estado = 'pendiente' AND tipo = 'psicopedagogica') as pendientes,
        (SELECT COUNT(*) FROM citas WHERE estado = 'confirmada' AND tipo = 'psicopedagogica') as confirmadas,
        (SELECT COUNT(*) FROM citas WHERE estado = 'realizada' AND tipo = 'psicopedagogica') as realizadas,
        (SELECT COUNT(*) FROM citas WHERE estado = 'cancelada' AND tipo = 'psicopedagogica') as canceladas,
        (SELECT COUNT(*) FROM citas WHERE tipo = 'psicopedagogica') as total
");

$estados = [
    'todos' => 'Todos',
    'pendiente' => '⏳ Pendiente',
    'confirmada' => '✅ Confirmada',
    'realizada' => '📋 Realizada',
    'cancelada' => '❌ Cancelada'
];

$colores_estado = [
    'pendiente' => 'warning',
    'confirmada' => 'success',
    'realizada' => 'info',
    'cancelada' => 'danger'
];
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-calendar-heart"></i> Citas Psicopedagógicas</h4>
        <p class="text-muted">Administra las citas psicopedagógicas de los postulantes</p>

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
                    <div class="number"><?php echo $stats['confirmadas'] ?? 0; ?></div>
                    <div class="label">✅ Confirmadas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-info-dark">
                    <div class="number"><?php echo $stats['realizadas'] ?? 0; ?></div>
                    <div class="label">📋 Realizadas</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-primary-dark">
                    <div class="number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="label">Total Citas</div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- CITAS PENDIENTES -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-clock-history"></i> Citas Pendientes de Confirmación</h6>
            <hr>

            <?php if (empty($citas_pendientes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle" style="font-size: 40px; color: #2e7d32;"></i>
                    <p>No hay citas psicopedagógicas pendientes de confirmación</p>
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
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($citas_pendientes as $c): ?>
                                <tr>
                                    <td><?php echo $c['nombres'] . ' ' . $c['apellido_paterno']; ?></td>
                                    <td><?php echo $c['dni']; ?></td>
                                    <td><?php echo $c['padre_nombre'] . ' ' . $c['padre_apellidos']; ?></td>
                                    <td><?php echo $c['grado']; ?></td>
                                    <td><?php echo $c['sede']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($c['fecha'])); ?></td>
                                    <td><?php echo date('H:i', strtotime($c['hora'])); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="citas.php?aprobar=1&id=<?php echo $c['id']; ?>" 
                                               class="btn btn-success"
                                               onclick="return confirm('¿Aprobar esta cita psicopedagógica?')">
                                                <i class="bi bi-check"></i> Aprobar
                                            </a>
                                            <a href="citas.php?rechazar=1&id=<?php echo $c['id']; ?>" 
                                               class="btn btn-danger"
                                               onclick="return confirm('¿Rechazar esta cita psicopedagógica?')">
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
        <!-- HISTORIAL DE CITAS PSICOPEDAGÓGICAS -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-list"></i> Historial de Citas Psicopedagógicas</h6>
                <span class="badge bg-primary"><?php echo count($citas_todas); ?> registros</span>
            </div>
            <hr>

            <!-- ========================================== -->
            <!-- FILTROS -->
            <!-- ========================================== -->
            <form method="GET" class="row g-2 mb-3">
                <div class="col-md-3">
                    <select name="estado" class="form-select form-select-sm">
                        <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                        <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>⏳ Pendientes</option>
                        <option value="confirmada" <?php echo $filtro_estado == 'confirmada' ? 'selected' : ''; ?>>✅ Confirmadas</option>
                        <option value="realizada" <?php echo $filtro_estado == 'realizada' ? 'selected' : ''; ?>>📋 Realizadas</option>
                        <option value="cancelada" <?php echo $filtro_estado == 'cancelada' ? 'selected' : ''; ?>>❌ Canceladas</option>
                    </select>
                </div>
                <div class="col-md-5">
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
                    <a href="citas.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="bi bi-arrow-clockwise"></i> Limpiar
                    </a>
                </div>
            </form>

            <?php if (empty($citas_todas)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay citas psicopedagógicas con los filtros seleccionados</p>
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
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($citas_todas as $c): ?>
                                <tr>
                                    <td><?php echo $c['nombres'] . ' ' . $c['apellido_paterno']; ?></td>
                                    <td><?php echo $c['dni']; ?></td>
                                    <td><?php echo $c['padre_nombre'] . ' ' . $c['padre_apellidos']; ?></td>
                                    <td><?php echo $c['grado']; ?></td>
                                    <td><?php echo $c['sede']; ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($c['fecha'])); ?></td>
                                    <td><?php echo date('H:i', strtotime($c['hora'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $colores_estado[$c['estado']] ?? 'secondary'; ?>">
                                            <?php echo $estados[$c['estado']] ?? $c['estado']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <?php if ($c['estado'] == 'confirmada'): ?>
                                                <a href="citas.php?realizada=1&id=<?php echo $c['id']; ?>" 
                                                   class="btn btn-info"
                                                   onclick="return confirm('¿Marcar esta cita como realizada?')">
                                                    <i class="bi bi-check2-circle"></i> Realizada
                                                </a>
                                            <?php endif; ?>
                                            <a href="citas.php?eliminar=1&id=<?php echo $c['id']; ?>" 
                                               class="btn btn-outline-danger"
                                               onclick="return confirm('¿Eliminar esta cita?')">
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