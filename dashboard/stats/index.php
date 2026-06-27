<?php
session_start();

if (!isset($_SESSION['userid'])) {
    header("Location: ../../");
    exit();
}

$userid = $_SESSION['userid'];

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

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

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

$sql_latest_session = "SELECT duration FROM workout_stats WHERE userid = '$userid' AND DATE(workout_date) = CURDATE() LIMIT 1";
$result_latest_session = $conn->query($sql_latest_session);
if (!$result_latest_session) {
    die("Hiba a legutóbbi edzés lekérdezésekor: " . $conn->error);
}
$latest_session_time = ($result_latest_session->num_rows > 0) ? $result_latest_session->fetch_assoc()['duration'] : 0;

$sql_avg_time = "SELECT AVG(duration) AS avg_duration FROM workout_stats WHERE userid = '$userid'";
$result_avg_time = $conn->query($sql_avg_time);
if (!$result_avg_time) {
    die("Hiba az átlagos edzésidő lekérdezésekor: " . $conn->error);
}
$avg_val = ($result_avg_time->num_rows > 0) ? $result_avg_time->fetch_assoc()['avg_duration'] : null;
$avg_duration = $avg_val ? round((float)$avg_val) : 0;

$sql_latest_training = "SELECT DATE(workout_date) as workout_date FROM workout_stats WHERE userid = '$userid' ORDER BY workout_date DESC LIMIT 1";
$result_latest_training = $conn->query($sql_latest_training);
if (!$result_latest_training) {
    die("Hiba a legutóbbi edzés dátumának lekérdezésekor: " . $conn->error);
}
$latest_training = ($result_latest_training->num_rows > 0) ? $result_latest_training->fetch_assoc()['workout_date'] : $translations["n/a"];

// --- NUEVAS ESTADÍSTICAS ---
// Racha actual (Streak)
$streak = 0;
$weekend_warrior = false;
$check_date = date('Y-m-d');
$sql_streak = "SELECT DISTINCT DATE(workout_date) as t_date FROM workout_stats WHERE userid = '$userid' AND DATE(workout_date) <= '$check_date' AND workout_date > '2000-01-01' ORDER BY t_date DESC";
$result_streak = $conn->query($sql_streak);
$dates_trained = [];
if ($result_streak && $result_streak->num_rows > 0) {
    while($r = $result_streak->fetch_assoc()) {
        if (!empty($r['t_date'])) {
            $dates_trained[] = $r['t_date'];
        }
    }
}

if (!empty($dates_trained)) {
    try {
        $today_obj = new DateTime($check_date);
        $eval_obj = new DateTime($check_date);
        
        $last_date = end($dates_trained);
        $oldest_obj = new DateTime($last_date);

        while ($eval_obj >= $oldest_obj) {
            $eval_date_str = $eval_obj->format('Y-m-d');
            $dw = $eval_obj->format('N'); // 1=Lunes ... 7=Domingo
            $is_weekend = ($dw == 6 || $dw == 7);
            $trained = in_array($eval_date_str, $dates_trained);

            if ($trained) {
                if ($is_weekend) {
                    $streak += 2; // Bono: doble racha por asistir en fin de semana
                    $interval = $today_obj->diff($eval_obj)->days;
                    if ($interval <= 7) {
                        $weekend_warrior = true;
                    }
                } else {
                    $streak++;
                }
            } else {
                if (!$is_weekend) {
                    if ($eval_date_str !== $check_date) {
                        break; // Faltó un día entre semana, se rompe la racha
                    }
                }
            }
            $eval_obj->modify('-1 day');
        }
    } catch (Exception $e) {
        // Ignorar si la fecha de la base de datos está corrompida para evitar el error 500
    }
}

// 1. Total de entrenamientos
$sql_total_workouts = "SELECT COUNT(*) AS total_count FROM workout_stats WHERE userid = '$userid'";
$result_total_workouts = $conn->query($sql_total_workouts);
$total_workouts = ($result_total_workouts->num_rows > 0) ? $result_total_workouts->fetch_assoc()['total_count'] : 0;

// 2. Total de horas
$sql_total_time = "SELECT SUM(duration) AS total_minutes FROM workout_stats WHERE userid = '$userid'";
$result_total_time = $conn->query($sql_total_time);
$total_minutes = ($result_total_time->num_rows > 0) ? $result_total_time->fetch_assoc()['total_minutes'] : 0;
$total_hours = ($total_minutes > 0) ? round($total_minutes / 60, 1) : 0;

// 3. Datos para el gráfico (Últimos 7 días)
$chart_dates = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_dates[$d] = 0;
}
$sql_chart = "SELECT DATE(workout_date) as w_date, SUM(duration) as duration FROM workout_stats WHERE userid = '$userid' AND DATE(workout_date) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY w_date ORDER BY w_date ASC";
$result_chart = $conn->query($sql_chart);
if ($result_chart && $result_chart->num_rows > 0) {
    while($row = $result_chart->fetch_assoc()) {
        if (isset($chart_dates[$row['w_date']])) {
            // Convertir minutos a horas
            $chart_dates[$row['w_date']] = round((int)$row['duration'] / 60, 1);
        }
    }
}
$chart_dates_json = [];
foreach (array_keys($chart_dates) as $d) {
    $chart_dates_json[] = date('d/m', strtotime($d));
}
$chart_durations_json = array_values($chart_dates);

// 4. Datos para gráfico de Frecuencia de la Semana Actual
$week_counts = array_fill(0, 7, 0); // 0=Lun, 1=Mar, ..., 6=Dom
$dw = date('N'); 
$monday_this_week = date('Y-m-d', strtotime('-' . ($dw - 1) . ' days'));
$sunday_this_week = date('Y-m-d', strtotime('+' . (7 - $dw) . ' days'));
$sql_weekly = "SELECT WEEKDAY(workout_date) as day_idx, COUNT(*) as count FROM workout_stats WHERE userid = '$userid' AND DATE(workout_date) >= '$monday_this_week' AND DATE(workout_date) <= '$sunday_this_week' GROUP BY day_idx";
$result_weekly = $conn->query($sql_weekly);
if ($result_weekly) {
    while($row = $result_weekly->fetch_assoc()) {
        $week_counts[(int)$row['day_idx']] = (int)$row['count'];
    }
}
$weekly_data = [$week_counts[0], $week_counts[1], $week_counts[2], $week_counts[3], $week_counts[4], $week_counts[5], $week_counts[6]];

// 5. Datos para gráfico de Horario (Mañana/Tarde/Noche)
$morning = 0; $afternoon = 0; $evening = 0;
$sql_tod = "SELECT HOUR(entry_time) as h, COUNT(*) as c FROM workout_stats WHERE userid = '$userid' AND entry_time IS NOT NULL GROUP BY h";
$result_tod = $conn->query($sql_tod);
if ($result_tod) {
    while($row = $result_tod->fetch_assoc()) {
        $h = (int)$row['h'];
        if ($h >= 5 && $h < 12) $morning += $row['c'];
        elseif ($h >= 12 && $h < 19) $afternoon += $row['c'];
        else $evening += $row['c'];
    }
}

$sql = "SELECT firstname, lastname FROM users WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $userid);
$stmt->execute();
$stmt->bind_result($firstname, $lastname);
$stmt->fetch();
$stmt->close();

$conn->close();
?>


<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $business_name; ?> <?php echo $translations["dashboard"]; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="../../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        /* Modern Card Styles */
        .card {
            background-color: #fff;
            border: none;
            border-radius: 1rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 24px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .card:hover {
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            transform: translateY(-3px);
        }
        .card-body {
            padding: 1.5rem;
        }
        .stat-card {
            overflow: hidden;
            position: relative;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.1);
        }
        .stat-card .card-body {
            display: flex;
            align-items: center;
            position: relative;
            z-index: 1;
        }
        .stat-card .stat-icon {
            font-size: 3rem;
            color: rgba(255,255,255,0.8);
            margin-right: 1.5rem;
        }
        .stat-card .stat-content h1 {
            font-size: 2.2rem;
            font-weight: 800;
            margin-top: 0;
            margin-bottom: 0.25rem;
            color: #fff;
        }
        .stat-card .stat-content h4 {
            font-size: 0.95rem;
            font-weight: 600;
            text-transform: uppercase;
            color: rgba(255,255,255,0.8);
            margin-top: 0;
            letter-spacing: 0.5px;
        }
        .bg-gradient-primary { background: linear-gradient(135deg, #4e73df 0%, #224abe 100%); }
        .bg-gradient-success { background: linear-gradient(135deg, #1cc88a 0%, #13855c 100%); }
        .bg-gradient-info { background: linear-gradient(135deg, #36b9cc 0%, #208796 100%); }
        .bg-gradient-warning { background: linear-gradient(135deg, #f6c23e 0%, #dfa009 100%); }
        .bg-gradient-danger { background: linear-gradient(135deg, #e74a3b 0%, #be2617 100%); }
        .bg-gradient-dark { background: linear-gradient(135deg, #5a5c69 0%, #373840 100%); }
        /* Mobile Responsiveness */
        @media (max-width: 767px) {
            .stat-card .stat-icon {
                font-size: 2.5rem;
                margin-right: 1rem;
            }
            .stat-card .stat-content h1 {
                font-size: 1.8rem;
            }
            .card-body {
                padding: 1.25rem;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href=""><img src="../../assets/img/logoSpartan.png" width="70px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../"><i class="bi bi-house"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li class="active"><a href=""><i class="bi bi-graph-up"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../profile/"><i class="bi bi-person-badge"></i> <?php echo $translations["profilepage"]; ?></a></li>
                    <li><a href="../invoices/"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../assets/img/logoSpartan.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../">
                            <i class="bi bi-house"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="">
                            <i class="bi bi-graph-up"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../profile/">
                            <i class="bi bi-person-badge"></i> <?php echo $translations["profilepage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../invoices/">
                            <i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?>
                        </a>
                    </li>
                </ul><br>
            </div>
            <div class="col-sm-10">
                <div class="hidden-xs topnav" style="display: flex; justify-content: space-between; align-items: center; padding-bottom: 15px; border-bottom: 1px solid #e3e6f0; margin-bottom: 25px;">
                    <div style="text-align: left;">
                        <h3 style="margin-top:0; font-weight: 700; color: #3a3b45;">¡<?php echo $translations["welcome"] ?? "Bienvenido"; ?> <?php echo $firstname; ?>! 👋</h3>
                        <p class="text-muted" style="margin-bottom: 0;">Resumen de tu rendimiento y estadísticas.</p>
                    </div>
                    <button type="button" class="btn btn-danger" style="border-radius: 20px; padding: 8px 20px; box-shadow: 0 4px 6px rgba(231, 74, 59, 0.2);" data-toggle="modal" data-target="#logoutModal">
                        <i class="bi bi-box-arrow-right"></i> <?php echo $translations["logout"]; ?>
                    </button>
                </div>
                
                <div class="visible-xs text-center" style="margin-top: 15px; margin-bottom: 20px;">
                    <h3 style="font-weight: 700; color: #3a3b45;">¡<?php echo $translations["welcome"] ?? "Hola"; ?>, <?php echo $firstname; ?>! 👋</h3>
                    <p class="text-muted">Tu progreso y estadísticas</p>
                </div>
                <div class="row">
                    <div class="col-md-4 col-sm-6">
                        <div class="card stat-card bg-gradient-warning">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-fire"></i></div>
                                <div class="stat-content">
                                    <h4>Racha Actual</h4>
                                    <h1><strong><?php echo $streak; ?></strong> <small style="font-size: 0.5em; color: rgba(255,255,255,0.8);"><?php echo $translations["days"] ?? "días"; ?></small></h1>
                                    <?php if ($weekend_warrior): ?>
                                    <span class="badge" style="background-color: #fff; color: #dfa009; font-size: 0.75em; margin-top: 5px;"><i class="bi bi-star-fill"></i> ¡Guerrero de Fin de Semana!</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="card stat-card bg-gradient-primary">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-trophy"></i></div>
                                <div class="stat-content">
                                    <h4>Total Entrenamientos</h4>
                                    <h1><strong><?php echo $total_workouts; ?></strong></h1>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="card stat-card bg-gradient-info">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
                                <div class="stat-content">
                                    <h4>Total Horas</h4>
                                    <h1><strong><?php echo $total_hours; ?></strong> <small style="font-size: 0.5em; color: rgba(255,255,255,0.8);">Hrs</small></h1>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4 col-sm-6">
                        <div class="card stat-card bg-gradient-success">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-stopwatch"></i></div>
                                <div class="stat-content">
                                    <h4><?php echo $translations["latestsessiontime"]; ?></h4>
                                    <h1><strong><?php echo $latest_session_time; ?></strong> <small style="font-size: 0.5em; color: rgba(255,255,255,0.8);"><?php echo $translations["minutes"]; ?></small></h1>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <div class="card stat-card bg-gradient-dark">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                                <div class="stat-content">
                                    <h4><?php echo $translations["averagetraintime"]; ?></h4>
                                    <h1><strong><?php echo $avg_duration; ?></strong> <small style="font-size: 0.5em; color: rgba(255,255,255,0.8);"><?php echo $translations["minutes"]; ?></small></h1>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-12">
                        <div class="card stat-card bg-gradient-danger">
                            <div class="card-body">
                                <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                                <div class="stat-content">
                                    <h4><?php echo $translations["latesttraining"]; ?></h4>
                                    <h1 style="font-size: 1.8rem; margin-top: 0.5rem;"><strong><?php echo $latest_training; ?></strong></h1>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráfico de Actividad -->
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card">
                            <div class="card-body">
                                <h4 class="card-title text-primary" style="margin-bottom: 20px; font-weight: 700;"><i class="bi bi-graph-up-arrow"></i> Progreso (Últimos 7 días)</h4>
                                <div id="activityChart"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos Adicionales -->
                <div class="row">
                    <div class="col-md-8 col-sm-12">
                        <div class="card" style="height: calc(100% - 24px);">
                            <div class="card-body">
                                <h4 class="card-title text-primary" style="margin-bottom: 20px; font-weight: 700;"><i class="bi bi-bar-chart-steps"></i> Asistencia esta Semana</h4>
                                <div id="weeklyChart"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-sm-12">
                        <div class="card" style="height: calc(100% - 24px);">
                            <div class="card-body">
                                <h4 class="card-title text-primary" style="margin-bottom: 20px; font-weight: 700;"><i class="bi bi-pie-chart"></i> Horario Preferido</h4>
                                <div id="timeChart"></div>
                            </div>
                        </div>
                    </div>
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
        <!-- SCRIPTS! -->
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
        <script>
            var options = {
                series: [{
                    name: 'Duración (hrs)',
                    data: <?php echo json_encode($chart_durations_json); ?>
                }],
                chart: {
                    type: 'area',
                    height: 320,
                    zoom: { enabled: false },
                    toolbar: { show: false },
                    fontFamily: 'inherit'
                },
                dataLabels: { enabled: false },
                stroke: { curve: 'smooth', width: 3 },
                xaxis: {
                    categories: <?php echo json_encode($chart_dates_json); ?>,
                    tooltip: { enabled: false }
                },
                tooltip: {
                    theme: 'light',
                    y: {
                        formatter: function (val) {
                            return val + " hrs"
                        }
                    }
                },
                colors: ['#4e73df'],
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.4,
                        opacityTo: 0.05,
                        stops: [0, 90, 100]
                    }
                }
            };
            var chart = new ApexCharts(document.querySelector("#activityChart"), options);
            chart.render();

            // Gráfico Semanal
            var weeklyOptions = {
                series: [{
                    name: 'Entrenamientos',
                    data: <?php echo json_encode($weekly_data); ?>
                }],
                chart: {
                    type: 'bar',
                    height: 300,
                    toolbar: { show: false },
                    fontFamily: 'inherit'
                },
                plotOptions: {
                    bar: { borderRadius: 6, horizontal: false, columnWidth: '50%' }
                },
                dataLabels: { enabled: false },
                xaxis: {
                    categories: ['<?php echo $translations["Mon"] ?? "Lun"; ?>', '<?php echo $translations["Tue"] ?? "Mar"; ?>', '<?php echo $translations["Wed"] ?? "Mié"; ?>', '<?php echo $translations["Thu"] ?? "Jue"; ?>', '<?php echo $translations["Fri"] ?? "Vie"; ?>', '<?php echo $translations["Sat"] ?? "Sáb"; ?>', '<?php echo $translations["Sun"] ?? "Dom"; ?>'],
                },
                colors: ['#1cc88a'],
                tooltip: {
                    theme: 'light'
                }
            };
            var weeklyChart = new ApexCharts(document.querySelector("#weeklyChart"), weeklyOptions);
            weeklyChart.render();

            // Gráfico de Horario (Donut)
            var timeOptions = {
                series: [<?php echo $morning; ?>, <?php echo $afternoon; ?>, <?php echo $evening; ?>],
                chart: {
                    type: 'donut',
                    height: 300,
                    fontFamily: 'inherit'
                },
                labels: ['Mañana (05-12)', 'Tarde (12-19)', 'Noche (19-05)'],
                colors: ['#f6c23e', '#36b9cc', '#5a5c69'],
                legend: { position: 'bottom' },
                dataLabels: { enabled: false },
                plotOptions: {
                    pie: {
                        donut: { size: '70%' },
                        expandOnClick: false
                    }
                },
                stroke: { width: 0 },
                tooltip: {
                    theme: 'light',
                    y: {
                        formatter: function (val) {
                            return val + " veces"
                        }
                    }
                }
            };
            
            if (<?php echo ($morning + $afternoon + $evening) > 0 ? 'true' : 'false'; ?>) {
                var timeChart = new ApexCharts(document.querySelector("#timeChart"), timeOptions);
                timeChart.render();
            } else {
                document.querySelector("#timeChart").innerHTML = `
                    <div class="text-center" style="padding: 60px 20px;">
                        <i class="bi bi-clock text-muted" style="font-size: 3rem;"></i>
                        <p class="text-muted mt-3">Aún no hay suficientes datos para determinar tu horario preferido. ¡Sigue entrenando!</p>
                    </div>`;
            }
        </script>
</body>

</html>