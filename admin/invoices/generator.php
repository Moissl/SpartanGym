<?php
function generateInvoicePDF($data) {
    // Intentar cargar autoload
    $possible_autoloads = [__DIR__ . '/../../vendor/autoload.php', $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'];
    foreach ($possible_autoloads as $autoload) { if (file_exists($autoload)) { require_once $autoload; break; } }

    if (!class_exists('Mpdf\\Mpdf')) {
        error_log("Error: Mpdf no está disponible. Ejecuta 'composer require mpdf/mpdf'");
        return null;
    }

    try {
        $mpdf = new \Mpdf\Mpdf();
    } catch (Exception $e) {
        error_log('Mpdf init error: ' . $e->getMessage());
        return null;
    }

    $business = htmlspecialchars($data['business_name'] ?? '');
    $client = htmlspecialchars($data['client_name'] ?? '');
    $method = htmlspecialchars($data['payment_method'] ?? '');
    $amount = htmlspecialchars($data['amount'] ?? '0');
    $currency = htmlspecialchars($data['currency'] ?? '');
    $items = $data['items'] ?? [];
    $description_text = htmlspecialchars($data['description_text'] ?? '');

    $invoiceNumber = bin2hex(random_bytes(6));
    $date = date('Y-m-d');

    $logoPath = __DIR__ . '/../../assets/img/logo.png';
    $logoHtml = file_exists($logoPath) ? "<img src='{$logoPath}' width='120' style='margin-bottom: 10px;' /><br>" : "";

    $html = "<!doctype html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; }
            .blue { color: #0950dc; }
            .hr { border-top: 1px solid #ddd; margin: 20px 0; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ddd; padding: 8px; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }
            .total { font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='text-center'>
            {$logoHtml}
            <h1 class='blue'>Ticket de Venta</h1>
        </div>
        <hr class='hr' />
        <table style='margin-bottom:10px;'>
            <tr>
                <td><strong>{$business}</strong><br></td>
                <td class='text-right'><strong>Fecha:</strong> {$date}<br><strong>ID Ticket:</strong> {$invoiceNumber}</td>
            </tr>
        </table>
        <p><strong>Destinatario:</strong><br>{$client}</p>
        <p><strong>Método de Pago:</strong> {$method}</p>
        <p>{$description_text}</p>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Descripción</th>
                    <th class='text-right'>Precio</th>
                </tr>
            </thead>
            <tbody>";

    $id = 1; $total = 0;
    foreach ($items as $item) {
        $iname = htmlspecialchars($item['name']);
        $iprice = htmlspecialchars($item['price']);
        $html .= "<tr><td>{$id}</td><td>{$iname}</td><td class='text-right'>{$iprice} {$currency}</td></tr>";
        $total += floatval(str_replace(',', '.', str_replace(' ', '', $iprice)));
        $id++;
    }

    $html .= "<tr><td colspan='2' class='text-right total'>Total</td><td class='text-right total'>" . number_format($total, 2) . " {$currency}</td></tr>";
    $html .= "</tbody></table>
    </body></html>";

    try {
        $mpdf->WriteHTML($html);
    } catch (Exception $e) {
        error_log('Mpdf write error: ' . $e->getMessage());
        return null;
    }

    $filename = (isset($data['userid']) ? $data['userid'] . '-' : '') . date('Ymd') . '-' . bin2hex(random_bytes(4)) . '.pdf';
    $saveDir = __DIR__ . '/../../assets/docs/invoices';
    if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

    $filePath = $saveDir . '/' . $filename;
    try {
        $mpdf->Output($filePath, \Mpdf\Output\Destination::FILE);
    } catch (Exception $e) {
        error_log('Mpdf save error: ' . $e->getMessage());
        return null;
    }

    return $filename;
}
?>