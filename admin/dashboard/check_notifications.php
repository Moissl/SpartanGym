<?php
session_start();
header('Content-Type: application/json');

error_reporting(0); // Silenciar errores para no romper el JSON
session_write_close(); // IMPORTANTE: Cerrar sesión YA para no bloquear navegación (logout, etc)

if (!isset($_SESSION['adminuser'])) {
    echo json_encode([]);
    exit;
}

// Soporte de modo "peek": si se solicita ?peek=1 devolvemos notificaciones
// sin marcarlas como vistas. Útil para clientes (dashboard) que solo quieren
// mostrar notificaciones sin consumirlas (la pantalla pública seguirá marcando).
$peek = false;
if (isset($_GET['peek']) && $_GET['peek'] == '1') $peek = true;
if (isset($_POST['peek']) && $_POST['peek'] == '1') $peek = true;

// Soporte de long-polling: si se solicita ?wait=1 la petición esperará hasta
// `LONG_POLL_MAX_SECONDS` buscando nuevas notificaciones antes de devolver.
$wait = false;
if (isset($_GET['wait']) && $_GET['wait'] == '1') $wait = true;
if (isset($_POST['wait']) && $_POST['wait'] == '1') $wait = true;

define('LONG_POLL_MAX_SECONDS', 15);


function read_env_file($file_path)
{
    if (!file_exists($file_path)) return [];
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

// OPTIMIZACIÓN CRÍTICA: Usar timeout en la conexión
$conn = mysqli_init();
if (!$conn) { 
    echo json_encode([]);
    exit; 
}

// Timeout de 2 segundos. Si la DB no responde rápido, abortar para no colgar el servidor.
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);

if (!@$conn->real_connect($db_host, $db_username, $db_password, $db_name)) {
    // Si falla, devolver vacío rápido y liberar el proceso
    echo json_encode([]);
    exit;
}

// Sincronizar zona horaria de MySQL con PHP para obtener la hora correcta
$now = new DateTime('now', new DateTimeZone($timezone));
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;
$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
$conn->query("SET time_zone = '$offset';");

// Limpiar notificaciones de "Usuario no encontrado" o códigos inválidos para hacer la aplicación transparente
$conn->query("DELETE FROM access_notifications WHERE message IN ('User not found', 'Error desconocido') OR user_name LIKE 'ID: %'");

// OPTIMIZACIÓN 2: Solo traer lo necesario. Evita el SELECT *
// Helper para obtener notificaciones no vistas
function fetch_unseen($conn, $peek, $limit = 10) {
    if ($peek) {
        $sql = "SELECT id, type, message, user_name, userid, created_at FROM access_notifications ORDER BY id DESC LIMIT ?";
    } else {
        $sql = "SELECT id, type, message, user_name, userid, created_at FROM access_notifications WHERE seen = 0 ORDER BY id ASC LIMIT ?";
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $row['created_at_formatted'] = date('H:i:s', strtotime($row['created_at']));
        $out[] = $row;
    }
    $stmt->close();
    
    // Si es para dashboard, lo invertimos para mostrar los más recientes en orden cronológico correcto
    if ($peek) {
        $out = array_reverse($out);
    }
    
    return $out;
}

$notifications = [];

if ($wait) {
    // Permitir ejecución durante el long-poll
    @set_time_limit(LONG_POLL_MAX_SECONDS + 5);

    $elapsed = 0;
    while ($elapsed < LONG_POLL_MAX_SECONDS) {
        $notifications = fetch_unseen($conn, $peek, 10);
        if (!empty($notifications)) break;
        sleep(1);
        $elapsed++;
    }
    // Si no encontramos nada, devolver vacío
} else {
    $limit = $peek ? 20 : 10;
    $notifications = fetch_unseen($conn, $peek, $limit);
}

$ids_to_update = [];
if (!empty($notifications)) {
    foreach ($notifications as $r) $ids_to_update[] = $r['id'];

    if (!empty($ids_to_update) && !$peek) {
        $ids_string = implode(',', array_map('intval', $ids_to_update));
        $conn->query("UPDATE access_notifications SET seen = 1 WHERE id IN ($ids_string)");
    }
}

$conn->close();
echo json_encode([
    'success' => true,
    'notifications' => $notifications,
    'count' => count($notifications)
]);

?>