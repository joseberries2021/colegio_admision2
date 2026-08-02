<?php
echo "<h2>Diagnóstico de Subida de Archivos</h2>";

// 1. Verificar carpeta
$carpeta = 'uploads/documentos/';
echo "<h3>1. Verificando carpeta: $carpeta</h3>";
echo "¿Existe? " . (file_exists($carpeta) ? '✅ Sí' : '❌ No') . "<br>";

if (!file_exists($carpeta)) {
    echo "Creando carpeta...<br>";
    if (mkdir($carpeta, 0777, true)) {
        echo "✅ Carpeta creada<br>";
    } else {
        echo "❌ Error al crear<br>";
    }
}

echo "Permisos: " . substr(sprintf('%o', fileperms($carpeta)), -4) . "<br>";
echo "¿Escribible? " . (is_writable($carpeta) ? '✅ Sí' : '❌ No') . "<br>";

// 2. Verificar configuración de PHP
echo "<h3>2. Configuración de PHP</h3>";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "<br>";
echo "post_max_size: " . ini_get('post_max_size') . "<br>";
echo "max_file_uploads: " . ini_get('max_file_uploads') . "<br>";

// 3. Verificar extensión de archivos
echo "<h3>3. Extensiones permitidas</h3>";
echo "file_uploads: " . (ini_get('file_uploads') ? '✅ Activado' : '❌ Desactivado') . "<br>";

// 4. Probar escritura
echo "<h3>4. Prueba de escritura</h3>";
$test_file = $carpeta . 'test.txt';
if (file_put_contents($test_file, 'Test de escritura')) {
    echo "✅ Escritura exitosa<br>";
    unlink($test_file);
    echo "✅ Archivo de prueba eliminado<br>";
} else {
    echo "❌ No se puede escribir en la carpeta<br>";
}

// 5. Información del servidor
echo "<h3>5. Información del servidor</h3>";
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";
echo "SCRIPT_FILENAME: " . $_SERVER['SCRIPT_FILENAME'] . "<br>";
echo "Ruta absoluta del proyecto: " . realpath('.') . "<br>";
?>