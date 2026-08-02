<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] != 'familia') {
    header('Location: ../login.php');
    exit;
}
require_once '../config/database.php';

$user_id = $_SESSION['user_id'];
$postulante_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$postulante = fetchOne("SELECT p.*, g.nombre as grado, s.nombre as sede, n.nombre as nivel
                        FROM postulantes p
                        JOIN grados g ON p.id_grado = g.id
                        JOIN sedes s ON p.id_sede = s.id
                        JOIN niveles n ON p.id_nivel = n.id
                        WHERE p.id = ? AND p.id_usuario_padre = ?", [$postulante_id, $user_id]);

if (!$postulante) {
    header('Location: index.php');
    exit;
}

$mensaje = '';
$error = '';
$debug_info = '';

$tipo_colegio = $postulante['tipo_colegio'] ?? 'particular';
if (empty($tipo_colegio)) $tipo_colegio = 'particular';

// Obtener documentos
$documentos = fetchAll("
    SELECT cd.*, ds.id as documento_subido_id, ds.nombre_archivo, ds.ruta, ds.estado, ds.fecha_subida
    FROM config_documentos cd
    LEFT JOIN documentos_subidos ds ON cd.id = ds.id_documento_requerido AND ds.id_postulante = ?
    WHERE cd.estado = 1
    AND (cd.id_nivel IS NULL OR cd.id_nivel = ?)
    AND (cd.id_grado IS NULL OR cd.id_grado = ?)
    AND (cd.tipo_colegio = 'ambos' OR cd.tipo_colegio = ?)
    AND (cd.tipo_alumno = 'ambos' OR cd.tipo_alumno = 'nuevo')
    ORDER BY cd.orden
", [$postulante_id, $postulante['id_nivel'], $postulante['id_grado'], $tipo_colegio]);

// ============================================
// PROCESAR SUBIDA
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $debug_info .= "✅ POST detectado\n";
    
    if (isset($_FILES['documento']) && $_FILES['documento']['error'] == UPLOAD_ERR_OK) {
        $doc_requerido_id = isset($_POST['doc_requerido_id']) ? (int)$_POST['doc_requerido_id'] : 0;
        $archivo = $_FILES['documento'];
        
        $debug_info .= "📋 ID documento: $doc_requerido_id\n";
        $debug_info .= "📁 Nombre: " . $archivo['name'] . "\n";
        $debug_info .= "📁 Tamaño: " . $archivo['size'] . " bytes\n";
        $debug_info .= "📁 Tipo: " . $archivo['type'] . "\n";
        $debug_info .= "📁 Temp: " . $archivo['tmp_name'] . "\n";
        
        if ($doc_requerido_id > 0) {
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $extensiones_permitidas = ['pdf', 'jpg', 'jpeg', 'png'];
            
            if (!in_array($extension, $extensiones_permitidas)) {
                $error = "❌ Formato no permitido. Usa PDF, JPG o PNG.";
                $debug_info .= "❌ " . $error . "\n";
            } elseif ($archivo['size'] > 5 * 1024 * 1024) {
                $error = "❌ El archivo es demasiado grande. Máximo 5MB.";
                $debug_info .= "❌ " . $error . "\n";
            } else {
                $carpeta_destino = __DIR__ . '/../uploads/documentos/';
                
                if (!file_exists($carpeta_destino)) {
                    if (mkdir($carpeta_destino, 0777, true)) {
                        $debug_info .= "✅ Carpeta creada\n";
                    } else {
                        $error = "❌ No se pudo crear la carpeta";
                        $debug_info .= "❌ " . $error . "\n";
                    }
                }
                
                if (empty($error)) {
                    $nombre_archivo = 'doc_' . $postulante_id . '_' . $doc_requerido_id . '_' . time() . '.' . $extension;
                    $ruta_completa = $carpeta_destino . $nombre_archivo;
                    
                    if (move_uploaded_file($archivo['tmp_name'], $ruta_completa)) {
                        $debug_info .= "✅ Archivo movido: $ruta_completa\n";
                        
                        $ruta_relativa = 'uploads/documentos/' . $nombre_archivo;
                        
                        // ============================================
                        // VERIFICAR TABLA documentos_subidos
                        // ============================================
                        $debug_info .= "🔍 Verificando tabla documentos_subidos...\n";
                        
                        // 1. Verificar si existe el registro
                        $existe = fetchOne("SELECT id FROM documentos_subidos WHERE id_postulante = ? AND id_documento_requerido = ?", 
                                          [$postulante_id, $doc_requerido_id]);
                        
                        if ($existe) {
                            $debug_info .= "📝 Registro existente (ID: " . $existe['id'] . ")\n";
                            
                            // Eliminar archivo anterior
                            $old = fetchOne("SELECT ruta FROM documentos_subidos WHERE id = ?", [$existe['id']]);
                            if ($old && $old['ruta'] && file_exists($old['ruta'])) {
                                unlink($old['ruta']);
                                $debug_info .= "🗑️ Archivo anterior eliminado\n";
                            }
                            
                            // Actualizar
                            $sql = "UPDATE documentos_subidos SET nombre_archivo = ?, ruta = ?, estado = 'pendiente', fecha_subida = NOW() 
                                   WHERE id_postulante = ? AND id_documento_requerido = ?";
                            $params = [$nombre_archivo, $ruta_relativa, $postulante_id, $doc_requerido_id];
                            $debug_info .= "📝 SQL UPDATE: $sql\n";
                            $debug_info .= "📝 Parámetros: " . print_r($params, true) . "\n";
                            
                            $resultado = query($sql, $params);
                            $debug_info .= "✅ UPDATE ejecutado\n";
                        } else {
                            $debug_info .= "📝 No existe registro previo. Insertando...\n";
                            
                            // Insertar
                            $sql = "INSERT INTO documentos_subidos (id_postulante, id_documento_requerido, nombre_archivo, ruta, estado) 
                                   VALUES (?, ?, ?, ?, 'pendiente')";
                            $params = [$postulante_id, $doc_requerido_id, $nombre_archivo, $ruta_relativa];
                            $debug_info .= "📝 SQL INSERT: $sql\n";
                            $debug_info .= "📝 Parámetros: " . print_r($params, true) . "\n";
                            
                            $resultado = query($sql, $params);
                            $debug_info .= "✅ INSERT ejecutado\n";
                        }
                        
                        // ============================================
                        // VERIFICAR QUE SE GUARDÓ CORRECTAMENTE
                        // ============================================
                        $verificar = fetchOne("SELECT id FROM documentos_subidos WHERE id_postulante = ? AND id_documento_requerido = ?", 
                                             [$postulante_id, $doc_requerido_id]);
                        
                        if ($verificar) {
                            $debug_info .= "✅ REGISTRO VERIFICADO EN BD (ID: " . $verificar['id'] . ")\n";
                            
                            // Actualizar estado del postulante
                            query("UPDATE postulantes SET estado_proceso = 'documentos_pendientes' WHERE id = ?", [$postulante_id]);
                            $debug_info .= "✅ Estado del postulante actualizado\n";
                            
                            $mensaje = "✅ Documento subido correctamente";
                            header('Location: documentos.php?id=' . $postulante_id . '&upload=success');
                            exit;
                        } else {
                            $error = "❌ No se pudo verificar el registro en la BD";
                            $debug_info .= "❌ " . $error . "\n";
                            
                            // Eliminar archivo físico si falló la BD
                            if (file_exists($ruta_completa)) {
                                unlink($ruta_completa);
                                $debug_info .= "🗑️ Archivo eliminado (falló BD)\n";
                            }
                        }
                    } else {
                        $error = "❌ Error al mover el archivo";
                        $debug_info .= "❌ " . $error . "\n";
                    }
                }
            }
        } else {
            $error = "❌ ID de documento requerido no válido";
            $debug_info .= "❌ " . $error . "\n";
        }
    } else {
        $error = "❌ No se recibió ningún archivo";
        $debug_info .= "❌ " . $error . "\n";
        $debug_info .= "📋 FILES: " . print_r($_FILES, true) . "\n";
    }
}

// ============================================
// MOSTRAR MENSAJES Y DEBUG
// ============================================
$total_docs = count($documentos);
$docs_subidos = 0;
$docs_aprobados = 0;
foreach ($documentos as $doc) {
    if ($doc['documento_subido_id']) {
        $docs_subidos++;
    }
    if ($doc['estado'] == 'aprobado') {
        $docs_aprobados++;
    }
}
$todos_subidos = ($total_docs > 0 && $docs_subidos == $total_docs);
$todos_aprobados = ($total_docs > 0 && $docs_aprobados == $total_docs);
$estado = $postulante['estado_proceso'];

$estado_mensaje = '';
$estado_color = 'warning';
if ($todos_aprobados) {
    $estado_mensaje = '✅ Todos los documentos han sido aprobados. Puedes continuar con el proceso.';
    $estado_color = 'success';
} elseif ($todos_subidos) {
    $estado_mensaje = '⏳ Todos los documentos han sido subidos. Espera la aprobación del administrador.';
    $estado_color = 'info';
} elseif ($docs_subidos > 0) {
    $estado_mensaje = '📄 Has subido ' . $docs_subidos . ' de ' . $total_docs . ' documentos. Continúa subiendo el resto.';
    $estado_color = 'warning';
} else {
    $estado_mensaje = '📄 Debes subir los ' . $total_docs . ' documentos requeridos.';
    $estado_color = 'danger';
}

// Eliminar documento
if (isset($_GET['eliminar'])) {
    $doc_id = (int)$_GET['eliminar'];
    $doc = fetchOne("SELECT ruta FROM documentos_subidos WHERE id = ? AND id_postulante = ?", [$doc_id, $postulante_id]);
    if ($doc && $doc['ruta'] && file_exists($doc['ruta'])) {
        unlink($doc['ruta']);
    }
    query("DELETE FROM documentos_subidos WHERE id = ? AND id_postulante = ?", [$doc_id, $postulante_id]);
    query("UPDATE postulantes SET estado_proceso = 'registrado' WHERE id = ?", [$postulante_id]);
    header('Location: documentos.php?id=' . $postulante_id);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentos - Admisión 2027</title>
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
        .btn-outline-secondary { color: #6c757d; border-color: #6c757d; }
        .btn-outline-secondary:hover { background: #6c757d; color: white; }
        .text-primary-dark { color: #1a3a6b; }
        .card-documento {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            border: 1px solid #e9ecef;
        }
        .card-documento .documento-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .card-documento .documento-header h5 { font-weight: 700; font-size: 16px; margin: 0; }
        .card-documento .documento-header .badge-estado { font-size: 12px; padding: 4px 14px; border-radius: 20px; }
        .card-documento .acciones-documento { display: flex; gap: 10px; flex-wrap: wrap; }
        .card-documento .acciones-documento .btn { font-size: 13px; padding: 6px 18px; }
        .card-documento .documento-subido {
            background: #e8f5e9; padding: 10px 15px; border-radius: 8px;
            display: flex; justify-content: space-between; align-items: center; margin-top: 10px;
        }
        .documento-pendiente { border-left: 4px solid #f57c00; }
        .documento-subido-estado { border-left: 4px solid #2e7d32; }
        .documento-aprobado { border-left: 4px solid #1a3a6b; }
        .documento-rechazado { border-left: 4px solid #c62828; }
        .badge-obligatorio { font-size: 11px; padding: 2px 8px; border-radius: 10px; background: #dc3545; color: white; }
        .info-documentos {
            background: #f8f9fa; padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; border-left: 4px solid #1a3a6b;
        }
        .info-documentos .badge-tipo { font-size: 13px; padding: 5px 14px; border-radius: 20px; }
        .header-documentos { text-align: center; padding: 20px 0 10px 0; }
        .header-documentos h3 { font-weight: 900; color: #1a3a6b; font-size: 24px; }
        .header-documentos p { color: #6c757d; font-size: 14px; }
        .estado-general { padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; font-weight: 600; }
        .estado-general.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .estado-general.warning { background: #fff3e0; color: #e65100; border: 1px solid #ffcc80; }
        .estado-general.info { background: #e3f2fd; color: #0d47a1; border: 1px solid #90caf9; }
        .estado-general.danger { background: #ffebee; color: #b71c1c; border: 1px solid #ef9a9a; }
        .debug-box {
            background: #1a1a2e;
            color: #00ff41;
            border: 2px solid #00ff41;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            font-size: 13px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 500px;
            overflow-y: auto;
        }
        .debug-box strong { color: #00ccff; }
        .debug-box .success { color: #00ff41; }
        .debug-box .error { color: #ff0044; }
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
    <div class="card" style="max-width: 900px; margin: 0 auto;">
        <div class="card-body p-4">

            <div class="header-documentos">
                <h3>📄 CARGA DE DOCUMENTACIÓN OBLIGATORIA</h3>
                <p>Suba los archivos escaneados o fotografías legibles para que la Comisión verifique su expediente.</p>
            </div>

            <div class="info-documentos">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <strong>Estudiante:</strong> 
                        <span><?php echo $postulante['nombres'] . ' ' . $postulante['apellido_paterno']; ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Grado:</strong> 
                        <span><?php echo $postulante['grado']; ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Tipo de Colegio:</strong> 
                        <span class="badge bg-info badge-tipo"><?php echo ucfirst($tipo_colegio); ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Estado:</strong> 
                        <span class="badge bg-<?php 
                            echo $estado == 'matriculado' ? 'success' : 
                                ($estado == 'documentos_pendientes' ? 'warning' : 
                                ($estado == 'documentos_revisados' ? 'info' : 'secondary')); 
                        ?> badge-tipo">
                            <?php echo str_replace('_', ' ', $estado); ?>
                        </span>
                    </div>
                </div>
            </div>

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
            <!-- CAJA DE DEPURACIÓN -->
            <!-- ========================================== -->
            <div class="debug-box">
                <strong>🔍 INFORMACIÓN DE DEPURACIÓN:</strong>
                <?php echo $debug_info; ?>
            </div>

            <div class="estado-general <?php echo $estado_color; ?>">
                <?php echo $estado_mensaje; ?>
                <?php if ($todos_subidos && !$todos_aprobados): ?>
                    <br><small>Los documentos están en revisión por el administrador.</small>
                <?php endif; ?>
                <?php if ($todos_aprobados): ?>
                    <br><small>✅ ¡Todos los documentos han sido aprobados! Puedes continuar con el proceso.</small>
                <?php endif; ?>
            </div>

            <div class="mb-4">
                <div class="d-flex justify-content-between">
                    <span>Progreso de carga</span>
                    <span><?php echo $docs_subidos; ?> / <?php echo $total_docs; ?> documentos subidos</span>
                </div>
                <div class="progress mt-1" style="height: 20px;">
                    <div class="progress-bar bg-<?php echo $todos_subidos ? 'success' : 'warning'; ?>" 
                         style="width: <?php echo ($total_docs > 0) ? ($docs_subidos / $total_docs * 100) : 0; ?>%;">
                        <?php echo ($total_docs > 0) ? round($docs_subidos / $total_docs * 100) : 0; ?>%
                    </div>
                </div>
            </div>

            <?php if (empty($documentos)): ?>
                <div class="alert alert-warning text-center">
                    <i class="bi bi-exclamation-triangle"></i> No hay documentos requeridos para esta configuración.
                    <br><small class="text-muted">Tipo de colegio: <?php echo ucfirst($tipo_colegio); ?></small>
                    <br><small class="text-muted">Contacta con el administrador para configurar los documentos.</small>
                </div>
            <?php else: ?>
                <?php foreach ($documentos as $doc): 
                    $esta_subido = $doc['documento_subido_id'] ? true : false;
                    $esta_aprobado = $doc['estado'] == 'aprobado';
                    $esta_rechazado = $doc['estado'] == 'rechazado';
                    $clase_borde = 'documento-pendiente';
                    if ($esta_aprobado) $clase_borde = 'documento-aprobado';
                    elseif ($esta_rechazado) $clase_borde = 'documento-rechazado';
                    elseif ($esta_subido) $clase_borde = 'documento-subido-estado';
                ?>
                    <div class="card-documento <?php echo $clase_borde; ?>">
                        <div class="documento-header">
                            <h5>
                                <?php echo $doc['nombre_documento']; ?>
                                <?php if ($doc['obligatorio']): ?>
                                    <span class="badge-obligatorio">*</span>
                                <?php endif; ?>
                            </h5>
                            <span class="badge-estado bg-<?php 
                                echo $esta_aprobado ? 'success' : 
                                    ($esta_rechazado ? 'danger' : 
                                    ($esta_subido ? 'warning' : 'secondary')); 
                            ?> text-white">
                                <?php echo $esta_aprobado ? '✅ Completado' : 
                                    ($esta_rechazado ? '❌ Rechazado' : 
                                    ($esta_subido ? '⏳ En revisión' : '⚠️ Pendiente')); ?>
                            </span>
                        </div>

                        <?php if ($esta_subido && $doc['ruta']): ?>
                            <div class="documento-subido">
                                <span class="nombre-archivo">
                                    <i class="bi bi-file-earmark-check text-success"></i> 
                                    <?php echo $doc['nombre_archivo']; ?>
                                </span>
                                <div>
                                    <a href="<?php echo $doc['ruta']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i> Ver
                                    </a>
                                    <a href="documentos.php?id=<?php echo $postulante_id; ?>&eliminar=<?php echo $doc['documento_subido_id']; ?>" 
                                       class="btn btn-sm btn-outline-danger" 
                                       onclick="return confirm('¿Eliminar este documento?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </div>
                            </div>
                            <?php if ($esta_rechazado): ?>
                                <div class="mt-2 text-danger small">
                                    <i class="bi bi-exclamation-triangle"></i> Documento rechazado. Por favor, vuelve a subirlo.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if (!$esta_aprobado): ?>
                            <div class="acciones-documento mt-3">
                                <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 flex-wrap">
                                    <input type="hidden" name="doc_requerido_id" value="<?php echo $doc['id']; ?>">
                                    <input type="file" name="documento" class="form-control" style="max-width: 250px;" 
                                           accept=".pdf,.jpg,.jpeg,.png" required>
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-upload"></i> Cargar archivo
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" 
                                            onclick="alert('Función de cámara próximamente disponible')">
                                        <i class="bi bi-camera"></i> Tomar Foto
                                    </button>
                                </form>
                                <small class="text-muted d-block mt-1">Formatos: PDF, JPG, PNG (máx. 5MB)</small>
                            </div>
                        <?php else: ?>
                            <div class="mt-2 text-success">
                                <i class="bi bi-check-circle"></i> Documento aprobado por el administrador.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="d-flex justify-content-between mt-4">
                <a href="detalle.php?id=<?php echo $postulante_id; ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Volver
                </a>
                <?php if ($todos_aprobados): ?>
                    <a href="pago.php?id=<?php echo $postulante_id; ?>" class="btn btn-success">
                        Continuar con el pago <i class="bi bi-arrow-right"></i>
                    </a>
                <?php else: ?>
                    <button class="btn btn-secondary" disabled>
                        <?php echo $todos_subidos ? '⏳ Esperando aprobación del administrador' : '📄 Sube todos los documentos para continuar'; ?>
                    </button>
                <?php endif; ?>
            </div>

            <?php if ($todos_subidos && !$todos_aprobados): ?>
                <div class="mt-3 text-center text-muted small">
                    <i class="bi bi-info-circle"></i> Todos los documentos han sido subidos. El administrador los revisará y aprobará para que puedas continuar.
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('form[enctype="multipart/form-data"]').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        const fileInput = this.querySelector('input[type="file"]');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Por favor selecciona un archivo');
            e.preventDefault();
            return false;
        }
        
        const maxSize = 5 * 1024 * 1024;
        if (file.size > maxSize) {
            alert('El archivo es demasiado grande. Máximo 5MB');
            e.preventDefault();
            return false;
        }
        
        const extensiones = ['pdf', 'jpg', 'jpeg', 'png'];
        const ext = file.name.split('.').pop().toLowerCase();
        if (!extensiones.includes(ext)) {
            alert('Formato no permitido. Usa PDF, JPG o PNG.');
            e.preventDefault();
            return false;
        }
        
        return true;
    });
});
</script>
</body>
</html>