<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$userid = $_SESSION['adminuser'];

function read_env_file($file_path)
{
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line);
        if (count($line_parts) == 2) {
            $key = trim($line_parts[0]);
            $value = trim($line_parts[1]);
            $env_data[$key] = $value;
        }
    }

    return $env_data;
}

$env_data = read_env_file('../../../.env');

// Establecer zona horaria desde .env
$timezone = $env_data['TIMEZONE'] ?? 'America/Mexico_City';
if (!in_array($timezone, timezone_identifiers_list())) {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Obtener todos los empleados
$sql = "SELECT userid, Firstname, Lastname FROM workers";
$result = $conn->query($sql);

require __DIR__ . '/../../../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Alignment\LabelAlignmentCenter;
use Endroid\QrCode\Label\Font\NotoSans;

// Crear directorio si no existe
$qrDir = __DIR__ . "/../../../assets/img/worker_qr";
if (!is_dir($qrDir)) {
    mkdir($qrDir, 0755, true);
}

$generated_count = 0;
$message = "";

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $worker_id = $row['userid'];
        $firstname = $row['Firstname'];
        $lastname = $row['Lastname'];
        $filename = $qrDir . "/{$worker_id}.png";

        // Generar siempre para actualizar el diseño con el ID
            try {
                $logoPath = __DIR__ . '/../../../assets/img/logo.png';
                
                $result_qr = Builder::create()
                    ->writer(new PngWriter())
                    ->data($worker_id)
                    ->encoding(new Encoding('UTF-8'))
                    ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
                    ->size(300)
                    ->margin(5);
                
                // Solo agregar logo si existe
                if (file_exists($logoPath)) {
                    $result_qr->logoPath($logoPath)
                        ->logoResizeToWidth(100);
                }
                
                $buildResult = $result_qr->labelText($firstname . ' ' . $lastname . ' (ID: ' . $worker_id . ')')
                    ->labelFont(new NotoSans(20))
                    ->labelAlignment(new LabelAlignmentCenter())
                    ->validateResult(false)
                    ->build();

                $buildResult->saveToFile($filename);
                $generated_count++;
            } catch (Exception $e) {
                $message .= "Error generando QR para {$firstname}: " . $e->getMessage() . "<br>";
            }
    }
}

$conn->close();

// Redirigir de vuelta a la página de empleados con mensaje
$_SESSION['qr_message'] = "Se generaron $generated_count códigos QR para los empleados." . ($message ? "<br>" . $message : "");
header("Location: index.php");
exit;
