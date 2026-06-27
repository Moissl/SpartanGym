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

// API!
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

$per_page = 10;
if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $page = $_GET['page'];
} else {
    $page = 1;
}

$start_from = ($page - 1) * $per_page;

$search_name = isset($_GET['search_name']) ? $_GET['search_name'] : '';
$search_email = isset($_GET['search_email']) ? $_GET['search_email'] : '';
$search_ticket = isset($_GET['search_ticket']) ? $_GET['search_ticket'] : '';

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'userid';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$allowed_sorts = ['userid', 'firstname', 'lastname', 'email', 'phone', 'ticket_name', 'ticket_expire'];
if (!in_array($sort, $allowed_sorts)) $sort = 'userid';
if ($order != 'ASC' && $order != 'DESC') $order = 'DESC';

$sql = "SELECT u.*, (SELECT ct.ticketname FROM current_tickets ct WHERE ct.userid = u.userid ORDER BY ct.expiredate DESC LIMIT 1) as ticket_name, (SELECT ct.expiredate FROM current_tickets ct WHERE ct.userid = u.userid ORDER BY ct.expiredate DESC LIMIT 1) as ticket_expire FROM users u";

if (!empty($search_name) || !empty($search_email) || !empty($search_ticket)) {
    $sql .= " WHERE ";
    $conditions = array();
    if (!empty($search_name)) {
        $conditions[] = "(u.firstname LIKE '%$search_name%' OR u.lastname LIKE '%$search_name%')";
    }
    if (!empty($search_email)) {
        $conditions[] = "u.email LIKE '%$search_email%'";
    }
    if (!empty($search_ticket)) {
        $conditions[] = "(SELECT ct.ticketname FROM current_tickets ct WHERE ct.userid = u.userid ORDER BY ct.expiredate DESC LIMIT 1) LIKE '%$search_ticket%'";
    }
    $sql .= implode(" AND ", $conditions);
}

$sql .= " ORDER BY $sort $order LIMIT $start_from, $per_page";

$result = $conn->query($sql);
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
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
    <style>
        .search-form .form-group {
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .search-form .btn {
                width: 100%;
                margin-bottom: 10px;
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
                <a class="navbar-brand" href="#"><img src="../../assets/img/logo.png" width="50px" alt="Logo"></a>
            </div>
            <div class="collapse navbar-collapse" id="myNavbar">
                <ul class="nav navbar-nav">
                    <li><a href="../dashboard"><i class="bi bi-speedometer"></i> <?php echo $translations["mainpage"]; ?></a></li>
                    <li class="active"><a href="#"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
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
                    <li class="sidebar-item active">
                        <a class="sidebar-link" href="#">
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
                    <h5 id="clock" style="display: inline-block; margin-bottom: 0;"></h5>
                </div>
                <div class="row">
                    <div class="col-sm-12">
                        <div class="card shadow">
                            <form method="GET" class="mb-4 search-form">
                                <div class="row">
                                    <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                                        <div class="form-group">
                                            <input type="text" class="form-control" placeholder="<?= $translations["name-search"]; ?>" name="search_name" value="<?php echo htmlspecialchars($search_name); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                                        <div class="form-group">
                                            <input type="text" class="form-control" placeholder="<?= $translations["email-search"]; ?>" name="search_email" value="<?php echo htmlspecialchars($search_email); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                                        <div class="form-group">
                                            <input type="text" class="form-control" placeholder="<?= $translations["tickettablename"] ?? 'Nombre de Pase'; ?>" name="search_ticket" value="<?php echo htmlspecialchars($search_ticket); ?>">
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                                        <div class="form-group">
                                            <button type="submit" class="btn btn-primary btn-block"><i class="bi bi-search"></i> <?php echo $translations["search"]; ?></button>
                                        </div>
                                    </div>
                                    <div class="col-lg-3 col-md-4 col-sm-6 col-12">
                                        <div class="form-group">
                                            <a href="index.php" class="btn btn-success btn-block"><i class="bi bi-arrow-clockwise"></i> <?php echo $translations["resetbtn"]; ?></a>
                                        </div>
                                    </div>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table table-dark table-bordered text-center">
                                    <thead>
                                        <tr>
                                            <th><?php echo $translations["profileimg"] ?? "Foto"; ?></th>
                                            <th><a href="?sort=userid&order=<?php echo ($sort == 'userid' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["identifier"]; ?> <?php if($sort == 'userid') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><a href="?sort=firstname&order=<?php echo ($sort == 'firstname' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["firstname"]; ?> <?php if($sort == 'firstname') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><a href="?sort=lastname&order=<?php echo ($sort == 'lastname' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["lastname"]; ?> <?php if($sort == 'lastname') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><a href="?sort=email&order=<?php echo ($sort == 'email' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["email"]; ?> <?php if($sort == 'email') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><a href="?sort=phone&order=<?php echo ($sort == 'phone' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["fno"]; ?> <?php if($sort == 'phone') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><a href="?sort=ticket_name&order=<?php echo ($sort == 'ticket_name' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["tickettablename"]; ?> <?php if($sort == 'ticket_name') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><?php echo $translations["buytime"]; ?></th>
                                            <th><a href="?sort=ticket_expire&order=<?php echo ($sort == 'ticket_expire' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search_name=<?php echo urlencode($search_name); ?>&search_email=<?php echo urlencode($search_email); ?>&search_ticket=<?php echo urlencode($search_ticket); ?>" style="color: black;"><?php echo $translations["remaining_time"]; ?> <?php if($sort == 'ticket_expire') echo ($order == 'ASC') ? '▲' : '▼'; ?></a></th>
                                            <th><?php echo $translations["action"]; ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if ($result->num_rows > 0) {
                                            while ($row = $result->fetch_assoc()) {

                                                echo "<tr>";

                                                $img_path = "../../assets/img/profiles/" . $row["userid"] . ".png";
                                                $full_img_path = __DIR__ . "/" . $img_path;
                                                echo "<td style='vertical-align: middle;'>";
                                                if (file_exists($full_img_path)) {
                                                    echo "<img src='{$img_path}?v=" . filemtime($full_img_path) . "' alt='Avatar' style='width: 55px; height: 55px; object-fit: cover; border-radius: 50%; cursor: zoom-in;' class='zoomable-image' loading='lazy'>";
                                                } else {
                                                    echo "<i class='bi bi-person-circle text-muted' style='font-size: 50px;'></i>";
                                                }
                                                echo "</td>";

                                                echo "<td>" . $row["userid"] . "</td>";
                                                echo "<td>" . $row["firstname"] . "</td>";
                                                echo "<td>" . $row["lastname"] . "</td>";
                                                echo "<td>" . $row["email"];
                                                if ($row["confirmed"] == "No") {
                                                    echo " <span class='text-danger bi bi-exclamation-triangle-fill' data-bs-toggle='tooltip' title='" . $translations["waitingconfirm"] . "'></span>";
                                                }
                                                echo "</td>";
                                                echo "<td>" . ($row["phone"] ?? '-') . "</td>";

                                                $row_userid = $row["userid"];
                                                $ticket_sql = "SELECT ct.ticketname, ct.buydate, ct.expiredate, t.expire_days 
                                                               FROM current_tickets ct 
                                                               LEFT JOIN tickets t ON ct.ticketname = t.name 
                                                               WHERE ct.userid = '$row_userid' 
                                                               ORDER BY ct.expiredate DESC LIMIT 1";
                                                $ticket_result = $conn->query($ticket_sql);

                                                if ($ticket_result->num_rows > 0) {
                                                    $ticket = $ticket_result->fetch_assoc();
                                                    $expiredate = $ticket["expiredate"];
                                                    $ticketname = $ticket["ticketname"];
                                                    $buydate = $ticket["buydate"];
                                                    $expire_days = $ticket["expire_days"];

                                                    if (strpos($expiredate, '9999-12-31') === 0) {
                                                        echo "<td>" . $ticketname . "</td>";
                                                        echo "<td>" . $buydate . "</td>"; // Ahora mostrará hora si está disponible
                                                        echo "<td class='text-success'>" . $translations["unlimited"] . "</td>";
                                                    } else {
                                                        $today = new DateTime();
                                                        $expire = new DateTime($expiredate);
                                                        $buyDateObj = new DateTime($buydate);


                                                        $originalExpire = new DateTime($expiredate);

                                                        if ($expire > $today) {
                                                            $interval = $today->diff($expire);
                                                            if ($interval->days < 1) {
                                                                $remaining = $interval->format('%H:%I:%S');
                                                            } else {
                                                                $remaining = $interval->format('%a d %h h %i m');
                                                            }
                                                            echo "<td>" . $ticketname . "</td>";
                                                            echo "<td>" . $buydate . "</td>";
                                                            echo "<td class='text-success'>$remaining</td>";
                                                        } else {
                                                            echo "<td>" . $ticketname . "</td>";
                                                            echo "<td>" . $buydate . "</td>";
                                                            echo "<td class='text-danger'>" . $translations["expired"] . " (" . $originalExpire->format("Y-m-d") . ")</td>";
                                                        }
                                                    }
                                                } else {
                                                    echo "<td>-</td>";
                                                    echo "<td>-</td>";
                                                    echo "<td class='text-muted'>" . $translations["youdonthaveticket"] . "</td>";
                                                }


                                                echo '<td><a class="btn btn-primary" href="edit/?user=' . $row["userid"] . '"><i class="bi bi-box-arrow-in-right"></i> ' . $translations["profilesee"] . '</a></td>';
                                                echo "</tr>";
                                            }
                                        } else {
                                            echo "<tr><td colspan='10'>No user data!</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>


                            <?php
                            $sql = "SELECT COUNT(*) AS total FROM users u";
                            if (!empty($search_name) || !empty($search_email) || !empty($search_ticket)) {
                                $sql .= " WHERE ";
                                $conditions = array();
                                if (!empty($search_name)) {
                                    $conditions[] = "(u.firstname LIKE '%$search_name%' OR u.lastname LIKE '%$search_name%')";
                                }
                                if (!empty($search_email)) {
                                    $conditions[] = "u.email LIKE '%$search_email%'";
                                }
                                if (!empty($search_ticket)) {
                                    $conditions[] = "(SELECT ct.ticketname FROM current_tickets ct WHERE ct.userid = u.userid ORDER BY ct.expiredate DESC LIMIT 1) LIKE '%$search_ticket%'";
                                }
                                $sql .= implode(" AND ", $conditions);
                            }

                            $result = $conn->query($sql);
                            $row = $result->fetch_assoc();
                            $total_pages = ceil($row["total"] / $per_page);

                            if ($total_pages > 1) {
                                echo "<ul class='pagination justify-content-center'>";
                                
                                // Botón Anterior (flecha izquierda)
                                if ($page > 1) {
                                    echo "<li class='page-item'><a class='page-link' href='?page=" . ($page - 1);
                                    if (!empty($search_name) || !empty($search_email) || !empty($search_ticket)) {
                                        echo "&search_name=" . urlencode($search_name) . "&search_email=" . urlencode($search_email) . "&search_ticket=" . urlencode($search_ticket);
                                    }
                                    echo "&sort=" . urlencode($sort) . "&order=" . urlencode($order);
                                    echo "'>&laquo;</a></li>";
                                }

                                for ($i = 1; $i <= $total_pages; $i++) {
                                    $active = ($i == $page) ? "active" : ""; // Resaltar página actual
                                    echo "<li class='page-item $active'><a class='page-link' href='?page=$i";
                                    if (!empty($search_name) || !empty($search_email) || !empty($search_ticket)) {
                                        echo "&search_name=" . urlencode($search_name) . "&search_email=" . urlencode($search_email) . "&search_ticket=" . urlencode($search_ticket);
                                    }
                                    echo "&sort=" . urlencode($sort) . "&order=" . urlencode($order);
                                    echo "'>$i</a></li>";
                                }

                                // Botón Siguiente (flecha derecha)
                                if ($page < $total_pages) {
                                    echo "<li class='page-item'><a class='page-link' href='?page=" . ($page + 1);
                                    if (!empty($search_name) || !empty($search_email) || !empty($search_ticket)) {
                                        echo "&search_name=" . urlencode($search_name) . "&search_email=" . urlencode($search_email) . "&search_ticket=" . urlencode($search_ticket);
                                    }
                                    echo "&sort=" . urlencode($sort) . "&order=" . urlencode($order);
                                    echo "'>&raquo;</a></li>";
                                }

                                echo "</ul>";
                            }
                            ?>
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
                    <p class="lead"><?php echo $translations["exit-modal"]; ?></p>
                </div>
                <div class="modal-footer">
                    <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
                    <a href="../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Zoom Modal -->
    <div class="modal fade" id="imageZoomModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="display: flex; align-items: center; min-height: calc(100% - 1rem);">
            <div class="modal-content" style="background: transparent; border: none; box-shadow: none;">
                <div class="modal-body text-center" style="padding: 0;">
                    <img src="" id="zoomModalImage" style="max-height: 90vh; max-width: 90vw; border-radius: 8px; box-shadow: 0 5px 20px rgba(0,0,0,0.5);">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="position: absolute; top: -10px; right: 0px; color: white; opacity: 1; font-size: 40px; text-shadow: 0 1px 3px rgba(0,0,0,0.8);">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SCRIPTS! -->
    <script src="../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
    <script>
        $(document).on('click', '.zoomable-image', function() {
            // Prevenir que el modal se abra si no hay imagen (es un ícono)
            if ($(this).is('img')) {
                var imgSrc = $(this).attr('src');
                $('#zoomModalImage').attr('src', imgSrc);
                $('#imageZoomModal').modal('show');
            }
        });
    </script>
</body>

</html>