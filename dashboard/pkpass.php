<?php
session_start();
require __DIR__ . '/../vendor/autoload.php';

// --- LIBRERÍAS PARA APPLE Y GOOGLE WALLET ---
use Passbook\PassFactory;
use Passbook\Pass\Image;
use Passbook\Pass\Field;
use Passbook\Pass\Barcode;
use Passbook\Pass\Structure;

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
$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? 'es';

// --- URL del dominio para las imágenes del pase ---
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$domain_url = $protocol . $host;

// --- Cargar traducciones ---
$lang = $lang_code;
$langDir  = __DIR__ . "/../assets/lang/";
$langFile = $langDir . "$lang.json";
$translations = json_decode(file_get_contents($langFile), true);

// --- Conexión a la base de datos ---
$conn = new mysqli($db_host, $db_username, $db_password, $db_name);
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// --- Obtener datos del usuario ---
if (!isset($_SESSION['userid'])) {
    die("Usuario no autenticado");
}
$userid = $_SESSION['userid'];
$sql = "SELECT firstname, lastname FROM users WHERE userid = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $userid);
    $stmt->execute();
    $stmt->bind_result($firstname, $lastname);
    if ($stmt->fetch()) {
        $memberName = $firstname . ' ' . $lastname;
    }
    $stmt->close();
}

// Determinar qué tipo de pase generar
$type = $_GET['type'] ?? 'apple';

if ($type === 'apple') {
    // ==================================================
    //  GENERACIÓN DE PASE PARA APPLE WALLET (.pkpass)
    // ==================================================

    // --- Configuración del Pase de Apple ---
    // ¡IMPORTANTE! Guarda estas credenciales de forma segura, por ejemplo, en tu archivo .env
    $passTypeIdentifier = $env_data['APPLE_PASS_TYPE_ID'] ?? 'pass.com.example.gym'; // Tu Pass Type ID de Apple
    $teamIdentifier     = $env_data['APPLE_TEAM_ID'] ?? 'YOUR_TEAM_ID';             // Tu Team ID de Apple
    $organizationName   = $business_name;

    // Rutas a tus certificados. Crea una carpeta 'certificates' en la raíz del proyecto.
    $p12FilePath = __DIR__ . '/../certificates/Certificates.p12'; // Certificado .p12
    $p12Password = $env_data['APPLE_CERT_PASSWORD'] ?? 'your_p12_password'; // Contraseña del .p12
    $wwdrCertPath = __DIR__ . '/../certificates/AppleWWDRCA.pem'; // Certificado WWDR de Apple

    if (!file_exists($p12FilePath) || !file_exists($wwdrCertPath)) {
        header("Location: index.php?error=apple_certs_missing");
        exit;
    }

    try {
        $passFactory = new PassFactory($passTypeIdentifier, $teamIdentifier, $organizationName, $p12FilePath, $p12Password, $wwdrCertPath);
        $pass = $passFactory->newPass();

        // --- Detalles del Pase ---
        $pass->setDescription($translations['gwalletinfobox'] ?? 'Pase de Miembro del Gimnasio');
        $pass->setSerialNumber((string)$userid);

        // --- Apariencia Visual ---
        $pass->setBackgroundColor('rgb(9, 80, 220)');
        $pass->setForegroundColor('rgb(255, 255, 255)');
        $pass->setLabelColor('rgb(255, 255, 255)');

        // --- Logo e Icono ---
        $logoPath = __DIR__ . '/../assets/img/logo.png';
        $iconPath = __DIR__ . '/../assets/img/brand/favicon.png';
        if (file_exists($logoPath)) $pass->addFile(new Image($logoPath, 'logo'));
        if (file_exists($iconPath)) $pass->addFile(new Image($iconPath, 'icon'));

        // --- Estructura y Campos del Pase ---
        $structure = new Structure();
        $primary = new Field('primary', $memberName);
        $primary->setLabel($translations['fullname'] ?? 'Nombre');
        $structure->addPrimaryField($primary);

        $secondary = new Field('secondary', (string)$userid);
        $secondary->setLabel($translations['userid'] ?? 'ID de Miembro');
        $structure->addSecondaryField($secondary);
        $pass->setStructure($structure);

        // --- Código de Barras ---
        // Agregamos el ID como texto alternativo (4to parámetro) para que aparezca debajo del QR
        $barcode = new Barcode(Barcode::TYPE_QR, (string)$userid, 'iso-8859-1', (string)$userid);
        $pass->setBarcode($barcode);

        // --- Generar y Enviar el Archivo .pkpass ---
        $pkpassFile = $passFactory->package($pass);

        header('Content-Type: application/vnd.apple.pkpass');
        header('Content-Disposition: attachment; filename="gym_pass.pkpass"');
        echo $pkpassFile->getStream();
        exit;

    } catch (Exception $e) {
        die("Error al generar el pase de Apple Wallet: " . $e->getMessage());
    }

} elseif ($type === 'google') {
    // ==================================================
    //  GENERACIÓN DE ENLACE PARA GOOGLE WALLET
    // ==================================================

    // --- Configuración de Google Wallet ---
    // ¡IMPORTANTE! Guarda estas credenciales de forma segura, por ejemplo, en tu archivo .env
    $googleCredentialsJsonPath = __DIR__ . '/../certificates/google-service-account.json'; // Ruta a tu clave de cuenta de servicio
    $issuerId = $env_data['GOOGLE_WALLET_ISSUER_ID'] ?? 'YOUR_ISSUER_ID'; // Tu ID de Emisor
    $passClassId = $env_data['GOOGLE_WALLET_CLASS_ID'] ?? 'YOUR_PASS_CLASS_ID'; // El ID de la clase de pase que creaste

    if (!file_exists($googleCredentialsJsonPath)) {
        header("Location: index.php?error=google_certs_missing");
        exit;
    }

    // ID único para el pase de este usuario
    $passObjectId = "{$passClassId}.{$userid}";

    // --- Crear el objeto del pase ---
    $passObject = [
        'id' => $passObjectId,
        'classId' => $passClassId,
        'state' => 'ACTIVE',
        'barcode' => [
            'type' => 'QR_CODE',
            'value' => (string)$userid,
            'alternateText' => (string)$userid
        ],
        'cardTitle' => [ 'defaultValue' => [ 'language' => $lang_code, 'value' => $business_name ] ],
        'header' => [ 'defaultValue' => [ 'language' => $lang_code, 'value' => $translations['mainpage'] ?? 'Acceso' ] ],
        'hexBackgroundColor' => '#0950DC',
        'logo' => [
            'sourceUri' => [ 'uri' => $domain_url . '/assets/img/logo.png' ],
            'contentDescription' => [ 'defaultValue' => [ 'language' => $lang_code, 'value' => 'Logo' ] ]
        ],
        'textModulesData' => [
            [ 'header' => $translations['fullname'] ?? 'Nombre', 'body' => $memberName, 'id' => 'member_name' ],
            [ 'header' => $translations['userid'] ?? 'ID de Miembro', 'body' => (string)$userid, 'id' => 'member_id' ]
        ]
    ];

    // --- Crear y firmar el JWT ---
    try {
        $serviceAccount = new \Google\Client();
        $serviceAccount->setAuthConfig($googleCredentialsJsonPath);
        $serviceAccount->addScope('https://www.googleapis.com/auth/wallet_object.issuer');

        $claims = [
            'iss' => $serviceAccount->getClientId(),
            'aud' => 'google',
            'typ' => 'savetowallet',
            'origins' => [$domain_url],
            'payload' => [
                // Para pases genéricos, usa 'genericObjects'
                'genericObjects' => [ $passObject ]
            ]
        ];

        $jwt = $serviceAccount->createJwt($claims);
        $saveUrl = "https://pay.google.com/gp/v/wallet/save/{$jwt}";

        // Redirigir al usuario a la URL para guardar el pase
        header("Location: " . $saveUrl);
        exit;

    } catch (Exception $e) {
        die("Error al generar el enlace de Google Wallet: " . $e->getMessage());
    }
} else {
    // Fallback: si el tipo no es ni 'apple' ni 'google', muestra un error.
    die("Tipo de pase no válido.");
}
?>
