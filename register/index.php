<?php
session_start();

// Ocultar advertencias de código obsoleto de PHP 8.2+ para evitar conflictos con header()
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

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

$alerts_html = "";

require_once '../vendor/autoload.php'; // COMPOSER!

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";

$host = $_SERVER['HTTP_HOST'];

$domain_url = $protocol . $host;

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
#$country = $env_data['COUNTRY'] ?? '';
#$street = $env_data['STREET'] ?? '';
#$city = $env_data['CITY'] ?? '';
#$hause_no = $env_data['HOUSE_NUMBER'] ?? '';
$description = $env_data['DESCRIPTION'] ?? '';
$metakey = $env_data['META_KEY'] ?? '';
$gkey = $env_data['GOOGLE_KEY'] ?? '';
$smtp_password = $env_data['MAIL_PASSWORD'] ?? '';
$smtp_port = $env_data['MAIL_PORT'] ?? '';
$smtp_username = $env_data["MAIL_USERNAME"] ?? '';
$smtp_encryption = $env_data['MAIL_ENCRYPTION'] ?? '';
$smtp_host = $env_data['MAIL_HOST'] ?? '';
$autoaccept = $env_data['AUTOACCEPT'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
  die("Archivo de idioma no encontrado: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $firstname = $_POST['firstname'];
  $lastname = $_POST['lastname'];
  $email = $_POST['email'];
  $password = $_POST['password'];
  $confirm_password = $_POST['confirm_password'];
  $gender = $_POST['gender'];
  $birthdate = $_POST['birthdate'];
  $country_code = $_POST['country_code'];
  $phone_number = $_POST['phone_number'];
  $full_phone = $country_code . $phone_number;
  if ($password !== $confirm_password) {
    $alerts_html .= '<div class="alert alert-danger">' . $translations["twopasswordnot"] . '</div>';
    header("Refresh: 5");
  } else {
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $userid = rand(pow(10, 9), pow(10, 10) - 1);

    if ($autoaccept === "TRUE") {
      $confirmed = 'YES';
    } else {
      $confirmed = 'NO';
    }


    $registration_date = date('Y-m-d H:i:s');

    $conn = new mysqli($db_host, $db_username, $db_password, $db_name);

    if ($conn->connect_error) {
      die("Error de conexión: " . $conn->connect_error);
    }

    // Fix for gender column truncation (ensure it supports 'Other')
    $conn->query("ALTER TABLE users MODIFY COLUMN gender VARCHAR(50)");

    // Check for duplicate email
    $checkStmt = $conn->prepare("SELECT userid FROM users WHERE email = ?");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkStmt->store_result();

    if ($checkStmt->num_rows > 0) {
        $alerts_html .= '<div class="alert alert-danger">El correo electrónico ya está registrado.</div>';
        $checkStmt->close();
    } else {
        $checkStmt->close();

        $stmt = $conn->prepare("INSERT INTO users (userid, firstname, lastname, email, password, gender, birthdate, registration_date, confirmed, phone) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt === false) {
          die("Error al preparar la consulta: " . $conn->error);
        }

        $stmt->bind_param("isssssssss", $userid, $firstname, $lastname, $email, $hashed_password, $gender, $birthdate, $registration_date, $confirmed, $full_phone);

        $ConfirmEmailPage_PLACEHOLDER = str_replace("{business_name}", $business_name, $translations["confirmemailpage"]);
        $replacements = [
          "{business_name}" => $business_name,
          "{first_name}" => $firstname
        ];
        $ConfirmEmailHeader_PLACEHOLDER = strtr($translations["confirmemailheader"], $replacements);
        $ConfirmEmailFooterWhy_PLACEHOLDER = str_replace("{business_name}", $business_name, $translations["confirmemailfooterwhy"]);

        try {
            if ($stmt->execute()) {
              $alerts_html .= '<div class="alert alert-success">' . ($translations["regconfirm"] ?? '¡Registro exitoso!') . '</div>';
              if (!empty($email)) {
      $transport = (new Swift_SmtpTransport($smtp_host, $smtp_port, $smtp_encryption))
        ->setUsername($smtp_username)
        ->setPassword($smtp_password);

      $mailer = new Swift_Mailer($transport);

      $successEmailContent = <<<EOD
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <style type="text/css">
    body, p, div { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; font-size: 14px; }
    body { color: #222222; background-color: #f8f9fa; margin: 0; padding: 0; }
    a { color: #0950DC; text-decoration: none; }
    .cta-button { display: inline-block; background: linear-gradient(135deg, #0950DC, #0742B8); color: white; text-decoration: none; padding: 16px 32px; border-radius: 8px; font-weight: 600; font-size: 16px; box-shadow: 0 4px 12px rgba(9,80,220,0.3); }
    .tips-section { background: #f8f9fa; padding: 24px; border-radius: 8px; margin: 32px 0; }
    .tip-item { color: #6B7280; margin-bottom: 8px; padding-left: 20px; position: relative; }
    .tip-item:before { content: "•"; color: #0950DC; font-weight: bold; position: absolute; left: 0; }
    .footer { background: #f8f9fa; padding: 24px 30px; text-align: center; color: #6B7280; font-size: 12px; }
  </style>
</head>
<body>
  <center>
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr>
        <td>
          <table width="680" align="center" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF">
            <tr>
              <td align="center" style="padding:40px 30px 20px;">
                <img src="{$domain_url}/assets/img/logo.png" alt="GYM Logo" style="max-width:200px; height:auto;" />
              </td>
            </tr>
            <tr>
              <td style="padding:0 30px 30px; text-align:center;">
                <h1 style="color:#333333; font-size:28px; font-weight:700; margin-bottom:16px;">{$ConfirmEmailHeader_PLACEHOLDER}</h1>
                <p style="color:#6B7280; font-size:16px; margin-bottom:32px;">{$translations["confirmemailheadertext"]}</p>
                <a href="{$domain_url}/register/confirm.php?userid={$userid}" class="cta-button">{$translations["regconfirmbtn"]}</a>
                <div style="margin:20px 0;">
                  <a href="{$domain_url}" style="color:#0950DC; font-size:14px;">{$translations["confirmemailorlogin"]} →</a>
                </div>
                <div class="tips-section">
                  <h2 style="color:#333333; font-size:18px; font-weight:600; margin-bottom:16px;">{$translations["confirmemailfirst"]}</h2>
                  <div class="tip-item">{$translations["confirmemailtipone"]}</div>
                  <div class="tip-item">{$translations["confirmemailtiptwo"]}</div>
                </div>
              </td>
            </tr>
            <tr>
              <td class="footer">
                <p>{$ConfirmEmailFooterWhy_PLACEHOLDER}</p>
                <p style="font-size:10px; color:#D1D5DB; display:flex; align-items:center; justify-content:center; gap:8px;">
                  <span>⚡</span>
                  <span>Engineered with <span style="color:#ef4444;">♥</span> by <a href="https://gymoneglobal.com" style="color:#0950DC;">GYM One</a></span>
                  <span>⚡</span>
                </p>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </center>
</body>
</html>
EOD;


      $recipientEmail = $email;
      $subject = $translations["confirmemailmailsub"];

      $isRegistrationSuccessful = true;

      if ($isRegistrationSuccessful) {
        $message = (new Swift_Message($subject))
          ->setFrom(["{$smtp_username}" => "{$ConfirmEmailPage_PLACEHOLDER}"])
          ->setTo([$recipientEmail])
          ->setBody($successEmailContent, 'text/html');
      }
      $result = $mailer->send($message);
              }
              header("Refresh: 2; url=../login/");
            }
        } catch (Exception $e) {
            if ($conn->errno == 1062) {
                $alerts_html .= '<div class="alert alert-danger">El correo electrónico ya está registrado.</div>';
            } else {
                $alerts_html .= '<div class="alert alert-danger">Error al registrar: ' . $e->getMessage() . '</div>';
            }
        }
        $stmt->close();
    }
    $conn->close();
  }
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $business_name; ?> - <?php echo $translations["register"]; ?></title>
  <!-- Bootstrap CSS -->
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/css/login-register.css?v=<?php echo time(); ?>">
  <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
</head>

<body>
  <div id="register">
    <div class="container">
      <div class="row justify-content-center pt-4">
        <div class="col-md-6">
          <div class="card">
            <div class="card-body">
              <div class="text-center">
                <h2><?php echo $translations["reg-page"]; ?></h2>
                <img class="img mb-3 mt-3 img-fluid" src="../assets/img/logoSpartan.png" title="<?php echo $business_name; ?>" alt="<?php echo $business_name; ?>">
              </div>
              <?php if (!empty($login_error)) : ?>
                <div class="alert alert-danger"><?php echo $login_error; ?></div>
              <?php endif; ?>
              <?php if (!empty($alerts_html)) : ?>
                <?php echo $alerts_html; ?>
              <?php endif; ?>

              <form method="POST">
                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label for="firstname"><?php echo $translations["firstname"]; ?></label>
                    <input type="text" class="form-control" id="firstname" name="firstname" required>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="lastname"><?php echo $translations["lastname"]; ?></label>
                    <input type="text" class="form-control" id="lastname" name="lastname" required>
                  </div>
                </div>
                <div class="form-group">
                  <label for="email"><?php echo $translations["email"]; ?></label>
                  <input type="email" class="form-control" id="email" name="email">
                </div>
                <div class="form-row">
                  <div class="form-group col-md-6">
                    <label for="password"><?php echo $translations["password"]; ?></label>
                    <input type="password" class="form-control" id="password" name="password" required>
                  </div>
                  <div class="form-group col-md-6">
                    <label for="confirm_password"><?php echo $translations["password-confirm"]; ?></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                  </div>
                </div>
                <div class="form-group">
                  <label for="gender"><?php echo $translations["gender"]; ?></label>
                  <select class="form-control" id="gender" name="gender" required>
                    <option value="Male"><?php echo $translations["boy"]; ?></option>
                    <option value="Female"><?php echo $translations["girl"]; ?></option>
                    <option value="Other">Otros</option>
                  </select>
                  <small class="form-text text-muted">Este campo es exclusivamente estadistico para el gimnasio</small>
                </div>
                <div class="form-group">
                  <label for="birthdate"><?php echo $translations["birthday"]; ?></label>
                  <input type="text" class="form-control" id="birthdate" name="birthdate" placeholder="<?php echo $translations["birthday"]; ?>" required style="background-color: #fff;">
                </div>
                <div class="form-row">
                  <div class="form-group col-md-4">
                    <label for="country_code">Extensión</label>
                    <select class="form-control" id="country_code" name="country_code" required>
                        <option value="+52">🇲🇽 +52 (MX)</option>
                        <option value="+1">🇺🇸 +1 (US)</option>
                        <option value="+34">🇪🇸 +34 (ES)</option>
                        <option value="+54">🇦🇷 +54 (AR)</option>
                        <option value="+57">🇨🇴 +57 (CO)</option>
                        <option value="+56">🇨🇱 +56 (CL)</option>
                        <option value="+51">🇵🇪 +51 (PE)</option>
                    </select>
                  </div>
                  <div class="form-group col-md-8">
                    <label for="phone_number"><?php echo $translations["fno"]; ?></label>
                    <input type="tel" class="form-control" id="phone_number" name="phone_number" placeholder="E.j 1234567890" maxlength="10" oninput="this.value = this.value.replace(/[^0-9]/g, '');" required>
                  </div>
                </div>
                <iframe src="../admin/boss/rule/rule.html" width="100%" height="200px" frameborder="0"></iframe>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" value="" id="flexCheckDefault" required>
                  <label class="form-check-label" for="flexCheckDefault">
                    <?php echo $translations["acceptrules"]; ?>
                  </label>
                </div>
                <button type="submit" class="btn btn-primary"><?php echo $translations["register"]; ?></button>
              </form>
              <div class="text-start mt-1">
                <small><?php echo $translations["doyouhaveaccount"]; ?> <span><a href="../login/"><?php echo $translations["login"]; ?></a></span></small>
              </div>
            </div>
          </div>
          <div class="text-center mt-4 mb-5">
              <a href="../" class="btn btn-outline-info shadow-sm" style="border-radius: 20px; padding: 8px 25px; font-weight: 600;">
                  <i class="bi bi-house-door-fill"></i> <?php echo $translations["backtothehomepage"] ?? 'Volver a la Página Principal';?>
              </a>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
  <script>
    flatpickr("#birthdate", {
      locale: "<?php echo (isset($lang_code) && strtolower($lang_code) == 'es') ? 'es' : 'default'; ?>",
      dateFormat: "Y-m-d",
      maxDate: "today",
      altInput: true,
      altFormat: "j F Y",
      disableMobile: true
    });
  </script>
</body>

</html>