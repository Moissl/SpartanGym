<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}

$userid = $_SESSION['adminuser'];

$alerts_html = "";

function read_env_file($file_path)
{
    if (!file_exists($file_path)) {
        die("No se puede encontrar el archivo .env: $file_path");
    }
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];

    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line, 2);
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
$currency = $env_data["CURRENCY"] ?? '';

$lang = $lang_code;
$langDir = __DIR__ . "/../../assets/lang/";
$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("No se puede encontrar el archivo de idioma: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
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

// Verificar y crear columnas necesarias si no existen (añadir solo las que falten)
$colType = $conn->query("SHOW COLUMNS FROM invoices LIKE 'type'");
if (!$colType || $colType->num_rows == 0) {
    $conn->query("ALTER TABLE invoices ADD COLUMN type VARCHAR(50) DEFAULT NULL");
}
$colPm = $conn->query("SHOW COLUMNS FROM invoices LIKE 'payment_method'");
if (!$colPm || $colPm->num_rows == 0) {
    $conn->query("ALTER TABLE invoices ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL");
}
$colDesc = $conn->query("SHOW COLUMNS FROM invoices LIKE 'description'");
if (!$colDesc || $colDesc->num_rows == 0) {
    $conn->query("ALTER TABLE invoices ADD COLUMN description TEXT DEFAULT NULL");
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

// Lógica de Exportación a CSV
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    $filenameDate = date('Y-m-d');
    $whereClauses = [];

    if (isset($_GET['date']) && !empty($_GET['date'])) {
        $filterDate = $conn->real_escape_string($_GET['date']);
        $whereClauses[] = "DATE(created_at) = '$filterDate'";
        $filenameDate = $filterDate;
    }
    
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $search = $conn->real_escape_string($_GET['search']);
        $whereClauses[] = "(name LIKE '%$search%' OR id = '$search')";
    }

    $whereClause = (count($whereClauses) > 0) ? " WHERE " . implode(' AND ', $whereClauses) : "";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=facturas_' . $filenameDate . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, array('ID Factura', 'Cliente', 'Monto', 'Moneda', 'Fecha', 'Estado', 'Concepto', 'Método de Pago', 'Archivo'));

    $sqlExport = "SELECT * FROM invoices" . $whereClause . " ORDER BY created_at DESC";
    $resultExport = $conn->query($sqlExport);
    while ($row = $resultExport->fetch_assoc()) {
        $created_at = new DateTime($row['created_at']);
        // Concepto (usar description si existe, de lo contrario fallback a type)
        if (!empty($row['description'])) {
            $typeDisplay = $row['description'];
        } else {
            $typeDisplay = $row['type'];
            if ($row['type'] == 'Locker') $typeDisplay = $translations['chroompage'] ?? 'Casillero';
            elseif ($row['type'] == 'Ticket') $typeDisplay = $translations['ticketspage'] ?? 'Pase';
            elseif ($row['type'] == 'Balance') $typeDisplay = $translations['profilebalance'] ?? 'Saldo';
            elseif ($row['type'] == 'Product') $typeDisplay = $translations['shopcategory'] ?? 'Producto';
        }
        fputcsv($output, array($row['id'], $row['name'], $row['price'], $currency, $created_at->format('Y-m-d H:i:s'), $row['status'], $typeDisplay, $row['payment_method'], $row['route']));
    }
    fclose($output);
    exit();
}

$records_per_page = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start_from = ($page - 1) * $records_per_page;

$whereClauses = [];
if (isset($_GET['date']) && !empty($_GET['date'])) {
    $filterDate = $conn->real_escape_string($_GET['date']);
    $whereClauses[] = "DATE(created_at) = '$filterDate'";
}
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
    $whereClauses[] = "(name LIKE '%$search%' OR id = '$search')";
}
$whereSql = (count($whereClauses) > 0) ? " WHERE " . implode(' AND ', $whereClauses) : "";

$sql = "SELECT * FROM invoices $whereSql ORDER BY created_at DESC LIMIT $start_from, $records_per_page";
$result = $conn->query($sql);

$total_records_query = "SELECT COUNT(*) AS total FROM invoices $whereSql";
$total_records_result = $conn->query($total_records_query);
$total_records = $total_records_result->fetch_assoc()['total'];
$total_pages = ceil($total_records / $records_per_page);



?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($lang_code, ENT_QUOTES, 'UTF-8'); ?>">

<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($translations["dashboard"], ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
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
                <a class="navbar-brand" href="#"><img src="../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li><a href="../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
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
                    <li><a href="#" data-toggle="modal" data-target="#logoutModal"><i class="bi bi-box-arrow-right"></i> <?php echo htmlspecialchars($translations["logout"], ENT_QUOTES, 'UTF-8'); ?></a></li>
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
                    <li class="sidebar-item active">
                        <a href="#" class="sidebar-link">
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
                        <?php echo htmlspecialchars($translations["support"], ENT_QUOTES, 'UTF-8'); ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank" rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo htmlspecialchars($translations["docs"], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo htmlspecialchars($translations["logout"], ENT_QUOTES, 'UTF-8'); ?>
                    </button>
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <?php echo $alerts_html; ?>
                        <div class="text-right mb-3" style="margin-bottom: 15px;">
                            <form action="" method="GET" class="form-inline" style="display: inline-block;">
                                <div class="form-group">
                                    <input type="text" name="search" class="form-control" placeholder="<?php echo $translations['search'] ?? 'Buscar'; ?>..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($_GET['date'] ?? ''); ?>">
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                                <button type="submit" name="export" value="csv" class="btn btn-success"><i class="bi bi-file-earmark-spreadsheet"></i> CSV</button>
                            </form>
                        </div>
                        <div class="card shadow">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped text-center">
                                        <thead>
                                            <tr>
                                                <th><?= $translations["fullname"]; ?></th>
                                                <th><?= $translations["price"]; ?></th>
                                                <th><?= $translations["date-log"]; ?></th>
                                                <th><?= $translations["status"]; ?></th>
                                                <th><?= $translations["invoicedescription"] ?? "Concepto"; ?></th>
                                                <th><?= $translations["paymenttype"]; ?></th>
                                                <th><?= $translations["interact"]; ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            if ($result->num_rows > 0) {
                                                while ($row = $result->fetch_assoc()) {
                                                    echo "<tr>";
                                                    echo "<td><a class='linkhref' href='../users/edit/?user=" . $row['userid'] . "'>" . $row['name'] . "</a></td>";
                                                    echo "<td>" . $row['price'] . " " . $currency . "</td>";
                                                    $created_at = new DateTime($row['created_at']);
                                                    echo "<td>" . $created_at->format('Y-m-d H:i:s') . "</td>";
                                                    echo "<td><span class=\"" . ($row["status"] === 'unpaid' ? 'label label-danger' : 'label label-success text-capitalized') . "\">" . ($row["status"] === 'unpaid' ? $translations["unpaid"] : $translations["paid"]) . "</span></td>";
                                                    
                                                    // Concepto (Type)
                                                    if (!empty($row['description'])) {
                                                        $typeDisplay = $row['description'];
                                                    } else {
                                                        $typeDisplay = $row['type'];
                                                        if ($row['type'] == 'Locker') $typeDisplay = $translations['chroompage'] ?? 'Casillero';
                                                        elseif ($row['type'] == 'Ticket') $typeDisplay = $translations['ticketspage'] ?? 'Pase';
                                                        elseif ($row['type'] == 'Balance') $typeDisplay = $translations['profilebalance'] ?? 'Saldo';
                                                        elseif ($row['type'] == 'Product') $typeDisplay = $translations['shopcategory'] ?? 'Producto';
                                                    }
                                                    echo "<td>" . htmlspecialchars($typeDisplay ?? '') . "</td>";

                                                    // Método de Pago
                                                    $pmDisplay = $row['payment_method'];
                                                    if ($row['payment_method'] == 'Cash') $pmDisplay = $translations['cash'] ?? 'Efectivo';
                                                    elseif ($row['payment_method'] == 'Card') $pmDisplay = $translations['card'] ?? 'Tarjeta';
                                                    elseif ($row['payment_method'] == 'Balance') $pmDisplay = $translations['profilebalance'] ?? 'Saldo';
                                                    elseif (empty($row['payment_method'])) $pmDisplay = '-';
                                                    echo "<td>" . htmlspecialchars($pmDisplay ?? '') . "</td>";

                                                    echo "<td><a target='_blank' href='../../assets/docs/invoices/" . $row['route'] . "' class='btn btn-primary'><i class='bi bi-eye'></i></a></td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='7' class='text-center'>" . $translations["youdonthaveinvoices"] . "</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>

                                <nav aria-label="Page_nav">
                                    <ul class="pagination justify-content-center">
                                        <?php
                                        $queryParams = $_GET;
                                        unset($queryParams['page']);
                                        unset($queryParams['export']);
                                        $queryString = http_build_query($queryParams);
                                        $linkPrefix = "?" . ($queryString ? $queryString . "&" : "") . "page=";

                                        // Botón Anterior
                                        if ($page > 1) {
                                            echo "<li class='page-item'><a class='page-link' href='" . $linkPrefix . ($page - 1) . "'>&laquo;</a></li>";
                                        } else {
                                            echo "<li class='page-item disabled'><span class='page-link'>&laquo;</span></li>";
                                        }

                                        $adjacents = 2;
                                        $p_start = max(1, $page - $adjacents);
                                        $p_end = min($total_pages, $page + $adjacents);

                                        if ($p_start > 1) {
                                            echo "<li class='page-item'><a class='page-link' href='" . $linkPrefix . "1'>1</a></li>";
                                            if ($p_start > 2) {
                                                echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                                            }
                                        }

                                        for ($i = $p_start; $i <= $p_end; $i++) {
                                            echo "<li class='page-item " . ($i == $page ? 'active' : '') . "'><a class='page-link' href='" . $linkPrefix . $i . "'>" . $i . "</a></li>";
                                        }

                                        if ($p_end < $total_pages) {
                                            if ($p_end < $total_pages - 1) {
                                                echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
                                            }
                                            echo "<li class='page-item'><a class='page-link' href='" . $linkPrefix . $total_pages . "'>" . $total_pages . "</a></li>";
                                        }

                                        // Botón Siguiente
                                        if ($page < $total_pages) {
                                            echo "<li class='page-item'><a class='page-link' href='" . $linkPrefix . ($page + 1) . "'>&raquo;</a></li>";
                                        } else {
                                            echo "<li class='page-item disabled'><span class='page-link'>&raquo;</span></li>";
                                        }
                                        ?>
                                    </ul>
                                </nav>
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

    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel" aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <p><?php echo htmlspecialchars($translations["exit-modal"], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <div class="modal-footer">
                    <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo htmlspecialchars($translations["not-yet"], ENT_QUOTES, 'UTF-8'); ?></a>
                    <a href="../logout.php" type="button" class="btn btn-danger"><?php echo htmlspecialchars($translations["confirm"], ENT_QUOTES, 'UTF-8'); ?></a>
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
    <script src="../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        $('form').on('submit', function() {
            if (!$(this).attr('target')) {
                $('#loading-overlay').css('display', 'flex');
            }
        });
    </script>
</body>

</html>