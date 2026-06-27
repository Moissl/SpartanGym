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

if (!isset($base_path)) {
    $base_path = './';
}

$env_data = read_env_file($base_path . '.env');

// Zona horaria: leer de .env (TIMEZONE) o usar America/Mexico_City
$timezone = $env_data['TIMEZONE'] ?? 'America/Mexico_City';
if (!in_array($timezone, timezone_identifiers_list())) {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';
$description = $env_data['DESCRIPTION'] ?? '';
$metakey = $env_data['META_KEY'] ?? '';
$gkey = $env_data['GOOGLE_KEY'] ?? '';
$capacity = $env_data['CAPACITY'] ?? '';
$about_us = $env_data['ABOUT'] ?? '';
$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';
$currency = $env_data['CURRENCY'] ?? '';
$country = $env_data['COUNTRY'] ?? '';
$city = $env_data['CITY'] ?? '';
$street = $env_data['STREET'] ?? '';
$hause_no = $env_data['HOUSE_NUMBER'] ?? '';
$mailadress = $env_data['MAIL_USERNAME'] ?? '';
$phoneno = $env_data['PHONE_NO'] ?? '';
$smtp_host = $env_data['MAIL_HOST'] ?? '';
$smtp_port = $env_data['MAIL_PORT'] ?? '';
$smtp_encryption = $env_data['MAIL_ENCRYPTION'] ?? '';
$smtp_username = $env_data['MAIL_USERNAME'] ?? '';
$smtp_password = $env_data['MAIL_PASSWORD'] ?? '';


$lang = $lang_code;

$langDir = $base_path . "assets/lang/";

$langFile = $langDir . "$lang.json";

if (!file_exists($langFile)) {
    die("Language file not found: $langFile");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ajustar zona horaria de la sesión MySQL al offset de PHP para mantener coherencia
try {
    $tz = new DateTimeZone(date_default_timezone_get());
    $now = new DateTime('now', $tz);
    $offset = $tz->getOffset($now); // segundos
    $sign = ($offset < 0) ? '-' : '+';
    $offset = abs($offset);
    $hours = str_pad(floor($offset / 3600), 2, '0', STR_PAD_LEFT);
    $minutes = str_pad(($offset % 3600) / 60, 2, '0', STR_PAD_LEFT);
    $sql_tz = $sign . $hours . ':' . $minutes;
    $conn->query("SET time_zone = '{$sql_tz}'");
} catch (Exception $e) {
    // Si falla no bloqueamos la app; MySQL seguirá usando la zona por defecto del servidor
}

$copyright_year = date("Y");

$dayNames = [
    1 => $translations["Mon"],
    2 => $translations["Tue"],
    3 => $translations["Wed"],
    4 => $translations["Thu"],
    5 => $translations["Fri"],
    6 => $translations["Sat"],
    7 => $translations["Sun"]
];

