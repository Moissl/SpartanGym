<?php
header('Content-Type: application/json');
// Incluir autoload para SwiftMailer
require_once __DIR__ . '/../../vendor/autoload.php';

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

$lang_code = $env_data['LANG_CODE'] ?? '';
$lang = $lang_code;

$langDir = __DIR__ . "/../../assets/lang/";
$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("El archivo de idioma no se encuentra: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'error' => 'Database connection failed']));
}

// Sincronizar zona horaria de MySQL con PHP
$now = new DateTime('now', new DateTimeZone($timezone));
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;
$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
$conn->query("SET time_zone = '$offset';");

// Crear tabla de notificaciones si no existe
$conn->query("CREATE TABLE IF NOT EXISTS access_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50),
    message TEXT,
    userid VARCHAR(50),
    user_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    seen TINYINT DEFAULT 0
)");

// Asegurar que temp_loggeduser tenga la columna people_count
$check_col = $conn->query("SHOW COLUMNS FROM temp_loggeduser LIKE 'people_count'");
if ($check_col->num_rows == 0) {
    $conn->query("ALTER TABLE temp_loggeduser ADD COLUMN people_count INT DEFAULT 1");
}

$qrCode = isset($_POST['qrcode']) ? $conn->real_escape_string(trim($_POST['qrcode'])) : '';

// Validación de seguridad: Verificar que el código tenga exactamente 10 dígitos.
if (!preg_match('/^\d{10}$/', $qrCode)) {
    die(json_encode(['success' => false, 'error' => 'Código inválido']));
}

// --- LÓGICA DE SALIDA (AUTO CHECK-OUT) ---
$checkStmt = $conn->prepare("SELECT login_date FROM temp_loggeduser WHERE userid = ?");
$checkStmt->bind_param("s", $qrCode);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult && $checkResult->num_rows > 0) {
    // El usuario ya está dentro -> Registrar SALIDA
    $rowLogged = $checkResult->fetch_assoc();
    $login_date = $rowLogged['login_date'];
    
    // Verificar si han pasado al menos 15 segundos desde la entrada
    $loginTime = new DateTime($login_date);
    $now = new DateTime();
    $diffSeconds = $now->getTimestamp() - $loginTime->getTimestamp();

    if ($diffSeconds < 15) {
        // Si es menos de 15 segundos, no registrar salida. Mostrar como entrada válida (Verde).
        $userStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE userid = ?");
        $userStmt->bind_param("s", $qrCode);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = ($userResult && $userResult->num_rows > 0) ? $userResult->fetch_assoc() : ['firstname' => 'Usuario', 'lastname' => ''];
        $userStmt->close();
        $checkStmt->close();
        $conn->close();
        echo json_encode(['success' => true, 'status_code' => 'valid', 'firstname' => $userData['firstname'], 'lastname' => $userData['lastname'], 'ticket_status' => $translations["valid"] ?? 'Válido', 'message' => 'Entrada registrada']);
        exit;
    }

    $checkStmt->close();
    
    // Obtener datos del usuario para el mensaje
    $userStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE userid = ?");
    $userStmt->bind_param("s", $qrCode);
    $userStmt->execute();
    $userResult = $userStmt->get_result();
    $userData = ($userResult && $userResult->num_rows > 0) ? $userResult->fetch_assoc() : ['firstname' => 'Usuario', 'lastname' => ''];
    $userStmt->close();
    
    // Calcular duración del entrenamiento
    $loginTime = new DateTime($login_date);
    $now = new DateTime();
    $interval = $loginTime->diff($now);
    $minutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
    
    // Guardar estadísticas (asegurarse de que existan columnas entry_time y exit_time)
    $dateStr = date('Y-m-d');

    $check_entry = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'entry_time'");
    if ($check_entry && $check_entry->num_rows == 0) {
        $conn->query("ALTER TABLE workout_stats ADD COLUMN entry_time DATETIME DEFAULT NULL");
    }
    $check_exit = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'exit_time'");
    if ($check_exit && $check_exit->num_rows == 0) {
        $conn->query("ALTER TABLE workout_stats ADD COLUMN exit_time DATETIME DEFAULT NULL");
    }

    $entry_time = (new DateTime($login_date))->format('Y-m-d H:i:s');
    $exit_time = $now->format('Y-m-d H:i:s');

    $stmtStats = $conn->prepare("INSERT INTO workout_stats (userid, duration, workout_date, entry_time, exit_time) VALUES (?, ?, ?, ?, ?)");
    $stmtStats->bind_param("sisss", $qrCode, $minutes, $dateStr, $entry_time, $exit_time);
    $stmtStats->execute();
    $stmtStats->close();
    
    // Eliminar de usuarios activos usando prepared statement
    $deleteStmt = $conn->prepare("DELETE FROM temp_loggeduser WHERE userid = ?");
    $deleteStmt->bind_param("s", $qrCode);
    $deleteStmt->execute();
    $deleteStmt->close();
    
    // Notificación de Salida
    $msg = "Salida registrada";
    $uName = $userData['firstname'] . ' ' . $userData['lastname'];
    
    $stmtExit = $conn->prepare("INSERT INTO access_notifications (type, message, userid, user_name) VALUES ('exit', ?, ?, ?)");
    $stmtExit->bind_param("sss", $msg, $qrCode, $uName);
    $stmtExit->execute();
    $notif_id = $stmtExit->insert_id;
    $stmtExit->close();

    echo json_encode([
        'success' => true,
        'action' => 'exit',
        'status_code' => 'exit',
        'firstname' => $userData['firstname'],
        'lastname' => $userData['lastname'],
        'message' => 'Gracias por venir, lo esperamos pronto',
        'notif_id' => $notif_id
    ]);
    $conn->close();
    exit;
}
$checkStmt->close();
// --- FIN LÓGICA SALIDA ---

// --- LÓGICA DE ENTRADA ---
$stmt = $conn->prepare("SELECT firstname, lastname, birthdate, gender, email FROM users WHERE userid = ?");
$stmt->bind_param("s", $qrCode);
$stmt->execute();
$result = $stmt->get_result();

$response = ['success' => false];

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $response['success'] = true;
    $response['firstname'] = $row['firstname'];
    $response['lastname'] = $row['lastname'];
    $response['birthdate'] = $row['birthdate'];
    $response['gender'] = $row["gender"];
    $response['email'] = $row['email'];

    $ticketStmt = $conn->prepare("SELECT t.opportunities, t.expiredate, t.buydate, tk.expire_days, tk.valid_days, tk.entry_people
                  FROM current_tickets t
                  LEFT JOIN tickets tk ON t.ticketname = tk.name
                  WHERE t.userid = ? 
                    AND t.expiredate >= CURDATE()
                  ORDER BY t.expiredate ASC");
    $ticketStmt->bind_param("s", $qrCode);
    $ticketStmt->execute();
    $ticketResult = $ticketStmt->get_result();

    $validTicketFound = false;
    $bestStatus = $translations["expired"];

    if ($ticketResult && $ticketResult->num_rows > 0) {
        while ($ticketRow = $ticketResult->fetch_assoc()) {
            $opportunities = $ticketRow['opportunities'];
            $expiredate = $ticketRow['expiredate'];
            $buydate = $ticketRow['buydate'];
            $expire_days = $ticketRow['expire_days'];
            $valid_days_json = $ticketRow['valid_days'];
            $entry_people = isset($ticketRow['entry_people']) ? intval($ticketRow['entry_people']) : 1;

            // Calcular vencimiento exacto usando la hora de compra
            $expireDateObj = new DateTime($expiredate);
            $buyDateObj = new DateTime($buydate);
            
            $now = new DateTime();

            if ($now <= $expireDateObj) {
                // VERIFICAR DÍAS VÁLIDOS (Calendario)
                $currentDayOfWeek = date('N'); // 1 (Lunes) a 7 (Domingo)
                $allowedDays = json_decode($valid_days_json, true);
                
                if (!empty($allowedDays) && !in_array((string)$currentDayOfWeek, $allowedDays)) {
                    $bestStatus = "Pase no válido hoy";
                    continue;
                }

                $validTicketFound = true;
                $response['status_code'] = 'valid';
                $response['ticket_status'] = $translations["valid"];
                $response['remaining_opportunities'] = $opportunities;
                $response['expiredate'] = $expiredate;

                $currentDate = date('Y-m-d');
                if ($expiredate == $currentDate) {
                    $response['expiredate_message'] = $translations["todayexpire"];
                } else {
                    $interval = date_diff(date_create($currentDate), date_create($expiredate));
                    $response['remaining_days'] = $interval->days;
                }

                // Only decrement opportunities for daily tickets (1 day), not for memberships
                if ($expire_days == 1 && !is_null($opportunities) && $opportunities > 0) {
                    $newOpportunities = $opportunities - 1;
                    $updateTicketStmt = $conn->prepare("UPDATE current_tickets 
                                        SET opportunities = ? 
                                        WHERE userid = ? 
                                          AND expiredate = ?");
                    $updateTicketStmt->bind_param("iss", $newOpportunities, $qrCode, $expiredate);
                    $updateTicketStmt->execute();
                    $updateTicketStmt->close();
                    $response['remaining_opportunities'] = $newOpportunities;
                }

                // Buscar si el usuario tiene un casillero rentado
                $lockerStmt = $conn->prepare("SELECT id, lockernum FROM lockers WHERE user_id = ? LIMIT 1");
                $lockerStmt->bind_param("s", $qrCode);
                $lockerStmt->execute();
                $lockerResult = $lockerStmt->get_result();
                $lockerId = 0;
                $lockerNum = "N/A";
                if ($lockerResult && $lockerResult->num_rows > 0) {
                    $lockerRow = $lockerResult->fetch_assoc();
                    $lockerId = $lockerRow['id'];
                    $lockerNum = $lockerRow['lockernum'];
                }
                $lockerStmt->close();
                $response['assigned_locker'] = $lockerNum;

                $loginDatePHP = date('Y-m-d H:i:s');
                $logUserStmt = $conn->prepare("INSERT INTO temp_loggeduser (name, userid, login_date, lockerid, people_count) 
                               VALUES (?, ?, ?, ?, ?)");
                $fullName = $row['firstname'] . ' ' . $row['lastname'];
                $logUserStmt->bind_param("sssii", $fullName, $qrCode, $loginDatePHP, $lockerId, $entry_people);
                $logUserStmt->execute();
                $logUserStmt->close();
                break;
            }
        }
    }

    if (!$validTicketFound) {
        $response['ticket_status'] = $bestStatus;
        $response['status_code'] = 'expired';

        // --- INICIO: ENVÍO DE ALERTA POR CORREO ---
        // Verificar si las alertas están activadas en el .env
        if (isset($env_data['ACCESS_ALERT_ENABLED']) && $env_data['ACCESS_ALERT_ENABLED'] === 'TRUE' && !empty($env_data['ACCESS_ALERT_EMAIL'])) {
            try {
                // Procesar múltiples correos separados por coma
                $recipients_raw = explode(',', $env_data['ACCESS_ALERT_EMAIL']);
                $alert_recipients = [];
                foreach ($recipients_raw as $email) {
                    $email = trim($email);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $alert_recipients[] = $email;
                    }
                }

                $business_name = $env_data['BUSINESS_NAME'] ?? 'GYM One';
                
                // Configurar transporte SMTP
                $transport = (new Swift_SmtpTransport($env_data['MAIL_HOST'], $env_data['MAIL_PORT'], $env_data['MAIL_ENCRYPTION']))
                    ->setUsername($env_data['MAIL_USERNAME'])
                    ->setPassword($env_data['MAIL_PASSWORD']);

                $mailer = new Swift_Mailer($transport);

                $subject = "⚠️ Alerta de Recepción: " . $row['firstname'] . " " . $row['lastname'];
                $body = "
                    <h3>Atención en Recepción</h3>
                    <p>El usuario <strong>{$row['firstname']} {$row['lastname']}</strong> ha escaneado su código QR pero <strong>no tiene un pase válido</strong>.</p>
                    <p><strong>Estado:</strong> {$bestStatus}</p>
                    <p><strong>Hora:</strong> " . date('H:i:s') . "</p>
                    <hr>
                    <small>Sistema de Alertas de {$business_name}</small>
                ";

                if (!empty($alert_recipients)) {
                    $message = (new Swift_Message($subject))
                        ->setFrom([$env_data['MAIL_USERNAME'] => $business_name])
                        ->setTo($alert_recipients)
                        ->setBody($body, 'text/html');

                    // COMENTADO TEMPORALMENTE PARA RESTAURAR VELOCIDAD DEL SISTEMA
                    // $mailer->send($message);
                }
            } catch (Exception $e) {
                // Silenciar errores de correo para no romper la respuesta JSON del escáner
                // error_log("Error enviando alerta de acceso: " . $e->getMessage());
            }
        }
        // --- FIN: ENVÍO DE ALERTA POR CORREO ---
    }
} else {
    $response['error'] = 'User not found';
}

// Evitar registrar notificación si el usuario no fue encontrado para hacer la app transparente
if (!$response['success'] && isset($response['error']) && $response['error'] === 'User not found') {
    $conn->close();
    echo json_encode($response);
    exit;
}

// Registrar notificación para el dashboard
$type = ($response['success'] && isset($response['ticket_status']) && $response['ticket_status'] == $translations["valid"]) ? 'success' : 'error';

if ($type === 'error' && isset($response['ticket_status'])) {
    $msg = $response['ticket_status'] . " - Requiere atención";
} else {
    $msg = $response['ticket_status'] ?? ($response['error'] ?? 'Error desconocido');
}

$uName = isset($response['firstname']) ? $response['firstname'] . ' ' . $response['lastname'] : 'Desconocido';

// Si es error y no hay nombre (usuario no encontrado), usar QR como nombre
if ($uName == 'Desconocido') $uName = "ID: " . $qrCode;

$stmtNotif = $conn->prepare("INSERT INTO access_notifications (type, message, userid, user_name) VALUES (?, ?, ?, ?)");
$stmtNotif->bind_param("ssss", $type, $msg, $qrCode, $uName);
$stmtNotif->execute();
$notif_id = $stmtNotif->insert_id;
$stmtNotif->close();

$response['notif_id'] = $notif_id;
$conn->close();
echo json_encode($response);
?>
