<?php
session_start();

if (!isset($_SESSION['adminuser'])) {
    header("Location: ../../");
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

$env_data = read_env_file('../../../../.env');

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';

$langDir = __DIR__ . "/../../../../assets/lang/";

$langFile = $langDir . "$lang_code.json";

if (!file_exists($langFile)) {
    die("Archivo de idioma no encontrado");
}

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

if (isset($_GET['id'])) {
    $product_id = (int)$_GET['id'];
    
    // Obtener el nombre del producto para el log
    $sql = "SELECT name, price, barcode FROM products WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $product = $result->fetch_assoc();
        
        // Eliminar producto
        $delete_sql = "DELETE FROM products WHERE id = ?";
        $delete_stmt = $conn->prepare($delete_sql);
        $delete_stmt->bind_param("i", $product_id);
        
        if ($delete_stmt->execute()) {
            // Registrar acción en log
            $action = $translations['delete'] . ' ' . $translations["product-name"] . ': ' . $product['name'] . ' (' . $translations["product-barcode"] . ': ' . $product['barcode'] . ')';
            $actioncolor = 'danger';
            
            $log_sql = "INSERT INTO logs (userid, action, actioncolor, time) VALUES (?, ?, ?, NOW())";
            $log_stmt = $conn->prepare($log_sql);
            $log_stmt->bind_param("iss", $userid, $action, $actioncolor);
            $log_stmt->execute();
            $log_stmt->close();
            
            header("Location: ../");
            exit();
        } else {
            echo "Error al eliminar el producto: " . $conn->error;
        }
        
        $delete_stmt->close();
    } else {
        echo "Producto no encontrado.";
    }
    
    $stmt->close();
} else {
    header("Location: ../");
    exit();
}

$conn->close();
?>
