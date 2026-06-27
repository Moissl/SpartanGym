<?php
// /var/www/html/login_as.php
session_start();

// 1. CONFIGURACIÓN: Pon aquí el correo del usuario que quieres investigar
$target_email = ""; // Cambia esto por el correo del usuario que quieres "espiar"

// --- Lógica de conexión (copiada de tus otros archivos) ---
function read_env_file($file_path) {
    if (!file_exists($file_path)) return [];
    $env_file = file_get_contents($file_path);
    $env_lines = explode("\n", $env_file);
    $env_data = [];
    foreach ($env_lines as $line) {
        $line_parts = explode('=', $line);
        if (count($line_parts) == 2) {
            $env_data[trim($line_parts[0])] = trim($line_parts[1]);
        }
    }
    return $env_data;
}

$env_data = read_env_file('.env');
$conn = new mysqli(
    $env_data['DB_SERVER'] ?? 'localhost', 
    $env_data['DB_USERNAME'] ?? 'root', 
    $env_data['DB_PASSWORD'] ?? '', 
    $env_data['DB_NAME'] ?? 'gymone'
);

if ($conn->connect_error) die("Error DB: " . $conn->connect_error);

// 2. BUSCAR EL ID DEL USUARIO
$sql = "SELECT userid, firstname, lastname FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $target_email);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // 3. FORZAR EL INICIO DE SESIÓN
    $_SESSION['userid'] = $row['userid'];
    
    echo "<h1>Sesión iniciada como: " . $row['firstname'] . " " . $row['lastname'] . "</h1>";
    echo "<p>Redirigiendo al dashboard...</p>";
    echo "<script>setTimeout(function(){ window.location.href = '/dashboard/'; }, 1000);</script>";
} else {
    echo "Usuario no encontrado con el correo: " . $target_email;
}

$stmt->close();
$conn->close();
?>
