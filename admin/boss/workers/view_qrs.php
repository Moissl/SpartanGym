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

$db_host = $env_data['DB_SERVER'] ?? '';
$db_username = $env_data['DB_USERNAME'] ?? '';
$db_password = $env_data['DB_PASSWORD'] ?? '';
$db_name = $env_data['DB_NAME'] ?? '';

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

$sql = "SELECT userid, Firstname, Lastname FROM workers ORDER BY Firstname";
$result = $conn->query($sql);
$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title>Descargar QRs de Empleados</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
    <style>
        @media print {
            .no-print {
                display: none;
            }
            .qr-grid {
                page-break-inside: avoid;
            }
            body {
                margin: 0;
                padding: 0;
            }
        }
        .qr-card {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin: 15px;
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            page-break-inside: avoid;
        }
        .qr-card img {
            max-width: 200px;
            margin: 15px auto;
            border: 1px solid #eee;
            padding: 5px;
        }
        .qr-card h4 {
            margin: 10px 0;
            font-weight: bold;
        }
        .qr-card p {
            margin: 5px 0;
            color: #666;
            font-size: 14px;
        }
        .qr-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        .download-btn {
            margin: 5px;
        }
    </style>
</head>

<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4 no-print">
            <div class="col-md-12">
                <a href="index.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Volver a Empleados
                </a>
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="bi bi-printer"></i> Imprimir QRs
                </button>
                <a href="download_qrs.php" class="btn btn-success">
                    <i class="bi bi-download"></i> Descargar ZIP
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-md-12">
                <h2 class="text-center mb-4">
                    <i class="bi bi-qr-code"></i> QRs de Empleados - <?php echo $business_name; ?>
                </h2>
            </div>
        </div>

        <div class="qr-grid">
            <?php
            if ($result->num_rows > 0) {
                while ($row = $result->fetch_assoc()) {
                    $worker_id = $row['userid'];
                    $firstname = $row['Firstname'];
                    $lastname = $row['Lastname'];
                    $qr_file = "../../../assets/img/worker_qr/{$worker_id}.png";
                    $qr_exists = file_exists(__DIR__ . '/' . $qr_file);
            ?>
                    <div class="qr-card">
                        <h4><?php echo htmlspecialchars($firstname . ' ' . $lastname); ?></h4>
                        <p><small>ID: <?php echo $worker_id; ?></small></p>
                        <?php if ($qr_exists) { ?>
                            <img src="<?php echo $qr_file; ?>?t=<?php echo time(); ?>" alt="QR de <?php echo htmlspecialchars($firstname); ?>">
                            <div class="no-print">
                                <a href="<?php echo $qr_file; ?>" download="QR_<?php echo $worker_id; ?>_<?php echo $firstname; ?>.png" class="btn btn-sm btn-info download-btn">
                                    <i class="bi bi-download"></i> Descargar
                                </a>
                            </div>
                        <?php } else { ?>
                            <p class="text-danger"><em>QR no disponible</em></p>
                        <?php } ?>
                    </div>
            <?php
                }
            } else {
                echo '<div class="alert alert-info">No hay empleados registrados</div>';
            }
            ?>
        </div>
    </div>

    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>
