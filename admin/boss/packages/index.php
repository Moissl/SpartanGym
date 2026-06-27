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
$currency = $env_data['CURRENCY'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
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

// Verificar si existe la columna sort_order en products
$check_col_sort = $conn->query("SHOW COLUMNS FROM products LIKE 'sort_order'");
if ($check_col_sort->num_rows == 0) {
    $conn->query("ALTER TABLE products ADD COLUMN sort_order INT DEFAULT 0");
}

// Manejador AJAX para guardar el orden
if (isset($_POST['update_order']) && isset($_POST['product_ids'])) {
    $product_ids = $_POST['product_ids'];
    foreach ($product_ids as $index => $id) {
        $id = intval($id);
        $order_val = intval($index);
        $conn->query("UPDATE products SET sort_order = $order_val WHERE id = $id");
    }
    exit('Success');
}

$message = "";


$file_path = 'https://api.gymoneglobal.com/latest/version.txt';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $file_path);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

$search = "";
if (isset($_GET['search'])) {
    $search = $conn->real_escape_string($_GET['search']);
}

$sql = "SELECT COUNT(*) AS low_stock_count FROM products WHERE stock <= 5";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $low_stock_count = $row['low_stock_count'];
} else {
    $low_stock_count = 0;
}

// Se elimina el sistema de ordenamiento anterior y se prioriza el de 'drag and drop' (sort_order).
$sql = "SELECT * FROM products 
        WHERE name LIKE '%$search%' OR description LIKE '%$search%'
        ORDER BY sort_order ASC";
$result = $conn->query($sql);

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
                                <li><a href="../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="active"><a href="#"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
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
                        <a class="sidebar-link" href="../../dashboard">
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
                        <a href="../../invoices" class="sidebar-link">
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
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
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
                </div>
                <div class="row">
                    <div class="col-sm-4">
                        <div class="card shadow">
                            <form method="GET" class="mb-4" action="">
                                <div class="input-group">
                                    <input type="text" name="search" class="form-control mt-3" placeholder="<?php echo $translations["searchbyproductname"]; ?>" value="<?php echo htmlspecialchars($search); ?>">
                                    <span class="input-group-btn">
                                        <button class="btn btn-primary mt-3" type="submit"><i class="bi bi-search"></i></button>
                                    </span>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="col-sm-4 text-center">
                        <div class="card shadow">
                            <a href="add/" class="btn btn-lg btn-success"><i class="bi bi-box-seam"></i> <?php echo $translations["addpackage"]; ?></a>
                        </div>
                    </div>
                    <div class="col-sm-4 text-center">
                        <div class="card shadow">
                            <a href="inventory/" class="btn btn-lg btn-info"><i class="bi bi-clipboard-check"></i> <?php echo $translations["werhousecorrection"]; ?>
                                <?php if ($low_stock_count > 0): ?>
                                    <span class="badge badge-warning"><?php echo $low_stock_count; ?></span>
                                <?php endif; ?></a>
                        </div>
                    </div>
                </div>
                <div class="row" id="sortable-products" style="display: flex; flex-wrap: wrap;">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <div class="col-md-4 mb-4 product-item" data-id="<?= $row['id'] ?>" style="display: flex; flex-direction: column;">
                                <div class="card h-100 shadow-sm" style="cursor: grab;" title="Arrastrar para mover">
                                    <span style="position: absolute; top: 10px; left: 10px; z-index: 10; background: rgba(255,255,255,0.7); padding: 5px; border-radius: 50%;"><i class="bi bi-arrows-move text-muted"></i></span>
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo htmlspecialchars($row['name']); ?></h5>
                                        <p class="card-text"><?php echo htmlspecialchars($row['description']); ?></p>
                                        <p class="text-success fw-bold"><?php echo number_format($row['price'], 2); ?> <?php echo $currency; ?></p>
                                        <?php
                                        $img_path = "../../../assets/img/packageimg/" . $row['barcode'] . ".png";
                                        $full_img_path = __DIR__ . "/" . $img_path;
                                        echo '<div class="text-center mb-2" style="height: 80px; display: flex; align-items: center; justify-content: center;">';
                                        if (file_exists($full_img_path)) {
                                            $version = filemtime($full_img_path);
                                            echo '<img loading="lazy" src="' . $img_path . '?v=' . $version . '" style="max-height: 100%; max-width: 100%; object-fit: contain;" class="img-fluid">';
                                        } else {
                                            echo '<i class="bi bi-box-seam" style="font-size: 3rem; color: #ccc;"></i>';
                                        }
                                        echo '</div>';
                                        ?>
                                        <a href="edit/?id=<?php echo $row['id']; ?>" class="btn btn-warning"><?php echo $translations["updatepackage"]; ?></a>
                                        <a href="delete/?id=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('¿Estás seguro de que deseas eliminar este producto?');"><?php echo $translations["delete"]; ?></a>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <p class="text-center"><?php echo $translations["nopackages"];?></p>
                    <?php endif; ?>
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

    <!-- EXIT MODAL -->
    <div class="modal fade" id="logoutModal" tabindex="-1" role="dialog" aria-labelledby="logoutModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-body">
                    <p class="lead"><?php echo $translations["exit-modal"]; ?></p>
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
            var el = document.getElementById('sortable-products');
            if (el) {
                new Sortable(el, {
                    animation: 150,
                    onEnd: function () {
                        var productIds = [];
                        $('#sortable-products .product-item').each(function () {
                            productIds.push($(this).data('id'));
                        });

                        $.ajax({
                            url: 'index.php',
                            type: 'POST',
                            data: { update_order: 1, product_ids: productIds },
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
    </script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        function startBarcodeScanner() {
            Quagga.init({
                inputStream: {
                    type: "LiveStream",
                    constraints: {
                        facingMode: "environment"
                    },
                    target: document.getElementById('reader'),
                    willReadFrequently: true
                },
                decoder: {
                    readers: ["ean_reader", "ean_8_reader"]
                }
            }, function(err) {
                if (err) {
                    console.error("A Quagga nem tudott elindulni:", err);
                    alert("Hiba történt a kamera elindítása közben.");
                    return;
                }
                Quagga.start();
            });

            Quagga.onDetected(function(result) {
                const barcode = result.codeResult.code;
                console.log("Beolvasott vonalkód:", barcode);

                fetch("get_BARCODE.php?barcode=" + barcode)
                    .then(response => response.json())
                    .then(data => {
                        if (data && data.id) {
                            window.location.href = "../edit?id=" + data.id;
                        } else {
                            console.warn("Érvénytelen vonalkód, újrapróbálkozás...");
                        }
                    })
                    .catch(err => {
                        console.error("Hiba történt a lekérdezés során:", err);
                    });
            });
        }

        window.onload = function() {
            startBarcodeScanner();
        };
    </script>
</body>

</html>
<?php
$conn->close();
?>