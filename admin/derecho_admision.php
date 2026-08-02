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
// OBTENER DATOS PARA SELECTS
// ============================================
$distritos = fetchAll("SELECT * FROM distritos WHERE estado = 1 ORDER BY nombre");
$sedes = fetchAll("SELECT * FROM sedes WHERE estado = 1 ORDER BY nombre");
$niveles = fetchAll("SELECT * FROM niveles WHERE estado = 1 ORDER BY nombre");
$grados = fetchAll("SELECT g.*, n.nombre as nivel_nombre FROM grados g JOIN niveles n ON g.id_nivel = n.id WHERE g.estado = 1 ORDER BY n.nombre, g.orden");

// ============================================
// AGREGAR CONFIGURACIÓN
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar'])) {
    $id_distrito = (int)$_POST['id_distrito'];
    $id_nivel = (int)$_POST['id_nivel'];
    $id_grado = !empty($_POST['id_grado']) ? (int)$_POST['id_grado'] : null;
    $id_sede = (int)$_POST['id_sede'];
    $monto = (float)$_POST['monto'];
    $estado = isset($_POST['estado']) ? 1 : 0;
    
    if ($id_distrito > 0 && $id_nivel > 0 && $id_sede > 0 && $monto > 0) {
        // Verificar si ya existe
        $existe = fetchOne("SELECT id FROM configuracion_derecho_admision WHERE id_sede = ? AND id_nivel = ? AND (id_grado = ? OR (id_grado IS NULL AND ? IS NULL))", 
                          [$id_sede, $id_nivel, $id_grado, $id_grado]);
        
        if ($existe) {
            $error = "❌ Ya existe una configuración para esta combinación de Sede, Nivel y Grado.";
        } else {
            $sql = "INSERT INTO configuracion_derecho_admision (id_sede, id_nivel, id_grado, monto, estado) 
                    VALUES (?, ?, ?, ?, ?)";
            $params = [$id_sede, $id_nivel, $id_grado, $monto, $estado];
            insert($sql, $params);
            registrarAuditoria('derecho_admision', 'crear', 'configuracion', null, 'Nueva configuración de derecho de admisión creada');
            $mensaje = "✅ Configuración guardada correctamente.";
        }
    } else {
        $error = "❌ Complete todos los campos obligatorios.";
    }
}

// ============================================
// EDITAR CONFIGURACIÓN
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar'])) {
    $id = (int)$_POST['id'];
    $id_distrito = (int)$_POST['id_distrito'];
    $id_nivel = (int)$_POST['id_nivel'];
    $id_grado = !empty($_POST['id_grado']) ? (int)$_POST['id_grado'] : null;
    $id_sede = (int)$_POST['id_sede'];
    $monto = (float)$_POST['monto'];
    $estado = isset($_POST['estado']) ? 1 : 0;
    
    if ($id > 0 && $id_sede > 0 && $id_nivel > 0 && $monto > 0) {
        $sql = "UPDATE configuracion_derecho_admision SET id_sede = ?, id_nivel = ?, id_grado = ?, monto = ?, estado = ? WHERE id = ?";
        $params = [$id_sede, $id_nivel, $id_grado, $monto, $estado, $id];
        query($sql, $params);
        registrarAuditoria('derecho_admision', 'editar', 'configuracion', $id, 'Configuración de derecho de admisión actualizada');
        $mensaje = "✅ Configuración actualizada correctamente.";
    } else {
        $error = "❌ Complete todos los campos obligatorios.";
    }
}

// ============================================
// ELIMINAR CONFIGURACIÓN
// ============================================
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    query("DELETE FROM configuracion_derecho_admision WHERE id = ?", [$id]);
    registrarAuditoria('derecho_admision', 'eliminar', 'configuracion', $id, 'Configuración de derecho de admisión eliminada');
    $mensaje = "🗑️ Configuración eliminada correctamente.";
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
$configuraciones = fetchAll("
    SELECT 
        c.*,
        s.nombre as sede_nombre,
        n.nombre as nivel_nombre,
        g.nombre as grado_nombre,
        d.nombre as distrito_nombre
    FROM configuracion_derecho_admision c
    JOIN sedes s ON c.id_sede = s.id
    JOIN niveles n ON c.id_nivel = n.id
    LEFT JOIN grados g ON c.id_grado = g.id
    LEFT JOIN distritos d ON s.id_distrito = d.id
    ORDER BY s.nombre, n.nombre, g.orden
");
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-cash-stack"></i> Configuración del Derecho de Admisión</h4>
        <p class="text-muted">Establezca el importe requerido para el Pago por Derecho de Admisión de manera independiente por la combinación de Sede, Nivel Educativo y Grado.</p>

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
        <!-- FORMULARIO PARA AGREGAR CONFIGURACIÓN -->
        <!-- ORDEN: Distrito → Nivel → Grado → Sede → Monto -->
        <!-- ========================================== -->
        <div class="card-dashboard mb-4">
            <h6 class="text-primary-dark"><i class="bi bi-plus-circle"></i> Nueva Configuración</h6>
            <hr>
            <form method="POST" class="row g-3" id="formDerechoAdmision">
                <input type="hidden" name="agregar" value="1">
                
                <div class="col-md-2">
                    <label class="form-label">Distrito</label>
                    <select name="id_distrito" class="form-select" id="selectDistrito" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($distritos as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo $d['nombre']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Nivel Educativo</label>
                    <select name="id_nivel" class="form-select" id="selectNivel" required>
                        <option value="">Seleccionar...</option>
                        <?php foreach ($niveles as $n): ?>
                            <option value="<?php echo $n['id']; ?>"><?php echo $n['nombre']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Grado</label>
                    <select name="id_grado" class="form-select" id="selectGrado">
                        <option value="">Todos los grados</option>
                        <?php foreach ($grados as $g): ?>
                            <option value="<?php echo $g['id']; ?>"><?php echo $g['nombre']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label class="form-label">Sede Local</label>
                    <select name="id_sede" class="form-select" id="selectSede" required>
                        <option value="">Seleccionar...</option>
                        <?php 
                        // Mostrar todas las sedes inicialmente
                        foreach ($sedes as $s): 
                            $distrito_nombre = fetchOne("SELECT nombre FROM distritos WHERE id = ?", [$s['id_distrito']]);
                        ?>
                            <option value="<?php echo $s['id']; ?>"><?php echo $s['nombre']; ?> (<?php echo $distrito_nombre['nombre'] ?? 'Sin distrito'; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <label class="form-label">Monto (S/)</label>
                    <input type="number" name="monto" class="form-control" placeholder="150.00" step="0.01" required>
                </div>
                
                <div class="col-md-1 d-flex align-items-center">
                    <div class="form-check">
                        <input type="checkbox" name="estado" class="form-check-input" id="estadoActivo" checked>
                        <label class="form-check-label" for="estadoActivo">Activo</label>
                    </div>
                </div>
                
                <div class="col-md-12 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save"></i> Guardar Configuración
                    </button>
                </div>
            </form>
        </div>

        <!-- ========================================== -->
        <!-- LISTA DE CONFIGURACIONES -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="text-primary-dark"><i class="bi bi-list"></i> Resumen de Configuración</h6>
                <span class="badge bg-primary"><?php echo count($configuraciones); ?> registros</span>
            </div>
            <hr>

            <?php if (empty($configuraciones)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay configuraciones de derecho de admisión</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Sede</th>
                                <th>Distrito</th>
                                <th>Nivel</th>
                                <th>Grado</th>
                                <th>Derecho de Admisión</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($configuraciones as $c): ?>
                                <tr>
                                    <td><strong><?php echo $c['sede_nombre']; ?></strong></td>
                                    <td><?php echo $c['distrito_nombre'] ?? '-'; ?></td>
                                    <td><?php echo $c['nivel_nombre']; ?></td>
                                    <td><?php echo $c['grado_nombre'] ?? 'Todos los grados'; ?></td>
                                    <td><strong>S/. <?php echo number_format($c['monto'], 2); ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo $c['estado'] ? 'success' : 'danger'; ?>">
                                            <?php echo $c['estado'] ? 'Activo' : 'Inactivo'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#editarModal" 
                                                data-id="<?php echo $c['id']; ?>"
                                                data-distrito="<?php echo $c['id_distrito']; ?>"
                                                data-nivel="<?php echo $c['id_nivel']; ?>"
                                                data-grado="<?php echo $c['id_grado']; ?>"
                                                data-sede="<?php echo $c['id_sede']; ?>"
                                                data-monto="<?php echo $c['monto']; ?>"
                                                data-estado="<?php echo $c['estado']; ?>">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="derecho_admision.php?eliminar=1&id=<?php echo $c['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('¿Eliminar esta configuración?')">
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
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL EDITAR -->
<!-- ========================================== -->
<div class="modal fade" id="editarModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Configuración</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="editId">
                    <input type="hidden" name="editar" value="1">
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Distrito</label>
                            <select name="id_distrito" id="editDistrito" class="form-select" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($distritos as $d): ?>
                                    <option value="<?php echo $d['id']; ?>"><?php echo $d['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Nivel</label>
                            <select name="id_nivel" id="editNivel" class="form-select" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($niveles as $n): ?>
                                    <option value="<?php echo $n['id']; ?>"><?php echo $n['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Grado</label>
                            <select name="id_grado" id="editGrado" class="form-select">
                                <option value="">Todos los grados</option>
                                <?php foreach ($grados as $g): ?>
                                    <option value="<?php echo $g['id']; ?>"><?php echo $g['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Sede</label>
                            <select name="id_sede" id="editSede" class="form-select" required>
                                <option value="">Seleccionar...</option>
                                <?php foreach ($sedes as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo $s['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Monto (S/)</label>
                            <input type="number" name="monto" id="editMonto" class="form-control" step="0.01" required>
                        </div>
                        <div class="col-md-1 d-flex align-items-center">
                            <div class="form-check">
                                <input type="checkbox" name="estado" id="editEstado" class="form-check-input" value="1">
                                <label class="form-check-label" for="editEstado">Activo</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // ============================================
    // DATOS DE SEDES POR DISTRITO Y NIVEL
    // ============================================
    const sedesData = <?php echo json_encode($sedes); ?>;
    const nivelesData = <?php echo json_encode($niveles); ?>;
    const gradosData = <?php echo json_encode($grados); ?>;

    // ============================================
    // SELECTORES
    // ============================================
    const selectDistrito = document.getElementById('selectDistrito');
    const selectNivel = document.getElementById('selectNivel');
    const selectGrado = document.getElementById('selectGrado');
    const selectSede = document.getElementById('selectSede');

    // ============================================
    // FILTRAR SEDES POR DISTRITO
    // ============================================
    function filtrarSedesPorDistrito(distritoId, sedeSelect) {
        sedeSelect.innerHTML = '<option value="">Seleccionar...</option>';
        
        const sedesFiltradas = sedesData.filter(s => s.id_distrito == distritoId);
        
        if (sedesFiltradas.length === 0) {
            sedeSelect.innerHTML += '<option value="">No hay sedes para este distrito</option>';
        } else {
            sedesFiltradas.forEach(s => {
                sedeSelect.innerHTML += `<option value="${s.id}">${s.nombre}</option>`;
            });
        }
    }

    // ============================================
    // FILTRAR GRADOS POR NIVEL
    // ============================================
    function filtrarGradosPorNivel(nivelId, gradoSelect) {
        gradoSelect.innerHTML = '<option value="">Todos los grados</option>';
        
        const gradosFiltrados = gradosData.filter(g => g.id_nivel == nivelId);
        
        if (gradosFiltrados.length === 0) {
            gradoSelect.innerHTML += '<option value="">No hay grados para este nivel</option>';
        } else {
            gradosFiltrados.forEach(g => {
                gradoSelect.innerHTML += `<option value="${g.id}">${g.nombre}</option>`;
            });
        }
    }

    // ============================================
    // EVENTO: CAMBIO DE DISTRITO
    // ============================================
    if (selectDistrito && selectSede) {
        selectDistrito.addEventListener('change', function() {
            const distritoId = this.value;
            if (distritoId) {
                filtrarSedesPorDistrito(distritoId, selectSede);
            } else {
                selectSede.innerHTML = '<option value="">Seleccionar...</option>';
                <?php foreach ($sedes as $s): ?>
                    selectSede.innerHTML += `<option value="<?php echo $s['id']; ?>"><?php echo $s['nombre']; ?></option>`;
                <?php endforeach; ?>
            }
        });
    }

    // ============================================
    // EVENTO: CAMBIO DE NIVEL (para filtrar grados)
    // ============================================
    if (selectNivel && selectGrado) {
        selectNivel.addEventListener('change', function() {
            const nivelId = this.value;
            if (nivelId) {
                filtrarGradosPorNivel(nivelId, selectGrado);
            } else {
                selectGrado.innerHTML = '<option value="">Todos los grados</option>';
                <?php foreach ($grados as $g): ?>
                    selectGrado.innerHTML += `<option value="<?php echo $g['id']; ?>"><?php echo $g['nombre']; ?></option>`;
                <?php endforeach; ?>
            }
        });
    }

    // ============================================
    // CARGAR DATOS EN MODAL
    // ============================================
    const modal = document.getElementById('editarModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            
            const id = button.getAttribute('data-id');
            const distritoId = button.getAttribute('data-distrito');
            const nivelId = button.getAttribute('data-nivel');
            const gradoId = button.getAttribute('data-grado') || '';
            const sedeId = button.getAttribute('data-sede');
            const monto = button.getAttribute('data-monto');
            const estado = button.getAttribute('data-estado') == '1';
            
            document.getElementById('editId').value = id;
            document.getElementById('editDistrito').value = distritoId;
            document.getElementById('editNivel').value = nivelId;
            document.getElementById('editGrado').value = gradoId;
            document.getElementById('editSede').value = sedeId;
            document.getElementById('editMonto').value = monto;
            document.getElementById('editEstado').checked = estado;
        });
    }
});
</script>

<?php include 'footer.php'; ?>