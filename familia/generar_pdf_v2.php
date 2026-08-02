<?php
session_start();

// Verificar que el registro esté completo
if (!isset($_SESSION['registro_completo']) || !$_SESSION['registro_completo']) {
    die('No hay datos de registro para generar el PDF');
}

require_once '../config/database.php';

// Incluir TCPDF
require_once '../includes/tcpdf/tcpdf.php';

// ============================================
// EXTENDER TCPDF PARA PIE DE PÁGINA PERSONALIZADO
// ============================================
class MYPDF extends TCPDF {
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Fecha de generación: ' . date('d/m/Y H:i') . ' | Este documento es un comprobante de inscripción oficial.', 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// ============================================
// OBTENER DATOS DE SESIÓN
// ============================================
$p = $_SESSION['postulante_guardado'] ?? [];
$ap = $p['apoderado'] ?? [];
$post = $p['postulante'] ?? [];
$credenciales = $_SESSION['credenciales'] ?? [];
$usuario = $credenciales['usuario'] ?? 'FAM-0001';

// Obtener datos adicionales
$sede_info = fetchOne("SELECT * FROM sedes WHERE id = ?", [$p['id_sede'] ?? 0]);
$nivel_info = fetchOne("SELECT * FROM niveles WHERE id = ?", [$p['id_nivel'] ?? 0]);
$grado_info = fetchOne("SELECT * FROM grados WHERE id = ?", [$p['id_grado'] ?? 0]);

// ============================================
// CREAR NUEVO PDF
// ============================================
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Configurar documento
$pdf->SetCreator('Colegio Juventud Científica');
$pdf->SetAuthor('Sistema de Admisión');
$pdf->SetTitle('Expediente de Admisión - ' . $usuario);
$pdf->SetSubject('Expediente de Admisión');
$pdf->SetKeywords('Admisión, Expediente, Colegio, Juventud Científica');

// Configurar márgenes
$pdf->SetMargins(15, 20, 15);
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 25);

// Agregar página
$pdf->AddPage();

// ============================================
// FUNCIÓN PARA AGREGAR SECCIÓN
// ============================================
function addSection($pdf, $title, $data) {
    // Título de sección
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(26, 58, 107);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(0, 8, ' ' . $title, 0, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    
    // Datos de la sección
    foreach ($data as $label => $value) {
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 6, $label . ':', 0, 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 6, $value ?: 'No especificado', 0, 1, 'L');
    }
    $pdf->Ln(3);
}

// ============================================
// CONTENIDO DEL PDF
// ============================================

// --- ENCABEZADO ---
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'I.E. JUVENTUD CIENTÍFICA', 0, 1, 'C');
$pdf->SetFont('helvetica', '', 10);
$pdf->Cell(0, 6, '"Ciencia, Disciplina y Valores de Alto Rendimiento"', 0, 1, 'C');
$pdf->Cell(0, 6, 'Autorización Ministerial N° 0451-2015-MINEDU', 0, 1, 'C');
$pdf->Ln(5);

$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'EXPEDIENTE COMPLETO DEL POSTULANTE', 0, 1, 'C');
$pdf->SetFont('helvetica', 'B', 12);
$pdf->Cell(0, 8, 'EXPEDIENTE: ' . $usuario, 0, 1, 'C');
$pdf->Ln(8);

// --- SECCIÓN 1: INFORMACIÓN GENERAL ---
addSection($pdf, '1. INFORMACIÓN GENERAL DEL PROCESO', [
    'Código de Familia' => $usuario,
    'Año Escolar' => '2027',
    'Estado' => 'Pre-Inscrito (Pendiente de Validación)',
    'Fecha de Registro' => date('d/m/Y')
]);

// --- SECCIÓN 2: DATOS DE POSTULACIÓN ---
addSection($pdf, '2. DATOS DE LA POSTULACIÓN', [
    'Nivel Educativo' => $nivel_info['nombre'] ?? 'No especificado',
    'Sede' => $sede_info['nombre'] ?? 'No especificado',
    'Grado de Ingreso' => $grado_info['nombre'] ?? 'No especificado',
    'Distrito' => $sede_info['distrito'] ?? 'No especificado',
    'Tipo de Postulante' => 'NUEVO'
]);

// --- SECCIÓN 3: DATOS DEL POSTULANTE ---
addSection($pdf, '3. DATOS PERSONALES DEL POSTULANTE', [
    'Nombres Completos' => strtoupper($post['nombres'] ?? ''),
    'Apellido Paterno' => strtoupper($post['apellido_paterno'] ?? ''),
    'Apellido Materno' => strtoupper($post['apellido_materno'] ?? ''),
    'Documento' => ($post['tipo_documento'] ?? 'DNI') . ' - ' . ($post['numero_documento'] ?? ''),
    'Género' => $post['genero'] ?? '',
    'Fecha Nacimiento' => $post['fecha_nacimiento'] ?? '',
    'Tipo de Colegio' => ucfirst($post['tipo_colegio'] ?? 'No especificado'),
    'Colegio de Procedencia' => $post['colegio_procedencia'] ?? 'No especificado'
]);

// --- SECCIÓN 4: LUGAR DE NACIMIENTO Y DOMICILIO ---
addSection($pdf, '4. LUGAR DE NACIMIENTO Y DOMICILIO', [
    'País de Nacimiento' => 'Perú',
    'Departamento' => 'Lima',
    'Provincia' => 'Lima',
    'Distrito de Residencia' => $ap['distrito'] ?? 'No especificado',
    'Correo de Contacto' => $ap['email'] ?? 'No especificado'
]);

// --- SECCIÓN 5: INFORMACIÓN COMPLEMENTARIA ---
addSection($pdf, '5. INFORMACIÓN COMPLEMENTARIA Y SALUD', [
    'Cuenta con Seguro' => ($post['seguro'] ?? 0) ? 'Sí' : 'No',
    'Compañía Aseguradora' => $post['seguro_compania'] ?? 'No especifica',
    'Diagnóstico Médico' => ($post['diagnostico'] ?? 0) ? 'Sí' : 'No',
    'Detalle de Diagnóstico' => $post['diagnostico_descripcion'] ?? 'No aplica',
    'Religión' => $post['religion'] ?? 'No especifica',
    'Bautizado' => ($post['bautizado'] ?? 0) ? 'Sí' : 'No',
    'Primera Comunión' => ($post['primera_comunion'] ?? 0) ? 'Sí' : 'No'
]);

// --- SECCIÓN 6: DATOS DEL APODERADO ---
addSection($pdf, '6. DATOS DEL APODERADO LEGAL', [
    'Nombres' => strtoupper($ap['nombres'] ?? ''),
    'Apellido Paterno' => strtoupper($ap['apellido_paterno'] ?? ''),
    'Apellido Materno' => strtoupper($ap['apellido_materno'] ?? ''),
    'Documento' => ($ap['tipo_documento'] ?? 'DNI') . ': ' . ($ap['numero_documento'] ?? ''),
    'Parentesco' => $ap['relacion'] ?? 'No especificado',
    'WhatsApp' => $ap['whatsapp'] ?? '',
    'Correo' => $ap['email'] ?? '',
    'Departamento' => strtoupper($ap['departamento'] ?? ''),
    'Provincia' => strtoupper($ap['provincia'] ?? ''),
    'Distrito' => strtoupper($ap['distrito'] ?? '')
]);

// --- SECCIÓN 7: ESTADO DE DOCUMENTOS ---
addSection($pdf, '7. ESTADO DE REQUISITOS Y CARGA DE DOCUMENTOS', [
    'DNI del Postulante' => 'Pendiente de carga',
    'DNI del Apoderado' => 'Pendiente de carga',
    'Recibo de Luz/Agua' => 'Pendiente de carga',
    'Fotografía' => 'Pendiente de carga',
    'Constancia de No Adeudo' => 'Pendiente de carga',
    'Carta de Buena Conducta' => 'Pendiente de carga'
]);

// --- SECCIÓN 8: ESTADO DEL PAGO ---
addSection($pdf, '8. ESTADO DEL PAGO Y EVALUACIONES', [
    'Estado del Pago' => 'Pendiente de Pago',
    'Código de Pago' => 'No registrado',
    'Cita Psicopedagógica' => 'Pendiente de Reserva',
    'Estado Final' => 'PRE-INSCRITO (PENDIENTE DE VALIDACIÓN)'
]);

// --- DECLARACIÓN JURADA ---
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetFillColor(26, 58, 107);
$pdf->SetTextColor(255, 255, 255);
$pdf->Cell(0, 8, ' 9. DECLARACIÓN JURADA DE VERACIDAD', 0, 1, 'L', true);
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', '', 10);
$pdf->MultiCell(0, 6, 'El apoderado firmante declara bajo juramento que todos los datos consignados en esta ficha oficial de admisión y expediente completo son rigurosamente verdaderos y se ajustan a la realidad, asumiendo plena responsabilidad administrativa, civil y legal en caso de falsedad u omisión, conforme a las directivas del reglamento interno de admisión de la Institución Educativa Colegio Juventud Científica.', 0, 'J', false, 1);
$pdf->Ln(5);

// --- FIRMAS ---
$pdf->Cell(80, 6, '_________________________________', 0, 0, 'C');
$pdf->Cell(30, 6, '', 0, 0, 'C');
$pdf->Cell(80, 6, '_________________________________', 0, 1, 'C');

$pdf->SetFont('helvetica', 'B', 10);
$pdf->Cell(80, 6, 'Firma del Apoderado Legal', 0, 0, 'C');
$pdf->Cell(30, 6, '', 0, 0, 'C');
$pdf->Cell(80, 6, 'Comisión de Admisión', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(80, 6, 'DNI: ' . ($ap['numero_documento'] ?? ''), 0, 0, 'C');
$pdf->Cell(30, 6, '', 0, 0, 'C');
$pdf->Cell(80, 6, 'Juventud Científica', 0, 1, 'C');

// ============================================
// SALIDA DEL PDF
// ============================================
$pdf->Output('Expediente_Admision_' . $usuario . '.pdf', 'D');
exit;
?>