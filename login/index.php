<?php
session_start();

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

function get_db_connection()
{
    global $env_data;

    $db_host = $env_data['DB_SERVER'] ?? '';
    $db_username = $env_data['DB_USERNAME'] ?? '';
    $db_password = $env_data['DB_PASSWORD'] ?? '';
    $db_name = $env_data['DB_NAME'] ?? '';

    $conn = new mysqli($db_host, $db_username, $db_password, $db_name);

    if ($conn->connect_error) {
        die("Kapcsolódási hiba: " . $conn->connect_error);
    }

    return $conn;
}

$env_data = read_env_file('../.env');

// Establecer zona horaria desde .env
$timezone = $env_data['TIMEZONE'] ?? 'America/Mexico_City';
if (!in_array($timezone, timezone_identifiers_list())) {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$version = $env_data["APP_VERSION"] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("A nyelvi fájl nem található: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);


$login_error = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $conn = get_db_connection();

    $stmt = $conn->prepare("SELECT userid, password, confirmed FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 1) {
        $stmt->bind_result($userid, $hashed_password, $confirmed);
        $stmt->fetch();

        if (password_verify($password, $hashed_password)) {
            if ($confirmed == 'Yes') {
                $current_datetime = date('Y-m-d H:i:s');
                $user_ip = $_SERVER['REMOTE_ADDR'];
                $update_stmt = $conn->prepare("UPDATE users SET lastlogin = ?, lastip = ? WHERE userid = ?");
                $update_stmt->bind_param("ssi", $current_datetime, $user_ip, $userid);
                $update_stmt->execute();
                $update_stmt->close();
                $_SESSION['userid'] = $userid;
                header("Location: ../dashboard");
                exit();
            } else {
                $login_error = $translations["acceptemailplease"];
            }
        } else {
            $login_error = $translations["notcorrectlogin"];
        }
    } else {
        $login_error = $translations["notcorrectlogin"];
    }

    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?php echo $translations["login"]; ?></title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../assets/css/login-register.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="../assets/img/brand/favicon.png" type="image/x-icon">
    <style>
        .admin-link {
            transition: color 0.3s ease;
        }
        .admin-link:hover {
            color: #343a40 !important;
            text-decoration: none;
        }
    </style>
</head>

<body>
    <div id="login">
        <div class="container">
            <div class="row justify-content-center pt-5">
                <div class="col-md-5">
                    <div class="card shadow-lg border-0" style="border-radius: 1rem;">
                        <div class="card-body p-5">
                            <div class="text-center mb-4">
                                <img class="img mb-3 img-fluid" src="../assets/img/brand/logo.png" style="max-height: 80px;" title="<?php echo $business_name; ?>" alt="<?php echo $business_name; ?>">
                                <h3 class="font-weight-bold"><?php echo $translations["login"]; ?></h3>
                            </div>
                            <?php if (!empty($login_error)) : ?>
                                <div class="alert alert-danger" style="border-radius: 8px;"><?php echo $login_error; ?></div>
                            <?php endif; ?>
                            <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                                <div class="form-group">
                                    <label for="email" class="font-weight-bold text-muted"><?php echo $translations["email"]; ?></label>
                                    <input type="email" class="form-control form-control-lg" id="email" name="email" required style="border-radius: 8px;">
                                </div>
                                <div class="form-group mb-4">
                                    <label for="password" class="font-weight-bold text-muted"><?php echo $translations["password"]; ?></label>
                                    <input type="password" class="form-control form-control-lg" id="password" name="password" required style="border-radius: 8px;">
                                </div>
                                <button type="submit" class="btn btn-lg btn-block btn-primary" style="border-radius: 8px; font-weight: bold;">
                                    <?php echo $translations["login"]; ?> <i class="bi bi-box-arrow-in-right"></i>
                                </button>
                            </form>
                            
                            <hr class="my-4">
                            
                            <div class="text-center">
                                <p class="text-muted mb-2" style="font-size: 0.9rem;"><?php echo $translations["youdonthaveaccount"];?></p>
                                <a href="../register/" class="btn btn-block btn-outline-primary" style="border-radius: 8px; font-weight: bold;">
                                    <?php echo $translations["registerbtn"];?> <i class="bi bi-person-plus"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-center mt-4 mb-5">
                        <a href="../" class="btn btn-outline-info shadow-sm mr-2" style="border-radius: 20px; padding: 8px 25px; font-weight: 600;">
                            <i class="bi bi-house-door-fill"></i> <?php echo $translations["backtothehomepage"];?>
                        </a>
                        <a href="../admin/" class="btn btn-outline-secondary admin-link shadow-sm" style="border-radius: 20px; padding: 8px 25px; font-weight: 600;">
                            <i class="bi bi-shield-lock-fill"></i> <?php echo $translations["adminaccountlogin"];?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://code.jquery.com/jquery-3.3.1.slim.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.7/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/js/bootstrap.min.js"></script>
</body>

</html>