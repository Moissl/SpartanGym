<?php
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

$lang = $lang_code;

$langDir = __DIR__ . "/../../assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("El archivo de idioma no se encuentra: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$alerts_html = '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión a la base de datos: " . $conn->connect_error);
}

// Ellenőrizzük, hogy a 'user' paraméter át van-e adva az URL-ben
if (isset($_GET['user'])) {
    $userid = $_GET['user'];

    // Lekérdezzük a felhasználó bejelentkezési idejét
    $sql = "SELECT login_date FROM temp_loggeduser WHERE userid = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        // Paraméterek bindolása
        $stmt->bind_param("s", $userid);

        // Lekérdezés végrehajtása
        $stmt->execute();
        $stmt->bind_result($login_date);
        $stmt->fetch();
        $stmt->close();

        // Ha van bejelentkezési idő
        if ($login_date) {
            // Jelenlegi idő lekérése
            $current_time = new DateTime();

            // Bejelentkezési idő átalakítása DateTime objektummá
            $login_time = new DateTime($login_date);

            // Különbség kiszámítása
            $interval = $login_time->diff($current_time);

            // Az eltelt idő percekben
            $minutes_spent = $interval->h * 60 + $interval->i;

            // Most, hogy tudjuk, mennyi időt töltött, beírjuk a workout_stats táblába
            $workout_date = $current_time->format('Y-m-d'); // Mai dátum

            $entry_time_str = $login_time->format('Y-m-d H:i:s');
            $exit_time_str = $current_time->format('Y-m-d H:i:s');

            $check_entry = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'entry_time'");
            if ($check_entry && $check_entry->num_rows == 0) {
                $conn->query("ALTER TABLE workout_stats ADD COLUMN entry_time DATETIME DEFAULT NULL");
            }
            $check_exit = $conn->query("SHOW COLUMNS FROM workout_stats LIKE 'exit_time'");
            if ($check_exit && $check_exit->num_rows == 0) {
                $conn->query("ALTER TABLE workout_stats ADD COLUMN exit_time DATETIME DEFAULT NULL");
            }

            // SQL lekérdezés a workout statisztika hozzáadására
            $insert_sql = "INSERT INTO workout_stats (userid, duration, workout_date, entry_time, exit_time) VALUES (?, ?, ?, ?, ?)";

            if ($insert_stmt = $conn->prepare($insert_sql)) {
                // Paraméterek bindolása
                $insert_stmt->bind_param("sisss", $userid, $minutes_spent, $workout_date, $entry_time_str, $exit_time_str);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
        }

        // Felhasználó neve a notifikációhoz
        $uName = 'Usuario';
        $userStmt = $conn->prepare("SELECT firstname, lastname FROM users WHERE userid = ?");
        $userStmt->bind_param("s", $userid);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        if ($userResult && $userResult->num_rows > 0) {
            $userData = $userResult->fetch_assoc();
            $uName = $userData['firstname'] . ' ' . $userData['lastname'];
        }
        $userStmt->close();

        // Felhasználó törlése a temp_loggeduser táblából
        $delete_sql = "DELETE FROM temp_loggeduser WHERE userid = ?";
        if ($delete_stmt = $conn->prepare($delete_sql)) {
            $delete_stmt->bind_param("s", $userid);
            if ($delete_stmt->execute()) {
                // Notificación de Salida
                $msg = "Salida registrada manualmente";
                $stmtExit = $conn->prepare("INSERT INTO access_notifications (type, message, userid, user_name) VALUES ('exit', ?, ?, ?)");
                $stmtExit->bind_param("sss", $msg, $userid, $uName);
                $stmtExit->execute();
                $stmtExit->close();
            }
            $delete_stmt->close();
        }

        header("Location: index.php");
        exit();
    }
}

// Kapcsolat lezárása
$conn->close();
?>
