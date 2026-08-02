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

// Procesar acciones ANTES de incluir header.php
if (isset($_GET['aprobar']) && isset($_GET['doc_id'])) {
    $doc_id = (int)$_GET['doc_id'];
    query("UPDATE documentos_subidos SET estado = 'aprobado' WHERE id = ?", [$doc_id]);
    
    $postulante_doc = fetchOne("SELECT id_postulante FROM documentos_subidos WHERE id = ?", [$doc_id]);
    if ($postulante_doc) {
        $pendientes = fetchOne("
            SELECT COUNT(*) as total FROM documentos_subidos 
            WHERE id_postulante = ? AND estado = 'pendiente'
        ", [$postulante_doc['id_postulante']]);
        
        if ($pendientes['total'] == 0) {
            query("UPDATE postulantes SET estado_proceso = 'documentos_revisados' WHERE id = ?", [$postulante_doc['id_postulante']]);
        }
    }
    
    header('Location: documentos.php?id=' . ($postulante_doc['id_postulante'] ?? 0));
    exit;
}

if (isset($_GET['rechazar']) && isset($_GET['doc_id'])) {
    $doc_id = (int)$_GET['doc_id'];
    query("UPDATE documentos_subidos SET estado = 'rechazado' WHERE id = ?", [$doc_id]);
    
    $postulante_doc = fetchOne("SELECT id_postulante FROM documentos_subidos WHERE id = ?", [$doc_id]);
    if ($postulante_doc) {
        query("UPDATE postulantes SET estado_proceso = 'documentos_pendientes' WHERE id = ?", [$postulante_doc['id_postulante']]);
    }
    
    header('Location: documentos.php?id=' . ($postulante_doc['id_postulante'] ?? 0));
    exit;
}

if (isset($_GET['eliminar']) && isset($_GET['doc_id'])) {
    $doc_id = (int)$_GET['doc_id'];
    $doc = fetchOne("SELECT ruta FROM documentos_subidos WHERE id = ?", [$doc_id]);
    if ($doc && $doc['ruta'] && file_exists($doc['ruta'])) {
        unlink($doc['ruta']);
    }
    query("DELETE FROM documentos_subidos WHERE id = ?", [$doc_id]);
    
    header('Location: documentos.php');
    exit;
}

// ============================================
// 3. TERCERO: INCLUIR HEADER (DESPUÉS DE PROCESAR)
// ============================================
include 'header.php';

// ============================================
// 4. CUARTO: OBTENER DATOS PARA MOSTRAR
// ============================================
$postulantes = fetchAll("
    SELECT DISTINCT 
        p.id,
        p.nombres,
        p.apellido_paterno,
        p.apellido_materno,
        p.dni,
        g.nombre as grado,
        s.nombre as sede,
        p.estado_proceso,
        (SELECT COUNT(*) FROM documentos_subidos ds WHERE ds.id_postulante = p.id AND ds.estado = 'pendiente') as pendientes,
        (SELECT COUNT(*) FROM documentos_subidos ds WHERE ds.id_postulante = p.id AND ds.estado = 'aprobado') as aprobados,
        (SELECT COUNT(*) FROM documentos_subidos ds WHERE ds.id_postulante = p.id) as total
    FROM postulantes p
    JOIN grados g ON p.id_grado = g.id
    JOIN sedes s ON p.id_sede = s.id
    WHERE p.estado_proceso IN ('registrado', 'documentos_pendientes', 'documentos_revisados')
    ORDER BY p.fecha_registro DESC
");

$postulante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$documentos_postulante = [];

if ($postulante_id > 0) {
    $documentos_postulante = fetchAll("
        SELECT 
            ds.*,
            cd.nombre_documento,
            cd.obligatorio
        FROM documentos_subidos ds
        JOIN config_documentos cd ON ds.id_documento_requerido = cd.id
        WHERE ds.id_postulante = ?
        ORDER BY ds.fecha_subida DESC
    ", [$postulante_id]);
}

// ============================================
// 5. QUINTO: MOSTRAR HTML
// ============================================
?>
<div class="row">
    <div class="col-md-12">
        <h4><i class="bi bi-files"></i> Revisión de Documentos</h4>
        <p class="text-muted">Revisa y aprueba los documentos subidos por los postulantes</p>

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

        <?php if ($postulante_id > 0 && !empty($documentos_postulante)): ?>
            <!-- ========================================== -->
            <!-- VER DOCUMENTOS DE UN POSTULANTE -->
            <!-- ========================================== -->
            <div class="card-dashboard mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="text-primary-dark">
                        <i class="bi bi-person"></i> 
                        Documentos de: <?php 
                            $p = fetchOne("SELECT nombres, apellido_paterno, apellido_materno FROM postulantes WHERE id = ?", [$postulante_id]);
                            echo $p['nombres'] . ' ' . $p['apellido_paterno'] . ' ' . $p['apellido_materno'];
                        ?>
                    </h6>
                    <a href="documentos.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver
                    </a>
                </div>
                <hr>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Documento</th>
                                <th>Archivo</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documentos_postulante as $doc): ?>
                                <tr>
                                    <td><?php echo $doc['nombre_documento']; ?></td>
                                    <td>
                                        <a href="../<?php echo $doc['ruta']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> Ver
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $doc['estado'] == 'aprobado' ? 'success' : 
                                                ($doc['estado'] == 'rechazado' ? 'danger' : 'warning'); 
                                        ?>">
                                            <?php echo ucfirst($doc['estado']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($doc['fecha_subida'])); ?></td>
                                    <td>
                                        <?php if ($doc['estado'] == 'pendiente'): ?>
                                            <a href="documentos.php?aprobar=1&doc_id=<?php echo $doc['id']; ?>" 
                                               class="btn btn-sm btn-success"
                                               onclick="return confirm('¿Aprobar este documento?')">
                                                <i class="bi bi-check"></i> Aprobar
                                            </a>
                                            <a href="documentos.php?rechazar=1&doc_id=<?php echo $doc['id']; ?>" 
                                               class="btn btn-sm btn-danger"
                                               onclick="return confirm('¿Rechazar este documento?')">
                                                <i class="bi bi-x"></i> Rechazar
                                            </a>
                                        <?php endif; ?>
                                        <a href="documentos.php?eliminar=1&doc_id=<?php echo $doc['id']; ?>" 
                                           class="btn btn-sm btn-outline-danger"
                                           onclick="return confirm('¿Eliminar este documento?')">
                                            <i class="bi bi-trash"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- ========================================== -->
        <!-- LISTA DE POSTULANTES -->
        <!-- ========================================== -->
        <div class="card-dashboard">
            <h6 class="text-primary-dark"><i class="bi bi-list"></i> Postulantes con Documentos</h6>
            <hr>

            <?php if (empty($postulantes)): ?>
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-inbox" style="font-size: 40px;"></i>
                    <p>No hay postulantes con documentos pendientes</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Postulante</th>
                                <th>DNI</th>
                                <th>Grado</th>
                                <th>Sede</th>
                                <th>Documentos</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($postulantes as $p): ?>
                                <tr>
                                    <td><?php echo $p['nombres'] . ' ' . $p['apellido_paterno']; ?></td>
                                    <td><?php echo $p['dni']; ?></td>
                                    <td><?php echo $p['grado']; ?></td>
                                    <td><?php echo $p['sede']; ?></td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><?php echo $p['pendientes']; ?> pendientes</span>
                                        <span class="badge bg-success"><?php echo $p['aprobados']; ?> aprobados</span>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo $p['estado_proceso'] == 'documentos_revisados' ? 'success' : 'warning'; 
                                        ?>">
                                            <?php echo str_replace('_', ' ', $p['estado_proceso']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="documentos.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-files"></i> Revisar
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

<?php include 'footer.php'; ?>