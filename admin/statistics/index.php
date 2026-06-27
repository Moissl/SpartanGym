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

$env_data = read_env_file('../../.env');

// Establecer zona horaria desde .env (TIMEZONE) o usar 'America/Mexico_City' por defecto
$timezone = $env_data['TIMEZONE'] ?? 'America/Mexico_City';
if (!in_array($timezone, timezone_identifiers_list())) {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$currency = $env_data["CURRENCY"] ?? '';


$lang = $lang_code;

$langDir = __DIR__ . "/../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
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

$months = [
    "01" => $translations["Jan"],
    "02" => $translations["Feb"],
    "03" => $translations["Mar"],
    "04" => $translations["Apr"],
    "05" => $translations["May"],
    "06" => $translations["Jun"],
    "07" => $translations["Jul"],
    "08" => $translations["Aug"],
    "09" => $translations["Sep"],
    "10" => $translations["Oct"],
    "11" => $translations["Nov"],
    "12" => $translations["Dec"]
];

$current_month = (int) date('m');
$current_year = (int) date('Y');

$categories = array();
$dataRegistrations = array();

for ($i = 11; $i >= 0; $i--) {
    $timestamp = mktime(0, 0, 0, $current_month - $i, 1, $current_year);
    $year_month = date("Y-m", $timestamp);
    $categories[] = $months[date('m', $timestamp)] . ' ' . date('Y', $timestamp);
    $dataRegistrations[$year_month] = 0;
}

$sqlRegistrations = "SELECT DATE_FORMAT(registration_date, '%Y-%m') as reg_month, 
                            COUNT(*) as count 
                     FROM users 
                     WHERE registration_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                     GROUP BY reg_month
                     ORDER BY reg_month";
$resultRegistrations = $conn->query($sqlRegistrations);

if ($resultRegistrations->num_rows > 0) {
    while ($row = $resultRegistrations->fetch_assoc()) {
        $dataRegistrations[$row['reg_month']] = $row['count'];
    }
}


$sqlUserCount = "SELECT COUNT(*) as count FROM users";
$resultUserCount = $conn->query($sqlUserCount);

$userCount = 0;

if ($resultUserCount->num_rows > 0) {
    $row = $resultUserCount->fetch_assoc();
    $userCount = $row["count"];
}

$sql = "SELECT is_boss FROM workers WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$stmt->store_result();

$is_boss = null;

if ($stmt->num_rows > 0) {
    $stmt->bind_result($is_boss);
    $stmt->fetch();
}
$stmt->close();

$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
curl_setopt($ch, CURLOPT_TIMEOUT, 2);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

$sql = "SELECT AVG(duration) AS avg_duration FROM workout_stats WHERE duration IS NOT NULL";
$result = mysqli_query($conn, $sql);

$avgDuration = 0;
if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $avgDuration = round($row['avg_duration'], 0);
}


$sql = "SELECT gender, COUNT(*) as count FROM users GROUP BY gender";
$result = $conn->query($sql);

$maleCount = 0;
$femaleCount = 0;
$otherCount = 0;

if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        if ($row["gender"] == "Male") {
            $maleCount = $row["count"];
        } elseif ($row["gender"] == "Female") {
            $femaleCount = $row["count"];
        } elseif ($row["gender"] == "Other") {
            $otherCount = $row["count"];
        }
    }
}

// Contar casilleros libres y ocupados (Unisex)
$sqlFree = "SELECT COUNT(*) AS count FROM lockers WHERE user_id IS NULL";
$resFree = $conn->query($sqlFree);
$freeLockers = ($resFree && $row = $resFree->fetch_assoc()) ? $row['count'] : 0;

$sqlOccupied = "SELECT COUNT(*) AS count FROM lockers WHERE user_id IS NOT NULL";
$resOccupied = $conn->query($sqlOccupied);
$occupiedLockers = ($resOccupied && $row = $resOccupied->fetch_assoc()) ? $row['count'] : 0;

$dates = [];
$bankCardData = [];
$cashData = [];
$dailyAttendanceData = [];

for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $dates[$date] = ['bank_card' => 0, 'cash' => 0, 'attendance' => 0];
}

// Usar tabla invoices para obtener totales reales por método de pago (incluyendo productos)
$start_date_stats = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$end_date_stats = date('Y-m-d') . ' 23:59:59';

$sqlStats = "SELECT 
    DATE(CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone)) as invoice_date, 
    payment_method, 
    SUM(price) as total 
FROM invoices 
WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$start_date_stats' AND '$end_date_stats'
GROUP BY invoice_date, payment_method";

$resultStats = $conn->query($sqlStats);

if ($resultStats && $resultStats->num_rows > 0) {
    while ($row = $resultStats->fetch_assoc()) {
        $d = $row['invoice_date'];
        if (isset($dates[$d])) {
            if ($row['payment_method'] === 'Card') {
                $dates[$d]['bank_card'] += (float)$row['total'];
            } else {
                $dates[$d]['cash'] += (float)$row['total'];
            }
        }
    }
}

// --- Asistencia Diaria (últimos 7 días) ---
$start_date_attendance = date('Y-m-d', strtotime('-6 days'));
$end_date_attendance = date('Y-m-d');

$sqlAttendance = "SELECT workout_date as attendance_date, COUNT(*) as daily_count FROM workout_stats WHERE workout_date BETWEEN ? AND ? GROUP BY workout_date";

if ($stmtAttendance = $conn->prepare($sqlAttendance)) {
    $stmtAttendance->bind_param("ss", $start_date_attendance, $end_date_attendance);
    $stmtAttendance->execute();
    $resultAttendance = $stmtAttendance->get_result();
    if ($resultAttendance && $resultAttendance->num_rows > 0) {
        while ($row = $resultAttendance->fetch_assoc()) {
            $d = $row['attendance_date'];
            if (isset($dates[$d])) {
                $dates[$d]['attendance'] = (int)$row['daily_count'];
            }
        }
    }
    $stmtAttendance->close();
}

foreach ($dates as $date => $values) {
    $formattedDates[] = $date;
    $bankCardData[] = $values['bank_card'];
    $cashData[] = $values['cash'];
    $dailyAttendanceData[] = $values['attendance'];
}

// --- 1. Horas Pico de Asistencia (Últimos 30 días) ---
$peakHoursData = array_fill(0, 24, 0);
$sqlPeak = "SELECT HOUR(entry_time) as login_hour, COUNT(*) as count FROM workout_stats WHERE entry_time IS NOT NULL AND entry_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY login_hour";
if ($stmtPeak = $conn->prepare($sqlPeak)) {
    $stmtPeak->execute();
    $resPeak = $stmtPeak->get_result();
    while($r = $resPeak->fetch_assoc()) {
        if ($r['login_hour'] !== null) {
            $peakHoursData[(int)$r['login_hour']] = (int)$r['count'];
        }
    }
    $stmtPeak->close();
}
$peakHoursLabels = [];
for($i=0; $i<24; $i++) {
    $peakHoursLabels[] = str_pad($i, 2, '0', STR_PAD_LEFT) . ':00';
}

// --- 2. Popularidad de Pases Activos ---
$ticketPopLabels = [];
$ticketPopData = [];
$sqlTicketPop = "SELECT ticketname, COUNT(*) as count FROM current_tickets WHERE buydate >= DATE_SUB(CURDATE(), INTERVAL 15 DAY) GROUP BY ticketname ORDER BY count DESC LIMIT 5";
$resTicketPop = $conn->query($sqlTicketPop);
if($resTicketPop && $resTicketPop->num_rows > 0) {
    while($r = $resTicketPop->fetch_assoc()) {
        $ticketPopLabels[] = $r['ticketname'];
        $ticketPopData[] = (int)$r['count'];
    }
}

// --- 3. Distribución por Edades ---
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

// --- Nuevos datos: Ingresos por tipo y productos vendidos por día (últimos 7 días)
$date_keys = array_keys($dates);
$types = ['Product', 'Balance', 'Locker', 'Ticket'];
$incomeByType = [];
foreach ($types as $t) {
    $incomeByType[$t] = array_fill(0, count($date_keys), 0.0);
}
$product_counts = []; // [product][date] => qty
$product_totals = []; // [product] => total qty

$start_date = date('Y-m-d', strtotime('-6 days')) . ' 00:00:00';
$end_date = date('Y-m-d') . ' 23:59:59';
$inv_sql = "SELECT CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) as created_at, price, type, description FROM invoices WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$start_date' AND '$end_date'";
$inv_res = $conn->query($inv_sql);
if ($inv_res && $inv_res->num_rows > 0) {
    while ($r = $inv_res->fetch_assoc()) {
        $d = date('Y-m-d', strtotime($r['created_at']));
        $idx = array_search($d, $date_keys);
        if ($idx === false) continue;
        $typ = $r['type'] ?? '';
        $price = (float) $r['price'];
        if (in_array($typ, $types)) {
            $incomeByType[$typ][$idx] += $price;
        }

        // Productos vendidos (parsear description: "Name (x2), Other (x1), ...")
        if ($typ === 'Product') {
            $desc = $r['description'] ?? '';
            if (!empty($desc)) {
                // Extraer patrones "Name (xN)"
                if (preg_match_all('/([^,]+?)\s*\(x(\d+)\)/', $desc, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $m) {
                        $pname = trim($m[1]);
                        $qty = intval($m[2]);
                        if ($qty < 1) $qty = 1;
                        $product_counts[$pname][$d] = ($product_counts[$pname][$d] ?? 0) + $qty;
                        $product_totals[$pname] = ($product_totals[$pname] ?? 0) + $qty;
                    }
                } else {
                    // Si no tiene (xN), contar cada ítem separado por coma como 1
                    $parts = array_map('trim', explode(',', $desc));
                    foreach ($parts as $part) {
                        if ($part === '') continue;
                        $pname = $part;
                        $product_counts[$pname][$d] = ($product_counts[$pname][$d] ?? 0) + 1;
                        $product_totals[$pname] = ($product_totals[$pname] ?? 0) + 1;
                    }
                }
            }
        }
    }
}

// Preparar series para JS
$incomeSeries = [];
foreach ($types as $t) {
    $incomeSeries[] = ['name' => $t, 'data' => array_values($incomeByType[$t])];
}

// Seleccionar top 10 productos por volumen
$topProducts = [];
if (!empty($product_totals)) {
    arsort($product_totals);
    $topProducts = array_slice(array_keys($product_totals), 0, 10);
}
$productSeries = [];
if (!empty($topProducts)) {
    foreach ($topProducts as $pname) {
        $series = [];
        foreach ($date_keys as $d) {
            $series[] = $product_counts[$pname][$d] ?? 0;
        }
        $productSeries[] = ['name' => $pname, 'data' => $series];
    }
} else {
    // Placeholder: no hubo ventas
    $productSeries[] = ['name' => 'Sin ventas', 'data' => array_fill(0, count($date_keys), 0)];
}

$incomeSeriesJson = json_encode($incomeSeries);
$productSeriesJson = json_encode($productSeries);
$chartLabelsJson = json_encode(array_values($date_keys));

$conn->close();


?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $translations["dashboard"]; ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
</head>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>


<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i> <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../boss/packages"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
                        <li><a href="../boss/chroom"><i class="bi bi-duffle"></i> <?php echo $translations["chroompage"]; ?></a></li>
                    <?php } ?>
                    <li><a href="../trainers/timetable"><i class="bi bi-calendar-event"></i> <?php echo $translations["timetable"]; ?></a></li>
                    <li><a href="../trainers/personal"><i class="bi bi-award"></i> <?php echo $translations["trainers"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../updater"><i class="bi bi-cloud-download"></i> <?php echo $translations["updatepage"]; ?>
                                <?php if ($is_new_version_available) : ?>
                                    <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                                <?php endif; ?>
                            </a></li>
                    <?php } ?>
                    <li><a href="../log"><i class="bi bi-clock-history"></i> <?php echo $translations["logpage"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../assets/img/logo.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../users">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../boss/sell">
                            <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../invoices/" class="sidebar-link">
                            <i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?>
                        </a>
                    </li>
                    <?php
                    if ($is_boss === 1) {
                    ?>
                        <li class="sidebar-header">
                            <?php echo $translations["settings"]; ?>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/mainsettings">
                                <i class="bi bi-gear"></i>
                                <span><?php echo $translations["businesspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/workers">
                                <i class="bi bi-people"></i>
                                <span><?php echo $translations["workers"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/hours">
                                <i class="bi bi-clock"></i>
                                <span><?php echo $translations["openhourspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/smtp">
                                <i class="bi bi-envelope-at"></i>
                                <span><?php echo $translations["mailpage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../boss/rule">
                                <i class="bi bi-file-ruled"></i>
                                <span><?php echo $translations["rulepage"]; ?></span>
                            </a>
                        </li>
                    <?php
                    }
                    ?>
                    <li class="sidebar-header">
                        <?php echo $translations["shopcategory"]; ?>

                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../shop/tickets">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <?php if ($is_boss === 1) { ?>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../boss/packages">
                            <i class="bi bi-box-seam"></i>
                            <span><?php echo $translations["packagepage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../boss/chroom">
                            <i class="bi bi-duffle"></i>
                            <span><?php echo $translations["chroompage"]; ?></span>
                        </a>
                    </li>
                    <?php } ?>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li><a class="sidebar-link" href="../trainers/timetable">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a></li>
                    <li><a class="sidebar-link" href="../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a></li>
                    <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
                    <?php
                    if ($is_boss === 1) {
                    ?>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../updater">
                                <i class="bi bi-cloud-download"></i>
                                <span><?php echo $translations["updatepage"]; ?></span>
                                <?php if ($is_new_version_available) : ?>
                                    <span class="sidebar-badge badge">
                                        <i class="bi bi-exclamation-circle"></i>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php
                    }
                    ?>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../log">
                            <i class="bi bi-clock-history"></i>
                            <span><?php echo $translations["logpage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="#" data-toggle="modal" data-target="#reportModal">
                            <i class="bi bi-file-earmark-spreadsheet"></i>
                            <span>Reporte Asistencia</span>
                        </a>
                    </li>
                </ul><br>
            </div>
            <br>
            <div class="col-sm-10">
                <div class="d-none topnav d-sm-inline-block">
                    <a href="https://gymoneglobal.com/discord" class="btn btn-primary mx-1" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-question-circle"></i>
                        <?php echo $translations["support"]; ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo $translations["docs"]; ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo $translations["logout"]; ?>
                    </button>
                    <a href="export_statistics.php" class="btn btn-success mx-1">
                        <i class="bi bi-download"></i> Descargar Reporte CSV
                    </a>
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <?php

                if ($is_boss == 1 && $is_new_version_available) {
                ?>
                    <div class="row">
                        <div class="col-sm-12">
                            <div class="alert alert-danger">
                                <?php echo $translations["newupdate-text"]; ?>
                            </div>
                        </div>
                    </div>
                <?php
                }
                ?>
                <div class="row">
                    <div class="col-sm-12">
                        <?php
                        $ruleContent = file_get_contents('../boss/rule/rule.html');

                        if (empty($ruleContent)) {
                            echo '<div class="alert alert-danger">';
                            echo '<i class="bi bi-exclamation-triangle"></i> ' . $translations['gymrulenotset'];
                            echo '</div>';
                        }
                        ?>

                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead"><?php echo $translations["new-users"]; ?></p>
                            </div>
                            <div class="card-body">
                                <div class="text-center" id="userschart"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead"><?php echo $translations["genderstats"]; ?></p>
                            </div>
                            <div class="card-body">
                                <div class="text-center" id="malefamalechart"></div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-header">
                                <p class="lead"><?php echo $translations["averagetraintime"]; ?></p>
                            </div>
                            <div class="card-body text-center">
                                <h1 class="lead"><?= $avgDuration; ?> <?= $translations["minutes"]; ?></h1>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-body">
                                <div class="card-header">
                                    <p class="lead"><?php echo $translations["avilablelockers"]; ?></p>
                                </div>
                                <div class="card-body text-center">
                                    <p class="lead">
                                        <?php
                                        if ($freeLockers > 0) {
                                            echo '<span class="badge bg-label-success">Libres: ' . $freeLockers . '</span>';
                                        } else {
                                            echo '<span class="badge bg-label-danger">' . ($translations["locker_notavilable"] ?? '¡No hay casilleros disponibles!') . '</span>';
                                        }
                                        ?>
                                    </p>
                                    <p class="lead">
                                        <span class="badge bg-label-warning">Ocupados: <?php echo $occupiedLockers; ?></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin-top: 20px;">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead">Asistencia Diaria (Últimos 7 días)</p>
                            </div>
                            <div class="card-body">
                                <div id="dailyAttendanceChart"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin-top: 20px;">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead">Horas Pico de Asistencia (Últimos 30 días)</p>
                            </div>
                            <div class="card-body">
                                <div id="peakHoursChart"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin-top: 20px;">
                    <div class="col-sm-6">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead">Popularidad de Pases (Últimos 15 días)</p>
                            </div>
                            <div class="card-body">
                                <div id="ticketPopularityChart"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="card">
                            <div class="card-header">
                                <p class="lead">Distribución por Edades</p>
                            </div>
                            <div class="card-body">
                                <div id="ageDemographicsChart"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col text-center">
                        <h2 class="lead"><?php echo $translations["moneystats"]; ?></h2>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-6">
                        <div class="card">
                            <div class="card-body">
                                <div class="text-center" id="moneyincomechart"></div>
            </div>
        </div>
    </div>
    <div class="row" style="margin-top:20px;">
        <div class="col-sm-6">
            <div class="card">
                <div class="card-header">
                    <p class="lead">Ingresos por Tipo (últimos 7 días)</p>
                </div>
                <div class="card-body">
                    <div id="incomeByTypeChart"></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="card">
                <div class="card-header">
                    <p class="lead">Productos más vendidos por día (últimos 7 días)</p>
                </div>
                <div class="card-body">
                    <div id="productSalesChart"></div>
                </div>
            </div>
        </div>
    </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REPORT MODAL -->
    <div class="modal fade" id="reportModal" tabindex="-1" role="dialog" aria-labelledby="reportModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="reportModalLabel">Descargar Reporte de Asistencia</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form action="../dashboard/export_attendance.php" method="GET">
                    <div class="modal-body">
                        <div class="form-group">
                            <label>Seleccione la fecha:</label>
                            <input type="date" name="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-download"></i> Descargar CSV</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- EXIT MODAL -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <p><?php echo $translations["exit-modal"]; ?></p>
                </div>
                <div class="modal-footer">
                    <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                    <a href="../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS! -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            let seriesData = Object.values(<?php echo json_encode($dataRegistrations); ?>);

            let maxValue = Math.max(...seriesData);
            let maxAxis = Math.ceil(maxValue / 10) * 10;
            if (maxAxis === 0) maxAxis = 10;

            var options = {
                chart: {
                    type: 'area',
                    fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif',
                    toolbar: {
                        show: false
                    },
                    zoom: {
                        enabled: false
                    }
                },
                colors: ['#59F8E4'],
                series: [{
                    name: '<?php echo $translations["reg-number"]; ?>',
                    data: seriesData
                }],
                xaxis: {
                    categories: <?php echo json_encode($categories); ?>,
                },
                yaxis: {
                    tickAmount: maxAxis / 10,
                    min: 0,
                    max: maxAxis,
                    labels: {
                        formatter: function(value) {
                            return Math.floor(value);
                        }
                    }
                },
            };

            var chart = new ApexCharts(document.querySelector("#userschart"), options);
            chart.render();
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var options = {
                series: [<?php echo $maleCount; ?>, <?php echo $femaleCount; ?>, <?php echo $otherCount; ?>],
                chart: {
                    type: 'donut',
                    width: '100%',
                    height: 'auto',
                    toolbar: {
                        show: false
                    }
                },
                colors: ['#1E90FF', '#FF69B4', '#808080'],
                labels: ['<?php echo $translations["boy"]; ?>', '<?php echo $translations["girl"]; ?>', 'Otros'],
                dataLabels: {
                    enabled: true,
                    formatter: function(val, opts) {
                        return val.toFixed(2) + '%';
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(val, opts) {
                            var total = opts.globals.series.reduce((a, b) => a + b, 0);
                            var percent = (val / total) * 100;
                            return percent.toFixed(2) + '%';
                        }
                    }
                },
                legend: {
                    show: true,
                    position: 'bottom'
                },
                responsive: [{
                    breakpoint: 480,
                    options: {
                        chart: {
                            width: '100%'
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }],
            };

            var chart = new ApexCharts(document.querySelector("#malefamalechart"), options);
            chart.render();
        });
    </script>
    <script>
        var options = {
            chart: {
                type: 'area',
                fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif',

                toolbar: {
                    show: false
                },
                zoom: {
                    enabled: false
                }
            },
            colors: ['#59F8E4', '#FB7B18'],

            series: [{
                name: '<?php echo $translations["card"]; ?>',
                data: <?php echo json_encode($bankCardData); ?>
            }, {
                name: '<?php echo $translations["cash"]; ?>',
                data: <?php echo json_encode($cashData); ?>
            }],
            xaxis: {
                categories: <?php echo json_encode($formattedDates); ?>
            },
            stroke: {
                curve: 'smooth'
            },
            yaxis: {
                title: {
                    text: '<?php echo $translations["incomemoney"]; ?> (<?php echo $currency; ?>)'
                }
            },
            legend: {
                position: 'bottom'
            }
        };

        var chart = new ApexCharts(document.querySelector("#moneyincomechart"), options);
        chart.render();
    </script>

    <script>
        // Ingresos por tipo (series generadas en servidor)
        var incomeSeries = <?php echo $incomeSeriesJson ?? '[]'; ?>;
        var incomeLabels = <?php echo $chartLabelsJson ?? '[]'; ?>;

        var incomeOptions = {
            chart: {
                type: 'area',
                stacked: true,
                toolbar: { show: false },
            },
            series: incomeSeries,
            xaxis: { categories: incomeLabels },
            yaxis: { labels: { formatter: function(v){ return v.toFixed(2); } } },
            legend: { position: 'bottom' },
            tooltip: { y: { formatter: function(val){ return val.toFixed(2) + ' <?php echo $currency; ?>'; } } }
        };

        var incomeChart = new ApexCharts(document.querySelector("#incomeByTypeChart"), incomeOptions);
        incomeChart.render();

        // Productos vendidos por día (top 10)
        var productSeries = <?php echo $productSeriesJson ?? '[]'; ?>;
        var productLabels = <?php echo $chartLabelsJson ?? '[]'; ?>;

        var productOptions = {
            chart: { type: 'bar', stacked: true, height: 360 },
            plotOptions: { bar: { horizontal: false, columnWidth: '60%' } },
            series: productSeries,
            xaxis: { categories: productLabels },
            legend: { position: 'bottom' },
            tooltip: { y: { formatter: function(val){ return val + ' unidades'; } } }
        };

        var productChart = new ApexCharts(document.querySelector("#productSalesChart"), productOptions);
        productChart.render();

        // Gráfico de Asistencia Diaria
        var attendanceOptions = {
            chart: { type: 'bar', height: 350, toolbar: { show: false }, fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif' },
            series: [{ name: 'Asistentes', data: <?php echo json_encode($dailyAttendanceData); ?> }],
            xaxis: { categories: <?php echo json_encode($formattedDates); ?> },
            yaxis: { labels: { formatter: function(val) { return Math.floor(val); } } },
            plotOptions: { bar: { columnWidth: '50%' } },
            tooltip: { y: { formatter: function (val) { return val + " personas" } } },
            colors: ['#008FFB']
        };
        new ApexCharts(document.querySelector("#dailyAttendanceChart"), attendanceOptions).render();

        // Horas Pico
        var peakOptions = {
            chart: { type: 'area', height: 350, toolbar: { show: false }, fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif' },
            series: [{ name: 'Visitas', data: <?php echo json_encode(array_values($peakHoursData)); ?> }],
            xaxis: { categories: <?php echo json_encode($peakHoursLabels); ?> },
            colors: ['#00E396'],
            stroke: { curve: 'smooth' }
        };
        new ApexCharts(document.querySelector("#peakHoursChart"), peakOptions).render();

        // Popularidad de pases
        var ticketPopOptions = {
            chart: { type: 'donut', height: 350, fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif' },
            series: <?php echo json_encode($ticketPopData); ?>,
            labels: <?php echo json_encode($ticketPopLabels); ?>,
            dataLabels: { enabled: true },
            legend: { position: 'bottom' },
            noData: { text: 'Sin datos disponibles' }
        };
        if(ticketPopOptions.series.length > 0) {
            new ApexCharts(document.querySelector("#ticketPopularityChart"), ticketPopOptions).render();
        } else {
            document.querySelector("#ticketPopularityChart").innerHTML = '<p class="text-center text-muted" style="line-height:300px;">No hay pases activos</p>';
        }

        // Edades
        var ageOptions = {
            chart: { type: 'bar', height: 350, toolbar: { show: false }, fontFamily: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif' },
            series: [{ name: 'Usuarios', data: <?php echo json_encode(array_values($ageBuckets)); ?> }],
            xaxis: { categories: <?php echo json_encode(array_keys($ageBuckets)); ?> },
            colors: ['#775DD0'],
            plotOptions: { bar: { horizontal: false, columnWidth: '50%' } }
        };
        new ApexCharts(document.querySelector("#ageDemographicsChart"), ageOptions).render();
    </script>
    <script src="../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js" integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa" crossorigin="anonymous"></script>
</body>

</html>