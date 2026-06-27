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
  die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
  die("Kapcsolódási hiba: " . $conn->connect_error);
}

// Verificar si existe la columna 'phone' y crearla si no
$check_phone = $conn->query("SHOW COLUMNS FROM users LIKE 'phone'");
if ($check_phone->num_rows == 0) {
    $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(50) DEFAULT NULL");
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
$latest_version = curl_exec($ch);
curl_close($ch);

$current_version = $version;

$alerts_html = "";

$is_new_version_available = version_compare($latest_version, $current_version) > 0;

if (isset($_GET['user']) && is_numeric($_GET['user'])) {
  $useridgymuser = $_GET['user'];

  $sql = "SELECT * FROM users WHERE userid = $useridgymuser";
  $result = $conn->query($sql);

  if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $firstname = $row['firstname'];
    $lastname = $row['lastname'];
    $email = $row['email'];
    $regdate = $row['registration_date'];
    $lastlogin = $row['lastlogin'];
    $verify = $row['confirmed'];
    $lastip = $row['lastip'];
    $balance = $row['profile_balance'];
    $phone = $row['phone'] ?? '';
    $gender = $row['gender'];

    // Obtener última visita
    $last_visit_date = "Nunca";
    $sql_visit = "SELECT workout_date FROM workout_stats WHERE userid = ? ORDER BY workout_date DESC LIMIT 1";
    if ($stmt_visit = $conn->prepare($sql_visit)) {
        $stmt_visit->bind_param("i", $useridgymuser);
        $stmt_visit->execute();
        $res_visit = $stmt_visit->get_result();
        if ($res_visit->num_rows > 0) {
            $last_visit_date = $res_visit->fetch_assoc()['workout_date'];
        }
        $stmt_visit->close();
    }
  } else {
    echo "The user does not exist!";
    exit;
  }
} else {
  echo "Incorrect request received!";
  exit;
}


if (isset($_POST['save'])) {
  $new_firstname = $_POST['firstname'];
  $new_lastname = $_POST['lastname'];
  $new_email = $_POST['email'];
  $new_phone = $_POST['phone'];
  $new_gender = $_POST['gender'];

  if (empty($new_firstname) || empty($new_lastname)) {
    echo "Minden mező kitöltése kötelező.";
  } else {
    $sql_update = "UPDATE users SET firstname = '$new_firstname', lastname = '$new_lastname', email = '$new_email', phone = '$new_phone', gender = '$new_gender' WHERE userid = $useridgymuser";

    if ($conn->query($sql_update) === TRUE) {
      $alerts_html .= '<div class="alert alert-success" role="alert">
                                    ' . $translations["success-update"] . '
                                </div>';
      $action = $translations['success-edit-user'] . ' ID: ' . $useridgymuser . ' Mail: ' . $new_email;
      $actioncolor = 'warning';
      $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
                            VALUES (?, ?, ?, NOW())";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("iss", $userid, $action, $actioncolor);
      $stmt->execute();
      header("Refresh:2");
      exit;
    } else {
      $alerts_html .= '<div class="alert alert-danger" role="alert">Unexpected error: ' . $conn->error . '</div>';
    }
  }
}

if (isset($_POST['delete_user'])) {

  $sql = "DELETE FROM users WHERE userid = ?";

  if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $useridgymuser);
    if ($stmt->execute()) {
      $action = $translations['success-delete-user'] . ' ID: ' . $useridgymuser . ' ' . $firstname . ' ' . $lastname;
      $actioncolor = 'danger';
      $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
                            VALUES (?, ?, ?, NOW())";
      $stmt = $conn->prepare($sql);
      $stmt->bind_param("iss", $userid, $action, $actioncolor);
      $stmt->execute();
      header("Location: ../");
    } else {
      $alerts_html .= '<div class="alert alert-danger" role="alert">
                                    ' . $translations["deletefail"] . '
                                </div>';
    }
    $stmt->close();
  }

  $conn->close();
}

// Revocar membresía
if (isset($_POST['revoke_membership'])) {
  $conn = new mysqli($db_host, $db_username, $db_password, $db_name);
  
  $sql_revoke = "DELETE FROM current_tickets WHERE userid = ?";
  $stmt_revoke = $conn->prepare($sql_revoke);
  $stmt_revoke->bind_param("i", $useridgymuser);
  
  if ($stmt_revoke->execute()) {
    $alerts_html .= '<div class="alert alert-success" role="alert">
                                    Membresía revocada exitosamente
                                </div>';
    $action = 'Membresía revocada - ID: ' . $useridgymuser . ' ' . $firstname . ' ' . $lastname;
    $actioncolor = 'warning';
    $sql = "INSERT INTO logs (userid, action, actioncolor, time) 
                            VALUES (?, ?, ?, NOW())";
    $stmt_log = $conn->prepare($sql);
    $stmt_log->bind_param("iss", $userid, $action, $actioncolor);
    $stmt_log->execute();
    header("Refresh:2");
    exit;
  } else {
    $alerts_html .= '<div class="alert alert-danger" role="alert">
                                    Error al revocar membresía
                                </div>';
  }
  $stmt_revoke->close();
  $conn->close();
}

// Restablecer Contraseña (Admin Force Reset)
if (isset($_POST['reset_password_submit'])) {
    $new_password = $_POST['new_password'];
    if (!empty($new_password)) {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $sql_update_pass = "UPDATE users SET password = ? WHERE userid = ?";
        
        if ($stmt_pass = $conn->prepare($sql_update_pass)) {
            $stmt_pass->bind_param("si", $hashed_password, $useridgymuser);
            if ($stmt_pass->execute()) {
                $alerts_html .= '<div class="alert alert-success" role="alert">¡Contraseña restablecida exitosamente!</div>';
                
                $action = 'Contraseña restablecida (Admin) - ID: ' . $useridgymuser;
                $actioncolor = 'warning';
                $sql_log = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
                $stmt_log = $conn->prepare($sql_log);
                $stmt_log->bind_param("iss", $userid, $action, $actioncolor);
                $stmt_log->execute();
                header("Refresh:2");
            } else {
                $alerts_html .= '<div class="alert alert-danger" role="alert">Error al actualizar la contraseña.</div>';
            }
            $stmt_pass->close();
        }
    }
}

// Guardar Foto de Perfil (Cámara)
if (isset($_POST['save_photo']) && isset($_POST['photo_data'])) {
    $img = $_POST['photo_data'];
    // Limpiar string base64
    $img = str_replace('data:image/png;base64,', '', $img);
    $img = str_replace(' ', '+', $img);
    $data = base64_decode($img);
    $uploadSuccess = file_put_contents('../../../assets/img/profiles/' . $useridgymuser . '.png', $data);
    
    if ($uploadSuccess) {
         $alerts_html .= '<div class="alert alert-success" role="alert">Foto de perfil actualizada correctamente.</div>';
         $action = 'Foto de perfil actualizada - ID: ' . $useridgymuser;
         $actioncolor = 'success';
         $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
         $stmt = $conn->prepare($sql);
         $stmt->bind_param("iss", $userid, $action, $actioncolor);
         $stmt->execute();
    } else {
         $alerts_html .= '<div class="alert alert-danger" role="alert">Error al guardar la foto.</div>';
    }
}

// Eliminar Foto de Perfil
if (isset($_POST['delete_photo'])) {
    $picPath = '../../../assets/img/profiles/' . $useridgymuser . '.png';
    if (file_exists($picPath)) {
        unlink($picPath);
        $alerts_html .= '<div class="alert alert-success" role="alert">Foto de perfil eliminada correctamente.</div>';
        $action = 'Foto de perfil eliminada - ID: ' . $useridgymuser;
        $actioncolor = 'danger';
        $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iss", $userid, $action, $actioncolor);
        $stmt->execute();
    }
}

$today = date('Y-m-d');

$sql = "SELECT ct.*, t.expire_days 
        FROM current_tickets ct 
        LEFT JOIN tickets t ON ct.ticketname = t.name 
        WHERE ct.userid = ? AND ct.expiredate >= ? 
        ORDER BY ct.expiredate DESC LIMIT 1";

if ($stmt = $conn->prepare($sql)) {
  $stmt->bind_param("is", $useridgymuser, $today);
  $stmt->execute();
  $result = $stmt->get_result();

  if ($row = $result->fetch_assoc()) {
    $ticket_name = $row['ticketname'];
    $ticket_buydate = $row['buydate'];
    $ticket_expiredate = $row['expiredate'];
    $ticket_opportunities = $row['opportunities'];
    $expire_days = $row['expire_days'];

    $buyDate = new DateTime($ticket_buydate);
    
    $expireDate = new DateTime($ticket_expiredate);
    $todayDate = new DateTime(); // Usar fecha y hora actual exacta


    // Cálculo de días para mostrar en texto
    $ticket_total_days = $buyDate->diff($expireDate)->days;
    // Cálculo de segundos para la barra de porcentaje (más preciso)
    $total_seconds = $expireDate->getTimestamp() - $buyDate->getTimestamp();

    if ($todayDate <= $expireDate) {
      $ticket_remaining_days = $todayDate->diff($expireDate)->days;
    } else {
      $ticket_remaining_days = 0;
    }

    // Considerar ilimitado si la fecha es 9999-12-31 o si quedan más de 10 años (3650 días)
    $is_unlimited = (strpos($ticket_expiredate, '9999-12-31') === 0) || ($expireDate->format('Y') == '9999') || ($ticket_remaining_days > 3650);

    if ($is_unlimited) {
        $ticket_remaining_percent = 100;
    } elseif ($total_seconds > 0) {
        $remaining_seconds = $expireDate->getTimestamp() - $todayDate->getTimestamp();
        $ticket_remaining_percent = round(($remaining_seconds / $total_seconds) * 100);
        if ($ticket_remaining_percent < 0) $ticket_remaining_percent = 0;
    } else {
        // Si es pase de 1 día (compra y vence el mismo día), es 100% si es válido
        $ticket_remaining_percent = ($todayDate <= $expireDate) ? 100 : 0;
    }

    $interval = $todayDate->diff($expireDate);
    if ($is_unlimited) {
        $translated_text = $translations["unlimited"];
    } elseif ($todayDate <= $expireDate) {
        if ($interval->days < 1) {
            $translated_text = $interval->format('%H:%I:%S');
        } else {
            $translated_text = $interval->format('%a d %h h %i m');
        }
    } else {
        $translated_text = $translations["expired"];
    }
  } else {
    $ticket_name = null;
    $ticket_buydate = null;
    $ticket_expiredate = null;
    $ticket_opportunities = null;

    $ticket_total_days = 0;
    $ticket_remaining_days = 0;
    $ticket_remaining_percent = 0;
    $translated_text = $translations["youdonthaveticket"];
  }

  $stmt->close();
} else {
  echo "Hiba a lekérdezés előkészítésekor: " . $conn->error;
}



if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['userid'])) {

  $sql_update = "UPDATE users SET confirmed = 'Yes' WHERE userid = $useridgymuser";

  if ($conn->query($sql_update) === TRUE) {
    $alerts_html .= '<div class="alert alert-success" role="alert">' . $translations["regconfirm"] . '</div>';

    $action = $translations['regconfirm'] . ' ID: ' . $useridgymuser;
    $actioncolor = 'success';
    $sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iss", $userid, $action, $actioncolor);
    $stmt->execute();

    header("Refresh:2");
    exit;
  } else {
    $alerts_html .= '<div class="alert alert-danger" role="alert">Unexpected error: ' . $conn->error . '</div>';
  }

  $conn->close();
}

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
          <li class="active"><a href="../"><i class="bi bi-people"></i> <?php echo $translations["users"]; ?></a></li>
          <li><a href="../../statistics"><i class="bi bi-bar-chart"></i> <?php echo $translations["statspage"]; ?></a></li>
          <li><a href="../../boss/sell"><i class="bi bi-shop"></i> <?php echo $translations["sellpage"]; ?></a></li>
          <li><a href="../../invoices"><i class="bi bi-receipt"></i> <?php echo $translations["invoicepage"]; ?></a></li>
          <?php if ($is_boss === 1) { ?>
            <li class="dropdown">
              <a class="dropdown-toggle" data-toggle="dropdown" href="#"><i class="bi bi-gear"></i> <?php echo $translations["settings"]; ?> <span class="caret"></span></a>
              <ul class="dropdown-menu">
                <li><a href="../../boss/mainsettings"><?php echo $translations["businesspage"]; ?></a></li>
                <li><a href="../../boss/workers"><?php echo $translations["workers"]; ?></a></li>
                <li><a href="../../boss/packages"><?php echo $translations["packagepage"]; ?></a></li>
                <li><a href="../../boss/hours"><?php echo $translations["openhourspage"]; ?></a></li>
                <li><a href="../../boss/smtp"><?php echo $translations["mailpage"]; ?></a></li>
                <li><a href="../../boss/chroom"><?php echo $translations["chroompage"]; ?></a></li>
                <li><a href="../../boss/rule"><?php echo $translations["rulepage"]; ?></a></li>
              </ul>
            </li>
          <?php } ?>
          <li><a href="../../shop/tickets"><i class="bi bi-ticket"></i> <?php echo $translations["ticketspage"]; ?></a></li>
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
          <li class="sidebar-item active">
            <a class="sidebar-link" href="#">
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
              <a class="sidebar-link" href="../../boss/packages">
                <i class="bi bi-box-seam"></i>
                <span><?php echo $translations["packagepage"]; ?></span>
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
              <a class="sidebar-link" href="../../boss/chroom">
                <i class="bi bi-duffle"></i>
                <span><?php echo $translations["chroompage"]; ?></span>
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
            <?php echo $alerts_html; ?>
          </div>
        </div>
        <div class="row">
          <div class="col-sm-6">
            <div class="card shadow">
              <div class="card-heading">
                <h5 class="card-title"><?php echo $translations["editprofile"]; ?></h5>
              </div>
              <form method="POST">
                <div class="row">
                  <div class="col-12 col-lg-9 order-2 order-lg-1">
                    <div class="mb-3">
                      <div class="form-group">
                        <label for="firstname"><?php echo $translations["firstname"]; ?></label>
                        <input type="text" class="form-control" id="firstname" name="firstname" value="<?php echo $firstname; ?>" required>
                      </div>
                    </div>
                    <div class="mb-3">
                      <div class="form-group">
                        <label for="lastname"><?php echo $translations["lastname"]; ?></label>
                        <input type="text" class="form-control" id="lastname" name="lastname" value="<?php echo $lastname; ?>" required>
                      </div>
                    </div>
                  </div>

                  <div class="col-12 col-lg-3 text-center order-1 order-lg-2 mb-3 mb-lg-0">
                    <?php
                    $profilePicPath = '../../../assets/img/profiles/' . $useridgymuser . '.png';
                    if (file_exists($profilePicPath)): ?>
                      <img src="<?php echo $profilePicPath; ?>?v=<?php echo filemtime($profilePicPath); ?>" alt="User" style="width: 150px; height: 150px; object-fit: cover; border-radius: 50%; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border: 3px solid #fff; cursor: zoom-in;" class="zoomable-image">
                      <br>
                      <button type="submit" name="delete_photo" class="btn btn-danger btn-sm mt-2" style="margin-top: 10px;" onclick="return confirm('¿Estás seguro de que deseas eliminar la foto de perfil?');">
                          <i class="bi bi-trash"></i> Eliminar Foto
                      </button>
                    <?php endif; ?>
                    <br>
                    <button type="button" class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#cameraModal" style="margin-top: 10px;">
                        <i class="bi bi-camera"></i> Tomar Foto
                    </button>

                    <!-- QR Code Display -->
                    <div style="margin-top: 20px;">
                        <p><strong>Código QR de Acceso</strong></p>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo $useridgymuser; ?>&size=150x150" alt="QR Code" style="width: 150px; height: 150px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); border: 3px solid #fff; border-radius: 4px;">
                        <br><small class="text-muted">ID: <?php echo $useridgymuser; ?></small>
                        <br>
                        <a href="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo $useridgymuser; ?>&size=300x300" class="btn btn-success btn-sm" style="margin-top: 10px;" download="qr_<?php echo $useridgymuser; ?>.png" target="_blank">
                            <i class="bi bi-download"></i> <?php echo $translations["download_qr"] ?? "Descargar QR"; ?>
                        </a>
                    </div>
                  </div>
                </div>
                <div class="mb-3">
                  <div class="form-group">
                    <label for="email"><?php echo $translations["email"]; ?></label>
                    <input type="email" class="form-control" id="email" name="email" value="<?php echo $email; ?>">
                  </div>

                </div>
                <div class="mb-3">
                  <div class="form-group">
                    <label for="phone"><?php echo $translations["fno"]; ?></label>
                    <input type="text" class="form-control" id="phone" name="phone" value="<?php echo $phone; ?>">
                  </div>
                </div>
                <div class="mb-3">
                  <div class="form-group">
                    <label for="gender"><?php echo $translations["gender"]; ?></label>
                    <select class="form-control" id="gender" name="gender">
                        <option value="Male" <?php if($gender == 'Male') echo 'selected'; ?>><?php echo $translations["boy"]; ?></option>
                        <option value="Female" <?php if($gender == 'Female') echo 'selected'; ?>><?php echo $translations["girl"]; ?></option>
                        <option value="Other" <?php if($gender == 'Other') echo 'selected'; ?>>Otros</option>
                    </select>
                  </div>
                </div>
                <button type="submit" name="save" class="btn btn-primary"><i class="bi bi-save"></i>
                  <?php echo $translations["save"]; ?></button>
                <?php
                if ($is_boss == 1) {
                ?>
                  <button type="button" class="btn btn-info" data-toggle="modal" data-target="#resetPasswordModal">
                    <i class="bi bi-key"></i>
                    Restablecer Contraseña
                  </button>
                  <button type="button" class="btn btn-warning" data-toggle="modal" data-target="#revokeModal">
                    <i class="bi bi-exclamation-circle"></i>
                    Revocar Membresía
                  </button>
                  <button type="button" class="btn btn-danger" data-toggle="modal" data-target="#deleteModal" data-userid="1">
                    <i class="bi bi-trash"></i>
                    <?php echo $translations["deleteuserbtn"]; ?>
                  </button> <?php
                          }
                            ?>

              </form>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card card-default">
              <div class="card-heading">
                <h5 class="card-title"><?php echo $translations["userinfo"]; ?></h5>
              </div>
              <div class="card-body">
                <div class="form-group">
                  <label><?php echo $translations["identifier"]; ?></label>
                  <input type="text" class="form-control" value="<?php echo $useridgymuser; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="registerInput"><?php echo $translations["reg-date"]; ?></label>
                  <input type="text" class="form-control" id="registerInput" value="<?php echo $regdate; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="lastLoginInput">Último acceso a la App</label>
                  <input type="text" class="form-control" id="lastLoginInput" value="<?php echo $lastlogin; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="Profile_balance"><?php echo $translations["profilebalance"]; ?></label>
                  <input type="text" class="form-control" id="Profile_balance" value="<?php echo $balance; ?> <?php echo $currency; ?>" disabled>
                </div>
                <div class="form-group">
                  <label for="emailVerifiedInput"><?php echo $translations["regconfirm"]; ?></label>
                  <form method="post">
                    <div class="input-group">
                      <input type="text" class="form-control text-danger" id="emailVerifiedInput" value="<?php echo ($verify == "Yes") ? $translations["yes"] : $translations["no"]; ?>" disabled>
                      <span class="input-group-btn">
                        <button class="btn btn-success" type="submit" <?php if ($verify == "Yes") {
                                                                        echo "disabled";
                                                                      } ?>>
                          <?php echo $translations["forceregconf"]; ?>
                        </button>
                        <input type="hidden" name="userid" value="<?php echo $useridgymuser; ?>">
                      </span>
                    </div>
                  </form>
                </div>
                <div class="form-group">
                  <label for="addressInput">Ultima fecha que visitó el gimnasio</label>
                  <input type="text" class="form-control" id="addressInput" value="<?php echo $last_visit_date; ?>" disabled>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="row">
          <div class="col-md-6">
            <div class="panel panel-default">
              <div class="panel-heading text-center" style="background: rgb(9, 80, 220);
    background: -moz-linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    background: -webkit-linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    background: linear-gradient(90deg, rgba(9, 80, 220, 1) 0%, rgba(9, 88, 210, 1) 50%, rgba(9, 110, 210, 1) 100%);
    filter: progid:DXImageTransform.Microsoft.gradient(startColorstr=' #0950dc', endColorstr='#096ed2' , GradientType=1); color: white;">
                <div style="margin-bottom: 10px;">
                  <span class="label <?php
                                      if (!isset($row) || empty($row)) {
                                        echo 'label-danger';
                                      } else {
                                        $expire = new DateTime($row['expiredate']);
                                        $today = new DateTime(date('Y-m-d'));
                                        $interval = $today->diff($expire)->format('%r%a');

                                        if ($interval < 0) {
                                          echo 'label-danger';
                                        } elseif ($interval == 0) {
                                          echo 'label-warning';
                                        } else {
                                          echo 'label-success';
                                        }
                                      }
                                      ?>" style="font-size: 14px; padding: 8px 15px;">
                    <?php
                    if (!isset($row) || empty($row)) {
                      echo '✗ ' . $translations["expired"];
                    } else {
                      $expire = new DateTime($row['expiredate']);
                      $today = new DateTime(date('Y-m-d'));
                      $interval = $today->diff($expire)->format('%r%a');

                      if ($interval < 0) {
                        echo '✗ ' . $translations["expired"];
                      } elseif ($interval == 0) {
                        echo $translations["expiresoon"];
                      } else {
                        echo '✓ ' . $translations["valid"];
                      }
                    }
                    ?>
                  </span>
                </div>
                <h4 style="margin: 0;"><?php echo $translations["status"]; ?></h4>
              </div>

              <div class="panel-body">
                <div class="row">
                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media">
                          <div class="media-left">
                            <div class="btn btn-success btn-circle" style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              📅
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted" style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["buytime"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $ticket_buydate; ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media">
                          <div class="media-left">
                            <div class="btn btn-danger btn-circle" style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              🎯
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted" style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["ticketspassname"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $ticket_name; ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media" style="margin-bottom: 15px;">
                          <div class="media-left">
                            <div class="btn btn-warning btn-circle" style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              ⏰
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted" style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["validity"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php echo $translated_text; ?></div>
                          </div>
                        </div>
                        <div class="progress" style="margin-bottom: 10px;">
                          <div class="progress-bar <?php
                                                    echo ($ticket_remaining_percent < 20)
                                                      ? 'progress-bar-danger'
                                                      : (($ticket_remaining_percent < 40)
                                                        ? 'progress-bar-warning'
                                                        : 'progress-bar-info');
                                                    ?>" role="progressbar" style="width: <?php echo $ticket_remaining_percent; ?>%">
                            <?php echo $ticket_remaining_percent; ?>%
                          </div>

                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-xs-12" style="margin-bottom: 15px;">
                    <div class="panel panel-default">
                      <div class="panel-body">
                        <div class="media" style="margin-bottom: 15px;">
                          <div class="media-left">
                            <div class="btn btn-info btn-circle" style="width: 40px; height: 40px; border-radius: 50%; padding: 8px;">
                              💪
                            </div>
                          </div>
                          <div class="media-body">
                            <small class="text-muted" style="text-transform: uppercase; font-weight: bold;"><?php echo $translations["tickettableoccassion"]; ?></small>
                            <div style="font-weight: bold; font-size: 16px;"><?php
                                                                              echo is_null($ticket_opportunities) ? $translations["unlimited"] : $ticket_opportunities . ' '. $translations["occassion_left"];
                                                                              ?>
                            </div>
                          </div>
                        </div>
                      </div>
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
          <a type="button" class="btn btn-secondary" data-dismiss="modal"><?php echo $translations["not-yet"]; ?></a>
          <a href="../../logout.php" type="button" class="btn btn-danger"><?php echo $translations["confirm"]; ?></a>
        </div>
      </div>
    </div>
  </div>
  <!-- DELETE USER MODAL -->

  <!-- Modal -->
  <div class="modal fade" id="deleteModal" tabindex="-1" role="dialog" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteModalLabel"><?php echo $translations["deleteuserbtn"]; ?></h5>
        </div>
        <div class="modal-body">
          <p><?php echo $translations["undoallert"]; ?></p>
          <code><?php echo $firstname; ?> <?php echo $lastname; ?> <?php echo $translations["identifier"]; ?> <?php echo $useridgymuser; ?></code>
        </div>
        <div class="modal-footer">
          <form method="post" action="">
            <input type="hidden" name="userid" id="userid" value="<?php echo $useridgymuser; ?>">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="bi bi-x-lg"></i>
              <?php echo $translations["not-yet"]; ?></button>
            <button type="submit" name="delete_user" class="btn btn-danger"><i class="bi bi-exclamation-triangle"></i>
              <?php echo $translations["delete"]; ?></button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- RESET PASSWORD MODAL -->
  <div class="modal fade" id="resetPasswordModal" tabindex="-1" role="dialog" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="resetPasswordModalLabel">Restablecer Contraseña</h5>
        </div>
        <form method="post" action="">
            <div class="modal-body">
              <p>Estás a punto de cambiar la contraseña del usuario <strong><?php echo $firstname . ' ' . $lastname; ?></strong>.</p>
              <div class="form-group">
                  <label for="new_password">Nueva Contraseña</label>
                  <input type="text" class="form-control" id="new_password" name="new_password" required placeholder="Ingrese nueva contraseña">
              </div>
              <p class="text-danger"><small>Esta acción no se puede deshacer y el usuario deberá usar esta nueva contraseña para ingresar.</small></p>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
              <button type="submit" name="reset_password_submit" class="btn btn-primary">Guardar Contraseña</button>
            </div>
        </form>
      </div>
    </div>
  </div>

  <!-- REVOKE MEMBERSHIP MODAL -->
  <div class="modal fade" id="revokeModal" tabindex="-1" role="dialog" aria-labelledby="revokeModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="revokeModalLabel">Revocar Membresía</h5>
        </div>
        <div class="modal-body">
          <p>¿Está seguro de que desea revocar la membresía de este usuario?</p>
          <code><?php echo $firstname; ?> <?php echo $lastname; ?> <?php echo $translations["identifier"]; ?> <?php echo $useridgymuser; ?></code>
          <p class="text-warning"><strong>Esta acción eliminará su acceso actual y su pase/membresía será cancelado.</strong></p>
        </div>
        <div class="modal-footer">
          <form method="post" action="">
            <input type="hidden" name="userid" id="revoke_userid" value="<?php echo $useridgymuser; ?>">
            <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="bi bi-x-lg"></i>
              <?php echo $translations["not-yet"]; ?></button>
            <button type="submit" name="revoke_membership" class="btn btn-warning"><i class="bi bi-exclamation-circle"></i>
              Revocar Membresía</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- CAMERA MODAL -->
  <div class="modal fade" id="cameraModal" tabindex="-1" role="dialog" aria-labelledby="cameraModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="cameraModalLabel">Tomar Foto de Perfil</h5>
          <button type="button" class="close" data-dismiss="modal" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
        <div class="modal-body text-center">
          <div id="my_camera" style="width:100%; max-width:320px; height:240px; margin: 0 auto; background: #000; border-radius: 4px; overflow: hidden;"></div>
          <div id="results" style="display:none; margin-top:10px;"></div>
          <input type="hidden" id="photo_data">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-info" onClick="take_snapshot()" id="btn-capture"><i class="bi bi-camera"></i> Capturar</button>
          <button type="button" class="btn btn-primary" onClick="save_photo()" id="btn-save" style="display:none;"><i class="bi bi-save"></i> Guardar Foto</button>
          <button type="button" class="btn btn-warning" onClick="reset_camera()" id="btn-retry" style="display:none;"><i class="bi bi-arrow-counterclockwise"></i> Reintentar</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Loading Overlay -->
  <div id="loading-overlay">
      <div class="spinner"></div>
      <h4 style="margin-top: 20px; color: #333;">Guardando cambios...</h4>
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
  <script src="../../../assets/js/date-time.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
  <script>
    let cameraStream = null;
    $(document).on('click', '.zoomable-image', function() {
        // Prevenir que el modal se abra si no hay imagen (es un ícono)
        if ($(this).is('img')) {
            var imgSrc = $(this).attr('src');
            $('#zoomModalImage').attr('src', imgSrc);
            $('#imageZoomModal').modal('show');
        }
    });

    const video = document.createElement('video');
    const canvas = document.createElement('canvas');
    
    $('#cameraModal').on('shown.bs.modal', function () {
        startCamera();
    });

    $('#cameraModal').on('hidden.bs.modal', function () {
        stopCamera();
        reset_camera();
    });

    function startCamera() {
        const container = document.getElementById('my_camera');
        container.innerHTML = '';
        video.setAttribute('autoplay', '');
        video.setAttribute('muted', '');
        video.setAttribute('playsinline', '');
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'cover';
        container.appendChild(video);

        navigator.mediaDevices.getUserMedia({ video: true })
            .then(function(stream) {
                cameraStream = stream;
                video.srcObject = stream;
            })
            .catch(function(err) {
                console.log("An error occurred: " + err);
                document.getElementById('my_camera').innerHTML = '<p class="text-danger">No se pudo acceder a la cámara.</p>';
            });
    }

    function stopCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(track => track.stop());
            cameraStream = null;
        }
    }

    function take_snapshot() {
        if (!cameraStream) return;
        
        const container = document.getElementById('my_camera');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        
        const data_uri = canvas.toDataURL('image/png');
        
        document.getElementById('photo_data').value = data_uri;
        document.getElementById('results').innerHTML = '<img src="'+data_uri+'" style="width:100%; max-width:320px; border-radius: 4px;"/>';
        
        video.style.display = 'none';
        document.getElementById('results').style.display = 'block';
        
        document.getElementById('btn-capture').style.display = 'none';
        document.getElementById('btn-save').style.display = 'inline-block';
        document.getElementById('btn-retry').style.display = 'inline-block';
    }

    function reset_camera() {
        const videoEl = document.querySelector('#my_camera video');
        if(videoEl) videoEl.style.display = 'block';
        document.getElementById('results').style.display = 'none';
        document.getElementById('btn-capture').style.display = 'inline-block';
        document.getElementById('btn-save').style.display = 'none';
        document.getElementById('btn-retry').style.display = 'none';
    }

    function save_photo() {
        const raw_image_data = document.getElementById('photo_data').value;
        
        // Create a form dynamically to submit
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '';
        
        const inputAction = document.createElement('input');
        inputAction.type = 'hidden';
        inputAction.name = 'save_photo';
        inputAction.value = '1';
        
        const inputData = document.createElement('input');
        inputData.type = 'hidden';
        inputData.name = 'photo_data';
        inputData.value = raw_image_data;
        
        form.appendChild(inputAction);
        form.appendChild(inputData);
        document.body.appendChild(form);
        form.submit();
    }

    $('form').on('submit', function() {
        if (!$(this).attr('target')) {
            $('#loading-overlay').css('display', 'flex');
        }
    });
  </script>
</body>

</html>