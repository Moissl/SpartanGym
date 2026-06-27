<?php
session_start();

date_default_timezone_set('America/Mexico_City');

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

$env_data = read_env_file('../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

$create_table_sql = "CREATE TABLE IF NOT EXISTS worker_timeclock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id BIGINT NOT NULL,
    checkin_time DATETIME NOT NULL,
    checkout_time DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_worker_date (worker_id, checkin_time)
)";

if (!$conn->query($create_table_sql)) {
    echo json_encode(['success' => false, 'message' => 'Error al crear la tabla: ' . $conn->error]);
    exit;
}

// Asegurar que la columna sea BIGINT si la tabla ya existía
$conn->query("ALTER TABLE worker_timeclock MODIFY worker_id BIGINT NOT NULL");

if ($action === 'toggle_timeclock') {
    $worker_id = isset($_POST['worker_id']) ? intval($_POST['worker_id']) : 0;

    if ($worker_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'ID de empleado inválido']);
        exit;
    }

    // Verificar que el empleado existe
    $sql = "SELECT userid, Firstname, Lastname FROM workers WHERE userid = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $worker_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Empleado no encontrado']);
        $stmt->close();
        exit;
    }

    $worker = $result->fetch_assoc();
    $worker_id = intval($worker['userid']); // Asegurar que es INT
    $stmt->close();

    // Verificar si existe un registro sin checkout para hoy
    $today = date('Y-m-d');
    $sql = "SELECT id, checkin_time FROM worker_timeclock 
            WHERE worker_id = ? AND DATE(checkin_time) = ? AND checkout_time IS NULL 
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("is", $worker_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        // Registrar salida
        $row = $result->fetch_assoc();
        $timeclock_id = intval($row['id']);
        $checkout_time = date('Y-m-d H:i:s');
        
        $sql = "UPDATE worker_timeclock SET checkout_time = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $checkout_time, $timeclock_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Salida registrada para ' . $worker['Firstname']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al registrar salida']);
        }
    } else {
        // Registrar entrada
        $checkin_time = date('Y-m-d H:i:s');
        
        $sql = "INSERT INTO worker_timeclock (worker_id, checkin_time) VALUES (?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("is", $worker_id, $checkin_time);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Entrada registrada para ' . $worker['Firstname']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error al registrar entrada']);
        }
    }
    
    $stmt->close();
} else {
    echo json_encode(['success' => false, 'message' => 'Acción no válida']);
}

$conn->close();
