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
// ASIGNAR CÓDIGO DE MATRÍCULA
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['asignar'])) {
    $id_postulante = (int)$_POST['id_postulante'];
    $codigo_matricula = trim($_POST['codigo_matricula']);
    $monto = (float)$_POST['monto'];
    
    if ($id_postulante > 0 && !empty($codigo_matricula) && $monto > 0) {
        // Verificar si el código ya existe
        $existe = fetchOne("SELECT id FROM matricula_asignacion WHERE codigo_matricula = ?", [$codigo_matricula]);
        if ($existe) {
            $error = "❌ El código de matrícula ya existe. Usa otro.";
        } else {
            // 🔥 CORREGIDO: Verificar si hay una asignación PENDIENTE O PAGADA (NO rechazados)
            $asignacion_activa = fetchOne("SELECT id FROM matricula_asignacion WHERE id_postulante = ? AND estado IN ('pendiente', 'pagado', 'verificado')", [$id_postulante]);
            if ($asignacion_activa) {
                $error = "❌ Este postulante ya tiene una asignación activa (pendiente, pagado o verificado).";
            } else {
                // ✅ Si hay una asignación rechazada, la eliminamos para crear una nueva
                $rechazado = fetchOne("SELECT id FROM matricula_asignacion WHERE id_postulante = ? AND estado = 'rechazado'", [$id_postulante]);
                if ($rechazado) {
                    query("DELETE FROM matricula_asignacion WHERE id = ?", [$rechazado['id']]);
                }
                
                $insertado = insert(
                    "INSERT INTO matricula_asignacion (id_postulante, codigo_matricula, monto, estado) VALUES (?, ?, ?, 'pendiente')",
                    [$id_postulante, $codigo_matricula, $monto]
                );
                
                if ($insertado) {
                    // ✅ Mantener el estado del postulante en 'cita_aprobada' o 'evaluacion_aprobada'
                    // Si está en 'voucher_rechazado', volver a 'cita_aprobada'
                    $postulante_actual = fetchOne("SELECT estado_proceso FROM postulantes WHERE id = ?", [$id_postulante]);
                    if ($postulante_actual['estado_proceso'] == 'voucher_rechazado') {
                        query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$id_postulante]);
                    }
                    
                    registrarAuditoria('matricula_asignacion', 'asignar', 'postulante', $id_postulante, "Nuevo código $codigo_matricula asignado por S/. $monto");
                    $mensaje = "✅ Nuevo código de matrícula asignado correctamente.";
                } else {
                    $error = "❌ Error al asignar el código.";
                }
            }
        }
    } else {
        $error = "❌ Complete todos los campos.";
    }
}

// ============================================
// VERIFICAR PAGO (MARCAR COMO VERIFICADO)
// ============================================
if (isset($_GET['verificar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $asignacion = fetchOne("SELECT id_postulante FROM matricula_asignacion WHERE id = ?", [$id]);
    if ($asignacion) {
        query("UPDATE matricula_asignacion SET estado = 'verificado', fecha_pago = NOW() WHERE id = ?", [$id]);
        query("UPDATE postulantes SET estado_proceso = 'matriculado' WHERE id = ?", [$asignacion['id_postulante']]);
        registrarAuditoria('matricula_asignacion', 'verificar', 'postulante', $asignacion['id_postulante'], 'Pago de matrícula verificado - POSTULANTE MATRICULADO');
        $mensaje = "✅ Pago de matrícula verificado correctamente. El postulante ahora está MATRICULADO.";
    }
    header('Location: asignar_matricula.php');
    exit;
}

// ============================================
// RECHAZAR PAGO
// ============================================
if (isset($_GET['rechazar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $asignacion = fetchOne("SELECT id_postulante FROM matricula_asignacion WHERE id = ?", [$id]);
    if ($asignacion) {
        query("UPDATE matricula_asignacion SET estado = 'rechazado' WHERE id = ?", [$id]);
        query("UPDATE postulantes SET estado_proceso = 'voucher_rechazado' WHERE id = ?", [$asignacion['id_postulante']]);
        registrarAuditoria('matricula_asignacion', 'rechazar', 'postulante', $asignacion['id_postulante'], 'Pago de matrícula rechazado - Postulante en voucher_rechazado');
        $mensaje = "❌ Pago de matrícula rechazado. El postulante puede solicitar una nueva asignación.";
    }
    header('Location: asignar_matricula.php');
    exit;
}

// ============================================
// ELIMINAR ASIGNACIÓN
// ============================================
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $asignacion = fetchOne("SELECT id_postulante FROM matricula_asignacion WHERE id = ?", [$id]);
    if ($asignacion) {
        query("DELETE FROM matricula_asignacion WHERE id = ?", [$id]);
        query("UPDATE postulantes SET estado_proceso = 'cita_aprobada' WHERE id = ?", [$asignacion['id_postulante']]);
        registrarAuditoria('matricula_asignacion', 'eliminar', 'postulante', $asignacion['id_postulante'], 'Asignación de matrícula eliminada - Postulante vuelve a cita_aprobada');
        $mensaje = "🗑️ Asignación eliminada correctamente.";
    }
    header('Location: asignar_matricula.php');
    exit;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
// Obtener postulantes que están en paso de matrícula (excluyendo los que tienen asignación activa)
$postulantes_pendientes = fetchAll("
    SELECT p.*, 
           g.nombre as grado, 
           s.nombre as sede,
           u.nombres as padre_nombre,
           u.apellidos as padre_apellidos
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN usuarios u ON p.id_usuario_padre = u.id
    WHERE p.estado_proceso IN ('cita_aprobada', 'evaluacion_aprobada', 'voucher_rechazado')
    AND p.id NOT IN (SELECT id_postulante FROM matricula_asignacion WHERE estado IN ('pendiente', 'pagado', 'verificado'))
    ORDER BY p.fecha_registro DESC
");

// Obtener asignaciones existentes (incluyendo rechazados)
$asignaciones = fetchAll("
    SELECT ma.*, 
           p.nombres, 
           p.apellido_paterno, 
           p.apellido_materno,
           p.dni,
           g.nombre as grado,
           s.nombre as sede,
           u.nombres as padre_nombre,
           u.apellidos as padre_apellidos,
           p.estado_proceso as postulante_estado
    FROM matricula_asignacion ma
    JOIN postulantes p ON ma.id_postulante = p.id
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    JOIN usuarios u ON p.id_usuario_padre = u.id
    ORDER BY ma.fecha_asignacion DESC
");

// Estadísticas
$stats = fetchOne("
    SELECT 
        (SELECT COUNT(*) FROM matricula_asignacion WHERE estado = 'pendiente') as pendientes,
        (SELECT COUNT(*) FROM matricula_asignacion WHERE estado = 'pagado') as pagados,
        (SELECT COUNT(*) FROM matricula_asignacion WHERE estado = 'verificado') as verificados,
        (SELECT COUNT(*) FROM matricula_asignacion WHERE estado = 'rechazado') as rechazados,
        (SELECT COUNT(*) FROM matricula_asignacion) as total
");

$estados = [
    'pendiente' => '⏳ Pendiente',
    'pagado' => '💳 Pagado (Voucher subido)',
    'verificado' => '✅ Verificado',
    'rechazado' => '❌ Rechazado'
];

$colores_estado = [
    'pendiente' => 'warning',
    'pagado' => 'info',
    'verificado' => 'success',
    'rechazado' => 'danger'
];
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-cash-stack"></i> Asignación de Matrícula</h4>
        <p class="text-muted">Asigna códigos de matrícula a los postulantes que están listos para pagar.</p>

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
                <div class="stat-card bg-info-dark">
                    <div class="number"><?php echo $stats['pagados'] ?? 0; ?></div>
                    <div class="label">💳 Pagados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-success-dark">
                    <div class="number"><?php echo $stats['verificados'] ?? 0; ?></div>
                    <div class="label">✅ Verificados</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card bg-primary-dark">
                    <div class="number"><?php echo $stats['total'] ?? 0; ?></div>
                    <div class="label">Total Asignaciones</div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- POSTULANTES PENDIENTES PARA ASIGNAR -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-person"></i> Postulantes Listos para Matrícula</h6>
            <hr>
            <p class="text-muted">Postulantes en <strong>cita_aprobada</strong>, <strong>evaluacion_aprobada</strong> o con <strong>voucher rechazado</strong>.</p>

            <?php if (empty($postulantes_pendientes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-check-circle" style="font-size: 40px; color: #2e7d32;"></i>
                    <p>No hay postulantes pendientes de asignación</p>
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
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postulantes_pendientes as $p): ?>
                                <tr>
                                    <td><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['padre_nombre'] . ' ' . $p['padre_apellidos']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $p['estado_proceso'] == 'voucher_rechazado' ? 'danger' : 'success'; 
                                        ?>">
                                            <?php echo str_replace('_', ' ', $p['estado_proceso']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#asignarModal"
                                                data-id="<?php echo $p['id']; ?>"
                                                data-nombre="<?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?>">
                                            <i class="bi bi-plus-circle"></i> Asignar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ========================================== -->
        <!-- ASIGNACIONES EXISTENTES -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-list"></i> Asignaciones de Matrícula</h6>
                <span class="badge bg-primary"><?php echo count($asignaciones); ?> registros</span>
            </div>
            <hr>

            <?php if (empty($asignaciones)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay asignaciones de matrícula registradas</p>
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
                                <th>Código</th>
                                <th>Monto</th>
                                <th>Voucher</th>
                                <th>Estado Matrícula</th>
                                <th>Estado Postulante</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asignaciones as $a): ?>
                                <tr>
                                    <td><?php echo $a['nombres'] . ' ' . $a['apellido_paterno']; ?></td>
                                    <td><?php echo $a['dni']; ?></td>
                                    <td><?php echo $a['padre_nombre'] . ' ' . $a['padre_apellidos']; ?></td>
                                    <td><?php echo $a['grado']; ?></td>
                                    <td><?php echo $a['sede']; ?></td>
                                    <td><code><?php echo $a['codigo_matricula']; ?></code></td>
                                    <td><strong>S/. <?php echo number_format($a['monto'], 2); ?></strong></td>
                                    <td>
                                        <?php if ($a['voucher']): ?>
                                            <a href="../uploads/vouchers_matricula/<?php echo $a['voucher']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> Ver
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">Sin voucher</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $colores_estado[$a['estado']] ?? 'secondary'; ?>">
                                            <?php echo $estados[$a['estado']] ?? $a['estado']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $a['postulante_estado'] == 'matriculado' ? 'success' : 'secondary'; ?>">
                                            <?php echo str_replace('_', ' ', $a['postulante_estado']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($a['estado'] == 'pendiente'): ?>
                                            <a href="asignar_matricula.php?eliminar=1&id=<?php echo $a['id']; ?>" 
                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('¿Eliminar esta asignación?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($a['estado'] == 'pagado'): ?>
                                            <a href="asignar_matricula.php?verificar=1&id=<?php echo $a['id']; ?>" 
                                               class="btn btn-sm btn-success"
                                               onclick="return confirm('¿Verificar este pago de matrícula?')">
                                                <i class="bi bi-check"></i> Verificar
                                            </a>
                                            <a href="asignar_matricula.php?rechazar=1&id=<?php echo $a['id']; ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('¿Rechazar este pago de matrícula?')">
                                                <i class="bi bi-x"></i> Rechazar
                                            </a>
                                        <?php endif; ?>
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

<!-- ========================================== -->
<!-- MODAL ASIGNAR CÓDIGO -->
<!-- ========================================== -->
<div class="modal fade" id="asignarModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Asignar Código de Matrícula</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id_postulante" id="asignarId">
                    <input type="hidden" name="asignar" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label">Postulante</label>
                        <input type="text" id="asignarNombre" class="form-control" disabled>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Código de Matrícula</label>
                        <input type="text" name="codigo_matricula" class="form-control" placeholder="Ej: MAT-2027-001" required>
                        <small class="text-muted">Código único para este postulante.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Monto a Pagar (S/)</label>
                        <input type="number" name="monto" class="form-control" placeholder="150.00" step="0.01" required>
                    </div>
                    
                    <div class="alert alert-info mt-2">
                        <i class="bi bi-info-circle"></i>
                        <strong>Nota:</strong> Si el postulante tiene un voucher rechazado, se eliminará la asignación anterior.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Asignar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('asignarModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-id');
            const nombre = button.getAttribute('data-nombre');
            
            document.getElementById('asignarId').value = id;
            document.getElementById('asignarNombre').value = nombre;
        });
    }
});
</script>

<?php include 'footer.php'; ?>