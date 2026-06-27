<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    http_response_code(401);
    exit('Unauthorized');
}

function read_env_file($file_path)
{
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
$currency = $env_data["CURRENCY"] ?? '';
$capacity = $env_data["CAPACITY"] ?? 0;
$lang_code = $env_data['LANG_CODE'] ?? 'es';

$langDir = __DIR__ . "/../../assets/lang/";
$langFile = $langDir . "$lang_code.json";
$translations = file_exists($langFile) ? json_decode(file_get_contents($langFile), true) : [];

try {
    $conn = new mysqli($db_host, $db_username, $db_password, $db_name);
} catch (Exception $e) {
    exit(json_encode(['error' => 'DB Connection Error']));
}

if ($conn->connect_error) exit(json_encode(['error' => 'DB Error']));

// Sincronizar zona horaria de MySQL con PHP
$now = new DateTime('now', new DateTimeZone($timezone));
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;
$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
$conn->query("SET time_zone = '$offset';");

// Limpieza automática de usuarios del día anterior (Reset diario)
$conn->query("DELETE FROM temp_loggeduser WHERE login_date < CURDATE()");

// 1. ESTADÍSTICAS
$stats_date = date('Y-m-d');
$stats_date_start = date('Y-m-d') . ' 00:00:00';
$stats_date_end = date('Y-m-d') . ' 23:59:59';

// Ingresos Hoy
$sqlIncome = "SELECT SUM(price) as total_income FROM invoices WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$stats_date_start' AND '$stats_date_end'";
$resultIncome = $conn->query($sqlIncome);
$dailyIncome = ($resultIncome && $row = $resultIncome->fetch_assoc()) ? ($row['total_income'] ?? 0) : 0;

// Usuarios Hoy
$sqlDailyUsers = "SELECT COUNT(*) AS total_people FROM workout_stats WHERE workout_date = '$stats_date'";
$resultDailyUsers = $conn->query($sqlDailyUsers);
$dailyUsers = ($resultDailyUsers && $row = $resultDailyUsers->fetch_assoc()) ? $row['total_people'] : 0;

// Total Miembros
$sqlTotalUsers = "SELECT COUNT(*) as count FROM users";
$resultTotalUsers = $conn->query($sqlTotalUsers);
$totalUsers = ($resultTotalUsers && $row = $resultTotalUsers->fetch_assoc()) ? $row['count'] : 0;

// 2. TABLA DE USUARIOS ACTIVOS
$sql = "SELECT t.name, t.userid, t.login_date, t.people_count, l.lockernum, 
        (SELECT ticketname FROM current_tickets WHERE userid = t.userid AND expiredate >= CURDATE() ORDER BY expiredate ASC LIMIT 1) as ticket_name,
        u.birthdate
        FROM temp_loggeduser t
        LEFT JOIN lockers l ON t.lockerid = l.id
        LEFT JOIN users u ON t.userid = u.userid";
$result = $conn->query($sql);

$tableHtml = '';
$totalActive = 0;

if ($result->num_rows > 0) {
    $counter = 1;
    while ($row = $result->fetch_assoc()) {
        $people_count = (isset($row['people_count']) && $row['people_count'] > 1) ? $row['people_count'] : 1;
        $totalActive += $people_count;

        $loginDateObj = new DateTime($row['login_date']);
        $nowObj = new DateTime();
        $seconds = $nowObj->getTimestamp() - $loginDateObj->getTimestamp();
        if ($seconds < 0) $seconds = 0;

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $elapsed_time = sprintf("%d Horas %d Minutos", $hours, $minutes);

        $profilePicPath = '../../assets/img/profiles/' . $row['userid'] . '.png';
        $checkPath = __DIR__ . '/../../assets/img/profiles/' . $row['userid'] . '.png';
        $imgDisplay = file_exists($checkPath) 
            ? "<img src='{$profilePicPath}?v=" . filemtime($checkPath) . "' alt='Avatar' style='width: 55px; height: 55px; object-fit: cover; border-radius: 50%; cursor: zoom-in;' class='zoomable-image' loading='lazy'>" 
            : "<i class='bi bi-person-circle text-muted' style='font-size: 50px;'></i>";

        $nameDisplay = $row["name"];
        if ($people_count > 1) {
            $nameDisplay .= " <span class='badge badge-info'>+" . ($people_count - 1) . "</span>";
        }

        // Ticket Info
        $row_userid = $row["userid"];
        $ticket_sql = "SELECT ct.ticketname, ct.buydate, ct.expiredate, t.expire_days 
                       FROM current_tickets ct 
                       LEFT JOIN tickets t ON ct.ticketname = t.name 
                       WHERE ct.userid = '$row_userid' 
                       ORDER BY ct.expiredate DESC LIMIT 1";
        $ticket_result = $conn->query($ticket_sql);
        
        $ticket_display = ($row['ticket_name'] ?? 'N/A');
        $buydate_display = '-';
        $remaining_display = '-';

        if ($ticket_result && $ticket_result->num_rows > 0) {
            $ticket_data = $ticket_result->fetch_assoc();
            $expiredate = $ticket_data["expiredate"];
            $buydate = $ticket_data["buydate"];
            $expire_days = $ticket_data["expire_days"];
            $ticket_display = $ticket_data["ticketname"];

            if ($expiredate && strpos($expiredate, '9999-12-31') === 0) {
                $buydate_display = $buydate;
                $remaining_display = "<span class='text-success'>" . ($translations["unlimited"] ?? 'Ilimitado') . "</span>";
            } else {
                try {
                    $today = new DateTime();
                    $expire = new DateTime($expiredate);
                    $buyDateObj = new DateTime($buydate);

                    $originalExpire = new DateTime($expiredate);

                    if ($expire > $today) {
                        $interval = $today->diff($expire);
                        $remaining = ($interval->days < 1) ? $interval->format('%H:%I:%S') : $interval->format('%a d %h h %i m');
                        $buydate_display = $buydate;
                        $remaining_display = "<span class='text-success'>$remaining</span>";
                    } else {
                        $buydate_display = $buydate;
                        $remaining_display = "<span class='text-danger'>" . ($translations["expired"] ?? 'Vencido') . " (" . $originalExpire->format("Y-m-d") . ")</span>";
                        $ticket_display = $translations["youdonthaveticket"] ?? "No tienes pase";
                    }
                } catch (Exception $e) {
                    $remaining_display = "Error";
                }
            }
        }

        $lockerNum = $row['lockernum'] ?? 'N/A';
        $logoutText = $translations["userlogout"] ?? 'Salir';
        $editText = $translations["editbtn"] ?? 'Editar';
        $sellText = $translations["sellpage"] ?? 'Venta';

        $is_birthday = false;
        if (!empty($row['birthdate'])) {
            $birthDateMonthDay = date('m-d', strtotime($row['birthdate']));
            $todayMonthDay = date('m-d');
            if ($birthDateMonthDay === $todayMonthDay) {
                $is_birthday = true;
            }
        }
        $birthday_display = $is_birthday ? "<span class='badge badge-warning' style='background-color:#ffc107;color:#000;'><i class='bi bi-gift'></i> ¡Es su cumpleaños!</span>" : "<span class='text-muted'>Aún no</span>";

        $tableHtml .= "<tr>
            <td>{$counter}</td>
            <td style='vertical-align: middle;'>{$imgDisplay}</td>
            <td>{$nameDisplay}</td>
            <td>{$lockerNum}</td>
            <td>{$ticket_display}</td>
            <td>{$buydate_display}</td>
            <td>{$remaining_display}</td>
            <td>{$birthday_display}</td>
            <td class='timer-cell' data-seconds='{$seconds}'>{$elapsed_time}</td>
            <td><a class='btn btn-danger' href='logout.php?user={$row["userid"]}'>{$logoutText}</a></td>
            <td><a class='btn btn-secondary' href='../users/edit/?user={$row["userid"]}'>{$editText}</a></td>
            <td><a class='btn btn-success' href='../boss/sell/ticket/?userid={$row["userid"]}'><i class='bi bi-cart-plus'></i> {$sellText}</a></td>
        </tr>";
        $counter++;
    }
} else {
    $tableHtml = "<tr><td colspan='12'>" . ($translations["noonetraining"] ?? 'Vacío') . "</td></tr>";
}

// 3. CAPACIDAD
$capacityPercent = ($capacity > 0) ? ($totalActive / $capacity) * 100 : 0;
$progresscolor = 'success';
if ($capacityPercent >= 70) $progresscolor = 'warning';
if ($capacityPercent >= 90) $progresscolor = 'danger';

$conn->close();

header('Content-Type: application/json');
echo json_encode([
    'stats' => [
        'income' => number_format($dailyIncome, 2) . ' ' . $currency,
        'daily_users' => $dailyUsers,
        'total_users' => $totalUsers
    ],
    'capacity' => [
        'percent' => number_format($capacityPercent, 2),
        'text' => number_format($capacityPercent, 0) . '%',
        'color' => $progresscolor
    ],
    'table_html' => $tableHtml
]);
?>