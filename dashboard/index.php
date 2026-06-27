<?php

session_start();

if (!isset($_SESSION['userid'])) {
    header("Location: ../");
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

$env_data = read_env_file('../.env');

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
$currency = $env_data['CURRENCY'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("No se puede encontrar el archivo de idioma: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$sql = "SELECT * FROM current_tickets WHERE userid = ? ORDER BY expiredate DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$result = $stmt->get_result();

$id = $ticketname = $buydate = $expiredate = $opportunities = null;
$currentDate = new DateTime();

$validTicketFound = false;

while ($row = $result->fetch_assoc()) {
    $expireDate = new DateTime($row['expiredate']);

    if ($expireDate > $currentDate) {
        $id = $row['id'];
        $ticketname = $row['ticketname'];
        $buydate = $row['buydate'];
        $expiredate = $row['expiredate'];
        $opportunities = $row['opportunities'];
        $validTicketFound = true;
        break;
    }
}

$stmt->close();

if (!empty($expiredate) && strtotime($expiredate) !== false) {
    $expireDate = new DateTime($expiredate);
    
    if ($expireDate > $currentDate) {
        $interval = $currentDate->diff($expireDate);
        if ($interval->days > 3650 || $expireDate->format('Y') == '9999') {
            $daysRemaining = $translations["unlimited"] ?? "Ilimitado";
        } elseif ($interval->days < 1) {
            $daysRemaining = $interval->format('%h h %i m');
        } else {
            $daysRemaining = $interval->format('%a d %h h %i m');
        }
    } else {
        $daysRemaining = $translations["expired"] ?? "Vencido";
    }
} else {
    $daysRemaining = "-";
}


$today_date = date('Y-m-d');
$sql_latest_training = "SELECT workout_date FROM workout_stats WHERE userid = $userid AND workout_date <= '$today_date' ORDER BY workout_id DESC LIMIT 1";
$result_latest_training = $conn->query($sql_latest_training);
if (!$result_latest_training) {
    die("Error al consultar la fecha del último entrenamiento: " . $conn->error);
}
$latest_training = ($result_latest_training->num_rows > 0) ? $result_latest_training->fetch_assoc()['workout_date'] : $translations["n/a"];



$sql = "SELECT firstname, lastname, profile_balance FROM users WHERE userid = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userid);
$stmt->execute();
$stmt->bind_result($firstname, $lastname, $profile_balance);
$stmt->fetch();

$stmt->close();

$conn->close();

// Crear directorio si no existe
$qrDir = __DIR__ . "/../assets/img/logincard";
if (!is_dir($qrDir)) {
    mkdir($qrDir, 0755, true);
}

require __DIR__ . '/../vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\Label\Alignment\LabelAlignmentCenter;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Label\Font\NotoSans;

$filename = __DIR__ . "/../assets/img/logincard/{$userid}.png";
$logoPath = __DIR__ . '/../assets/img/logoSpartan.png';

if (!file_exists($filename)) {
    try {
        $result = Builder::create()
            ->writer(new PngWriter())
            ->data($userid)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel(new ErrorCorrectionLevelHigh())
            ->size(300)
            ->margin(5);
        
        // Solo agregar logo si existe
        if (file_exists($logoPath)) {
            $result->logoPath($logoPath)
                ->logoResizeToWidth(100);
        }
        
        $buildResult = $result->labelText($firstname . ' ' . $lastname)
            ->labelFont(new NotoSans(20))
            ->labelAlignment(new LabelAlignmentCenter())
            ->validateResult(false)
            ->build();

        $buildResult->saveToFile($filename);
        header("Refresh:2");
    } catch (Exception $e) {
        echo 'Error: ' . $e->getMessage();
    }
}
?>


<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $business_name; ?> - <?php echo $translations["dashboard"]; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        /* Custom styles for a more modern look */
        .card {
            background-color: #fff;
            border: 1px solid #e3e6f0;
            border-radius: 0.75rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            margin-bottom: 20px;
            transition: all 0.3s;
        }
        .card:hover {
            box-shadow: 0 0.3rem 2.5rem 0 rgba(58, 59, 69, 0.2);
            transform: translateY(-2px);
        }
        .card-body {
            padding: 1.5rem;
        }
        .stat-card .card-body {
            display: flex;
            align-items: center;
        }
        .stat-card .stat-icon {
            font-size: 3rem;
            color: #dddfeb;
            margin-right: 1.5rem;
        }
        .stat-card .stat-content h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 0.25rem;
            color: #3a3b45;
        }
        .stat-card .stat-content h4 {
            font-size: 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #858796;
            margin-top: 0;
        }
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
                <a class="navbar-brand" href=""><img width="70px" src="../assets/img/logoSpartan.png" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li class="active"><a href=""><i class="bi bi-house"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="stats/"><i class="bi bi-graph-up"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="profile/"><i class="bi bi-person-badge"></i> <?php echo $translations["profilepage"]; ?></a></li>
                    <li><a href="invoices/"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                    <li><a href="#" data-toggle="modal" data-target="#logoutModal"><i class="bi bi-box-arrow-right"></i> <?php echo $translations["logout"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../assets/img/logoSpartan.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-house"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="stats/">
                            <i class="bi bi-graph-up"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="profile/">
                            <i class="bi bi-person-badge"></i> <?php echo $translations["profilepage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="invoices/">
                            <i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?>
                        </a>
                    </li>
                </ul><br>
            </div>
            <div class="col-sm-10">
                <div class="hidden-xs topnav">
                    <h4><?php echo $translations["welcome"]; ?> <?php echo $lastname; ?> <?php echo $firstname; ?></h4>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo $translations["logout"]; ?>
                    </button>
                </div>
                <div class="row">
                    <!-- QR Code Section -->
                    <div class="col-md-4 col-sm-12">
                        <div class="card">
                            <div class="card-body text-center">
                                <h4 class="card-title fw-semibold" style="margin-bottom: 15px;"><?php echo $translations["userlogginer"]; ?></h4>
                                <?php
                                if (file_exists($filename)) {
                                    echo "<img class='img img-responsive' style='margin: 0 auto; border-radius: 8px; border: 5px solid #fff; box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.1);' src='../assets/img/logincard/{$userid}.png' alt='{$firstname}-{$lastname}-{$userid}'>";
                                    echo "<a href='../assets/img/logincard/{$userid}.png' download class='btn btn-primary btn-block mt-3' style='margin-top: 15px;'><i class='bi bi-download'></i> " . ($translations['download_qr'] ?? 'Descargar QR') . "</a>";
                                } else {
                                    echo "<div class='text-center' style='padding: 40px 0;'><div class='spinner-border' role='status'></div><h4 class='lead' style='margin-top:15px;'>{$translations["qrgenerateing"]}</h4></div>";
                                }
                                ?>
                                <!-- Wallet Buttons -->
                                <?php
                                $apple_certs_exist = file_exists(__DIR__ . '/../certificates/Certificates.p12') && file_exists(__DIR__ . '/../certificates/AppleWWDRCA.pem');
                                if ($apple_certs_exist) {
                                ?>
                                <a href="pkpass.php?type=apple" class="btn btn-dark btn-block" style="background-color: #000; color: #fff; margin-top: 10px;">
                                    <i class="bi bi-apple"></i> Añadir a Apple Wallet
                                </a>
                                <?php } ?>

                                <?php
                                $google_creds_exist = file_exists(__DIR__ . '/../certificates/google-service-account.json');
                                if ($google_creds_exist) {
                                ?>
                                <a href="pkpass.php?type=google" target="_blank">
                                    <img src="../assets/img/brand/wallet/<?php echo $lang_code; ?>_add_to_google_wallet_add-wallet-badge.png"
                                        alt="<?= $lang_code; ?>_add_to_google_wallet_add-wallet-badge"
                                        class="img img-responsive mt-2 google-wallet" style="margin: 10px auto 0; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                </a>
                                <?php } ?>
                            </div>
                        </div>
                    </div>

                    <!-- Stats Section -->
                    <div class="col-md-8 col-sm-12">
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
                                        <div class="stat-content">
                                            <h4><?php echo $translations["currentticket"]; ?></h4>
                                            <h1><strong><?php if (!empty($ticketname)): ?>
                                                        <?php echo $ticketname; ?>
                                                    <?php else: ?>
                                                        -
                                                    <?php endif; ?>
                                                </strong></h1>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="stat-icon"><i class="bi bi-calendar-check"></i></div>
                                        <div class="stat-content">
                                            <h4><?php echo $translations["lastworkout"]; ?></h4>
                                            <h1><strong><?= $latest_training; ?></strong></h1>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="stat-icon"><i class="bi bi-hourglass-split"></i></div>
                                        <div class="stat-content">
                                            <h4><?php echo $translations["remainingdays"]; ?></h4>
                                            <h1><strong><?php echo $daysRemaining; ?> </strong></h1>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="card stat-card">
                                    <div class="card-body">
                                        <div class="stat-icon"><i class="bi bi-wallet2"></i></div>
                                        <div class="stat-content">
                                            <h4><?php echo $translations["profilebalance"]; ?></h4>
                                            <h1><strong><?php echo number_format((float)$profile_balance, 2); ?></strong> <?php echo $currency; ?></h1>
                                        </div>
                                    </div>
                                </div>
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
                        <a type="button" class="btn btn-secondary"
                            data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                        <a href="logout.php" type="button"
                            class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS! -->
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>