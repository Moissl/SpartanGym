<?php
session_start();

// Cargar configuración de base de datos
function read_env_file($file_path) {
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

$env_data = read_env_file('../../../../.env');

// Establecer zona horaria desde .env
$timezone = $env_data['TIMEZONE'] ?? 'America/Mexico_City';
if (!in_array($timezone, timezone_identifiers_list())) {
    $timezone = 'America/Mexico_City';
}
date_default_timezone_set($timezone);

$conn = new mysqli($env_data['DB_SERVER'], $env_data['DB_USERNAME'], $env_data['DB_PASSWORD'], $env_data['DB_NAME']);
$business_name = $env_data['BUSINESS_NAME'] ?? 'GYM One';
$currency = $env_data['CURRENCY'] ?? '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $userid = $_POST['userid'];
    $amount = (float) $_POST['amount'];
    $payment_method = $_POST['payment_method'] ?? 'Cash';
    
    // 1. Actualizar saldo del usuario
    $stmt = $conn->prepare("UPDATE users SET profile_balance = profile_balance + ? WHERE userid = ?");
    $stmt->bind_param("ds", $amount, $userid);
    
    if ($stmt->execute()) {
        // 2. Obtener nombre del usuario para la factura
        $userQ = $conn->query("SELECT firstname, lastname, email FROM users WHERE userid = '$userid'");
        $userName = "Cliente";
        $uEmail = "";
        if ($userRow = $userQ->fetch_assoc()) {
            $userName = $userRow['firstname'] . ' ' . $userRow['lastname'];
            $uEmail = $userRow['email'] ?? '';
        }

        // 3. Generar Factura
        $description = 'Recarga de saldo';
        
        // Generar PDF
        $generatorPath = __DIR__ . '/../../../invoices/generator.php';
        $route = 'error.pdf';
        if (file_exists($generatorPath)) {
            require_once $generatorPath;
            $pdfData = [
                'business_name' => $business_name,
                'client_name' => $userName,
                'payment_method' => $payment_method,
                'amount' => number_format($amount, 2),
                'currency' => $currency,
                'items' => [['name' => 'Recarga de Saldo', 'price' => number_format($amount, 2)]],
                'description_text' => 'Abono a cuenta personal'
            ];
            $pdfFile = generateInvoicePDF($pdfData);
            $route = $pdfFile ? $pdfFile : 'error.pdf';
        }

        // Comprobamos si la columna 'description' existe; si no, usamos 'route'
        $colCheck = $conn->query("SHOW COLUMNS FROM invoices LIKE 'description'");
        if ($colCheck && $colCheck->num_rows > 0) {
            $inv_sql = "INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, description, route) VALUES (?, ?, ?, 'Balance', ?, 'paid', NOW(), ?, ?)";
            $inv_stmt = $conn->prepare($inv_sql);
            $inv_stmt->bind_param("ssdsss", $userid, $userName, $amount, $payment_method, $description, $route);
        } else {
            $inv_sql = "INSERT INTO invoices (userid, name, price, type, payment_method, status, created_at, route) VALUES (?, ?, ?, 'Balance', ?, 'paid', NOW(), ?)";
            $inv_stmt = $conn->prepare($inv_sql);
            $inv_stmt->bind_param("ssdss", $userid, $userName, $amount, $payment_method, $route);
        }

        if ($inv_stmt && $inv_stmt->execute()) {
            $inv_stmt->close();

            // Enviar Ticket por correo electrónico
            if (!empty($uEmail) && $route !== 'error.pdf' && file_exists(__DIR__ . '/../../../../assets/docs/invoices/' . $route)) {
                try {
                    require_once __DIR__ . '/../../../../vendor/autoload.php';
                    $transport = (new Swift_SmtpTransport($env_data['MAIL_HOST'], $env_data['MAIL_PORT']))
                        ->setUsername($env_data['MAIL_USERNAME'])
                        ->setPassword($env_data['MAIL_PASSWORD']);
                    if (!empty($env_data['MAIL_ENCRYPTION'])) {
                        $transport->setEncryption($env_data['MAIL_ENCRYPTION']);
                    }
                    $mailer = new Swift_Mailer($transport);

                    $subject = "Ticket de Venta - " . $business_name;
                    $fromEmail = !empty($env_data['MAIL_FROM_ADDRESS']) ? $env_data['MAIL_FROM_ADDRESS'] : $env_data['MAIL_USERNAME'];
                    $fromName = !empty($env_data['MAIL_FROM_NAME']) ? $env_data['MAIL_FROM_NAME'] : $business_name;

                    $mailMsg = (new Swift_Message($subject))
                        ->setFrom([$fromEmail => $fromName])
                        ->setTo([$uEmail]);

                    $logoPath = __DIR__ . '/../../../../assets/img/logo.png';
                    $cid = file_exists($logoPath) ? $mailMsg->embed(Swift_Image::fromPath($logoPath)) : '';
                    $logoHtml = $cid ? '<img src="' . $cid . '" alt="Logo" style="max-width: 80px; height: auto;">' : '<h1 style="color: #ffffff; margin: 0;">' . $business_name . '</h1>';

                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                    $host = $_SERVER['HTTP_HOST'];
                    $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
                    $baseDir = preg_replace('#/admin/boss/sell/ticket$#', '', $scriptDir);
                    $pdfUrl = $protocol . $host . $baseDir . '/assets/docs/invoices/' . $route;

                    $body = '
                    <div style="font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4; padding: 30px 10px;">
                        <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1);">
                            <div style="background-color: #0950dc; text-align: center; padding: 25px;">
                                ' . $logoHtml . '
                            </div>
                            <div style="padding: 30px; color: #333333;">
                                <h2 style="color: #0950dc; margin-top: 0; font-size: 24px;">¡Gracias por tu preferencia, ' . $userName . '!</h2>
                                <p style="font-size: 16px; line-height: 1.6;">Confirmamos que tu transacción se ha realizado con éxito. Hemos adjuntado a este correo tu <strong>Ticket de Venta</strong> con todos los detalles de la operación.</p>
                                
                                <div style="background-color: #f8f9fa; border-left: 4px solid #0950dc; padding: 15px; margin: 25px 0;">
                                    <p style="margin: 0; font-size: 15px;"><strong>Concepto:</strong> Recarga de Saldo</p>
                                    <p style="margin: 5px 0 0 0; font-size: 15px;"><strong>Fecha:</strong> ' . date('d/m/Y') . '</p>
                                </div>

                                <div style="text-align: center; margin: 35px 0;">
                                    <a href="' . $pdfUrl . '" target="_blank" style="background-color: #0950dc; color: #ffffff; text-decoration: none; padding: 14px 30px; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">📄 Ver / Descargar Ticket</a>
                                </div>
                                <hr style="border: 0; border-top: 1px solid #eeeeee; margin: 30px 0;">
                                <p style="font-size: 14px; color: #666666; margin-bottom: 0; line-height: 1.5;">Si tienes alguna duda o aclaración sobre este ticket, por favor acércate a la recepción de nuestro gimnasio. ¡Estaremos felices de ayudarte!</p>
                            </div>
                            <div style="background-color: #f1f1f1; text-align: center; padding: 20px; font-size: 13px; color: #888888;">
                                <p style="margin: 0; font-size: 14px;"><strong>' . $business_name . '</strong></p>
                                <p style="margin: 5px 0 0 0;">Este es un correo generado automáticamente. Por favor, no respondas a este mensaje.</p>
                            </div>
                        </div>
                    </div>';

                    $mailMsg->setBody($body, 'text/html')
                        ->attach(Swift_Attachment::fromPath(__DIR__ . '/../../../../assets/docs/invoices/' . $route));

                    $mailer->send($mailMsg);
                } catch (Exception $e) {
                    error_log("Error enviando email: " . $e->getMessage());
                }
            }

            header("Location: index.php?userid=$userid&success=balance_added");
        } else {
            $inv_stmt && $inv_stmt->close();
            echo "Error al generar factura.";
        }
    } else {
        echo "Error al actualizar saldo.";
    }
    
    $stmt->close();
    $conn->close();
    exit();
}
?>
