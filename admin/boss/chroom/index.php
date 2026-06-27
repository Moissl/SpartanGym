<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$userid = $_SESSION['adminuser'];
$alerts_html = '';


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

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$currency = $env_data["CURRENCY"] ?? '';

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

// Verificar y crear columnas necesarias si no existen
$check_cols = $conn->query("SHOW COLUMNS FROM lockers LIKE 'rental_type'");
if ($check_cols->num_rows == 0) {
    $conn->query("ALTER TABLE lockers ADD COLUMN rental_type VARCHAR(50) DEFAULT NULL");
    $conn->query("ALTER TABLE lockers ADD COLUMN expiration_date DATETIME DEFAULT NULL");
}

// Fix for gender column truncation (ensure it supports 'Unisex')
$conn->query("ALTER TABLE lockers MODIFY COLUMN gender VARCHAR(50)");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['add'])) {
        $numero_casillero = $_POST['numero_casillero'];

        $sql = "INSERT INTO lockers (lockernum) VALUES ('$numero_casillero')";
        if ($conn->query($sql) === TRUE) {
            $alerts_html .= '<div class="alert alert-success" role="alert">
                                ' . $translations["success-add-locker"] . '
                            </div>';
            $action = $translations['success-add-locker'] . ' ' . $numero_casillero;
            $actioncolor = 'warning';
            $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
                                VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $userid, $action, $actioncolor);
            $stmt->execute();
            header("Refresh:1");
        } else {
            $alerts_html .= "Error: " . $sql . "<br>" . $conn->error;
        }
    }

    // Lógica para RENTAR casillero
    if (isset($_POST['rent_locker'])) {
        $locker_id = intval($_POST['locker_id']);
        $user_id = intval($_POST['user_id']);
        $rental_type = $_POST['rental_type'];

        // Calcular fecha de expiración
        $days = 0;
        switch ($rental_type) {
            case 'weekly':
                $days = 7;
                break;
            case 'monthly':
                $days = 30;
                break;
            case 'quarterly':
                $days = 90;
                break;
        }
        $expiration = date('Y-m-d H:i:s', strtotime("+$days days"));

        $price = floatval($_POST['price'] ?? 0);
        $payment_method = $_POST['payment_method'] ?? 'Cash';

        // Verificar usuario
        $checkUser = $conn->query("SELECT userid, firstname, lastname FROM users WHERE userid = '$user_id'");
        if ($checkUser->num_rows > 0) {
            $userData = $checkUser->fetch_assoc();
            $userName = $userData['firstname'] . ' ' . $userData['lastname'];

            $stmt = $conn->prepare("UPDATE lockers SET user_id = ?, rental_type = ?, expiration_date = ? WHERE id = ?");
            $stmt->bind_param("issi", $user_id, $rental_type, $expiration, $locker_id);
            if ($stmt->execute()) {
                $alerts_html .= '<div class="alert alert-success">Renta asignada correctamente.</div>';
                // Log
                $action = "Renta de casillero ID $locker_id a usuario $user_id ($rental_type)";
                $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ('$userid', '$action', 'success', NOW())");
                
                // Obtener número de casillero para la descripción
                $lockerQ = $conn->query("SELECT lockernum FROM lockers WHERE id = '$locker_id'");
                $lockerRow = $lockerQ->fetch_assoc();
                
                $typeLabel = $rental_type;
                if ($typeLabel == 'weekly') $typeLabel = $translations['weekly'];
                if ($typeLabel == 'monthly') $typeLabel = $translations['Jan'];
                if ($typeLabel == 'quarterly') $typeLabel = $translations['quarterly'];
                $description = "Renta Casillero #" . $lockerRow['lockernum'] . " (" . ucfirst($typeLabel) . ")";

                // Generar PDF
                require_once __DIR__ . '/../../invoices/generator.php';
                $pdfData = [
                    'business_name' => $business_name,
                    'client_name' => $userName,
                    'userid' => $user_id,
                    'payment_method' => $payment_method,
                    'amount' => number_format($price, 2),
                    'currency' => $currency,
                    'items' => [['name' => $description, 'price' => number_format($price, 2)]],
                    'description_text' => 'Renta de casillero'
                ];
                $pdfFile = generateInvoicePDF($pdfData);
                $route = $pdfFile ? $pdfFile : 'error.pdf';

                $inv_sql = "INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, description, route) VALUES (?, ?, ?, 'Locker', ?, 'paid', NOW(), ?, ?)";
                $inv_stmt = $conn->prepare($inv_sql);
                $inv_stmt->bind_param("isdsss", $user_id, $userName, $price, $payment_method, $description, $route);

                if ($inv_stmt && $inv_stmt->execute()) {
                    $inv_stmt->close();
                } else {
                    $alerts_html .= '<div class="alert alert-warning">Error guardando factura: ' . ($inv_stmt ? $inv_stmt->error : $conn->error) . '</div>';
                }

                header("Refresh:1");
            } else {
                $alerts_html .= '<div class="alert alert-danger">Error al asignar renta.</div>';
            }
            $stmt->close();
        } else {
            $alerts_html .= '<div class="alert alert-danger">' . $translations["user-notexist"] . '</div>';
        }
    }

    // Lógica para TERMINAR renta
    if (isset($_POST['release_locker'])) {
        $locker_id = $_POST['locker_id'];
        if ($conn->query("UPDATE lockers SET user_id = NULL, rental_type = NULL, expiration_date = NULL WHERE id = $locker_id")) {
            $alerts_html .= '<div class="alert alert-success">Renta terminada y casillero liberado.</div>';
            // Log
            $action = "Casillero ID $locker_id liberado";
            $conn->query("INSERT INTO logs (userid, action, actioncolor, time) VALUES ('$userid', '$action', 'warning', NOW())");
            header("Refresh:1");
        }
    }
}

$sql = "SELECT * FROM lockers";
// Consulta actualizada para obtener datos del dueño
$sql = "SELECT l.*, u.firstname, u.lastname 
        FROM lockers l 
        LEFT JOIN users u ON l.user_id = u.userid";
$result = $conn->query($sql);

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];

    $sql = "SELECT lockernum FROM lockers WHERE id=$id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $lockernum = $row['lockernum'];

        $sql = "DELETE FROM lockers WHERE id=$id";
        if ($conn->query($sql) === TRUE) {
            $alerts_html .= '<div class="alert alert-success" role="alert">
                                ' . $translations["success-delete-locker"] . '
                            </div>';
            $action = $translations['success-delete-locker'] . ' ' . $lockernum;
            $actioncolor = 'warning';

            $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
                            VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iss", $userid, $action, $actioncolor);
            $stmt->execute();

            header("Location: " . strtok($_SERVER["REQUEST_URI"], '?'));
        } else {
            $alerts_html .= "Error: " . $conn->error;
        }
    } else {
        $alerts_html .= "Error: Locker not found.";
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

$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

$conn->close();
?>


<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo $translations["dashboard"]; ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">

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
                <a class="navbar-brand" href="#"><img src="../../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown active">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i> <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li class="active"><a href="#"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../boss/packages"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
                        <li class="active"><a href="../../boss/chroom"><i class="bi bi-duffle"></i> <?php echo $translations["chroompage"]; ?></a></li>
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
                        <a class="sidebar-link" href="../../users">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../boss/sell">
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
                        <a class="sidebar-link" href="../../shop/tickets">
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
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
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
                <div class="row">
                    <div class="col-sm-12">
                        <?php echo $alerts_html; ?>
                        <div class="card shadow">
                            <div class="card-body">

                                <?php
                                if ($is_boss == 1) {
                                ?>
                                    <h2 class="mt-4"><?php echo $translations["newlocker"]; ?></h2>
                                    <form method="post" action="">
                                        <div class="form-group">
                                            <label for="numero_casillero"><?php echo $translations["lockernum"]; ?></label>
                                            <input type="number" class="form-control" id="numero_casillero" name="numero_casillero" required>
                                        </div>
                                        <button type="submit" name="add" class="btn btn-primary mt-5"><i class="bi bi-plus-circle"></i> <?php echo $translations["add"]; ?></button>
                                    </form>

                                    <div class="table-responsive">
                                    <table class="mt-4 table table-bordered">
                                        <thead>
                                            <tr>
                                                <th><?php echo $translations["lockernum"]; ?></th>
                                                <th><?php echo $translations["owner"]; ?></th>
                                                <th><?php echo $translations["rental_type"]; ?></th>
                                                <th><?php echo $translations["remaining_time"]; ?></th>
                                                <th><?php echo $translations["interact"]; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            if ($result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    echo "<tr>
            <td>{$row['lockernum']}</td>";

                                                    // Columna Dueño
                                                    if (!empty($row['user_id']) && !empty($row['firstname'])) {
                                                        echo "<td>" . htmlspecialchars($row['firstname'] . ' ' . $row['lastname']) . " (" . $row['user_id'] . ")</td>";
                                                    } else {
                                                        echo "<td><span class='label label-success'>Libre</span></td>";
                                                    }

                                                    // Columna Tipo
                                                    $typeLabel = $row['rental_type'] ?? '-';
                                                    if ($typeLabel == 'weekly') $typeLabel = $translations['weekly'];
                                                    if ($typeLabel == 'monthly') $typeLabel = $translations['Jan']; // Usando mes genérico o key específica
                                                    if ($typeLabel == 'quarterly') $typeLabel = $translations['quarterly'];
                                                    echo "<td>" . ucfirst($typeLabel) . "</td>";

                                                    // Columna Tiempo Restante
                                                    if (!empty($row['expiration_date'])) {
                                                        $now = new DateTime();
                                                        $exp = new DateTime($row['expiration_date']);
                                                        if ($now > $exp) {
                                                            echo "<td><span class='label label-danger'>" . $translations['expired'] . "</span></td>";
                                                        } else {
                                                            $diff = $now->diff($exp);
                                                            $daysStr = $diff->days > 0 ? $diff->days . " " . ($translations['days'] ?? 'd') . " " : "";
                                                            echo "<td>" . $daysStr . $diff->h . "h " . $diff->i . "m</td>";
                                                        }
                                                    } else {
                                                        echo "<td>-</td>";
                                                    }

                                                    echo "<td>";
                                                    // Botones de Acción
                                                    if (empty($row['user_id'])) {
                                                        // Botón Rentar
                                                        echo "<button class='btn btn-primary btn-sm rent-btn' data-id='{$row['id']}' data-num='{$row['lockernum']}' data-toggle='modal' data-target='#rentModal'><i class='bi bi-key'></i> " . $translations['rent_locker'] . "</button> ";
                                                        // Botón Eliminar (solo si está libre)
                                                        echo "<a href='?delete={$row['id']}' class='btn btn-danger btn-sm'><i class='bi bi-trash'></i></a>";
                                                    } else {
                                                        // Botón Liberar
                                                        echo "<form method='POST' style='display:inline;' onsubmit='return confirm(\"¿Estás seguro?\");'><input type='hidden' name='locker_id' value='{$row['id']}'><button type='submit' name='release_locker' class='btn btn-warning btn-sm'><i class='bi bi-unlock'></i> " . $translations['release_locker'] . "</button></form>";
                                                    }
                                                    echo "</td>
        </tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='4'>{$translations["notlockers"]}</td></tr>";
                                            }
                                            ?>

                                        </tbody>
                                    </table>
                                    </div>
                                <?php
                                } else {
                                    echo $translations["dont-access"];
                                }
                                ?>

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

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <p><?php echo $translations["exit-modal"]; ?></p>
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
                    <h4 class="modal-title" id="rentModalLabel"><?php echo $translations["rent_locker"]; ?></h4>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="locker_id" id="modal_locker_id">
                        <p>Casillero #: <strong id="modal_locker_num"></strong></p>

                        <div class="form-group">
                            <label><?php echo $translations["userid"]; ?></label>
                            <input type="text" name="user_id" class="form-control" required placeholder="Escanea QR o escribe ID">
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations["rental_type"]; ?></label>
                            <select name="rental_type" class="form-control" required>
                                <option value="weekly"><?php echo $translations["weekly"]; ?> (7 <?php echo $translations["day"]; ?>)</option>
                                <option value="monthly"><?php echo $translations["Jan"]; ?>/Mensual (30 <?php echo $translations["day"]; ?>)</option>
                                <option value="quarterly"><?php echo $translations["quarterly"]; ?> (90 <?php echo $translations["day"]; ?>)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations["price"]; ?></label>
                            <input type="number" step="0.01" name="price" class="form-control" required placeholder="0.00">
                        </div>

                        <div class="form-group">
                            <label><?php echo $translations["paymenttype"]; ?></label>
                            <select name="payment_method" class="form-control" required>
                                <option value="Cash"><?php echo $translations["cash"]; ?></option>
                                <option value="Card"><?php echo $translations["card"]; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal"><?php echo $translations["close"]; ?></button>
                        <button type="submit" name="rent_locker" class="btn btn-primary"><?php echo $translations["save"]; ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="spinner"></div>
        <h4 style="margin-top: 20px; color: #333;">Procesando...</h4>
    </div>

    <!-- SCRIPTS! -->
    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        $(document).on("click", ".rent-btn", function() {
            var lockerId = $(this).data('id');
            var lockerNum = $(this).data('num');
            $("#modal_locker_id").val(lockerId);
            $("#modal_locker_num").text(lockerNum);
        });

        $('form').on('submit', function() {
            if (!$(this).attr('target')) {
                $('#loading-overlay').css('display', 'flex');
            }
        });
    </script>
</body>

</html>