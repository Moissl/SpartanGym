<?php
session_start();

// 1. Silenciar advertencias de obsolescencia que rompen las cabeceras HTTP
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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

if (!isset($_GET['userid']) || empty($_GET['userid']) || $_GET['userid'] === 'N/A') {
    // If no valid user ID is provided, redirect to the user search page.
    header("Location: ../");
    exit();
}
$ticketbuyerid = htmlspecialchars($_GET['userid']);
$no_entry_param = (isset($_GET['no_entry']) && $_GET['no_entry'] === 'true') ? '&no_entry=true' : '';

$env_data = read_env_file('../../../../.env');

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

$langDir = __DIR__ . "/../../../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Kapcsolódási hiba: " . $conn->connect_error);
}

// Verificar y crear columna sort_order para productos si no existe
$check_col_sort_prod = $conn->query("SHOW COLUMNS FROM products LIKE 'sort_order'");
if ($check_col_sort_prod && $check_col_sort_prod->num_rows == 0) {
    $conn->query("ALTER TABLE products ADD COLUMN sort_order INT DEFAULT 0");
}

// Procesar ordenamiento de pases
if (isset($_POST['update_ticket_order']) && isset($_POST['ticket_ids'])) {
    foreach ($_POST['ticket_ids'] as $index => $id) {
        $conn->query("UPDATE tickets SET sort_order = " . intval($index) . " WHERE id = " . intval($id));
    }
    exit('Success');
}

// Procesar ordenamiento de productos
if (isset($_POST['update_product_order']) && isset($_POST['product_ids'])) {
    foreach ($_POST['product_ids'] as $index => $id) {
        $conn->query("UPDATE products SET sort_order = " . intval($index) . " WHERE id = " . intval($id));
    }
    exit('Success');
}

// Verificar si el usuario ya tiene un pase activo (para advertencia)
$active_ticket_data = null;
$at_sql = "SELECT ticketname, expiredate FROM current_tickets WHERE userid = ? AND expiredate >= NOW() ORDER BY expiredate DESC LIMIT 1";
if ($stmt_at = $conn->prepare($at_sql)) {
    $stmt_at->bind_param("i", $ticketbuyerid);
    $stmt_at->execute();
    $res_at = $stmt_at->get_result();
    if ($res_at->num_rows > 0) {
        $active_ticket_data = $res_at->fetch_assoc();
    }
    $stmt_at->close();
}

// --- LÓGICA PARA PROCESAR VENTA DE PASE (DIRECTA) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['sell_ticket_action'])) {
    $ticketId = intval($_POST['ticket_id']);
    $paymentMethod = $_POST['payment_method'];
    $userId = $_POST['userid'];

    // Revocar pase anterior si se seleccionó la opción
    if (isset($_POST['revoke_previous']) && $_POST['revoke_previous'] == '1') {
        $del_stmt = $conn->prepare("DELETE FROM current_tickets WHERE userid = ?");
        $del_stmt->bind_param("i", $userId);
        $del_stmt->execute();
        $del_stmt->close();
    }

    // Obtener datos del pase
    $stmt = $conn->prepare("SELECT name, price, expire_days, occasions, entry_people, valid_days FROM tickets WHERE id = ?");
    $stmt->bind_param("i", $ticketId);
    $stmt->execute();
    $stmt->bind_result($tName, $tPrice, $tExpireDays, $tOccasions, $tEntryPeople, $tValidDays);
    
    if ($stmt->fetch()) {
        $stmt->close();
        $tPrice = (float)$tPrice; // Asegurar que sea número para number_format

        // Calcular expiración
        $buyDate = date('Y-m-d H:i:s');
        $expireDateObj = new DateTime();
        if ($tExpireDays == 1) {
             $expireDateObj->add(new DateInterval('PT8H')); // 8 horas para pases diarios
        } elseif ($tExpireDays > 1) {
            $expireDateObj->add(new DateInterval('P' . $tExpireDays . 'D'));
        } else {
            $expireDateObj = new DateTime('9999-12-31');
        }
        $expireDateDb = $expireDateObj->format('Y-m-d H:i:s');

        // Asignar pase
        $ins = $conn->prepare("INSERT INTO current_tickets (userid, ticketname, buydate, expiredate, opportunities) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("isssi", $userId, $tName, $buyDate, $expireDateDb, $tOccasions);
        $ins->execute();
        $ins->close();

        // Generar Factura y Registrar Ingreso
        $uq = $conn->query("SELECT firstname, lastname, email FROM users WHERE userid = '$userId'");
        $uRow = $uq->fetch_assoc();
        $uName = $uRow['firstname'] . ' ' . $uRow['lastname'];
        $uEmail = $uRow['email'] ?? '';

        // Generar PDF (usando generador existente)
        $generatorPath = __DIR__ . '/../../../invoices/generator.php';
        $route = 'error.pdf';
        if (file_exists($generatorPath)) {
            require_once $generatorPath;
            $pdfData = [
                'business_name' => $business_name,
                'client_name' => $uName,
                'userid' => $userId,
                'payment_method' => $paymentMethod,
                'amount' => number_format($tPrice, 2),
                'currency' => $currency,
                'items' => [['name' => $tName, 'price' => number_format($tPrice, 2)]],
                'description_text' => 'Venta de Pase'
            ];
            $pdfFilename = generateInvoicePDF($pdfData);
            $route = $pdfFilename ? $pdfFilename : 'error.pdf';
        }
        
        $desc = "Venta Pase: " . $tName;
        
        // Insertar Factura
        $colCheck = $conn->query("SHOW COLUMNS FROM invoices LIKE 'description'");
        if ($colCheck && $colCheck->num_rows > 0) {
             $inv = $conn->prepare("INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, description, route) VALUES (?, ?, ?, 'Ticket', ?, 'paid', NOW(), ?, ?)");
             $inv->bind_param("isdsss", $userId, $uName, $tPrice, $paymentMethod, $desc, $route);
        } else {
             $inv = $conn->prepare("INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, route) VALUES (?, ?, ?, 'Ticket', ?, 'paid', NOW(), ?)");
             $inv->bind_param("isdss", $userId, $uName, $tPrice, $paymentMethod, $route);
        }
        $inv->execute();
        $inv->close();

        // Actualizar Caja (Revenu Stats)
        $dateStr = date('Y-m-d');
        $revCheck = $conn->query("SELECT id FROM revenu_stats WHERE date = '$dateStr'");
        if ($revCheck->num_rows > 0) {
            $revRow = $revCheck->fetch_assoc();
            $revId = $revRow['id'];
            $col = ($paymentMethod == 'Card') ? 'bank_card' : 'cash';
            $conn->query("UPDATE revenu_stats SET $col = $col + $tPrice WHERE id = $revId");
        } else {
            $cash = ($paymentMethod == 'Cash') ? $tPrice : 0;
            $card = ($paymentMethod == 'Card') ? $tPrice : 0;
            $conn->query("INSERT INTO revenu_stats (date, cash, bank_card) VALUES ('$dateStr', $cash, $card)");
        }

        // Efectuar redirección de inmediato
        if (empty($no_entry_param)) {
            header("Location: ../../../dashboard/?userid=" . $userId . "&status=paid");
        } else {
            header("Location: index.php?userid=" . $userId . "&success=ticket_sold&status=paid" . $no_entry_param);
        }

        // Liberar la conexión con el navegador para que la venta sea instantánea
        if (function_exists('fastcgi_finish_request')) {
            session_write_close();
            fastcgi_finish_request();
        }

        // Enviar Ticket por correo electrónico
        if (!empty($uEmail) && $route !== 'error.pdf' && file_exists(__DIR__ . '/../../../../assets/docs/invoices/' . $route)) {
            try {
                require_once __DIR__ . '/../../../../vendor/autoload.php';
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

                $logoPath = __DIR__ . '/../../../../assets/img/logo.png';
                $cid = file_exists($logoPath) ? $mailMsg->embed(Swift_Image::fromPath($logoPath)) : '';
                $logoHtml = $cid ? '<img src="' . $cid . '" alt="Logo" style="max-width: 80px; height: auto;">' : '<h1 style="color: #ffffff; margin: 0;">' . $business_name . '</h1>';

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'];
                $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
                $baseDir = preg_replace('#/admin/boss/sell/ticket$#', '', $scriptDir);
                $pdfUrl = $protocol . $host . $baseDir . '/assets/docs/invoices/' . $route;

                $body = '
                <div style="font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4; padding: 30px 10px;">
                    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                        <div style="background-color: #0950dc; text-align: center; padding: 25px;">
                            ' . $logoHtml . '
                        </div>
                        <div style="padding: 30px; color: #333333;">
                            <h2 style="color: #0950dc; margin-top: 0; font-size: 24px;">¡Gracias por tu preferencia, ' . $uName . '!</h2>
                            <p style="font-size: 16px; line-height: 1.6;">Confirmamos que tu transacción se ha realizado con éxito. Hemos adjuntado a este correo tu <strong>Ticket de Venta</strong> con todos los detalles de la operación.</p>
                            
                            <div style="background-color: #f8f9fa; border-left: 4px solid #0950dc; padding: 15px; margin: 25px 0;">
                                <p style="margin: 0; font-size: 15px;"><strong>Concepto:</strong> Compra de Pase</p>
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
                    ->attach(Swift_Attachment::fromPath(__DIR__ . '/../../../../assets/docs/invoices/' . $route));

                // Enviar correo ahora en segundo plano
                $mailer->send($mailMsg);
            } catch (Exception $e) {
                error_log("Error enviando email: " . $e->getMessage());
            }
        }
        exit();
    } else {
        $stmt->close();
        $message = '<div class="alert alert-danger">Error: Pase no encontrado.</div>';
    }
}

// --- LÓGICA PARA CREAR PASE PERSONALIZADO ---
if (isset($_POST['create_custom_ticket'])) {
    $name = !empty($_POST['custom_name']) ? $_POST['custom_name'] : 'Pase Personalizado';
    $price = floatval($_POST['custom_price']);
    $expire_days_input = $_POST['custom_expire_days'];
    $occasions = !empty($_POST['custom_occasions']) ? intval($_POST['custom_occasions']) : null;
    $entry_people = !empty($_POST['custom_entry_people']) ? intval($_POST['custom_entry_people']) : 1;
    $valid_days = isset($_POST['custom_valid_days']) ? json_encode($_POST['custom_valid_days']) : '["1","2","3","4","5","6","7"]';

    $expire_days = ($expire_days_input === '' || strtolower($expire_days_input) === 'ilimitado') ? null : intval($expire_days_input);

    // Insertar como oculto (hidden=1)
    $stmt = $conn->prepare("INSERT INTO tickets (name, price, expire_days, occasions, entry_people, valid_days, hidden) VALUES (?, ?, ?, ?, ?, ?, 1)");
    $stmt->bind_param("sdiiss", $name, $price, $expire_days, $occasions, $entry_people, $valid_days);

    if ($stmt->execute()) {
        $new_ticket_id = $stmt->insert_id;
        // En lugar de redirigir a payment, simulamos el envío del formulario de venta
        // Esto requiere un pequeño truco de HTML/JS o simplemente llamar a la lógica si estuviera en una función.
        // Para mantenerlo simple, redirigimos a esta misma página con parámetros para activar la venta automática o mostrar el modal.
        // Mejor opción: Mostrar el modal de pago para este nuevo ticket automáticamente.
        $auto_sell_ticket_id = $new_ticket_id;
        $auto_sell_ticket_name = $name;
        $auto_sell_ticket_price = $price;
    } else {
        $message = '<div class="alert alert-danger">Error al crear pase personalizado: ' . $conn->error . '</div>';
    }
    $stmt->close();
}
// --- FIN LÓGICA PERSONALIZADA ---

$sql = "DELETE FROM temp_cart";

$conn->query($sql);

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

$sql = "SELECT * FROM tickets ORDER BY sort_order ASC, price ASC";
$result = $conn->query($sql);

$stmt->close();

$product_stmt = $conn->prepare("SELECT * FROM products ORDER BY sort_order ASC");
$product_stmt->execute();
$product_result = $product_stmt->get_result();

$message = "";
if (isset($_GET['success']) && $_GET['success'] === 'products_sold') {
    $desc = isset($_GET['desc']) ? htmlspecialchars(urldecode($_GET['desc'])) : '';
    $message = '<div class="alert alert-success">Venta realizada con éxito.';
    if (!empty($desc)) {
        $message .= '<br><strong>Productos:</strong> ' . $desc;
    }
    $message .= '</div>';
} elseif (isset($_GET['error']) && $_GET['error'] === 'no_items_sold') {
    $message = '<div class="alert alert-danger">No se vendió ningún producto. Asegúrese de seleccionar cantidades válidas y que haya stock disponible.</div>';
} elseif (isset($_GET['success']) && $_GET['success'] === 'ticket_sold') {
    $message = '<div class="alert alert-success">Pase asignado y cobrado correctamente.</div>';
} elseif (isset($_GET['success']) && $_GET['success'] === 'balance_added') {
    $message = '<div class="alert alert-success">Saldo agregado correctamente.</div>';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
</head>
<style>
    .product-card {
        transition: all 0.3s ease;
        border: 1px solid #ddd;
        border-radius: 8px;
    }
    .product-card.selected {
        border: 2px solid #337ab7;
        background-color: #f0f8ff;
        box-shadow: 0 8px 16px rgba(0,0,0,0.2);
    }
</style>
<!-- ApexCharts -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<script src="../../../../assets/js/tinymce/js/tinymce/tinymce.min.js" referrerpolicy="origin"></script>


<body>
    <nav class="navbar navbar-inverse visible-xs">
        <div class="container-fluid">
            <div class="navbar-header">
                <button type="button" class="navbar-toggle" data-toggle="collapse" data-target="#myNavbar">
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                    <span class="icon-bar"></span>
                </button>
                <a class="navbar-brand" href="#"><img src="../../../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../../../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li><a href="../../../users"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
                    <li><a href="../../../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
                    <li class="active"><a href="../"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
                    <li><a href="../../../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li class="dropdown">
                            <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i> <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
                            <ul class="dropdown-menu">
                                <li><a href="../../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                                <li><a href="../../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                                <li><a href="../../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                                <li><a href="../../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                                <li><a href="../../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
                            </ul>
                        </li>
                    <?php } ?>
                    <li><a href="../../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../../boss/packages"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
                        <li><a href="../../../boss/chroom"><i class="bi bi-duffle"></i> <?php echo $translations["chroompage"]; ?></a></li>
                    <?php } ?>
                    <li><a href="../../../trainers/timetable"><i class="bi bi-calendar-event"></i> <?php echo $translations["timetable"]; ?></a></li>
                    <li><a href="../../../trainers/personal"><i class="bi bi-award"></i> <?php echo $translations["trainers"]; ?></a></li>
                    <?php if ($is_boss === 1) { ?>
                        <li><a href="../../../updater"><i class="bi bi-cloud-download"></i> <?php echo $translations["updatepage"]; ?>
                                <?php if ($is_new_version_available) : ?>
                                    <span class="badge badge-warning"><i class="bi bi-exclamation-circle"></i></span>
                                <?php endif; ?>
                            </a></li>
                    <?php } ?>
                    <li><a href="../../../log"><i class="bi bi-clock-history"></i> <?php echo $translations["logpage"]; ?></a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid">
        <div class="row content">
            <div class="col-sm-2 sidenav hidden-xs text-center">
                <h2><img src="../../../../assets/img/logo.png" width="105px" alt="Logo"></h2>
                <p class="lead mb-4 fs-4"><?php echo $business_name ?> - <?php echo $version; ?></p>
                <ul class="nav nav-pills nav-stacked">
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../../dashboard/">
                            <i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../../users/">
                            <i class="bi bi-people"></i> <?php echo $translations["users"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../../statistics">
                            <i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
                            <i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a href="../../../invoices/" class="sidebar-link">
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
                            <a class="sidebar-link" href="../../../boss/mainsettings">
                                <i class="bi bi-gear"></i>
                                <span><?php echo $translations["businesspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../../boss/workers">
                                <i class="bi bi-people"></i>
                                <span><?php echo $translations["workers"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../../boss/hours">
                                <i class="bi bi-clock"></i>
                                <span><?php echo $translations["openhourspage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../../boss/smtp">
                                <i class="bi bi-envelope-at"></i>
                                <span><?php echo $translations["mailpage"]; ?></span>
                            </a>
                        </li>
                        <li class="sidebar-item">
                            <a class="sidebar-link" href="../../../boss/rule">
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
                        <a class="sidebar-ling" href="../../../shop/tickets">
                            <i class="bi bi-ticket"></i>
                            <span><?php echo $translations["ticketspage"]; ?></span>
                        </a>
                    </li>
                    <?php if ($is_boss === 1) { ?>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../../boss/packages">
                            <i class="bi bi-box-seam"></i>
                            <span><?php echo $translations["packagepage"]; ?></span>
                        </a>
                    </li>
                    <li class="sidebar-item">
                        <a class="sidebar-link" href="../../../boss/chroom">
                            <i class="bi bi-duffle"></i>
                            <span><?php echo $translations["chroompage"]; ?></span>
                        </a>
                    </li>
                    <?php } ?>
                    <li class="sidebar-header">
                        <?php echo $translations["trainersclass"]; ?>
                    </li>
                    <li><a class="sidebar-link" href="../../../trainers/timetable">
                            <i class="bi bi-calendar-event"></i>
                            <span><?php echo $translations["timetable"]; ?></span>
                        </a></li>
                    <li><a class="sidebar-link" href="../../../trainers/personal">
                            <i class="bi bi-award"></i>
                            <span><?php echo $translations["trainers"]; ?></span>
                        </a></li>
                    <li class="sidebar-header"><?php echo $translations["other-header"]; ?></li>
                    <?php
                    if ($is_boss === 1) {
                    ?>
                        <li class="sidebar-item">
                            <a class="sidebar-ling" href="../../../updater">
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
                        <a class="sidebar-ling" href="../../../log">
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
                <?php echo $message; ?>
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
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>

                <!-- PESTAÑAS DE NAVEGACIÓN -->
                <ul class="nav nav-tabs" style="margin-bottom: 20px; font-size: 16px; font-weight: bold;">
                    <li class="active"><a data-toggle="tab" href="#tab-tickets"><i class="bi bi-ticket-perforated"></i> <?php echo $translations["ticketspage"]; ?></a></li>
                    <li><a data-toggle="tab" href="#tab-products"><i class="bi bi-box-seam"></i> <?php echo $translations["packagepage"]; ?></a></li>
                    <li><a data-toggle="tab" href="#tab-balance"><i class="bi bi-wallet2"></i> <?php echo $translations["profilebalance"]; ?></a></li>
                </ul>

                <div class="tab-content">

                <!-- ==================== PESTAÑA PASES ==================== -->
                <div id="tab-tickets" class="tab-pane fade in active">
                
                <!-- SECCIÓN DE PASE PERSONALIZADO (Colapsable) -->
                <div class="row">
                    <div class="col-md-12 mb-4" style="margin-bottom: 20px;">
                        <div class="card shadow">
                            <div class="card-header" role="button" data-toggle="collapse" data-target="#customTicketCollapse" aria-expanded="false" aria-controls="customTicketCollapse" style="cursor: pointer; background-color: #f8f9fa;">
                                <h4 class="card-title m-0 text-primary"><i class="bi bi-pencil-square"></i> Crear Pase Personalizado <small class="text-muted">(Clic para desplegar)</small></h4>
                            </div>
                            <div class="collapse" id="customTicketCollapse">
                                <div class="card-body">
                                    <form method="post">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Nombre del Pase</label>
                                                    <input type="text" name="custom_name" class="form-control" value="Pase Personalizado" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="form-group">
                                                    <label>Precio (<?php echo $currency; ?>)</label>
                                                    <input type="number" name="custom_price" class="form-control" step="0.01" required>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Días de Validez (Vacío = Ilimitado)</label>
                                                    <input type="text" name="custom_expire_days" class="form-control" placeholder="Ej: 30">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Ocasiones (Opcional)</label>
                                                    <input type="number" name="custom_occasions" class="form-control" placeholder="Ej: 10">
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="form-group">
                                                    <label>Personas por Pase</label>
                                                    <input type="number" name="custom_entry_people" class="form-control" value="1" min="1">
                                                </div>
                                            </div>
                                            <div class="col-md-12 mt-2">
                                                <label>Días Permitidos:</label><br>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="1" checked> Lun</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="2" checked> Mar</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="3" checked> Mié</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="4" checked> Jue</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="5" checked> Vie</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="6" checked> Sáb</label>
                                                <label class="checkbox-inline"><input type="checkbox" name="custom_valid_days[]" value="7" checked> Dom</label>
                                            </div>
                                        </div>
                                        <button type="submit" name="create_custom_ticket" class="btn btn-success mt-3"><i class="bi bi-check-circle"></i> Crear y Vender</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- FIN SECCIÓN PERSONALIZADA -->

                <!-- BUSCADOR DE PASES -->
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <div class="input-group">
                            <span class="input-group-addon"><i class="bi bi-search"></i></span>
                            <input type="text" id="searchTicketInput" class="form-control input-lg" placeholder="<?php echo $translations["search"]; ?>..." oninput="searchTickets()">
                        </div>
                    </div>
                </div>

                <!-- LISTA DE PASES -->
                <div class="row" id="ticketList" style="display: flex; flex-wrap: wrap;">
                    <?php if ($result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>
                            <?php $ticketColor = !empty($row['color']) ? htmlspecialchars($row['color']) : '#337ab7'; ?>
                            <div class="col-xs-12 col-sm-6 col-md-4 col-lg-3 ticket-item mb-4" data-id="<?php echo $row['id']; ?>" style="margin-bottom: 20px; cursor: grab;">
                                <div class="card shadow" style="height: 100%; border-top: 5px solid <?php echo $ticketColor; ?>;">
                                    <div class="card-body">
                                        <h5 class="card-title" style="color: <?php echo $ticketColor; ?>; font-weight: bold;"><?php echo htmlspecialchars($row['name']); ?></h5>
                                        <p class="card-text"><?php echo $translations["expiredate"]; ?> <?php echo $row['expire_days'] ? htmlspecialchars($row['expire_days']) . " " . $translations["day"] . "" : "" . $translations["unlimited"] . ""; ?></p>
                                        <p class="card-text"><?php echo $translations["tickettableoccassion"]; ?>: <?php echo $row['occasions'] ? htmlspecialchars($row['occasions']) : "No especificado"; ?></p>
                                        <p class="card-text"><?php echo $translations["price"]; ?>: <?php echo htmlspecialchars($row['price']); ?> <?php echo $currency; ?></p>
                                        
                                        <!-- Botón que abre el modal de venta -->
                                        <button type="button" class="btn btn-primary btn-block btn-sell-ticket" 
                                                data-id="<?php echo $row['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($row['name']); ?>" 
                                                data-price="<?php echo htmlspecialchars($row['price']); ?>"
                                                data-color="<?php echo $ticketColor; ?>">
                                            <i class="bi bi-cart-plus"></i> <?php echo $translations["sellpage"] ?? "Vender"; ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div class="col-md-12"><p class="text-center"><?php echo $translations["notickets"]; ?></p></div>
                    <?php endif; ?>
                </div>
                </div> <!-- Fin Tab Tickets -->

                <!-- ==================== PESTAÑA SALDO ==================== -->
                <div id="tab-balance" class="tab-pane fade">
                <br>
                <div class="row">
                    <div class="col-md-6 col-md-offset-3 mb-4">
                        <div class="card shadow">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $translations["customaddmoneyheader"]; ?></h5>
                                <form method="post" action="process_balance.php" class="form-inline">
                                    <div class="form-group">
                                        <label for="amount"><?php echo $translations["price"]; ?></label>
                                        <div class="input-group">
                                            <input type="text" id="amount" name="amount" class="form-control" placeholder="<?php echo $translations['balancegiveadd']; ?>" required>
                                            <span class="input-group-addon"><?php echo $currency; ?></span>
                                        </div>
                                    </div>
                                    <div class="form-group" style="margin-left: 10px;">
                                        <label><?php echo $translations["paymenttype"]; ?></label>
                                        <select name="payment_method" class="form-control">
                                            <option value="Cash"><?php echo $translations["cash"]; ?></option>
                                            <option value="Card"><?php echo $translations["card"]; ?></option>
                                        </select>
                                    </div>
                                    <input type="hidden" id="userid" name="userid" value="<?php echo $ticketbuyerid; ?>">

                                    <button type="submit" class="btn btn-primary"><i class="bi bi-wallet2"></i> <?php echo $translations["add"]; ?></button>
                                </form>

                                <p class="card-text mt-3">
                                    <code><?php echo $translations["profilebalanceattencion"]; ?></code>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                </div> <!-- Fin Tab Balance -->

                <!-- ==================== PESTAÑA PRODUCTOS ==================== -->
                <div id="tab-products" class="tab-pane fade">
                <br>

                <?php if ($product_result->num_rows > 0): ?>
                    <form action="cart_process.php" method="post">
                        <!-- CARRITO DE COMPRAS FIJO ARRIBA -->
                        <div class="well text-center" style="position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 15px rgba(0,0,0,0.15); background-color: #ffffff; border: 2px solid #337ab7; border-radius: 8px; margin-bottom: 25px; padding: 15px;">
                            <div class="form-inline" style="display: flex; flex-wrap: wrap; justify-content: center; align-items: center; gap: 15px;">
                                <h3 style="margin: 0; font-weight: bold; color: #2c3e50;">
                                    <i class="bi bi-cart3 text-primary"></i> Total Carrito: 
                                    <span id="grand-total" class="text-success">0.00</span> <span class="text-success"><?php echo $currency; ?></span>
                                </h3>
                                <div class="form-group" style="margin: 0;">
                                    <label style="margin-right: 10px; font-size: 16px;"><i class="bi bi-credit-card"></i> <?php echo $translations["paymenttype"]; ?>:</label>
                                    <select name="payment_method" class="form-control input-lg" style="min-width: 150px; font-weight: bold;">
                                        <option value="Cash"><?php echo $translations["cash"]; ?></option>
                                        <option value="Card"><?php echo $translations["card"]; ?></option>
                                    </select>
                                </div>
                                <input type="hidden" id="userid" name="userid" value="<?php echo $ticketbuyerid; ?>">
                                <?php if (!empty($no_entry_param)): ?>
                                    <input type="hidden" name="no_entry" value="true">
                                <?php endif; ?>
                                <button type="button" id="clear-cart-btn" class="btn btn-danger btn-lg" style="font-weight: bold; padding: 10px 20px;"><i class="bi bi-trash"></i> <?= $translations["empty_cart"] ?? "Vaciar"; ?></button>
                                <button type="submit" class="btn btn-success btn-lg" style="font-weight: bold; padding: 10px 30px;"><i class="bi bi-check-circle"></i> <?= $translations["paybutton"] ?? "Cobrar"; ?></button>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3" style="margin-bottom: 20px;">
                                <div class="input-group">
                                    <span class="input-group-addon"><i class="bi bi-search"></i></span>
                                    <input type="text" id="searchInput" class="form-control input-lg" placeholder="<?= $translations["search"]; ?>..." oninput="searchProducts()">
                                </div>
                            </div>
                        </div>

                        <div id="productList" class="row" style="display: flex; flex-wrap: wrap;">
                                <?php while ($row = $product_result->fetch_assoc()): ?>
                                    <div class="col-xs-12 col-sm-6 col-md-4 col-lg-3 product-item mb-4" data-id="<?php echo $row['id']; ?>" style="margin-bottom: 20px; cursor: grab;">
                                        <div class="card shadow product-card" data-barcode="<?php echo htmlspecialchars($row['barcode']); ?>" data-price="<?php echo $row['price']; ?>" style="height: 100%; overflow: hidden; cursor: pointer;" title="Haz clic para agregar +1 al carrito">
                                            <div class="card-heading" style="background-color: #f9f9f9; padding: 10px; border-bottom: 1px solid #eee; height: 50px; overflow: hidden;">
                                                <h5 class="card-title text-center" style="margin: 0; line-height: 30px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($row['name']); ?>"><?php echo htmlspecialchars($row['name']); ?></h5>
                                            </div>
                                            <div class="card-body" style="padding: 15px;">
                                                <?php
                                                $img_path = "../../../../assets/img/packageimg/" . $row['barcode'] . ".png";
                                                $full_img_path = __DIR__ . "/" . $img_path;
                                                $thumb_path = "../../../../assets/img/packageimg/thumb_" . $row['barcode'] . ".png";
                                                $full_thumb_path = __DIR__ . "/" . $thumb_path;

                                                echo '<div class="text-center mb-3" style="height: 120px; display: flex; align-items: center; justify-content: center;">';
                                                if (file_exists($full_img_path)) {
                                                    // Generación dinámica de miniaturas (thumbnails)
                                                    if (function_exists('imagecreatefrompng') && (!file_exists($full_thumb_path) || filemtime($full_thumb_path) < filemtime($full_img_path))) {
                                                        $imgInfo = @getimagesize($full_img_path);
                                                        if ($imgInfo !== false && $imgInfo[0] > 0 && $imgInfo[1] > 0) {
                                                            $width = $imgInfo[0];
                                                            $height = $imgInfo[1];
                                                            $new_height = 240; // 240px para soportar pantallas de alta densidad (120px CSS * 2)
                                                            if ($height > $new_height) {
                                                                $new_width = floor($width * ($new_height / $height));
                                                                $thumb = imagecreatetruecolor($new_width, $new_height);
                                                                imagealphablending($thumb, false);
                                                                imagesavealpha($thumb, true);
                                                                $source = @imagecreatefrompng($full_img_path);
                                                                if ($source) {
                                                                    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
                                                                    @imagepng($thumb, $full_thumb_path, 6); // Nivel de compresión 6
                                                                    imagedestroy($source);
                                                                }
                                                                imagedestroy($thumb);
                                                            }
                                                        }
                                                    }

                                                    $display_path = file_exists($full_thumb_path) ? $thumb_path : $img_path;
                                                    $version = filemtime(file_exists($full_thumb_path) ? $full_thumb_path : $full_img_path);
                                                    
                                                    // Carga la miniatura con atributo `alt` para accesibilidad. `loading="lazy"` ya existía.
                                                    echo '<img loading="lazy" src="' . $display_path . '?v=' . $version . '" alt="' . htmlspecialchars($row['name']) . '" style="max-height: 100%; max-width: 100%; object-fit: contain;" class="img-fluid rounded">';
                                                } else {
                                                    echo '<i class="bi bi-box-seam" style="font-size: 4rem; color: #ccc;"></i>';
                                                }
                                                echo '</div>';
                                                ?>
                                                <p class="card-text text-muted text-center" style="font-size: 12px; height: 35px; overflow: hidden; margin-bottom: 5px;"><?php echo mb_strimwidth(strip_tags($row['description']), 0, 60, "..."); ?></p>
                                                <h4 class="text-center text-primary" style="margin-top: 0; font-weight: bold;"><?php echo number_format($row['price'], 2, ',', '.'); ?> <?php echo $currency; ?></h4>
                                                <p class="text-center small text-muted"><?php echo $translations["piece"]; ?>: <?php echo $row['stock']; ?></p>
                                                
                                                <div class="input-group">
                                                    <span class="input-group-btn"><button type="button" class="btn btn-default btn-number" disabled="disabled" data-type="minus" data-field="quantities[<?php echo $row['id']; ?>]"><i class="bi bi-dash"></i></button></span>
                                                    <input type="text" name="quantities[<?php echo $row['id']; ?>]" class="form-control input-number text-center" value="0" min="0" max="<?php echo $row['stock']; ?>">
                                                    <span class="input-group-btn"><button type="button" class="btn btn-default btn-number" data-type="plus" data-field="quantities[<?php echo $row['id']; ?>]"><i class="bi bi-plus"></i></button></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                        </div>

                    </form>
                <?php else: ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info"><?= $translations["nopackages"] ?? "No hay productos disponibles."; ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                </div> <!-- Fin Tab Productos -->

                </div> <!-- Fin Tab Content -->

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
                <form action="../../../dashboard/export_attendance.php" method="GET">
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

    <!-- MODAL DE VENTA DE PASE -->
    <div class="modal fade" id="sellTicketModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">Confirmar Venta de Pase</h4>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="sell_ticket_action" value="1">
                        <input type="hidden" name="userid" value="<?php echo $ticketbuyerid; ?>">
                        <input type="hidden" name="ticket_id" id="modal_ticket_id">
                        
                        <h3 class="text-center" id="modal_ticket_name"></h3>
                        <h2 class="text-center" id="modal_ticket_price"></h2>
                        <hr>
                        <div class="form-group">
                            <label>Método de Pago:</label>
                            <select name="payment_method" class="form-control input-lg">
                                <option value="Cash"><?php echo $translations["cash"]; ?></option>
                                <option value="Card"><?php echo $translations["card"]; ?></option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success">Confirmar Venta</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

        <!-- Loading Overlay -->
        <div id="loading-overlay">
            <div class="spinner"></div>
            <h4 style="margin-top: 20px; color: #333;">Procesando Venta...</h4>
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
                        <a href="../../../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $conn->close();
        ?>
        <!-- SCRIPTS! -->
        <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var ticketList = document.getElementById("ticketList");
                if (ticketList) {
                    new Sortable(ticketList, {
                        animation: 150,
                        onEnd: function () {
                            var ticketIds = [];
                            $(ticketList).find('.ticket-item').each(function () {
                                ticketIds.push($(this).data('id'));
                            });
                            $.ajax({
                                url: 'index.php?userid=<?php echo urlencode($ticketbuyerid); ?>',
                                type: 'POST',
                                data: { update_ticket_order: 1, ticket_ids: ticketIds },
                                success: function(response) {
                                    var toast = $('<div class="alert alert-success shadow" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: none;"><i class="bi bi-check-circle"></i> Orden de pases guardado</div>');
                                    $('body').append(toast);
                                    toast.fadeIn(300).delay(2000).fadeOut(300, function() { $(this).remove(); });
                                }
                            });
                        }
                    });
                }
                var productList = document.getElementById("productList");
                if (productList) {
                    new Sortable(productList, {
                        animation: 150,
                        onEnd: function () {
                            var productIds = [];
                            $(productList).find('.product-item').each(function () {
                                productIds.push($(this).data('id'));
                            });
                            $.ajax({
                                url: 'index.php?userid=<?php echo urlencode($ticketbuyerid); ?>',
                                type: 'POST',
                                data: { update_product_order: 1, product_ids: productIds },
                                success: function(response) {
                                    var toast = $('<div class="alert alert-success shadow" style="position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: none;"><i class="bi bi-check-circle"></i> Orden de productos guardado</div>');
                                    $('body').append(toast);
                                    toast.fadeIn(300).delay(2000).fadeOut(300, function() { $(this).remove(); });
                                }
                            });
                        }
                    });
                }
            });
        </script>
        <script>
            function searchTickets() {
                var input = document.getElementById("searchTicketInput");
                var filter = input.value.toLowerCase();
                var ticketList = document.getElementById("ticketList");
                var tickets = ticketList.getElementsByClassName("ticket-item");

                for (var i = 0; i < tickets.length; i++) {
                    var ticketName = tickets[i].getElementsByClassName("card-title")[0].innerText;
                    if (ticketName.toLowerCase().indexOf(filter) > -1) {
                        tickets[i].style.display = "";
                    } else {
                        tickets[i].style.display = "none";
                    }
                }
            }

            function searchProducts() {
                var input = document.getElementById("searchInput");
                var filter = input.value.toLowerCase();
                var productList = document.getElementById("productList");
                var products = productList.getElementsByClassName("product-item");

                for (var i = 0; i < products.length; i++) {
                    var productName = products[i].getElementsByClassName("card-title")[0].innerText;
                    if (productName.toLowerCase().indexOf(filter) > -1) {
                        products[i].style.display = "";
                    } else {
                        products[i].style.display = "none";
                    }
                }
            }

            $('form').on('submit', function() {
                if (!$(this).attr('target')) {
                    $('#loading-overlay').css('display', 'flex');
                }
            });

            // Script para el modal de venta de pase
            $(document).on("click", ".btn-sell-ticket", function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                var price = $(this).data('price');
                var color = $(this).data('color');
                
                $('#modal_ticket_id').val(id);
                $('#modal_ticket_name').text(name).css('color', color);
                $('#sellTicketModal .modal-content').css('border-top', '5px solid ' + color);
                $('#modal_ticket_price').text(price + ' <?php echo $currency; ?>');
                
                // Advertencia de pase activo y opción de revocar
                $('#active-ticket-warning').remove();
                <?php if ($active_ticket_data): ?>
                    var activeName = "<?php echo htmlspecialchars($active_ticket_data['ticketname']); ?>";
                    var activeExpire = "<?php echo htmlspecialchars($active_ticket_data['expiredate']); ?>";
                    var warningHtml = `
                        <div id="active-ticket-warning" class="alert alert-warning text-left">
                            <h4 style="margin-top:0"><i class="bi bi-exclamation-triangle-fill"></i> ¡Usuario con Pase Activo!</h4>
                            <p>Este usuario ya tiene: <strong>${activeName}</strong></p>
                            <p>Vence el: <strong>${activeExpire}</strong></p>
                            <div class="checkbox" style="margin-top:10px; border-top:1px solid #faebcc; padding-top:10px;">
                                <label style="font-weight:bold; color:#8a6d3b;"><input type="checkbox" name="revoke_previous" value="1"> Revocar pase anterior y asignar nuevo</label>
                            </div>
                        </div>
                    `;
                    $('.modal-body').prepend(warningHtml);
                <?php endif; ?>
                
                $('#sellTicketModal').modal('show');
            });

            <?php if(isset($auto_sell_ticket_id)): ?>
                // Abrir modal automáticamente si se acaba de crear un pase personalizado
                $('.btn-sell-ticket[data-id="<?php echo $auto_sell_ticket_id; ?>"]').click();
            <?php endif; ?>

            // Hacer que toda la tarjeta del producto sume +1 al hacerle clic
            $('#productList').on('click', '.product-card', function(e) {
                // Ignorar clics en los botones, el grupo de input, o el input mismo para no duplicar la acción
                if (!$(e.target).closest('.input-group, .btn, .btn-number, .input-number').length) {
                    $(this).find(".btn-number[data-type='plus']").trigger('click');
                }
            });

            // Lógica para botones +/- y cálculo de total
            $('.btn-number').click(function(e){
                e.preventDefault();
                
                fieldName = $(this).attr('data-field');
                type      = $(this).attr('data-type');
                var input = $("input[name='"+fieldName+"']");
                var currentVal = parseInt(input.val());
                if (!isNaN(currentVal)) {
                    if(type == 'minus') {
                        if(currentVal > input.attr('min')) {
                            input.val(currentVal - 1).change();
                        } 
                        if(parseInt(input.val()) == input.attr('min')) {
                            $(this).attr('disabled', true);
                        }
                    } else if(type == 'plus') {
                        if(currentVal < input.attr('max')) {
                            input.val(currentVal + 1).change();
                        }
                        if(parseInt(input.val()) == input.attr('max')) {
                            $(this).attr('disabled', true);
                        }
                    }
                } else {
                    input.val(0);
                }
            });

            $('.input-number').focusin(function(){
               $(this).data('oldValue', $(this).val());
            });

            $('.input-number').change(function() {
                var minValue =  parseInt($(this).attr('min'));
                var maxValue =  parseInt($(this).attr('max'));
                var valueCurrent = parseInt($(this).val());
                var name = $(this).attr('name');
                
                if(valueCurrent >= minValue) {
                    $(".btn-number[data-type='minus'][data-field='"+name+"']").removeAttr('disabled');
                } else {
                    $(this).val($(this).data('oldValue'));
                }
                if(valueCurrent <= maxValue) {
                    $(".btn-number[data-type='plus'][data-field='"+name+"']").removeAttr('disabled');
                } else {
                    $(this).val($(this).data('oldValue'));
                }

                // Actualizar estilo de tarjeta y total
                var card = $(this).closest('.product-card');
                if(valueCurrent > 0) card.addClass('selected');
                else card.removeClass('selected');

                calculateTotal();
            });

            function calculateTotal() {
                var total = 0;
                $('.product-card').each(function() {
                    var price = parseFloat($(this).data('price'));
                    var qty = parseInt($(this).find('.input-number').val()) || 0;
                    total += price * qty;
                });
                $('#grand-total').text(total.toFixed(2));
            }

            // Lógica para vaciar el carrito
            $('#clear-cart-btn').click(function(e) {
                e.preventDefault();
                // Poner todos los inputs a 0
                $('.input-number').val(0);
                // Deshabilitar los botones de restar (-)
                $('.btn-number[data-type="minus"]').attr('disabled', true);
                // Reactivar los botones de sumar (+) sólo si hay stock
                $('.input-number').each(function() {
                    var name = $(this).attr('name');
                    if (parseInt($(this).attr('max')) > 0) {
                        $(".btn-number[data-type='plus'][data-field='"+name+"']").removeAttr('disabled');
                    }
                });
                // Quitar los estilos de tarjeta seleccionada y recalcular el total a 0
                $('.product-card').removeClass('selected');
                calculateTotal();
            });
        </script>
        <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
        <script src="../../../../assets/js/date-time.js"></script>
</body>

</html>