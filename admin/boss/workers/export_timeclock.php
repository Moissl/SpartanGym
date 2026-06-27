<?php
session_start();

date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

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

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_asistencia_' . $start_date . '_al_' . $end_date . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID Empleado', 'Nombre', 'Apellido', 'Entrada', 'Salida', 'Horas Trabajadas']);

$sql = "SELECT wt.*, w.Firstname, w.Lastname 
        FROM worker_timeclock wt 
        JOIN workers w ON wt.worker_id = w.userid 
        WHERE DATE(wt.checkin_time) BETWEEN ? AND ? 
        ORDER BY wt.checkin_time DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $start_date, $end_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $checkin = new DateTime($row['checkin_time']);
    $checkout = $row['checkout_time'] ? new DateTime($row['checkout_time']) : null;
    $hours_worked = $checkout ? $checkin->diff($checkout)->format('%H:%I:%S') : 'En turno';
    
    fputcsv($output, [
        $row['worker_id'],
        $row['Firstname'],
        $row['Lastname'],
        $row['checkin_time'],
        $row['checkout_time'] ?? '-',
        $hours_worked
    ]);
}

fclose($output);
$conn->close();
?>