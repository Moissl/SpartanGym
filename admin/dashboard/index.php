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

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$capacity = $env_data["CAPACITY"] ?? '';
$daily_entry_price = $env_data['DAILY_ENTRY_PRICE'] ?? '25.00';
$currency = $env_data["CURRENCY"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("El archivo de idioma no se encuentra: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
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

// Limpieza automática de usuarios del día anterior (Reset diario)
$conn->query("DELETE FROM temp_loggeduser WHERE login_date < CURDATE()");

// Inicializar variables
$message = '';

$sqlUserCount = "SELECT COUNT(*) as count FROM users";
$resultUserCount = $conn->query($sqlUserCount);

$userCount = 0;

if ($resultUserCount->num_rows > 0) {
    $row = $resultUserCount->fetch_assoc();
    $userCount = $row["count"];
}

// Obtener pases para el registro de visitas (Guest Entry)
$guest_tickets = [];
$sqlTickets = "SELECT id, name, price FROM tickets WHERE hidden = 0 ORDER BY price ASC";
$resultTickets = $conn->query($sqlTickets);
if ($resultTickets && $resultTickets->num_rows > 0) {
    while ($row = $resultTickets->fetch_assoc()) {
        $guest_tickets[] = $row;
    }
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

// Verificar estado de asistencia del trabajador (si es trabajador)
$worker_status = 'unknown';
if ($is_boss !== null) { // Si existe en la tabla workers
    $today_date = date('Y-m-d');
    $sqlTC = "SELECT id FROM worker_timeclock WHERE worker_id = ? AND DATE(checkin_time) = ? AND checkout_time IS NULL LIMIT 1";
    $stmtTC = $conn->prepare($sqlTC);
    $stmtTC->bind_param("is", $userid, $today_date);
    $stmtTC->execute();
    $stmtTC->store_result();
    $worker_status = ($stmtTC->num_rows > 0) ? 'clocked_in' : 'clocked_out';
    $stmtTC->close();
}

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
// SUM DAILY USERS

$stats_date = date('Y-m-d');
$sql = "SELECT COUNT(*) AS total_people FROM workout_stats WHERE workout_date = '$stats_date'";

$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
}

// SUM DAILY INCOME
$stats_date_start = date('Y-m-d') . ' 00:00:00';
$stats_date_end = date('Y-m-d') . ' 23:59:59';
$sqlIncome = "SELECT SUM(price) as total_income FROM invoices WHERE CONVERT_TZ(created_at, @@global.time_zone, @@session.time_zone) BETWEEN '$stats_date_start' AND '$stats_date_end'";
$resultIncome = $conn->query($sqlIncome);
$dailyIncome = 0;
if ($resultIncome && $resultIncome->num_rows > 0) {
    $rowIncome = $resultIncome->fetch_assoc();
    $dailyIncome = $rowIncome['total_income'] ?? 0;
}
// SUM DAILY USERS !!!!END!!!!

// TEMP USERS TABLE!!!

$sql = "SELECT t.name, t.userid, t.login_date, t.people_count, l.lockernum, 
        (SELECT ticketname FROM current_tickets WHERE userid = t.userid AND expiredate >= CURDATE() ORDER BY expiredate ASC LIMIT 1) as ticket_name,
        u.birthdate
        FROM temp_loggeduser t
        LEFT JOIN lockers l ON t.lockerid = l.id
        LEFT JOIN users u ON t.userid = u.userid";
$result = $conn->query($sql);

$sql_count = "SELECT COALESCE(SUM(people_count), COUNT(*)) AS total_count FROM temp_loggeduser";
$result_count = $conn->query($sql_count);

if ($result_count) {
    $row_count = $result_count->fetch_assoc();
    $total_count = $row_count['total_count'];

    if ($capacity > 0) {
        $capacityPercent = ($total_count / $capacity) * 100;
    } else {
        $capacityPercent = 0;
    }
}
$progresscolor = '';

if ($capacityPercent >= 0 && $capacityPercent < 70) {
    $progresscolor = 'success';
} elseif ($capacityPercent >= 70 && $capacityPercent < 90) {
    $progresscolor = 'warning';
} elseif ($capacityPercent >= 90) {
    $progresscolor = 'danger';
}

$sql = "SELECT lastname FROM workers WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $userid);
$stmt->execute();
$stmt->bind_result($username);
$stmt->fetch();
$stmt->close();

// Números de emergencia (DESHABILITADO)
/*
$ipInfoUrl = 'https://ipinfo.io/json';

$ipInfo = json_decode(file_get_contents($ipInfoUrl), true);
$countryCode = $ipInfo['country'];

$jsonFile = 'https://emergencynumberapi.com/api/data/all';

$jsonData = @file_get_contents($jsonFile);
if (!$jsonData) {
    exit;
}

$data = json_decode($jsonData, true);
if (!$data) {
    exit;
}

$ambulanceNumbers = $translations["México"];
$fireNumbers = $translations["México"];
$policeNumbers = $translations["México"];

foreach ($data as $item) {
    if (isset($item['Country']['ISOCode']) && $item['Country']['ISOCode'] == $countryCode) {
        $ambulanceNumbers = isset($item['Ambulance']['All']) ? implode(', ', $item['Ambulance']['All']) : "Desconocido";
        $fireNumbers = isset($item['Fire']['All']) ? implode(', ', $item['Fire']['All']) : "Desconocido";
        $policeNumbers = isset($item['Police']['All']) ? implode(', ', $item['Police']['All']) : "Desconocido";
        break;
    }
}
*/

?>




<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $translations["dashboard"]; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo time(); ?>">
    <script src="https://unpkg.com/@zxing/library@latest"></script>

    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
</head>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
    #video-container {
        position: relative;
        width: 100%;
        height: 300px;
    }

    #video {
        width: 100%;
        height: 100%;
    }

    #video.scanned {
        filter: brightness(0.5) sepia(100%);
    }

    #video.error {
        filter: brightness(0.5) contrast(1.5) sepia(1) hue-rotate(-50deg);
    }

    #checkmark,
    #error {
        display: none;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 4em;
    }

    #checkmark {
        color: green;
    }

    #error {
        color: red;
    }
</style>

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
                    <li class="active"><a href="#"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
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
                    <li><a href="https://gymoneglobal.com/discord" target="_blank"><i class="bi bi-question-circle"></i> <?php echo $translations["support"]; ?></a></li>
                    <li><a href="https://gymoneglobal.com/docs" target="_blank"><i class="bi bi-journals"></i> <?php echo $translations["docs"]; ?></a></li>
                    <li><a href="#" data-toggle="modal" data-target="#logoutModal"><i class="bi bi-box-arrow-right"></i> <?php echo $translations["logout"]; ?></a></li>
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
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../users">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../statistics">
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
            <div class="col-sm-10">
                <div class="hidden-xs topnav">
                    <a href="https://gymoneglobal.com/discord" class="btn btn-primary mx-1" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-question-circle"></i>
                        <?php echo $translations["support"]; ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo $translations["docs"]; ?>
                    </a>
                    <?php if ($worker_status === 'clocked_in'): ?>
                        <button type="button" class="btn btn-danger mx-1" onclick="confirmCheckout()">
                            <i class="bi bi-box-arrow-right"></i>
                            Finalizar Turno (Salida)
                        </button>
                    <?php else: ?>
                        <a href="../boss/workers/timeclock.php" class="btn btn-success mx-1">
                            <i class="bi bi-clock-history"></i>
                            Control de Asistencia
                        </a>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo $translations["logout"]; ?>
                    </button>
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
                        #if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
                        #    echo '<div id="notHttpsAlert" class="alert alert-warning shadow-sm" role="alert">';
                        #    echo '<i class="bi bi-exclamation-triangle"></i> ' . $translations['notusehttps'];
                        #    echo '</div>';
                        #}
                        ?>
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
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-0 fw-semibold"><?php echo $translations["incomemoney"]; ?> (Hoy)</h5>
                                <h1><strong id="stat-income"><?php echo number_format($dailyIncome, 2) . ' ' . $currency; ?></strong></h1>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-0 fw-semibold"><?php echo $translations["dailyusers"]; ?></h5>
                                <h1><strong id="stat-daily-users"><?php echo $row["total_people"]; ?></strong></h1>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-0 fw-semibold"><?php echo $translations["users"]; ?></h5>
                                <h1><strong id="stat-total-users"><?php echo $userCount; ?></strong></h1>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title mb-0 fw-semibold"><?php echo $translations["userlogginer"]; ?></h5>
                                <div class="text-center">
                                    <a data-toggle="modal" data-target="#Logginer_MODAL" class="btn mt-3 btn-success">
                                        <h4><?= $translations["logginer"]; ?></h4>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-body">
                                <p><?php echo $translations["dayopendayclose"]; ?></p>
                                <div class="d-flex justify-content-between text-center">
                                    <!-- <?php if ($message): ?>
                                        <div class="alert alert-info" role="alert">
                                            <?php echo $message; ?>
                                        </div>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-success mt-3" data-toggle="modal" data-target="#openModal">
                                        <?php echo $translations["dayopen"]; ?>
                                    </button>
                                    <a href="" class="btn btn-danger"><?php echo $translations["dayclose"]; ?></a> -->
                                </div>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                <table class="table table-dark table-bordered text-center">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th><?php echo $translations["profileimg"] ?? 'Foto'; ?></th>
                                            <th><?php echo $translations["fullname"]; ?></th>
                                            <th><?php echo $translations["lockernum"] ?? 'Casillero'; ?></th>
                                            <th><?php echo $translations["ticketspassname"] ?? 'Pase'; ?></th>
                                            <th><?php echo $translations["buytime"]; ?></th>
                                            <th><?php echo $translations["remaining_time"]; ?></th>
                                            <th>Cumpleaños</th>
                                            <th><?php echo $translations["logintime"]; ?></th>
                                            <th><?php echo $translations["userlogout"]; ?></th>
                                            <th><?php echo $translations["editbtn"]; ?></th>
                                            <th><?php echo $translations["sellpage"]; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody id="activeUsersTableBody">
                                        <?php
                                        if ($result->num_rows > 0) {
                                            $counter = 1;
                                            while ($row = $result->fetch_assoc()) {
                                                // Obtener conteo de personas si existe la columna, sino 1
                                                $people_count = 1;
                                                if (isset($row['people_count']) && $row['people_count'] > 1) {
                                                    $people_count = $row['people_count'];
                                                }

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

                                                echo "<tr>";
                                                echo "<td>" . $counter . "</td>";
                                                echo "<td style='vertical-align: middle;'>" . $imgDisplay . "</td>";
                                                if ($people_count > 1) {
                                                    echo "<td>" . $row["name"] . " <span class='badge badge-info'>+" . ($people_count - 1) . "</span></td>";
                                                } else {
                                                    echo "<td>" . $row["name"] . "</td>";
                                                }
                                                echo "<td>" . ($row['lockernum'] ?? 'N/A') . "</td>";

                                                // Logic from users/index.php
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
                                                    $ticketname = $ticket_data["ticketname"];
                                                    $buydate = $ticket_data["buydate"];
                                                    $expire_days = $ticket_data["expire_days"];

                                                    $ticket_display = $ticketname;

                                                    if ($expiredate && strpos($expiredate, '9999-12-31') === 0) {
                                                        $buydate_display = $buydate;
                                                        $remaining_display = "<span class='text-success'>" . $translations["unlimited"] . "</span>";
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
                                                                $remaining_display = "<span class='text-danger'>" . $translations["expired"] . " (" . $originalExpire->format("Y-m-d") . ")</span>";
                                                                $ticket_display = $translations["youdonthaveticket"] ?? "No tienes pase";
                                                            }
                                                        } catch (Exception $e) {
                                                            $buydate_display = $buydate;
                                                            $remaining_display = "<span class='text-danger'>Error</span>";
                                                        }
                                                    }
                                                }

                                                $is_birthday = false;
                                                if (!empty($row['birthdate'])) {
                                                    $birthDateMonthDay = date('m-d', strtotime($row['birthdate']));
                                                    $todayMonthDay = date('m-d');
                                                    if ($birthDateMonthDay === $todayMonthDay) {
                                                        $is_birthday = true;
                                                    }
                                                }
                                                $birthday_display = $is_birthday ? "<span class='badge badge-warning' style='background-color:#ffc107;color:#000;'><i class='bi bi-gift'></i> ¡Es su cumpleaños!</span>" : "<span class='text-muted'>Aún no</span>";

                                                echo "<td>" . $ticket_display . "</td>";
                                                echo "<td>" . $buydate_display . "</td>";
                                                echo "<td>" . $remaining_display . "</td>";
                                                echo "<td>" . $birthday_display . "</td>";
                                                echo "<td class='timer-cell' data-seconds='" . $seconds . "'>" . $elapsed_time . "</td>";
                                                echo '<td><a class="btn btn-danger" href="logout.php?user=' . $row["userid"] . '">' . $translations["userlogout"] . '</a></td>';
                                                echo '<td><a class= "btn btn-secondary" href="../users/edit/?user=' . $row["userid"] . '">' . $translations["editbtn"] . '</a></td>';
                                                echo '<td><a class="btn btn-success" href="../boss/sell/ticket/?userid=' . $row["userid"] . '"><i class="bi bi-cart-plus"></i> ' . $translations["sellpage"] . '</a></td>';
                                                echo "</tr>";

                                                $counter++;
                                            }
                                        } else {
                                            echo "<tr><td colspan='12'>" . $translations["noonetraining"] . "</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row justify-content-between text-center">
                    <div class="col-sm-2">
                        <div class="card">
                            <p><?= $translations["capacitytext"]; ?></p>
                            <div class="card-body">
                                <div class="progress">
                                    <div id="stat-capacity-bar" class="progress-bar-<?php echo $progresscolor; ?>" role="progressbar" style="width: <?php echo number_format($capacityPercent, 2); ?>%;" aria-valuenow="<?php echo number_format($capacityPercent, 2); ?>" aria-valuemin="0" aria-valuemax="100"><?php echo number_format($capacityPercent, 0); ?>%</div>
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
                <form action="export_attendance.php" method="GET" class="no-loader">
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

    <!-- WELCOME MODAL -->

    <div class="modal fade" id="welcomeModal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-body">
                    <div class="row text-center">
                        <div class="col">
                            <img src="../../assets/img/brand/logo.png" width="50%" class="img img-fluid" alt="Logo">
                            <h1 id="modalMessage"></h1>
                            <p class="lead"><?php echo $translations["haveagoodday"]; ?></p>
                        </div>
                    </div>
                    <div class="footer text-center">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["next"]; ?></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- BELÉPTETŐ MODAL -->
    <div class="modal fade" id="Logginer_MODAL" tabindex="-1" role="dialog" aria-labelledby="LogginerModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="LogginerModalLabel"><?php echo $translations["userlogginer"]; ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="row text-center">
                        <div class="col-12">
                            <div id="video-container">
                                <video id="video" autoplay></video>
                                <div id="checkmark">✔</div>
                                <div id="error">✘</div>
                            </div>
                            <p id="result"><?php echo $translations["qrscann"]; ?></p>
                        </div>
                        <h1><?php echo $translations["or"]; ?></h1>
                        <form class="form-inline my-2 my-lg-0">
                            <input id="search" class="form-control mr-sm-2" type="search" placeholder="<?php echo $translations["name-search"]; ?> " aria-label="Search">
                        </form>
                        <div id="results" class="mt-4"></div>

                        <hr>
                        <a href="user_access.php" target="_blank" class="btn btn-primary btn-block btn-lg">
                            <i class="bi bi-window-fullscreen"></i> Abrir Vista de Acceso (Miembros)
                        </a>
                        
                        <hr>
                        <!-- Botón para mostrar formulario de visita -->
                        <button class="btn btn-info btn-block" id="btnToggleGuest" onclick="toggleGuestForm()">
                            <i class="bi bi-person-plus-fill"></i> Registro Manual / Visita
                        </button>

                        <!-- Formulario de Visita (Oculto por defecto) -->
                        <div id="guest-entry-form" style="display:none; text-align: left; margin-top: 15px; background: #333; padding: 15px; border-radius: 5px;">
                            <h4 class="text-center text-white mb-3">Registro Rápido</h4>
                            <form id="formGuestEntry">
                                <div class="form-group">
                                    <label class="text-white">Nombre Completo</label>
                                    <input type="text" class="form-control" id="guest_name" required placeholder="Ej: Juan Pérez">
                                </div>
                                <div class="form-group">
                                    <label class="text-white">Género</label>
                                    <select class="form-control" id="guest_gender" required>
                                        <option value="Male">Masculino</option>
                                        <option value="Female">Femenino</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="text-white">Fecha de Nacimiento</label>
                                    <input type="date" class="form-control" id="guest_birthdate" required>
                                </div>
                                <div class="form-group">
                                    <label class="text-white">Teléfono</label>
                                    <input type="tel" class="form-control" id="guest_phone" placeholder="Ej: 2281234567">
                                </div>
                                <div class="form-group">
                                    <label class="text-white">Pase / Ticket</label>
                                    <select class="form-control" id="guest_ticket" required>
                                        <?php foreach($guest_tickets as $gt): ?>
                                            <option value="<?= $gt['id'] ?>"><?= $gt['name'] ?> - <?= $gt['price'] ?> <?= $currency ?? '' ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-success btn-block">Registrar Entrada y Cobrar</button>
                                <button type="button" class="btn btn-secondary btn-block" onclick="toggleGuestForm()">Cancelar</button>
                            </form>
                        </div>

                        <input hidden id="qrcodeContent">
                    </div>
                </div>
                <div class="modal-footer">
                    <a type="button" id="continueButton" class="btn btn-primary" style="display: none;"><?php echo $translations["next"]; ?></a>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["close"]; ?></button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="UserDetails_MODAL" tabindex="-1" role="dialog" aria-labelledby="userDetailsLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userDetailsLabel"><?= $translations["userinfo"]; ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="<?php echo $translations["close"]; ?>">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="userDetails"></div>
                </div>
                <div class="modal-footer">
                    <button id="nextButton" class="btn btn-primary"><?php echo $translations["next"]; ?></button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["close"]; ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Ticket Details Modal -->
    <div class="modal fade" id="TicketDetails_MODAL" tabindex="-1" role="dialog" aria-labelledby="TicketDetailsModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="TicketDetailsModalLabel"><?php echo $translations["ticketinfomodal"]; ?></h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body" id="ticketDetails"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal" onclick="window.location.reload();">
                        <?php echo $translations["close"]; ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL OBLIGATORIO DE ASISTENCIA (WORKER CHECK-IN) -->
    <div class="modal fade" id="WorkerTimeclockModal" tabindex="-1" role="dialog" aria-labelledby="wtmLabel" aria-hidden="true" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h4 class="modal-title" id="wtmLabel"><i class="bi bi-clock"></i> Registro de Entrada Obligatorio</h4>
                </div>
                <div class="modal-body text-center">
                    <p class="lead">Hola <strong><?php echo $username; ?></strong>. Registra tu entrada para acceder.</p>
                    
                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs" role="tablist" style="margin-bottom: 15px;">
                        <li role="presentation" class="active"><a href="#qr-tab" aria-controls="qr-tab" role="tab" data-toggle="tab"><i class="bi bi-qr-code"></i> Escanear QR</a></li>
                        <li role="presentation"><a href="#manual-tab" aria-controls="manual-tab" role="tab" data-toggle="tab"><i class="bi bi-keyboard"></i> Entrada Manual</a></li>
                    </ul>

                    <div class="tab-content">
                        <div role="tabpanel" class="tab-pane active" id="qr-tab">
                            <div id="worker-video-container" style="position: relative; width: 100%; height: 400px; margin: 0 auto;">
                                <video id="workerTimeclockVideo" style="width: 100%; height: 100%; object-fit: cover;"></video>
                            </div>
                            <div id="workerTimeclockError" style="display: none; color: red; margin-top: 20px;">
                                <h4><i class="bi bi-x-circle"></i> Error al detectar QR</h4>
                            </div>
                        </div>
                        <div role="tabpanel" class="tab-pane" id="manual-tab">
                            <div style="padding: 20px;">
                                <div class="form-group">
                                    <label for="manualWorkerID" class="h4">Ingrese su ID de Empleado:</label>
                                    <input type="number" id="manualWorkerID" class="form-control input-lg text-center" placeholder="Ej: 123456" style="font-size: 24px;">
                                </div>
                                <button type="button" class="btn btn-primary btn-lg btn-block" onclick="submitManualCheckin()">
                                    <i class="bi bi-check-circle"></i> Registrar Entrada
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div id="workerTimeclockSuccess" style="display: none; color: green; margin-top: 20px;">
                        <h3><i class="bi bi-check-circle"></i> ¡Entrada Registrada!</h3>
                        <p>Redirigiendo...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <!-- No close button to force check-in -->
                    <a href="../logout.php" class="btn btn-default">Cancelar y Salir</a>
                </div>
            </div>
        </div>
    </div>

    <!-- DAYOPEN MODAL -->

    <div class="modal fade" id="openModal" tabindex="-1" role="dialog" aria-labelledby="openModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="openModalLabel">Abrir un cajero</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="opening_amount">Monto inicial</label>
                            <input type="number" name="opening_amount" step="0.01" class="form-control" required placeholder="Monto inicial">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" name="open" class="btn btn-primary">Apertura</button>
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
                    <p class="lead"><?php echo $translations["exit-modal"]; ?></p>
                </div>
                <div class="modal-footer">
                    <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                    <a href="../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="spinner"></div>
        <h4 style="margin-top: 20px; color: #333;">Cargando...</h4>
    </div>

    <!-- SCRIPTS! -->
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <script>
        // Lógica para el Control de Asistencia Obligatorio
        var workerStatus = '<?php echo $worker_status; ?>';
        var loggedInUserId = '<?php echo $userid; ?>';
        var workerCodeReader = null;
        var persistentCodeReader = null;
        var globalTranslations = <?php echo json_encode($translations); ?>;

        // --- SISTEMA DE NOTIFICACIONES EN TIEMPO REAL ---
        var notificationsCheckInterval = null;
        var lastNotificationIds = [];
        var isFirstCheck = true;
        var hasPriorSession = false;

        try {
            var storedIds = sessionStorage.getItem('gymone_notif_ids');
            if (storedIds) {
                lastNotificationIds = JSON.parse(storedIds);
                hasPriorSession = true;
            }
        } catch (e) {}

        function checkNotifications() {
            $.ajax({
                url: 'check_notifications.php?peek=1',
                dataType: 'json',
                cache: false,
                timeout: 8000,
                success: function(data) {
                    if (data && data.success && data.notifications && data.notifications.length > 0) {
                        var changed = false;
                        // Mostrar solo las nuevas notificaciones (dedupe por id)
                        data.notifications.forEach(function(notif) {
                            if (!lastNotificationIds.includes(notif.id)) {
                                lastNotificationIds.push(notif.id);
                                changed = true;
                                // Limitar el array para que no crezca indefinidamente
                                if (lastNotificationIds.length > 200) lastNotificationIds.shift();
                                
                                // Solo mostrar si no es la primera carga limpia de la pestaña
                                // Si ya había una sesión, significa que fuimos a otra página y regresamos
                                if (!isFirstCheck || hasPriorSession) {
                                    showNotification(notif);
                                }
                            }
                        });
                        
                        if (changed) {
                            try {
                                sessionStorage.setItem('gymone_notif_ids', JSON.stringify(lastNotificationIds));
                            } catch(e) {}
                        }
                    }
                    isFirstCheck = false;
                },
                error: function(xhr, status, error) {
                    // Silenciar errores - es solo un sistema auxiliar
                }
            });
        }

        function showNotification(notif) {
            // Reproducir sonido
            playSystemSound(notif.type === 'success' || notif.type === 'exit' ? 'success' : 'error');

            // Crear contenedor de notificaciones si no existe para evitar superposición
            if ($('#notification-container').length === 0) {
                $('body').append('<div id="notification-container" style="position: fixed; top: 80px; right: 20px; z-index: 9999; width: 350px; display: flex; flex-direction: column; gap: 10px; pointer-events: none;"></div>');
            }

            // Determinar colores y iconos según el tipo
            var alertClass = '';
            var icon = '';
            var bgColor = '';
            var borderColor = '';
            
            if (notif.type === 'exit') {
                alertClass = 'alert-info';
                icon = '<i class="bi bi-box-arrow-right" style="color:#17a2b8; font-size: 18px;"></i>';
                bgColor = '#d1ecf1';
                borderColor = '#bee5eb';
            } else if (notif.type === 'success') {
                alertClass = 'alert-success';
                icon = '<i class="bi bi-check-circle-fill" style="color:#28a745; font-size: 18px;"></i>';
                bgColor = '#d4edda';
                borderColor = '#c3e6cb';
            } else {
                alertClass = 'alert-warning';
                icon = '<i class="bi bi-exclamation-triangle-fill" style="color:#856404; font-size: 18px;"></i>';
                bgColor = '#fff3cd';
                borderColor = '#ffeaa7';
            }
            
            var actionBtn = '';
            if (notif.type === 'error' && notif.userid) {
                // Botón para asignar pase si hay error
                actionBtn = `<div class="mt-3"><a href="../boss/sell/ticket/?userid=${notif.userid}" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> Asignar Pase</a></div>`;
            }

            var timestamp = notif.created_at_formatted || 'Ahora';
            
            var html = `
                <div class="alert ${alertClass} alert-dismissible" role="alert" 
                     style="pointer-events: auto; min-width: 100%; box-shadow: 0 8px 24px rgba(0,0,0,0.25); background-color: ${bgColor}; border: 1px solid ${borderColor};
                            border-radius: 8px; padding: 16px; animation: slideInRight 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);">
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close" style="position: absolute; right: 12px; top: 8px; opacity: 0.8;">
                        <span aria-hidden="true" style="font-size: 24px; line-height: 1;">&times;</span>
                    </button>
                    <div style="padding-right: 30px;">
                        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                            ${icon}
                            <strong style="font-size: 15px; margin: 0;">${notif.user_name}</strong>
                        </div>
                        <div style="font-size: 14px; margin-bottom: 6px; color: #333; line-height: 1.4;">
                            ${notif.message}
                        </div>
                        <small style="color: #666; font-size: 12px;">
                            <i class="bi bi-clock-history"></i> ${timestamp}
                        </small>
                        ${actionBtn}
                    </div>
                </div>
            `;
            $('#notification-container').append(html);
            
            // Auto-cerrar después de 8 segundos
            var alertElement = $('#notification-container').children().last();
            setTimeout(function() {
                alertElement.fadeOut(400, function() {
                    $(this).remove();
                });
            }, 8000);
        }

        // Agregar estilos de animación
        $('<style>').text(`
            @keyframes slideInRight {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `).appendTo('head');

        // Iniciar sistema de notificaciones al cargar
        $(document).ready(function() {
            // Iniciar polling periódico (cada segundo) y ejecutar inmediatamente
            notificationsCheckInterval = setInterval(checkNotifications, 1000);
            checkNotifications();
        });

        // Limpiar interval al salir
        $(window).on('beforeunload', function() {
            if (notificationsCheckInterval) {
                clearInterval(notificationsCheckInterval);
            }
        });

        // ------------------------------------------------

        $(document).ready(function() {
            // Si el usuario es trabajador y NO ha marcado entrada hoy, mostrar modal
            if (workerStatus === 'clocked_out') {
                $('#WorkerTimeclockModal').modal('show');
            }
            
            // Iniciar cámara persistente si no hay modales abiertos
            // startPersistentScanning(); // DESHABILITADO: Se movió a user_access.php
        });

        $('#WorkerTimeclockModal').on('shown.bs.modal', function() {
            startWorkerScanning();
        });

        $('#WorkerTimeclockModal').on('hidden.bs.modal', function() {
            stopWorkerScanning();
        });

        // Controlar cámara al cambiar de pestaña
        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            var target = $(e.target).attr("href");
            if (target === '#qr-tab') {
                startWorkerScanning();
            } else {
                stopWorkerScanning();
            }
        });

        function startWorkerScanning() {
            const video = document.getElementById('workerTimeclockVideo');
            if (!video) return;

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(stream => {
                    video.srcObject = stream;
                    video.play();
                    workerCodeReader = new ZXing.BrowserMultiFormatReader();
                    scanWorkerQR();
                })
                .catch(err => {
                    console.error('Error accessing camera:', err);
                    alert('No se pudo acceder a la cámara. Verifique los permisos.');
                });
        }

        function scanWorkerQR() {
            const video = document.getElementById('workerTimeclockVideo');
            if (!video || !workerCodeReader) return;

            workerCodeReader.decodeFromVideoElement(video)
                .then(result => {
                    const scannedCode = result.text;
                    stopWorkerScanning(); // Detener escaneo inmediatamente para evitar bucles
                    // Opcional: Verificar que el QR escaneado coincida con el usuario logueado
                    // if (scannedCode != loggedInUserId) { alert("El QR no corresponde al usuario logueado."); setTimeout(scanWorkerQR, 1000); return; }
                    
                    document.getElementById('workerTimeclockSuccess').style.display = 'block';
                    processWorkerTimeclock(scannedCode, 'checkin');
                })
                .catch((err) => {
                    // Continuar escaneando si no detecta nada
                    setTimeout(scanWorkerQR, 300);
                });
        }

        function stopWorkerScanning() {
            const video = document.getElementById('workerTimeclockVideo');
            if (video && video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
            if (workerCodeReader) {
                workerCodeReader.reset();
            }
        }

        function processWorkerTimeclock(workerId, type) {
            $.ajax({
                url: '../boss/workers/process_timeclock.php?action=toggle_timeclock',
                type: 'POST',
                data: { worker_id: workerId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (type === 'checkout') {
                            alert('✔ Turno finalizado. Cerrando sesión...');
                            window.location.href = '../logout.php';
                        } else {
                            // Check-in exitoso
                            setTimeout(() => {
                                location.reload();
                            }, 1000);
                        }
                    } else {
                        alert('Error: ' + response.message);
                        if(type === 'checkin') {
                            document.getElementById('workerTimeclockSuccess').style.display = 'none';
                            // Reiniciar escaneo si hubo error
                            startWorkerScanning();
                        }
                    }
                },
                error: function() {
                    alert('Error de conexión al procesar el registro');
                }
            });
        }

        function submitManualCheckin() {
            var manualID = $('#manualWorkerID').val();
            if(manualID) processWorkerTimeclock(manualID, 'checkin');
        }

        function confirmCheckout() {
            if (confirm("¿Estás seguro de que deseas finalizar tu turno? Esto registrará tu salida y cerrará la sesión.")) {
                // Usamos el ID del usuario logueado directamente para la salida
                processWorkerTimeclock(loggedInUserId, 'checkout');
            }
        }

        // Generador de sonidos simple (Beep)
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function playSystemSound(type) {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            
            if (type === 'success') {
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(880, audioCtx.currentTime); 
                oscillator.frequency.exponentialRampToValueAtTime(440, audioCtx.currentTime + 0.1);
            } else {
                oscillator.type = 'sawtooth';
                oscillator.frequency.setValueAtTime(150, audioCtx.currentTime); 
                oscillator.frequency.linearRampToValueAtTime(100, audioCtx.currentTime + 0.3);
            }
            
            gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
            oscillator.start();
            oscillator.stop(audioCtx.currentTime + 0.3);
        }

        $(document).ready(function() {
            $(document).on("click", ".loginUser", function(e) {
                e.preventDefault();

                const userId = $(this).data("userid");

                fetch('process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `qrcode=${encodeURIComponent(userId)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Actualizar notificaciones inmediatamente
                        if (typeof checkNotifications === 'function') {
                            checkNotifications();
                        }

                        if (data.success) {
                            const isValid = (data.ticket_status === translations.valid || data.ticket_status === 'Válido');
                            const isExit = (data.action === 'exit');

                            if (isExit || isValid) {
                                // Si es salida o entrada válida, cerrar modal y listo (la notificación avisa)
                                $('#Logginer_MODAL').modal('hide');
                            } else {
                                // Si no tiene pase o está vencido, redirigir a venta
                                window.location.href = `../boss/sell/ticket/?userid=${userId}`;
                            }
                        } else {
                            alert(data.error || translations['qr-error']);
                        }
                    })
                    .catch(err => {
                        console.error('Manual login error:', err);
                        resultElement.textContent = translations['qr-error'];
                        video.classList.add('error');
                        error.style.display = 'block';
                        checkmark.style.display = 'none';
                        continueButton.style.display = 'none';
                    });
            });


            $("#search").on("input", function() {
                var query = $(this).val();
                if (query.length > 2) {
                    $.ajax({
                        url: 'search.php',
                        method: 'POST',
                        data: {
                            search: query
                        },
                        success: function(data) {
                            $("#results").html(data);
                        }
                    });
                } else {
                    $("#results").html('');
                }
            });

            const codeReader = new ZXing.BrowserQRCodeReader();
            const video = document.getElementById('video');
            const resultElement = document.getElementById('result');
            const checkmark = document.getElementById('checkmark');
            const error = document.getElementById('error');
            const qrCodeContent = document.getElementById('qrcodeContent');
            const continueButton = document.getElementById('continueButton');
            const userDetails = document.getElementById('userDetails');
            const nextButton = document.getElementById('nextButton');

            let isDashboardProcessing = false;
            let scanCompleted = false;
            let scanning = false;

            let userData = {};

            var translations = <?php echo json_encode($translations); ?>;
            var dailyPrice = '<?php echo $daily_entry_price; ?>';

            // Función unificada para procesar el código (Cámara o Lector USB)
            function processQrCode(qrCodeText) {
                if (isDashboardProcessing) return;
                isDashboardProcessing = true;
                setTimeout(function() { isDashboardProcessing = false; }, 3000);

                scanCompleted = true; // Detener escaneo de cámara si estaba activo
                // resultElement.textContent = `QR Code Result: ${qrCodeText}`;
                // qrCodeContent.value = qrCodeText;
                // continueButton.style.display = 'inline';

                // Asegurar que el modal esté abierto para mostrar el resultado
                // $('#Logginer_MODAL').modal('show');

                fetch('process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `qrcode=${encodeURIComponent(qrCodeText)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Actualizar notificaciones inmediatamente para feedback visual
                        if (typeof checkNotifications === 'function') {
                            checkNotifications();
                        }

                        // Si el modal de escaneo estaba abierto (cámara), lo cerramos para ver la notificación
                        if ($('#Logginer_MODAL').hasClass('in')) {
                             $('#Logginer_MODAL').modal('hide');
                             stopScanning();
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        if (isModalOpen) {
                            resultElement.textContent = translations['qr-error'];
                            video.classList.add('error');
                            error.style.display = 'block';
                        }
                    });
            }

            function startScanning() {
                if (scanCompleted || scanning) return;

                scanning = true;
                codeReader.decodeFromVideoDevice(null, video, (result, error) => {
                    if (result && !scanCompleted) {
                        scanCompleted = true;
                        const qrCodeText = result.text;
                        processQrCode(qrCodeText);
                    }
                    if (error && !scanCompleted) {
                        console.error(error);
                    }
                }).catch(error => console.error(error));
            }

            function stopScanning() {
                if (scanning) {
                    codeReader.reset();
                    video.srcObject = null;
                    scanning = false;
                    scanCompleted = false;
                    resultElement.textContent = '';
                    checkmark.style.display = 'none';
                    error.style.display = 'none';
                    video.classList.remove('scanned', 'error');
                    continueButton.style.display = 'none';
                }
            }

            // Listener para lector de código de barras (Teclado USB)
            let dashboardScannerBuffer = '';
            let dashboardScannerTimer;
            document.addEventListener('keydown', (e) => {
                // Ignorar si el usuario está escribiendo en un input (como el buscador)
                if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

                if (e.key === 'Enter') {
                    const code = dashboardScannerBuffer.trim();
                    if (/^\d{10}$/.test(code)) {
                        processQrCode(code);
                    }
                    dashboardScannerBuffer = '';
                } else if (e.key.length === 1) {
                    dashboardScannerBuffer += e.key;
                    clearTimeout(dashboardScannerTimer);
                    dashboardScannerTimer = setTimeout(() => dashboardScannerBuffer = '', 100);
                }
            });

            $('#continueButton').on('click', function() {
                $('#Logginer_MODAL').modal('hide');
                stopScanning();

                userDetails.innerHTML = `
                    <div class="text-center">
                        <img loading="lazy" src="../../assets/img/profiles/${userData.userid}.png" onerror="this.style.display='none'" style="width: 120px; height: 120px; border-radius: 50%; object-fit: cover; margin-bottom: 15px; border: 3px solid #0950dc;">
                        <h3>${userData.firstname} ${userData.lastname}</h3>
                        <p>${userData.email}</p>
                    </div>
                    <hr>
                    <p><strong>${translations.birthday}:</strong> ${userData.birthdate}</p>
                    <p><strong>${translations.ticketinfo}</strong> ${userData.ticket_status}</p>`;

                $('#UserDetails_MODAL').modal('show');
            });

                $('#nextButton').on('click', function() {
                    if (userData.ticket_status === translations.valid) {
                        $('#UserDetails_MODAL').modal('hide');
                        $('#TicketDetails_MODAL').modal('show');
            
                        $('#ticketDetails').html(
                            `${translations.tickettableoccassion}: <span>${userData.remaining_opportunities}</span><br>
                                ${translations.expiredate} ${userData.expiredate}<br>
                                ${translations.randomlockerselected} <span class="flash">${userData.assigned_locker}</span>`
                        );
                    } else {
                        // If the ticket is expired, redirect to the sell page for this user
                        sessionStorage.setItem('pending_checkin_userid', userData.userid);
                        window.location.href = `../boss/sell/ticket/?userid=${userData.userid}`;
                    }
                });
            $('#Logginer_MODAL').on('shown.bs.modal', function() { startScanning(); });
            $('#Logginer_MODAL').on('hidden.bs.modal', function() { stopScanning(); });

            // Automatically process check-in if a user ID is passed in the URL from the sell page
            const urlParams = new URLSearchParams(window.location.search);
            const userIdFromUrl = urlParams.get('userid');
            const statusFromUrl = urlParams.get('status');
            const pendingCheckinUser = sessionStorage.getItem('pending_checkin_userid');
            sessionStorage.removeItem('pending_checkin_userid'); // Limpiar bandera para evitar falsos positivos futuros

            if (userIdFromUrl && statusFromUrl === 'paid') {
                // A user was just sold a ticket. Let's process them automatically.
                fetch('process.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `qrcode=${encodeURIComponent(userIdFromUrl)}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Populate the global userData object
                            userData = {
                                firstname: data.firstname,
                                lastname: data.lastname,
                                birthdate: data.birthdate,
                                ticket_status: data.ticket_status,
                                remaining_opportunities: data.remaining_opportunities,
                                expiredate: data.expiredate,
                                email: data.email,
                                userid: userIdFromUrl,
                                assigned_locker: data.assigned_locker
                            };

                            // Actualizar notificaciones
                            if (typeof checkNotifications === 'function') {
                                checkNotifications();
                            }
                        } else {
                            alert("Error processing auto check-in: " + (data.message || 'Unknown error'));
                        }
                    })
                    .catch(error => {
                        console.error('Auto check-in error:', error);
                        alert("Error processing auto check-in.");
                    });

                // Clean the URL so a refresh doesn't re-trigger this action
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });

        $('<style>')
            .prop('type', 'text/css')
            .html(`
        .flash {
            color: red;
            animation: flash-animation 1s infinite;
        }
        @keyframes flash-animation {
            0% { opacity: 1; }
            50% { opacity: 0; }
            100% { opacity: 1; }
        }
    `)
            .appendTo('head');

        // Real-time Timer Script
        setInterval(function() {
            $('.timer-cell').each(function() {
                var seconds = parseInt($(this).attr('data-seconds'));
                seconds++;
                $(this).attr('data-seconds', seconds);
                
                var hours = Math.floor(seconds / 3600);
                var minutes = Math.floor((seconds % 3600) / 60);
                // Optional: Show seconds if desired, currently requested Hours/Minutes
                // var secs = seconds % 60;
                
                var timeString = hours + " Horas " + minutes + " Minutos";
                $(this).text(timeString);
            });
        }, 1000);

        // Funciones para el Registro de Visita
        function toggleGuestForm() {
            var form = document.getElementById('guest-entry-form');
            var btn = document.getElementById('btnToggleGuest');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                btn.style.display = 'none';
                stopScanning(); // Detener cámara para ahorrar recursos y evitar conflictos
            } else {
                form.style.display = 'none';
                btn.style.display = 'block';
                startScanning(); // Reactivar cámara
            }
        }

        $('#formGuestEntry').on('submit', function(e) {
            e.preventDefault();
            var name = $('#guest_name').val();
            var ticket = $('#guest_ticket').val();
            var gender = $('#guest_gender').val();
            var birthdate = $('#guest_birthdate').val();
            var phone = $('#guest_phone').val();
            
            $.ajax({
                url: 'process_guest.php',
                type: 'POST',
                data: { guest_name: name, guest_ticket: ticket, guest_gender: gender, guest_birthdate: birthdate, guest_phone: phone },
                dataType: 'json',
                success: function(response) {
                    if(response.success) {
                        alert('✔ ' + response.message);
                        location.reload();
                    } else {
                        alert('✘ Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error de conexión al procesar la visita.');
                }
            });
        });

        // Activar pantalla de carga al enviar formularios
        $('form').on('submit', function() {
            if (!$(this).attr('target') && !$(this).hasClass('no-loader')) { // No mostrar si se abre en nueva pestaña o tiene clase no-loader
                $('#loading-overlay').css('display', 'flex');
            }
        });

        // --- ACTUALIZACIÓN AUTOMÁTICA DEL DASHBOARD ---
        function updateDashboard() {
            $.ajax({
                url: 'get_dashboard_data.php',
                dataType: 'json',
                success: function(data) {
                    // Actualizar Estadísticas
                    $('#stat-income').text(data.stats.income);
                    $('#stat-daily-users').text(data.stats.daily_users);
                    $('#stat-total-users').text(data.stats.total_users);

                    // Actualizar Tabla
                    $('#activeUsersTableBody').html(data.table_html);

                    // Actualizar Capacidad
                    var capBar = $('#stat-capacity-bar');
                    capBar.css('width', data.capacity.percent + '%');
                    capBar.text(data.capacity.text);
                    capBar.attr('class', 'progress-bar-' + data.capacity.color);
                }
            });
        }

        // Ejecutar casi al instante (cada 1 segundo)
        setInterval(updateDashboard, 1000);
    </script>





    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const now = new Date();
            const hours = now.getHours();
            const minutes = now.getMinutes();
            let message = '';

            if ((hours === 0 && minutes >= 0) || (hours < 11) || (hours === 11 && minutes < 30)) {
                message = '<?php echo $translations["morninghello"]; ?>';
            } else if ((hours === 11 && minutes >= 30) || (hours < 17)) {
                message = '<?php echo $translations["dayhello"]; ?>';
            } else {
                message = '<?php echo $translations["nighthello"]; ?>';
            }
            const username = "<?php echo $username; ?>";
            const finalMessage = `${message} ${username}!`;

            const today = new Date().toISOString().split('T')[0];

            if (localStorage.getItem('modalShownDate') !== today) {
                document.getElementById('modalMessage').innerText = finalMessage;
                $('#welcomeModal').modal('show');
                localStorage.setItem('modalShownDate', today);
            }
        });
    </script>
    <!-- MODAL DE ACCESO CON QR -->
    <script src="../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>