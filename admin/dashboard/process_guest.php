<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['adminuser'])) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

function read_env_file($file_path) {
    if (!file_exists($file_path)) return [];
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];
    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line);
        if (count($line_parts) == 2) {
            $env_data[trim($line_parts[0])] = trim($line_parts[1]);
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
    echo json_encode(['success' => false, 'message' => 'Error de base de datos']);
    exit;
}

$name = $_POST['guest_name'] ?? '';
$ticketId = $_POST['guest_ticket'] ?? 0;

if (empty($name) || empty($ticketId)) {
    echo json_encode(['success' => false, 'message' => 'Faltan datos']);
    exit;
}

// 1. Crear Usuario Invitado
$userid = rand(1000000000, 9999999999);
$email = 'guest_' . $userid . '@local.com'; // Email dummy
$password = password_hash('guest123', PASSWORD_DEFAULT);
$regDate = date('Y-m-d H:i:s');

// Separar nombre para firstname/lastname
$parts = explode(' ', trim($name), 2);
$fname = $parts[0];
$lname = isset($parts[1]) ? $parts[1] . ' (Visita)' : '(Visita)';

// Obtener valores del formulario
$birthdate = $_POST['guest_birthdate'] ?? date('Y-m-d');
$gender = $_POST['guest_gender'] ?? 'Male';
$phone = $_POST['guest_phone'] ?? '';

$stmt = $conn->prepare("INSERT INTO users (userid, firstname, lastname, email, password, registration_date, confirmed, birthdate, gender, phone) VALUES (?, ?, ?, ?, ?, ?, 'Yes', ?, ?, ?)");
$stmt->bind_param("issssssss", $userid, $fname, $lname, $email, $password, $regDate, $birthdate, $gender, $phone);
if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => 'Error al crear usuario invitado']);
    exit;
}
$stmt->close();

// 2. Obtener datos del Ticket
$stmt = $conn->prepare("SELECT name, price, expire_days, occasions, entry_people FROM tickets WHERE id = ?");
$stmt->bind_param("i", $ticketId);
$stmt->execute();
$stmt->bind_result($tName, $tPrice, $tExpireDays, $tOccasions, $tEntryPeople);
$stmt->fetch();
$stmt->close();

// 3. Registrar Ingreso (Revenue)
$date = date('Y-m-d');
$sqlRev = "SELECT id FROM revenu_stats WHERE date = ?";
$stmtRev = $conn->prepare($sqlRev);
$stmtRev->bind_param("s", $date);
$stmtRev->execute();
$resRev = $stmtRev->get_result();

if ($resRev->num_rows > 0) {
    $rowRev = $resRev->fetch_assoc();
    // Asumimos pago en Efectivo (Cash) para visitas rápidas por defecto
    $updateRev = $conn->prepare("UPDATE revenu_stats SET cash = cash + ? WHERE id = ?");
    $updateRev->bind_param("di", $tPrice, $rowRev['id']);
    $updateRev->execute();
    $updateRev->close();
} else {
    $insertRev = $conn->prepare("INSERT INTO revenu_stats (date, bank_card, cash) VALUES (?, 0, ?)");
    $insertRev->bind_param("sd", $date, $tPrice);
    $insertRev->execute();
    $insertRev->close();
}
$stmtRev->close();

// 4. Registrar Factura (Invoice)
$invoiceNumber = bin2hex(random_bytes(8));
$fullName = $fname . ' ' . $lname;
$desc = "Visita Rápida - " . $tName;
$route = "guest.pdf"; // No generamos PDF real para ahorrar tiempo, o usa generator si es necesario
$stmtInv = $conn->prepare("INSERT INTO invoices (userid, name, price, type, payment_method, status, route, created_at, description) VALUES (?, ?, ?, 'Ticket', 'Cash', 'paid', ?, NOW(), ?)");
$stmtInv->bind_param("issss", $userid, $fullName, $tPrice, $route, $desc);
$stmtInv->execute();
$stmtInv->close();

// 5. Asignar Ticket al Usuario
$buyDate = date('Y-m-d H:i:s');
$expireDateObj = new DateTime();
if ($tExpireDays == 1) {
    $expireDateObj->add(new DateInterval('PT12H')); // 12 horas para pase diario
} else if ($tExpireDays > 1) {
    $expireDateObj->add(new DateInterval('P' . $tExpireDays . 'D'));
} else {
    // Ilimitado o error, poner fecha lejana
    $expireDateObj = new DateTime('9999-12-31');
}
$expireDateDb = $expireDateObj->format('Y-m-d H:i:s');

$stmtTick = $conn->prepare("INSERT INTO current_tickets (userid, ticketname, buydate, expiredate, opportunities) VALUES (?, ?, ?, ?, ?)");
$stmtTick->bind_param("isssi", $userid, $tName, $buyDate, $expireDateDb, $tOccasions);
$stmtTick->execute();
$stmtTick->close();

// 6. Check-In Automático (Entrada)
$entryPeople = $tEntryPeople > 0 ? $tEntryPeople : 1;
$stmtLog = $conn->prepare("INSERT INTO temp_loggeduser (name, userid, login_date, lockerid, people_count) VALUES (?, ?, ?, 0, ?)");
$stmtLog->bind_param("sssi", $fullName, $userid, $buyDate, $entryPeople);

if ($stmtLog->execute()) {
    echo json_encode(['success' => true, 'message' => 'Visita registrada y acceso concedido: ' . $fullName]);
} else {
    echo json_encode(['success' => false, 'message' => 'Error al registrar entrada']);
}
$stmtLog->close();
$conn->close();
?>