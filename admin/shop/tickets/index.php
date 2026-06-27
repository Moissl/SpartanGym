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

$env_data = read_env_file('../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';
$currency_env = $env_data["CURRENCY"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$alerts_html = "";

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

// Verificar si existe la columna 'hidden' y crearla si no
$check_col = $conn->query("SHOW COLUMNS FROM tickets LIKE 'hidden'");
if ($check_col->num_rows == 0) {
    $conn->query("ALTER TABLE tickets ADD COLUMN hidden TINYINT(1) DEFAULT 0");
}

// Verificar si existen las columnas para días válidos y cantidad de personas
$check_col_days = $conn->query("SHOW COLUMNS FROM tickets LIKE 'valid_days'");
if ($check_col_days->num_rows == 0) {
    $conn->query("ALTER TABLE tickets ADD COLUMN valid_days VARCHAR(255) DEFAULT '[\"1\",\"2\",\"3\",\"4\",\"5\",\"6\",\"7\"]'");
}
$check_col_people = $conn->query("SHOW COLUMNS FROM tickets LIKE 'entry_people'");
if ($check_col_people->num_rows == 0) {
    $conn->query("ALTER TABLE tickets ADD COLUMN entry_people INT DEFAULT 1");
}
$check_col_color = $conn->query("SHOW COLUMNS FROM tickets LIKE 'color'");
if ($check_col_color->num_rows == 0) {
    $conn->query("ALTER TABLE tickets ADD COLUMN color VARCHAR(20) DEFAULT '#337ab7'");
}

// Verificar si existe la columna sort_order
$check_col_sort = $conn->query("SHOW COLUMNS FROM tickets LIKE 'sort_order'");
if ($check_col_sort->num_rows == 0) {
    $conn->query("ALTER TABLE tickets ADD COLUMN sort_order INT DEFAULT 0");
}

// Manejador AJAX para guardar el orden
if (isset($_POST['update_order']) && isset($_POST['ticket_ids'])) {
    $ticket_ids = $_POST['ticket_ids'];
    foreach ($ticket_ids as $index => $id) {
        $id = intval($id);
        $order_val = intval($index);
        $conn->query("UPDATE tickets SET sort_order = $order_val WHERE id = $id");
    }
    exit('Success');
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

if (isset($_POST['add_ticket'])) {
    $name = $_POST['name'];
    $expire_days = $_POST['expire_days'] === 'unlimited' ? 'NULL' : $_POST['expire_days'];
    $price = $_POST['price'];
    $occasions = $_POST['occasions'] === '' ? 'NULL' : $_POST['occasions'];
    $hidden = isset($_POST['hidden']) ? 1 : 0;
    $entry_people = isset($_POST['entry_people']) ? intval($_POST['entry_people']) : 1;
    $valid_days = isset($_POST['valid_days']) ? json_encode($_POST['valid_days']) : '["1","2","3","4","5","6","7"]';
    $color = isset($_POST['color']) ? $_POST['color'] : '#337ab7';

    $sql = "INSERT INTO tickets (name, expire_days, price, occasions, hidden, entry_people, valid_days, color) 
            VALUES ('$name', $expire_days, $price, $occasions, $hidden, $entry_people, '$valid_days', '$color')";
    mysqli_query($conn, $sql);
}

if (isset($_POST['edit_ticket'])) {
    $id = intval($_POST['ticket_id']);
    $name = $_POST['name'];
    $expire_days = $_POST['expire_days'] === 'unlimited' ? 'NULL' : $_POST['expire_days'];
    $price = $_POST['price'];
    $occasions = $_POST['occasions'] === '' ? 'NULL' : $_POST['occasions'];
    $hidden = isset($_POST['hidden']) ? 1 : 0;
    $entry_people = isset($_POST['entry_people']) ? intval($_POST['entry_people']) : 1;
    $valid_days = isset($_POST['valid_days']) ? json_encode($_POST['valid_days']) : '["1","2","3","4","5","6","7"]';
    $color = isset($_POST['color']) ? $_POST['color'] : '#337ab7';

    $sql = "UPDATE tickets SET name='$name', expire_days=$expire_days, price=$price, occasions=$occasions, hidden=$hidden, entry_people=$entry_people, valid_days='$valid_days', color='$color' WHERE id=$id";
    mysqli_query($conn, $sql);
    header("Location: index.php");
    exit();
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    $sql = "DELETE FROM tickets WHERE id = $id";
    mysqli_query($conn, $sql);
}

if (isset($_GET['toggle_hidden'])) {
    $id = intval($_GET['toggle_hidden']);
    $res = $conn->query("SELECT hidden FROM tickets WHERE id = $id");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        $new_status = $row['hidden'] ? 0 : 1;
        $conn->query("UPDATE tickets SET hidden = $new_status WHERE id = $id");
    }
    header("Location: index.php");
    exit();
}

// Se elimina el sistema de ordenamiento por URL y se prioriza el de 'drag and drop' (sort_order).
$sql = "SELECT * FROM tickets ORDER BY sort_order ASC";
$result = mysqli_query($conn, $sql);

// Separar los pases en dos arreglos: activos y ocultos.
$active_tickets = [];
$hidden_tickets = [];
while ($row = mysqli_fetch_assoc($result)) {
    if (isset($row['hidden']) && $row['hidden']) {
        $hidden_tickets[] = $row;
    } else {
        $active_tickets[] = $row;
    }
}

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
                    <li class="active"><a href="#"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
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
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
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
                    <li><a class="sidebar-link active" href="../../trainers/timetable">
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
                <div class="d-none topnav d-sm-inline-block">
                    <a href="https://gymoneglobal.com/discord" class="btn btn-primary mx-1" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-question-circle"></i>
                        <?php echo $translations["support"]; ?>
                    </a>

                    <a href="https://gymoneglobal.com/docs" class="btn btn-danger" target="_blank"
                        rel="noopener noreferrer">
                        <i class="bi bi-journals"></i>
                        <?php echo $translations["docs"]; ?>
                    </a>
                    <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#logoutModal">
                        <?php echo $translations["logout"]; ?>
                    </button>
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <?php
                if ($is_boss == 1 && $is_new_version_available) {
                ?>
                    <div class="row justify-content-center">
                        <div class="col-sm-5">
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
                        <?php echo $alerts_html; ?>
                        <?php
                        if ($is_boss == 1) {
                        ?>
                            <div class="card mb-2">
                                <div class="card-header">
                                    <h2 class="card-title"><?php echo $translations["ticketsandpassesadd"]; ?></h2>
                                </div>
                                <div class="card-body">
                                    <form method="post">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="name" class="form-label"><?php echo $translations["ticketspassname"]; ?></label>
                                                    <input type="text" class="form-control" id="name" name="name" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="expire_days" class="form-label"><?php echo $translations["tickettableexpiry"]; ?></label>
                                                    <input type="text" class="form-control" id="expire_days" name="expire_days" placeholder="<?php echo $translations["expiredatetext"]; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="price" class="form-label"><?php echo $translations["price"]; ?> (<?php echo $currency_env; ?>)</label>
                                                    <input type="number" class="form-control" id="price" name="price" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="occasions" class="form-label"><?php echo $translations["tickettableoccassion"]; ?></label>
                                                    <input type="number" class="form-control" id="occasions" name="occasions" placeholder="<?php echo $translations["onlyfordaily"]; ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-check" style="margin-top: 30px;">
                                                    <input class="form-check-input" type="checkbox" id="hidden" name="hidden">
                                                    <label class="form-check-label" for="hidden">Ocultar en precios (No visible al público)</label>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="color" class="form-label">Color Distintivo</label>
                                                    <input type="color" class="form-control" id="color" name="color" value="#337ab7" style="height: 40px;">
                                                </div>
                                            </div>
                                            <div class="col-md-12">
                                                <hr>
                                                <h4>Configuración Avanzada</h4>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Días Válidos (Calendario)</label><br>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="1" checked> Lun</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="2" checked> Mar</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="3" checked> Mié</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="4" checked> Jue</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="5" checked> Vie</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="6" checked> Sáb</label>
                                                    <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="7" checked> Dom</label>
                                                    <p class="help-block"><small>El pase solo funcionará los días seleccionados.</small></p>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label for="entry_people">Personas por Pase (Grupo/Familia)</label>
                                                    <input type="number" class="form-control" id="entry_people" name="entry_people" value="1" min="1">
                                                    <p class="help-block"><small>Ej: Ponga 3 si es un pase familiar (Padre + 2 hijos). Se contarán 3 personas en el aforo.</small></p>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-primary mt-3" name="add_ticket"><i class="bi bi-plus-circle"></i> <?php echo $translations["ticketsandpassesadd"]; ?></button>
                                    </form>

                                </div>
                            </div>
                        <?php
                        } else {
                            echo $translations["dont-access"];
                        }
                        ?>
                    </div>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <?php echo $alerts_html; ?>
                        <?php
                        if ($is_boss == 1) {
                        ?>
                            <div class="card">
                                <div class="card-header">
                                    <h2 class="card-title"><?php echo $translations["ticketsandpasseslist"]; ?></h2>
                                </div>
                                <div class="card-body">
                                <ul class="nav nav-tabs" style="margin-bottom: 20px; font-size: 16px; font-weight: bold;">
                                    <li class="active"><a data-toggle="tab" href="#active-tickets"><i class="bi bi-eye"></i> Visibles</a></li>
                                    <li><a data-toggle="tab" href="#hidden-tickets"><i class="bi bi-eye-slash"></i> Ocultos</a></li>
                                </ul>

                                <div class="tab-content">
                                    <!-- Pases Visibles -->
                                    <div id="active-tickets" class="tab-pane fade in active">
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 40px;"></th>
                                                        <th>ID</th>
                                                        <th><?php echo $translations["tickettablename"]; ?></th>
                                                        <th><?php echo $translations["tickettableexpiry"]; ?></th>
                                                        <th><?php echo $translations["price"]; ?> (<?php echo $currency_env; ?>)</th>
                                                        <th><?php echo $translations["tickettableoccassion"]; ?></th>
                                                        <th>Personas</th>
                                                        <th>Visibilidad</th>
                                                        <th><?php echo $translations["interact"]; ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="sortable-tickets">
                                                    <?php foreach ($active_tickets as $row): ?>
                                                        <tr style="border-left: 5px solid <?php echo isset($row['color']) ? $row['color'] : '#337ab7'; ?>;" data-id="<?= $row['id'] ?>">
                                                            <td style="cursor: grab; text-align: center; vertical-align: middle;" class="drag-handle" title="Arrastrar para ordenar"><i class="bi bi-grip-vertical text-muted" style="font-size: 1.5rem;"></i></td>
                                                            <td><?= $row['id'] ?></td>
                                                            <td><?= $row['name'] ?></td>
                                                            <td><?= is_null($row['expire_days']) ? $translations["unlimited"] : $row['expire_days'] ?></td>
                                                            <td><?= $row['price'] ?></td>
                                                            <td><?= is_null($row['occasions']) ? '-' : $row['occasions'] ?></td>
                                                            <td><?= isset($row['entry_people']) ? $row['entry_people'] : '1' ?></td>
                                                            <td>
                                                                <span class="label label-success">Visible</span>
                                                            </td>
                                                            <td>
                                                                <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm"><i class="bi bi-x-circle"></i> <?php echo $translations["delete"]; ?></a>
                                                                <a href="?toggle_hidden=<?= $row['id'] ?>" class="btn btn-info btn-sm"><i class="bi bi-eye-slash"></i></a>
                                                                <button type="button" class="btn btn-warning btn-sm btn-edit" 
                                                                    data-id="<?= $row['id'] ?>" 
                                                                    data-name="<?= htmlspecialchars($row['name']) ?>" 
                                                                    data-expire="<?= is_null($row['expire_days']) ? 'unlimited' : $row['expire_days'] ?>" 
                                                                    data-price="<?= $row['price'] ?>" 
                                                                    data-occasions="<?= is_null($row['occasions']) ? '' : $row['occasions'] ?>" 
                                                                    data-hidden="<?= $row['hidden'] ?>" 
                                                                    data-people="<?= isset($row['entry_people']) ? $row['entry_people'] : 1 ?>" 
                                                                    data-validdays='<?= htmlspecialchars($row['valid_days'], ENT_QUOTES, 'UTF-8') ?>'
                                                                    data-color="<?= isset($row['color']) ? $row['color'] : '#337ab7' ?>"
                                                                    data-toggle="modal" data-target="#editTicketModal"><i class="bi bi-pencil"></i></button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>

                                    <!-- Pases Ocultos -->
                                    <div id="hidden-tickets" class="tab-pane fade">
                                        <div class="table-responsive">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 40px;"></th>
                                                        <th>ID</th>
                                                        <th><?php echo $translations["tickettablename"]; ?></th>
                                                        <th><?php echo $translations["tickettableexpiry"]; ?></th>
                                                        <th><?php echo $translations["price"]; ?> (<?php echo $currency_env; ?>)</th>
                                                        <th><?php echo $translations["tickettableoccassion"]; ?></th>
                                                        <th>Personas</th>
                                                        <th>Visibilidad</th>
                                                        <th><?php echo $translations["interact"]; ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody class="sortable-tickets">
                                                    <?php foreach ($hidden_tickets as $row): ?>
                                                        <tr style="border-left: 5px solid <?php echo isset($row['color']) ? $row['color'] : '#337ab7'; ?>;" data-id="<?= $row['id'] ?>">
                                                            <td style="cursor: grab; text-align: center; vertical-align: middle;" class="drag-handle" title="Arrastrar para ordenar"><i class="bi bi-grip-vertical text-muted" style="font-size: 1.5rem;"></i></td>
                                                            <td><?= $row['id'] ?></td>
                                                            <td><?= $row['name'] ?></td>
                                                            <td><?= is_null($row['expire_days']) ? $translations["unlimited"] : $row['expire_days'] ?></td>
                                                            <td><?= $row['price'] ?></td>
                                                            <td><?= is_null($row['occasions']) ? '-' : $row['occasions'] ?></td>
                                                            <td><?= isset($row['entry_people']) ? $row['entry_people'] : '1' ?></td>
                                                            <td>
                                                                <span class="label label-warning">Oculto</span>
                                                            </td>
                                                            <td>
                                                                <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger btn-sm"><i class="bi bi-x-circle"></i> <?php echo $translations["delete"]; ?></a>
                                                                <a href="?toggle_hidden=<?= $row['id'] ?>" class="btn btn-info btn-sm"><i class="bi bi-eye"></i></a>
                                                                <button type="button" class="btn btn-warning btn-sm btn-edit" 
                                                                    data-id="<?= $row['id'] ?>" 
                                                                    data-name="<?= htmlspecialchars($row['name']) ?>" 
                                                                    data-expire="<?= is_null($row['expire_days']) ? 'unlimited' : $row['expire_days'] ?>" 
                                                                    data-price="<?= $row['price'] ?>" 
                                                                    data-occasions="<?= is_null($row['occasions']) ? '' : $row['occasions'] ?>" 
                                                                    data-hidden="<?= $row['hidden'] ?>" 
                                                                    data-people="<?= isset($row['entry_people']) ? $row['entry_people'] : 1 ?>" 
                                                                    data-validdays='<?= htmlspecialchars($row['valid_days'], ENT_QUOTES, 'UTF-8') ?>'
                                                                    data-color="<?= isset($row['color']) ? $row['color'] : '#337ab7' ?>"
                                                                    data-toggle="modal" data-target="#editTicketModal"><i class="bi bi-pencil"></i></button>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    </div>
                                </div>
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

    <!-- EDIT TICKET MODAL -->
    <div class="modal fade" id="editTicketModal" tabindex="-1" role="dialog" aria-labelledby="editTicketModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="editTicketModalLabel"><?php echo $translations["editbtn"] ?? "Editar"; ?> Ticket</h4>
                </div>
                <form method="post">
                    <div class="modal-body">
                        <input type="hidden" name="ticket_id" id="edit_ticket_id">
                        <div class="form-group">
                            <label for="edit_name" class="form-label"><?php echo $translations["ticketspassname"]; ?></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="form-group">
                            <label for="edit_expire_days" class="form-label"><?php echo $translations["tickettableexpiry"]; ?></label>
                            <input type="text" class="form-control" id="edit_expire_days" name="expire_days" placeholder="<?php echo $translations["expiredatetext"]; ?>">
                        </div>
                        <div class="form-group">
                            <label for="edit_price" class="form-label"><?php echo $translations["price"]; ?> (<?php echo $currency_env; ?>)</label>
                            <input type="number" class="form-control" id="edit_price" name="price" required step="0.01">
                        </div>
                        <div class="form-group">
                            <label for="edit_occasions" class="form-label"><?php echo $translations["tickettableoccassion"]; ?></label>
                            <input type="number" class="form-control" id="edit_occasions" name="occasions" placeholder="<?php echo $translations["onlyfordaily"]; ?>">
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="edit_hidden" name="hidden">
                            <label class="form-check-label" for="edit_hidden">Ocultar en precios (No visible al público)</label>
                        </div>
                        <div class="form-group">
                            <label for="edit_color" class="form-label">Color Distintivo</label>
                            <input type="color" class="form-control" id="edit_color" name="color" style="height: 40px;">
                        </div>
                        <hr>
                        <h4>Configuración Avanzada</h4>
                        <div class="form-group">
                            <label>Días Válidos (Calendario)</label><br>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="1" id="edit_day_1"> Lun</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="2" id="edit_day_2"> Mar</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="3" id="edit_day_3"> Mié</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="4" id="edit_day_4"> Jue</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="5" id="edit_day_5"> Vie</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="6" id="edit_day_6"> Sáb</label>
                            <label class="checkbox-inline"><input type="checkbox" name="valid_days[]" value="7" id="edit_day_7"> Dom</label>
                        </div>
                        <div class="form-group">
                            <label for="edit_entry_people">Personas por Pase (Grupo/Familia)</label>
                            <input type="number" class="form-control" id="edit_entry_people" name="entry_people" min="1">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>
                        <button type="submit" class="btn btn-primary" name="edit_ticket">Guardar Cambios</button>
                    </div>
                </form>
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
        <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel"
            aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-body">
                        <p><?php echo $translations["exit-modal"]; ?></p>
                    </div>
                    <div class="modal-footer">
                        <a type="button" class="btn btn-secondary"
                            data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                        <a href="../../logout.php" type="button"
                            class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                    </div>
                </div>
            </div>
        </div>

        <!-- SCRIPTS! -->

        <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var els = document.querySelectorAll('.sortable-tickets');
                els.forEach(function(el) {
                    if (el) {
                        new Sortable(el, {
                            handle: '.drag-handle',
                            animation: 150,
                            onEnd: function () {
                                var ticketIds = [];
                                $(el).find('tr').each(function () {
                                    ticketIds.push($(this).data('id'));
                                });
    
                                $.ajax({
                                    url: 'index.php',
                                    type: 'POST',
                                    data: { update_order: 1, ticket_ids: ticketIds },
                                    success: function(response) {
                                        var toast = $('<div class="alert alert-success shadow" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: none;"><i class="bi bi-check-circle"></i> Orden guardado correctamente</div>');
                                        $('body').append(toast);
                                        toast.fadeIn(300).delay(2000).fadeOut(300, function() { $(this).remove(); });
                                    }
                                });
                            }
                        });
                    }
                });
            });
        </script>
        <script src="../../../assets/js/date-time.js"></script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"
            integrity="sha384-Tc5IQib027qvyjSMfHjOMaLkfuWVxZxUPnCJA7l2mCWNIpG9mGCD8wGNIcPD7Txa"
            crossorigin="anonymous"></script>
        <script>
            $(document).ready(function() {
                $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                    localStorage.setItem('activeTabTickets', $(e.target).attr('href'));
                });
                var activeTab = localStorage.getItem('activeTabTickets');
                if (activeTab) {
                    $('ul.nav-tabs a[href="' + activeTab + '"]').tab('show');
                }
            });

            $(document).on("click", ".btn-edit", function () {
                var id = $(this).data('id');
                var name = $(this).data('name');
                var expire = $(this).data('expire');
                var price = $(this).data('price');
                var occasions = $(this).data('occasions');
                var hidden = $(this).data('hidden');
                var people = $(this).data('people');
                var validDays = $(this).data('validdays');
                var color = $(this).data('color');

                $('#edit_ticket_id').val(id);
                $('#edit_name').val(name);
                $('#edit_expire_days').val(expire);
                $('#edit_price').val(price);
                $('#edit_occasions').val(occasions);
                $('#edit_entry_people').val(people);
                $('#edit_color').val(color);

                $('#edit_hidden').prop('checked', hidden == 1);

                $('input[name="valid_days[]"]').prop('checked', false);
                
                if (validDays) {
                    $.each(validDays, function(index, value) {
                        $('#edit_day_' + value).prop('checked', true);
                    });
                }
            });
        </script>
</body>

</html>