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

$business_name = $env_data['BUSINESS_NAME'] ?? '';
$lang_code = $env_data['LANG_CODE'] ?? '';

$lang = $lang_code;

$langDir = __DIR__ . "/../../../assets/lang/";
$langFile = $langDir . "$lang.json";

$translations = json_decode(file_get_contents($langFile), true);

$conn = new mysqli($db_host, $db_username, $db_password, $db_name);

if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}

// Crear tabla si no existe
$create_table_sql = "CREATE TABLE IF NOT EXISTS worker_timeclock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    worker_id BIGINT NOT NULL,
    checkin_time DATETIME NOT NULL,
    checkout_time DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_worker_date (worker_id, checkin_time)
)";

if (!$conn->query($create_table_sql)) {
    die("Error al crear la tabla: " . $conn->error);
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

// Obtener registros de asistencia de hoy
$today = date('Y-m-d');
$sql = "SELECT wt.*, w.Firstname, w.Lastname 
        FROM worker_timeclock wt 
        JOIN workers w ON wt.worker_id = w.userid 
        WHERE DATE(wt.checkin_time) = ? 
        ORDER BY wt.checkin_time DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$conn->close();
?>

<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <title>Check-in/Check-out</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="../../../assets/css/dashboard.css">
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <link rel="shortcut icon" href="https://gymoneglobal.com/assets/img/logo.png" type="image/x-icon">
</head>

<body>
    <div class="container-fluid mt-5">
        <div class="row mb-3">
            <div class="col-md-12">
                <a href="../../dashboard/" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Volver al Dashboard
                </a>
            </div>
        </div>
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <h3><i class="bi bi-clock"></i> Control de Asistencia - Check-in/Check-out</h3>
                    </div>
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="input-group">
                                    <span class="input-group-addon"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control" id="searchEmployee" placeholder="Buscar empleado por nombre...">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <h4>Registrar Entrada/Salida</h4>
                                <button class="btn btn-primary btn-lg btn-block" data-toggle="modal" data-target="#timeclockModal">
                                    <i class="bi bi-qr-code"></i> Escanear QR
                                </button>
                            </div>
                            <div class="col-md-6">
                                <h4>Estado del Empleado</h4>
                                <div id="employeeStatus" class="alert alert-info">
                                    Ningún empleado seleccionado
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row mb-4">
                            <div class="col-md-12">
                                <h4>Descargar Reporte</h4>
                                <form action="export_timeclock.php" method="GET" class="form-inline">
                                    <div class="form-group">
                                        <label for="start_date">Desde:</label>
                                        <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="end_date">Hasta:</label>
                                        <input type="date" class="form-control" name="end_date" value="<?php echo date('Y-m-d'); ?>" required>
                                    </div>
                                    <button type="submit" class="btn btn-success"><i class="bi bi-download"></i> Descargar CSV</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header">
                        <h4><i class="bi bi-calendar-check"></i> Registros de Hoy (<?php echo date('d/m/Y'); ?>)</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Empleado</th>
                                        <th>Entrada</th>
                                        <th>Salida</th>
                                        <th>Horas Trabajadas</th>
                                    </tr>
                                </thead>
                                <tbody id="timeclockTableBody">
                                    <?php
                                    if ($result && $result->num_rows > 0) {
                                        while ($row = $result->fetch_assoc()) {
                                            $checkin = new DateTime($row['checkin_time']);
                                            $checkout = $row['checkout_time'] ? new DateTime($row['checkout_time']) : null;
                                            $hours_worked = $checkout ? $checkin->diff($checkout)->format('%h:%i') : 'En turno';
                                            $fullname = $row['Firstname'] . ' ' . $row['Lastname'];
                                    ?>
                                        <tr class="employee-row" data-employee="<?php echo strtolower($fullname); ?>">
                                            <td><?php echo $fullname; ?></td>
                                            <td><?php echo $checkin->format('H:i:s'); ?></td>
                                            <td><?php echo $checkout ? $checkout->format('H:i:s') : '-'; ?></td>
                                            <td><?php echo $hours_worked; ?></td>
                                        </tr>
                                    <?php
                                        }
                                    } else {
                                        echo "<tr><td colspan='4' class='text-center text-muted'><em>No hay registros de entrada/salida para hoy. Escanea un QR para comenzar.</em></td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL PARA LEER QR -->
    <div class="modal fade" id="timeclockModal" tabindex="-1" role="dialog" aria-labelledby="timeclockModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="timeclockModalLabel">Leer QR del Empleado</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <div id="video-container" style="position: relative; width: 100%; height: 400px;">
                        <video id="timeclockVideo" style="width: 100%; height: 100%; object-fit: cover;"></video>
                    </div>
                    <div id="timeclockSuccess" style="display: none; color: green; text-align: center; margin-top: 20px;">
                        <h4>✔ ¡QR Detectado!</h4>
                    </div>
                    <div id="timeclockError" style="display: none; color: red; text-align: center; margin-top: 20px;">
                        <h4>✘ Error al detectar QR</h4>
                    </div>
                    <input type="hidden" id="scannedWorkerIdTimeclock">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var timeclockCodeReader = null;

        $('#timeclockModal').on('shown.bs.modal', function() {
            startTimeclockScanning();
        });

        $('#timeclockModal').on('hidden.bs.modal', function() {
            stopTimeclockScanning();
        });

        function startTimeclockScanning() {
            const video = document.getElementById('timeclockVideo');
            if (!video) return;

            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(stream => {
                    video.srcObject = stream;
                    video.play();
                    timeclockCodeReader = new ZXing.BrowserMultiFormatReader();
                    scanTimeclockQR();
                })
                .catch(err => console.error('Error accessing camera:', err));
        }

        function scanTimeclockQR() {
            const video = document.getElementById('timeclockVideo');
            if (!video || !timeclockCodeReader) return;

            const decodePromise = timeclockCodeReader.decodeFromVideoElement(video);
            if (decodePromise) {
                decodePromise
                    .then(result => {
                        const scannedCode = result.text;
                        document.getElementById('scannedWorkerIdTimeclock').value = scannedCode;
                        document.getElementById('timeclockSuccess').style.display = 'block';
                        document.getElementById('timeclockError').style.display = 'none';
                        setTimeout(() => {
                            $('#timeclockModal').modal('hide');
                            processTimeclock(scannedCode);
                        }, 1500);
                    })
                    .catch(() => {
                        setTimeout(scanTimeclockQR, 300);
                    });
            } else {
                setTimeout(scanTimeclockQR, 300);
            }
        }

        function stopTimeclockScanning() {
            const video = document.getElementById('timeclockVideo');
            if (video && video.srcObject) {
                video.srcObject.getTracks().forEach(track => track.stop());
            }
            if (timeclockCodeReader) {
                timeclockCodeReader.reset();
            }
        }

        function processTimeclock(workerId) {
            $.ajax({
                url: 'process_timeclock.php?action=toggle_timeclock',
                type: 'POST',
                data: { worker_id: workerId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        alert('✔ ' + response.message);
                        location.reload();
                    } else {
                        alert('Error: ' + response.message);
                    }
                },
                error: function() {
                    alert('Error al procesar el registro');
                }
            });
        }

        // Búsqueda de empleados
        document.getElementById('searchEmployee').addEventListener('keyup', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.employee-row');

            rows.forEach(row => {
                const employeeName = row.getAttribute('data-employee');
                if (employeeName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Listener para lector de código de barras (Teclado USB)
        let scannerBuffer = '';
        let scannerTimer;
        document.addEventListener('keydown', (e) => {
            // Ignorar inputs normales (como el buscador)
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;

            if (e.key === 'Enter') {
                if (scannerBuffer.length > 0) {
                    processTimeclock(scannerBuffer);
                    scannerBuffer = '';
                }
            } else {
                if (e.key.length === 1) {
                    scannerBuffer += e.key;
                }
                clearTimeout(scannerTimer);
                scannerTimer = setTimeout(() => { scannerBuffer = ''; }, 100);
            }
        });
    </script>

    <script src="../../../assets/js/date-time.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/js/bootstrap.min.js"></script>
</body>

</html>
