<?php
session_start();

if (isset($_GET['user']) && !empty($_GET['user'])) {
    $userIdFromUrl = $_GET['user'];
    header("Location: ticket/?userid=" . urlencode($userIdFromUrl));
    exit();
}

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

$env_data = read_env_file('../../../.env');

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
$currency = $env_data['CURRENCY'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("El archivo de idioma no se encuentra: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
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

$search = '';
$results = [];
if (isset($_POST['search']) && !empty($_POST['search'])) {
    $search = $conn->real_escape_string($_POST['search']);
    // SQL lekérdezés a szűrt kereséshez
    $sql = "SELECT * FROM users WHERE firstname LIKE '%$search%' OR lastname LIKE '%$search%'";
    $results = $conn->query($sql);
}

$stmt->close();

// Obtener casilleros disponibles para el modal
$availableLockers = [];
$lockerSql = "SELECT id, lockernum, gender FROM lockers WHERE user_id IS NULL ORDER BY lockernum ASC";
$lockerResult = $conn->query($lockerSql);
if ($lockerResult && $lockerResult->num_rows > 0) {
    while ($l = $lockerResult->fetch_assoc()) {
        $availableLockers[] = $l;
    }
}

$message = "";

// Procesar Renta de Casillero
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['rent_locker'])) {
    $locker_id = $_POST['locker_id'];
    $user_id = $_POST['user_id'];
    $rental_type = $_POST['rental_type'];
    $amount = floatval($_POST['payment_amount'] ?? 0);
    $method = $_POST['payment_method'] ?? 'Cash';

    $days = 0;
    switch ($rental_type) {
        case 'weekly': $days = 7; break;
        case 'monthly': $days = 30; break;
        case 'quarterly': $days = 90; break;
    }
    $expiration = date('Y-m-d H:i:s', strtotime("+$days days"));

    $stmt = $conn->prepare("UPDATE lockers SET user_id = ?, rental_type = ?, expiration_date = ? WHERE id = ?");
    $stmt->bind_param("sssi", $user_id, $rental_type, $expiration, $locker_id);
    
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Casillero rentado exitosamente.</div>';
        $action = $conn->real_escape_string("Renta de casillero (Venta) ID $locker_id a usuario $user_id. Pago: $amount $currency ($method)");
        $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ('$userid', '$action', 'success', NOW())");

        // Generar Factura y Registro
        // 1. Obtener nombre del usuario
        $userQ = $conn->query("SELECT firstname, lastname, email FROM users WHERE userid = '$user_id'");
        $userRow = $userQ->fetch_assoc();
        $userName = $userRow['firstname'] . ' ' . $userRow['lastname'];
        $uEmail = $userRow['email'] ?? '';

        // 2. Obtener número de casillero
        $lockerQ = $conn->query("SELECT lockernum FROM lockers WHERE id = '$locker_id'");
        $lockerRow = $lockerQ->fetch_assoc();
        $lockerNum = $lockerRow['lockernum'];

        // 3. Obtener nombre del empleado que atendió
        $adminName = "Sistema";
        $workerQ = $conn->query("SELECT Firstname, Lastname FROM workers WHERE userid = '$userid'");
        if ($workerQ && $workerQ->num_rows > 0) {
            $wRow = $workerQ->fetch_assoc();
            $adminName = $wRow['Firstname'] . ' ' . $wRow['Lastname'];
        }

        // 4. Preparar descripción detallada
        $typeLabel = $rental_type;
        if ($typeLabel == 'weekly') $typeLabel = $translations['weekly'] ?? 'Semanal';
        if ($typeLabel == 'monthly') $typeLabel = $translations['Jan'] ?? 'Mensual';
        if ($typeLabel == 'quarterly') $typeLabel = $translations['quarterly'] ?? 'Trimestral';
        
        $description = "Renta Casillero #$lockerNum (" . ucfirst($typeLabel) . ")";
        $description_text = "Atendido por: " . $adminName;

        // 5. Generar PDF
        require_once __DIR__ . '/../../invoices/generator.php';
        $pdfData = [
            'business_name' => $business_name,
            'client_name' => $userName,
            'userid' => $user_id,
            'payment_method' => $method,
            'amount' => number_format($amount, 2),
            'currency' => $currency,
            'items' => [['name' => $description, 'price' => number_format($amount, 2)]],
            'description_text' => $description_text
        ];
        $pdfFilename = generateInvoicePDF($pdfData);
        $route = $pdfFilename ? $pdfFilename : 'error.pdf';

        // 6. Insertar en base de datos de facturas
        $invSql = "INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, description, route) VALUES (?, ?, ?, 'Locker', ?, 'paid', NOW(), ?, ?)";
        $invStmt = $conn->prepare($invSql);
        $invStmt->bind_param("isdsss", $user_id, $userName, $amount, $method, $description, $route);
        $invStmt->execute();
        $invStmt->close();

        // Enviar Ticket por correo electrónico
        if (!empty($uEmail) && $route !== 'error.pdf' && file_exists(__DIR__ . '/../../../assets/docs/invoices/' . $route)) {
            try {
                require_once __DIR__ . '/../../../vendor/autoload.php';
                $transport = (new Swift_SmtpTransport($env_data['MAIL_HOST'], $env_data['MAIL_PORT']))
                    ->setUsername($env_data['MAIL_USERNAME'])
                    ->setPassword($env_data['MAIL_PASSWORD']);
                if (!empty($env_data['MAIL_ENCRYPTION'])) {
                    $transport->setEncryption($env_data['MAIL_ENCRYPTION']);
                }
                $mailer = new Swift_Mailer($transport);

                $subject = ($translations["invoice"] ?? "Ticket de Venta") . " - " . $business_name;
                $fromEmail = !empty($env_data['MAIL_FROM_ADDRESS']) ? $env_data['MAIL_FROM_ADDRESS'] : $env_data['MAIL_USERNAME'];
                $fromName = !empty($env_data['MAIL_FROM_NAME']) ? $env_data['MAIL_FROM_NAME'] : $business_name;

                $mailMsg = (new Swift_Message($subject))
                    ->setFrom([$fromEmail => $fromName])
                    ->setTo([$uEmail]);

                $logoPath = __DIR__ . '/../../../assets/img/logo.png';
                $cid = file_exists($logoPath) ? $mailMsg->embed(Swift_Image::fromPath($logoPath)) : '';
                $logoHtml = $cid ? '<img src="' . $cid . '" alt="Logo" style="max-width: 80px; height: auto;">' : '<h1 style="color: #ffffff; margin: 0;">' . $business_name . '</h1>';

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
                $baseDir = preg_replace('#/admin/boss/sell$#', '', $scriptDir);
                $pdfUrl = $protocol . $host . $baseDir . '/assets/docs/invoices/' . $route;

                $body = '
                <div style="font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4; padding: 30px 10px;">
                    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                        <div style="background-color: #0950dc; text-align: center; padding: 25px;">
                            ' . $logoHtml . '
                        </div>
                        <div style="padding: 30px; color: #333333;">
                            <h2 style="color: #0950dc; margin-top: 0; font-size: 24px;">¡Gracias por tu preferencia, ' . $userName . '!</h2>
                            <p style="font-size: 16px; line-height: 1.6;">Confirmamos que tu transacción se ha realizado con éxito. Hemos adjuntado a este correo tu <strong>Ticket de Venta</strong> con todos los detalles de la operación.</p>
                            
                            <div style="background-color: #f8f9fa; border-left: 4px solid #0950dc; padding: 15px; margin: 25px 0;">
                                <p style="margin: 0; font-size: 15px;"><strong>Concepto:</strong> Renta de Casillero</p>
                                <p style="margin: 5px 0 0 0; font-size: 15px;"><strong>Fecha:</strong> ' . date('d/m/Y') . '</p>
                            </div>

                            <div style="text-align: center; margin: 35px 0;">
                                <a href="' . $pdfUrl . '" target="_blank" style="background-color: #0950dc; color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">📄 Ver / Descargar Ticket</a>
                            </div>
                            <hr style="border: 0; border-top: 1px solid #eeeeee; margin: 30px 0;">
                            <p style="font-size: 14px; color: #666666; margin-bottom: 0; line-height: 1.5;">Si tienes alguna duda o aclaración sobre este ticket, por favor acércate a la recepción de nuestro gimnasio. ¡Estaremos felices de ayudarte!</p>
                        </div>
                        <div style="background-color: #f1f1f1; text-align: center; padding: 20px; font-size: 13px; color: #888888;">
                            <p style="margin: 0; font-size: 14px;"><strong>' . $business_name . '</strong></p>
                            <p style="margin: 5px 0 0 0;">Este es un correo generado automáticamente. Por favor, no respondas a este mensaje.</p>
                        </div>
                    </div>
                </div>';

                $mailMsg->setBody($body, 'text/html')
                    ->attach(Swift_Attachment::fromPath(__DIR__ . '/../../../assets/docs/invoices/' . $route));

                // Enviar el ticket de renta de casillero al cliente
                $mailer->send($mailMsg);
            } catch (Exception $e) {
                error_log("Error enviando email: " . $e->getMessage());
            }
        }

        // 7. Actualizar estadísticas de ingresos (Caja)
        $dateStr = date('Y-m-d');
        $revCheck = $conn->query("SELECT id FROM revenu_stats WHERE date = '$dateStr'");
        if ($revCheck->num_rows > 0) {
            $revRow = $revCheck->fetch_assoc();
            $revId = $revRow['id'];
            $col = ($method == 'Card') ? 'bank_card' : 'cash';
            $conn->query("UPDATE revenu_stats SET $col = $col + $amount WHERE id = $revId");
        } else {
            $conn->query("INSERT INTO revenu_stats (date, cash, bank_card) VALUES ('$dateStr', " . ($method == 'Cash' ? $amount : 0) . ", " . ($method == 'Card' ? $amount : 0) . ")");
        }
    } else {
        $message = '<div class="alert alert-danger">Error al rentar casillero.</div>';
    }
    $stmt->close();
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
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
    <style>
        /* Modern Cards */
        .card {
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            border: 1px solid #edf2f9;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }
        .card:hover {
            box-shadow: 0 8px 20px rgba(0,0,0,0.08);
        }
        .card-body {
            padding: 30px;
        }
        /* Search Input */
        .search-input {
            border-radius: 30px 0 0 30px !important;
            padding-left: 20px;
            border-color: #d1d3e2;
        }
        .search-btn {
            border-radius: 0 30px 30px 0 !important;
            padding-left: 25px;
            padding-right: 25px;
        }
        /* Modern Table */
        .table-responsive {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            border: 1px solid #edf2f9;
        }
        .custom-table {
            margin-bottom: 0;
        }
        .custom-table thead th {
            background-color: #f8f9fc;
            border-bottom: 2px solid #e3e6f0;
            color: #4e73df;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.85rem;
            vertical-align: middle;
        }
        .custom-table tbody td {
            vertical-align: middle;
            border-top: 1px solid #edf2f9;
        }
        /* Modals */
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        .modal-header {
            border-bottom: 1px solid #edf2f9;
            background-color: #f8f9fc;
            border-radius: 12px 12px 0 0;
        }
        .mt-4 { margin-top: 1.5rem !important; }
        .mb-4 { margin-bottom: 1.5rem !important; }
    </style>
</head>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="../../../assets/js/tinymce/js/tinymce/tinymce.min.js" referrerpolicy="origin"></script>


<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i> <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../boss/packages"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
                        <li><a href="../../boss/chroom"><i class="bi bi-duffle"></i> <?php echo $translations["chroompage"]; ?></a></li>
                    <?php } ?>
                    <li><a href="../../trainers/timetable"><i class="bi bi-calendar-event"></i> <?php echo $translations["timetable"]; ?></a></li>
                    <li><a href="../../trainers/personal"><i class="bi bi-award"></i> <?php echo $translations["trainers"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../updater"><i class="bi bi-cloud-download"></i> <?php echo $translations["updatepage"]; ?>
                                <?php if ($is_new_version_available) : ?>
                                    <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                                <?php endif; ?>
                            </a></li>
                    <?php } ?>
                    <li><a href="../../log"><i class="bi bi-clock-history"></i> <?php echo $translations["logpage"]; ?></a></li>
                    <li><a href="#" data-toggle="modal" data-target="#logoutModal"><i class="bi bi-box-arrow-right"></i> <?php echo $translations["logout"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../../assets/img/logo.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../users/">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../../invoices/" class="sidebar-link">
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
                            <a class="sidebar-link" href="../../boss/mainsettings">
                                <i class="bi bi-gear"></i>
                                <span><?php echo $translations["businesspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/workers">
                                <i class="bi bi-people"></i>
                                <span><?php echo $translations["workers"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/hours">
                                <i class="bi bi-clock"></i>
                                <span><?php echo $translations["openhourspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/smtp">
                                <i class="bi bi-envelope-at"></i>
                                <span><?php echo $translations["mailpage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../boss/rule">
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
                        <!-- <a class="sidebar-ling" href="../shop/gateway">
                            <i class="bi bi-shield-lock"></i>
                            <span><?php echo $translations["gatewaypage"]; ?></span>
                        </a> -->
                        <a class="sidebar-ling" href="../../shop/tickets">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <?php if ($is_boss === 1) { ?>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/packages">
                            <i class="bi bi-box-seam"></i>
                            <span><?php echo $translations["packagepage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/chroom">
                            <i class="bi bi-duffle"></i>
                            <span><?php echo $translations["chroompage"]; ?></span>
                        </a>
                    </li>
                    <?php } ?>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li><a class="sidebar-link" href="../../trainers/timetable">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a></li>
                    <li><a class="sidebar-link" href="../../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a></li>
                    <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
                    <?php
                    if ($is_boss === 1) {
                    ?>
                        <li class="sidebar-item">
                            <a class="sidebar-ling" href="../../updater">
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
                        <a class="sidebar-ling" href="../../log">
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
                <div class="hidden-xs topnav">
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
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <div class="row text-center">
                    <div class="col-sm-6">
                        <?php echo $message; ?>
                        <div class="card" style="min-height: 250px; display: flex; flex-direction: column; justify-content: center;">
                            <div class="card-body">
                                <form method="post" action="">
                                    <h3 class="mb-4 text-primary" style="font-weight: 600;"><i class="bi bi-person-bounding-box"></i> Buscar Usuario</h3>
                                    <div class="input-group input-group-lg" style="max-width: 100%; margin: 0 auto;">
                                        <input type="text" id="search" name="search" class="form-control search-input" placeholder="Ingresa nombre o apellido..." value="<?php echo htmlspecialchars($search); ?>" autofocus>
                                        <span class="input-group-btn">
                                            <button type="submit" class="btn btn-primary search-btn"><i class="bi bi-search"></i> <?= $translations["search"]; ?></button>
                                        </span>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($search) && isset($results)) : ?>
                        <div class="col-sm-6">
                            <div class="card">
                                <div class="card-body">
                                    <?php if ($results->num_rows > 0) : ?>
                                        <div class="table-responsive">
                                        <table class="table table-hover table-striped custom-table mt-4">
                                            <thead>
                                                <tr>
                                                    <th>ID</th>
                                                    <th><?= $translations["firstname"]; ?></th>
                                                    <th><?= $translations["lastname"]; ?></th>
                                                    <th><?= $translations["interact"]; ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($row = $results->fetch_assoc()) : ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($row["userid"]); ?></td>
                                                        <td><?php echo htmlspecialchars($row["firstname"]); ?></td>
                                                        <td><?php echo htmlspecialchars($row["lastname"]); ?></td>
                                                        <td>
                                                            <a href='ticket/?userid=<?php echo htmlspecialchars($row["userid"]); ?>&no_entry=true' class="btn btn-primary"><i class="bi bi-box-arrow-in-right"></i> <?= $translations["next"]; ?></a>
                                                            <button type="button" class="btn btn-warning rent-btn" data-userid="<?php echo htmlspecialchars($row["userid"]); ?>" data-name="<?php echo htmlspecialchars($row["firstname"] . ' ' . $row["lastname"]); ?>" data-toggle="modal" data-target="#rentModal"><i class="bi bi-key"></i> <?php echo $translations["rent_locker"] ?? "Rentar"; ?></button>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                        </div>
                                    <?php else : ?>
                                        <div class="alert alert-info mt-4"><?= $translations["user-notexist"]; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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
                <form action="../../dashboard/export_attendance.php" method="GET">
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
                    <p class="lead"><?php echo $translations["exit-modal"]; ?></p>
                </div>
                <div class="modal-footer">
                    <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                    <a href="../../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                </div>
            </div>
        </div>
    </div>

    <!-- RENT MODAL -->
    <div class="modal fade" id="rentModal" tabindex="-1" role="dialog" aria-labelledby="rentModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="rentModalLabel"><?php echo $translations["rent_locker"] ?? "Rentar Casillero"; ?></h4>
                </div>
                <form method="POST" id="rentForm">
                    <div class="modal-body">
                        <input type="hidden" name="user_id" id="modal_user_id">
                        <input type="hidden" name="payment_amount" id="hidden_payment_amount">
                        <input type="hidden" name="payment_method" id="hidden_payment_method">
                        <p>Usuario: <strong id="modal_user_name"></strong></p>

                        <div class="form-group">
                            <label><?php echo $translations["chroompage"] ?? "Casillero"; ?></label>
                            <select name="locker_id" class="form-control" required>
                                <option value="">Seleccione un casillero...</option>
                                <?php foreach ($availableLockers as $locker): ?>
                                    <option value="<?php echo $locker['id']; ?>">
                                        #<?php echo $locker['lockernum']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations["rental_type"] ?? "Tipo de Renta"; ?></label>
                            <select name="rental_type" class="form-control" required>
                                <option value="weekly"><?php echo $translations["weekly"] ?? "Semanal"; ?> (7 <?php echo $translations["day"]; ?>)</option>
                                <option value="monthly"><?php echo $translations["Jan"] ?? "Mensual"; ?>/Mensual (30 <?php echo $translations["day"]; ?>)</option>
                                <option value="quarterly"><?php echo $translations["quarterly"] ?? "Trimestral"; ?> (90 <?php echo $translations["day"]; ?>)</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $translations["close"]; ?></button>
                        <button type="button" class="btn btn-success" id="goToPaymentBtn"><?php echo $translations["paybutton"] ?? "Pagar"; ?> <i class="bi bi-arrow-right"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- PAYMENT MODAL -->
    <div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-labelledby="paymentModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="paymentModalLabel"><?php echo $translations["paybutton"] ?? "Pago"; ?></h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label><?php echo $translations["price"] ?? "Precio"; ?> (<?php echo $currency; ?>)</label>
                        <input type="number" id="modal_payment_amount" class="form-control" placeholder="0.00" step="0.01">
                    </div>
                    <div class="form-group">
                        <label><?php echo $translations["paymenttype"] ?? "Método de pago"; ?></label>
                        <select id="modal_payment_method" class="form-control">
                            <option value="Cash"><?php echo $translations["cash"] ?? "Efectivo"; ?></option>
                            <option value="Card"><?php echo $translations["card"] ?? "Tarjeta"; ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" id="backToRentBtn"><i class="bi bi-arrow-left"></i> <?php echo $translations["back"] ?? "Atrás"; ?></button>
                    <button type="button" class="btn btn-success" id="confirmPaymentBtn"><?php echo $translations["paybutton"] ?? "Pagar"; ?> & <?php echo $translations["save"] ?? "Guardar"; ?></button>
                </div>
            </div>
        </div>
    </div>

    <?php
    $conn->close();
    ?>
    <!-- SCRIPTS! -->
    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        $(document).on("click", ".rent-btn", function () {
            var userId = $(this).data('userid');
            var userName = $(this).data('name');
            $("#modal_user_id").val(userId);
            $("#modal_user_name").text(userName + " (" + userId + ")");
        });

        $("#goToPaymentBtn").click(function() {
            $("#rentModal").modal('hide');
            setTimeout(function() {
                $("#paymentModal").modal('show');
            }, 500);
        });

        $("#backToRentBtn").click(function() {
            $("#paymentModal").modal('hide');
            $("#rentModal").modal('show');
        });

        $("#confirmPaymentBtn").click(function() {
            $("#hidden_payment_amount").val($("#modal_payment_amount").val());
            $("#hidden_payment_method").val($("#modal_payment_method").val());
            
            var submitBtn = $('<input type="submit" name="rent_locker" style="display:none;">');
            $("#rentForm").append(submitBtn);
            submitBtn.click();
        });
    </script>
</body>

</html>