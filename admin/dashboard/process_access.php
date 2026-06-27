<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('Unauthorized');
}

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : '';

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
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

if ($action === 'log_access') {
    $userid = isset($_POST['userid']) ? intval($_POST['userid']) : 0;
    $admin_id = $_SESSION['adminuser'];

    if ($userid <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de usuario inválido']);
        exit;
    }

    // Verificar que el usuario existe
    $sql = "SELECT firstname, lastname FROM users WHERE userid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Usuario no encontrado']);
        $stmt->close();
        exit;
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Registrar acceso en la tabla de logs
    $access_time = date('Y-m-d H:i:s');
    $sql = "INSERT INTO logs (userid, action, details, color) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    
    $action_text = "Acceso registrado (QR)";
    $details = "Acceso registrado por admin ID: " . $admin_id . " para usuario: " . $user['firstname'] . " " . $user['lastname'];
    $color = "info";
    
    $stmt->bind_param("isss", $userid, $action_text, $details, $color);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Acceso registrado correctamente']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error al registrar acceso']);
    }
    
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

$conn->close();
