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
// CARGAR CÓDIGOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cargar_codigos') {
    $codigos_texto = $_POST['codigos'] ?? '';
    $monto = (float)($_POST['monto'] ?? 0);
    
    if (empty($codigos_texto)) {
        $error = "❌ No se ingresaron códigos.";
        header('Location: codigos_pago.php?error=' . urlencode($error));
        exit;
    }
    
    if ($monto <= 0) {
        $error = "❌ El monto debe ser mayor a 0.";
        header('Location: codigos_pago.php?error=' . urlencode($error));
        exit;
    }
    
    $codigos = explode("\n", $codigos_texto);
    $contador = 0;
    $duplicados = 0;
    
    foreach ($codigos as $codigo) {
        $codigo = trim($codigo);
        if (!empty($codigo)) {
            // Verificar si ya existe
            $existe = fetchOne("SELECT id FROM codigos_pago WHERE codigo = ?", [$codigo]);
            if (!$existe) {
                insert("INSERT INTO codigos_pago (codigo, monto, usado) VALUES (?, ?, 0)", [$codigo, $monto]);
                $contador++;
            } else {
                $duplicados++;
            }
        }
    }
    
    registrarAuditoria('codigos_pago', 'cargar', 'codigo', 0, "Carga de $contador códigos de pago, $duplicados duplicados");
    
    $mensaje = "✅ Se cargaron $contador códigos correctamente.";
    if ($duplicados > 0) {
        $mensaje .= " $duplicados códigos duplicados fueron omitidos.";
    }
    header('Location: codigos_pago.php?mensaje=' . urlencode($mensaje));
    exit;
}

// ============================================
// ELIMINAR CÓDIGOS USADOS
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'eliminar_usado') {
    $eliminados = query("DELETE FROM codigos_pago WHERE usado = 1");
    registrarAuditoria('codigos_pago', 'eliminar_usados', 'codigo', 0, "Eliminación de códigos usados");
    header('Location: codigos_pago.php?mensaje=' . urlencode('✅ Códigos usados eliminados'));
    exit;
}

// ============================================
// ELIMINAR CÓDIGO INDIVIDUAL
// ============================================
if (isset($_GET['eliminar']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $codigo = fetchOne("SELECT codigo FROM codigos_pago WHERE id = ?", [$id]);
    if ($codigo) {
        query("DELETE FROM codigos_pago WHERE id = ?", [$id]);
        registrarAuditoria('codigos_pago', 'eliminar', 'codigo', $id, "Eliminación de código: " . $codigo['codigo']);
        header('Location: codigos_pago.php?mensaje=' . urlencode('✅ Código eliminado correctamente'));
    } else {
        header('Location: codigos_pago.php?error=' . urlencode('❌ Código no encontrado'));
    }
    exit;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
$codigos = fetchAll("SELECT * FROM codigos_pago ORDER BY id DESC");
$total = count($codigos);
$usados = 0;
foreach ($codigos as $c) {
    if ($c['usado']) $usados++;
}

// Leer mensajes de la URL
if (isset($_GET['mensaje'])) {
    $mensaje = $_GET['mensaje'];
}
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}
?>
<h4><i class="bi bi-upc-scan"></i> Códigos de Pago</h4>
<p class="text-muted">Gestión de códigos únicos para el pago de derecho de admisión</p>

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

<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-card bg-primary-dark">
            <div>
                <div class="number"><?php echo $total; ?></div>
                <div class="label">Total Códigos</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-success-dark">
            <div>
                <div class="number"><?php echo $total - $usados; ?></div>
                <div class="label">Disponibles</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-warning-dark">
            <div>
                <div class="number"><?php echo $usados; ?></div>
                <div class="label">Usados</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card bg-danger-dark">
            <div>
                <div class="number"><?php echo $total - $usados; ?></div>
                <div class="label">Disponibles</div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- FORMULARIO PARA CARGAR CÓDIGOS -->
<!-- ========================================== -->
<div class="card-dashboard mb-4">
    <h6 class="text-primary-dark"><i class="bi bi-plus-circle"></i> Cargar Códigos</h6>
    <hr>
    <form method="POST">
        <input type="hidden" name="action" value="cargar_codigos">
        <div class="row">
            <div class="col-md-8">
                <textarea name="codigos" class="form-control" rows="4" placeholder="COD-001&#10;COD-002&#10;COD-003"></textarea>
                <small class="text-muted">Ingresa un código por línea</small>
            </div>
            <div class="col-md-4">
                <input type="number" name="monto" class="form-control" step="0.01" value="100.00" required>
                <button type="submit" class="btn btn-primary mt-2 w-100">
                    <i class="bi bi-upload"></i> Cargar Códigos
                </button>
            </div>
        </div>
    </form>
</div>

<!-- ========================================== -->
<!-- LISTA DE CÓDIGOS -->
<!-- ========================================== -->
<div class="card-dashboard">
    <div class="d-flex justify-content-between align-items-center">
        <h6 class="text-primary-dark"><i class="bi bi-list"></i> Lista de Códigos</h6>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="eliminar_usado">
            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar todos los códigos usados?')">
                <i class="bi bi-trash"></i> Limpiar Usados
            </button>
        </form>
    </div>
    <hr>

    <?php if (empty($codigos)): ?>
        <div class="text-center py-4 text-muted">
            <i class="bi bi-inbox" style="font-size: 40px;"></i>
            <p>No hay códigos de pago cargados</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover table-sm">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Código</th>
                        <th>Monto</th>
                        <th>Estado</th>
                        <th>Asignado a</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($codigos as $c): ?>
                        <tr>
                            <td><?php echo $c['id']; ?></td>
                            <td><code><?php echo $c['codigo']; ?></code></td>
                            <td>S/. <?php echo number_format($c['monto'], 2); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $c['usado'] ? 'danger' : 'success'; ?>">
                                    <?php echo $c['usado'] ? 'Usado' : 'Disponible'; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($c['id_postulante']): 
                                    $post = fetchOne("SELECT nombres, apellido_paterno FROM postulantes WHERE id = ?", [$c['id_postulante']]);
                                    echo $post ? $post['nombres'] . ' ' . $post['apellido_paterno'] : 'N/A';
                                else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo $c['fecha_asignacion'] ? date('d/m/Y H:i', strtotime($c['fecha_asignacion'])) : '-'; ?>
                            </td>
                            <td>
                                <?php if (!$c['usado']): ?>
                                    <a href="codigos_pago.php?eliminar=1&id=<?php echo $c['id']; ?>" 
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('¿Eliminar este código?')">
                                        <i class="bi bi-trash"></i>
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

<?php include 'footer.php'; ?>