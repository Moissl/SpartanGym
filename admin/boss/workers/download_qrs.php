<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$qr_dir = __DIR__ . '/../../../assets/img/worker_qr';

if (!is_dir($qr_dir)) {
    die("Directorio de QRs no encontrado");
}

// Crear ZIP
$zip_file = tempnam(sys_get_temp_dir(), 'qr_') . '.zip';
$zip = new ZipArchive();

if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    die("Error al crear el ZIP");
}

// Agregar todos los QRs al ZIP
$files = scandir($qr_dir);
foreach ($files as $file) {
    if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'png') {
        $file_path = $qr_dir . '/' . $file;
        $zip->addFile($file_path, 'QRs/' . $file);
    }
}

$zip->close();

// Enviar al navegador
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="QRs_Empleados.zip"');
header('Content-Length: ' . filesize($zip_file));
readfile($zip_file);

// Eliminar archivo temporal
unlink($zip_file);
exit;
