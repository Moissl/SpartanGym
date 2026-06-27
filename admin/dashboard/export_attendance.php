<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    // Si no hay sesión de administrador, devolver 403 en lugar de redirigir (mejor para endpoints de descarga)
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado: necesita iniciar sesión como administrador para descargar reportes.";
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

$env_data = read_env_file('../../.env');

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

// Asegurar que las columnas entry_time y exit_time existen (para compatibilidad retroactiva)
$check_entry = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'entry_time'");
if ($check_entry && $check_entry->num_rows == 0) {
    $conn->query("ALTER TABLE workout_stats ADD COLUMN entry_time DATETIME DEFAULT NULL");
}
$check_exit = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'exit_time'");
if ($check_exit && $check_exit->num_rows == 0) {
    $conn->query("ALTER TABLE workout_stats ADD COLUMN exit_time DATETIME DEFAULT NULL");
}

$report_date = $_GET['date'] ?? date('Y-m-d');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="asistencia_' . $report_date . '.csv"');

// Asegurar conexión en UTF-8
$conn->set_charset('utf8mb4');

$output = fopen('php://output', 'w');
// Escribir BOM para compatibilidad con Excel y asegurar UTF-8
fwrite($output, "\xEF\xBB\xBF");

// Cabecera: Nombre, Correo, Hora entrada, Hora salida, Tiempo en Gimnasio
fputcsv($output, ['Nombre', 'Correo', 'Hora entrada', 'Hora salida', 'Tiempo en Gimnasio']);

$sql = "SELECT u.firstname, u.lastname, u.email, ws.duration, ws.entry_time, ws.exit_time 
        FROM workout_stats ws 
        JOIN users u ON ws.userid = u.userid 
        WHERE ws.workout_date = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $report_date);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    // Normalizar encoding por seguridad
    $firstname = mb_convert_encoding($row['firstname'], 'UTF-8', 'UTF-8');
    $lastname = mb_convert_encoding($row['lastname'], 'UTF-8', 'UTF-8');
    $email = mb_convert_encoding($row['email'], 'UTF-8', 'UTF-8');

    $entry_time = $row['entry_time'] ?? null;
    $exit_time = $row['exit_time'] ?? null;

    $entry_str = $entry_time ? (new DateTime($entry_time))->format('Y-m-d H:i:s') : '';
    $exit_str = $exit_time ? (new DateTime($exit_time))->format('Y-m-d H:i:s') : '';

    // Calcular duración usando entry/exit si están disponibles, si no usar duration (minutos)
    if ($entry_time && $exit_time) {
        $dtEntry = new DateTime($entry_time);
        $dtExit = new DateTime($exit_time);
        $diff = $dtEntry->diff($dtExit);
        $hours = $diff->h + ($diff->d * 24);
        $mins = $diff->i;
        $time_formatted = sprintf("%d Horas %d Minutos", $hours, $mins);
    } else {
        $minutes = intval($row['duration'] ?? 0);
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        $time_formatted = sprintf("%d Horas %d Minutos", $hours, $mins);
    }

    fputcsv($output, [trim($firstname . ' ' . $lastname), $email, $entry_str, $exit_str, $time_formatted]);
}

fclose($output);
$conn->close();
?>