<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
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
$currency = $env_data["CURRENCY"] ?? '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Sincronizar zona horaria de MySQL con PHP
$now = new DateTime();
$mins = $now->getOffset() / 60;
$sgn = ($mins < 0 ? -1 : 1);
$mins = abs($mins);
$hrs = floor($mins / 60);
$mins -= $hrs * 60;
$offset = sprintf('%+d:%02d', $hrs*$sgn, $mins);
$conn->query("SET time_zone = '$offset';");

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_estadisticas_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputs($output, "\xEF\xBB\xBF"); // BOM para que Excel reconozca caracteres especiales (tildes, ñ)

// 1. Registros de Usuarios (Últimos 12 Meses)
fputcsv($output, ['--- REGISTROS DE USUARIOS (Últimos 12 meses) ---']);
fputcsv($output, ['Mes', 'Cantidad']);
$sql = "SELECT DATE_FORMAT(registration_date, '%Y-%m') as reg_month, COUNT(*) as count 
        FROM users 
        WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY reg_month
        ORDER BY reg_month";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['reg_month'], $row['count']]);
    }
}
fputcsv($output, []); // Línea vacía

// 2. Estadísticas de Género
fputcsv($output, ['--- DISTRIBUCIÓN DE GÉNERO ---']);
fputcsv($output, ['Género', 'Cantidad']);
$sql = "SELECT gender, COUNT(*) as count FROM users GROUP BY gender";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['gender'], $row['count']]);
    }
}
fputcsv($output, []);

// 3. Distribución por Edades
fputcsv($output, ['--- DISTRIBUCIÓN POR EDADES ---']);
fputcsv($output, ['Rango de Edad', 'Cantidad']);
$ageBuckets = ['<18' => 0, '18-24' => 0, '25-34' => 0, '35-44' => 0, '45-54' => 0, '55+' => 0];
$sqlAge = "SELECT birthdate FROM users WHERE birthdate IS NOT NULL AND birthdate != '0000-00-00'";
$resAge = $conn->query($sqlAge);
if($resAge && $resAge->num_rows > 0) {
    $today = new DateTime();
    while($r = $resAge->fetch_assoc()) {
        try {
            $bday = new DateTime($r['birthdate']);
            $age = $today->diff($bday)->y;
            if($age < 18) $ageBuckets['<18']++;
            elseif($age <= 24) $ageBuckets['18-24']++;
            elseif($age <= 34) $ageBuckets['25-34']++;
            elseif($age <= 44) $ageBuckets['35-44']++;
            elseif($age <= 54) $ageBuckets['45-54']++;
            else $ageBuckets['55+']++;
        } catch(Exception $e) {}
    }
}
foreach ($ageBuckets as $bucket => $count) {
    fputcsv($output, [$bucket, $count]);
}
fputcsv($output, []);

// 4. Tiempo Promedio de Entrenamiento
fputcsv($output, ['--- TIEMPO PROMEDIO DE ENTRENAMIENTO (minutos) ---']);
$sql = "SELECT AVG(duration) AS avg_duration FROM workout_stats WHERE duration IS NOT NULL";
$result = $conn->query($sql);
$avg = 0;
if ($result && $row = $result->fetch_assoc()) {
    $avg = round($row['avg_duration'], 0);
}
fputcsv($output, ['Promedio', $avg]);
fputcsv($output, []);

// 5. Estado de Casilleros
fputcsv($output, ['--- ESTADO DE CASILLEROS ---']);
fputcsv($output, ['Género', 'Libres', 'Ocupados', 'Total']);
$sql = "SELECT 
            gender, 
            COUNT(CASE WHEN user_id IS NULL THEN 1 END) AS free_lockers,
            COUNT(CASE WHEN user_id IS NOT NULL THEN 1 END) AS occupied_lockers,
            COUNT(*) as total_lockers
        FROM lockers 
        GROUP BY gender";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [$row['gender'], $row['free_lockers'], $row['occupied_lockers'], $row['total_lockers']]);
    }
}
fputcsv($output, []);

// 6. Asistencia Diaria (Últimos 7 días)
fputcsv($output, ['--- ASISTENCIA DIARIA (Últimos 7 días) ---']);
fputcsv($output, ['Fecha', 'Asistentes']);
$start_date_attendance = date('Y-m-d', strtotime('-6 days'));
$end_date_attendance = date('Y-m-d');
$sqlAttendance = "SELECT workout_date as attendance_date, COUNT(*) as daily_count FROM workout_stats WHERE workout_date BETWEEN ? AND ? GROUP BY workout_date ORDER BY attendance_date ASC";
if ($stmtAttendance = $conn->prepare($sqlAttendance)) {
    $stmtAttendance->bind_param("ss", $start_date_attendance, $end_date_attendance);
    $stmtAttendance->execute();
    $resultAttendance = $stmtAttendance->get_result();
    if ($resultAttendance) {
        while ($row = $resultAttendance->fetch_assoc()) {
            fputcsv($output, [$row['attendance_date'], $row['daily_count']]);
        }
    }
    $stmtAttendance->close();
}
fputcsv($output, []);

// 7. Horas Pico de Asistencia (Últimos 30 días)
fputcsv($output, ['--- HORAS PICO DE ASISTENCIA (Últimos 30 días) ---']);
fputcsv($output, ['Hora', 'Visitas']);
$sqlPeak = "SELECT HOUR(entry_time) as login_hour, COUNT(*) as count FROM workout_stats WHERE entry_time IS NOT NULL AND entry_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY login_hour ORDER BY login_hour";
$resPeak = $conn->query($sqlPeak);
if ($resPeak) {
    while($r = $resPeak->fetch_assoc()) {
        if ($r['login_hour'] !== null) {
            fputcsv($output, [str_pad($r['login_hour'], 2, '0', STR_PAD_LEFT) . ':00', $r['count']]);
        }
    }
}
fputcsv($output, []);

// 8. Popularidad de Pases (Últimos 15 días)
fputcsv($output, ['--- POPULARIDAD DE PASES (Últimos 15 días) ---']);
fputcsv($output, ['Nombre del Pase', 'Cantidad Vendida']);
$sqlTicketPop = "SELECT ticketname, COUNT(*) as count FROM current_tickets WHERE buydate >= DATE_SUB(CURDATE(), INTERVAL 15 DAY) GROUP BY ticketname ORDER BY count DESC";
$resTicketPop = $conn->query($sqlTicketPop);
if($resTicketPop) {
    while($r = $resTicketPop->fetch_assoc()) {
        fputcsv($output, [$r['ticketname'], $r['count']]);
    }
}
fputcsv($output, []);

// 9. Ingresos por Método de Pago (Últimos 7 Días)
fputcsv($output, ['--- INGRESOS POR MÉTODO DE PAGO (Últimos 7 días) ---']);
fputcsv($output, ['Fecha', 'Tarjeta (' . $currency . ')', 'Efectivo (' . $currency . ')', 'Total (' . $currency . ')']);
$start_date_stats = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$end_date_stats = date('Y-m-d') . ' 23:59:59';

$sqlStats = "SELECT 
    DATE(CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone)) as invoice_date, 
    SUM(CASE WHEN payment_method = 'Card' THEN price ELSE 0 END) as card_total,
    SUM(CASE WHEN payment_method != 'Card' THEN price ELSE 0 END) as cash_total
FROM invoices 
WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$start_date_stats' AND '$end_date_stats'
GROUP BY invoice_date
ORDER BY invoice_date ASC";

$resultStats = $conn->query($sqlStats);
if ($resultStats) {
    while ($row = $resultStats->fetch_assoc()) {
        fputcsv($output, [
            $row['invoice_date'], 
            $row['card_total'], 
            $row['cash_total'], 
            $row['card_total'] + $row['cash_total']
        ]);
    }
}
fputcsv($output, []);

// 10. Detalle de Ingresos y Ventas de Productos (Últimos 7 Días)
$start_date = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$end_date = date('Y-m-d') . ' 23:59:59';
$inv_sql = "SELECT CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) as created_at_tz, price, type, description FROM invoices WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$start_date' AND '$end_date' ORDER BY created_at_tz ASC";
$inv_res = $conn->query($inv_sql);

$income_rows = [];
$product_sales = [];

if ($inv_res && $inv_res->num_rows > 0) {
    while ($r = $inv_res->fetch_assoc()) {
        $d = date('Y-m-d', strtotime($r['created_at_tz']));
        $typ = $r['type'] ?? 'Unknown';
        $price = (float) $r['price'];
        
        $income_rows[] = [$d, $typ, $price];

        if ($typ === 'Product') {
            $desc = $r['description'] ?? '';
            if (!empty($desc)) {
                // Intentar parsear formato "Producto (x2)"
                if (preg_match_all('/([^,]+?)\s*\(x(\d+)\)/', $desc, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $pname = trim($m[1]);
                        $qty = intval($m[2]);
                        $product_sales[] = [$d, $pname, $qty];
                    }
                } else {
                    // Formato simple separado por comas
                    $parts = array_map('trim', explode(',', $desc));
                    foreach ($parts as $part) {
                        if ($part === '') continue;
                        $product_sales[] = [$d, $part, 1];
                    }
                }
            }
        }
    }
}

fputcsv($output, ['--- DETALLE DE INGRESOS POR TIPO (Últimos 7 días) ---']);
fputcsv($output, ['Fecha', 'Tipo', 'Monto (' . $currency . ')']);
foreach ($income_rows as $row) {
    fputcsv($output, $row);
}
fputcsv($output, []);

fputcsv($output, ['--- VENTAS DE PRODUCTOS (Últimos 7 días) ---']);
fputcsv($output, ['Fecha', 'Producto', 'Cantidad']);
foreach ($product_sales as $row) {
    fputcsv($output, $row);
}

fclose($output);
$conn->close();
?>