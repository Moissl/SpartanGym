<?php
session_start();
if (!isset($_SESSION['adminuser'])) {
    header("Location: ../");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Acceso de Miembros</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.4.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://unpkg.com/@zxing/library@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0950dc;
            --bg-gradient-start: #1a1a1a;
            --bg-gradient-end: #000000;
            --text-main: #ffffff;
            --text-muted: #aaaaaa;
        }
        html, body { height: 100%; margin: 0; overflow: hidden; }
        body { 
            background: radial-gradient(circle at center, var(--bg-gradient-start), var(--bg-gradient-end)); 
            color: var(--text-main); 
            font-family: 'Montserrat', sans-serif; 
            display: flex; 
            flex-direction: column;
        }
        
        /* Top Bar with Clock */
        .top-bar {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            padding: 30px 50px;
            display: flex;
            justify-content: flex-end;
            z-index: 10;
        }
        .clock-widget {
            text-align: right;
        }
        .clock-time {
            font-size: 5rem;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -1px;
        }
        .clock-date {
            font-size: 1.5rem;
            font-weight: 300;
            color: var(--text-muted);
            margin-top: 5px;
            text-transform: capitalize;
        }

        /* Main Layout */
        .main-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            padding: 0 50px;
        }
        .content-left {
            flex: 1;
            padding-right: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
        }
        .content-right {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        /* Typography & Logo */
        .logo-img {
            max-width: 250px;
            margin-bottom: 40px;
            filter: drop-shadow(0 0 15px rgba(255,255,255,0.1));
        }
        h1 {
            font-size: 5.5rem;
            font-weight: 700;
            margin: 0 0 20px 0;
            background: linear-gradient(90deg, #fff, #ccc);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .lead-text {
            font-size: 2.4rem;
            font-weight: 300;
            color: var(--text-muted);
            line-height: 1.4;
        }

        /* Video Container */
        #video-container {
            position: relative;
            width: 100%;
            max-width: 980px;
            aspect-ratio: 16/9;
            height: auto;
            background: #000;
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            border: 4px solid #333;
        }
        video { width: 100%; height: 100%; object-fit: cover; }
        
        #overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            display: none; align-items: center; justify-content: center;
            background: rgba(0,0,0,0.9); z-index: 9999; text-align: center;
            flex-direction: column;
            cursor: pointer;
            backdrop-filter: blur(8px);
        }
        .status-icon { font-size: 6em; margin-bottom: 20px; filter: drop-shadow(0 5px 15px rgba(0,0,0,0.3)); }
        .status-title { font-size: 3em; font-weight: 700; margin-bottom: 15px; }
        .status-desc { font-size: 1.6em; color: #eee; font-weight: 300; }
        
        /* Responsive adjustments */
        @media (max-width: 1680px) and (max-height: 1050px) {
            #video-container { max-width: 800px; }
            h1 { font-size: 4.5rem; margin-bottom: 15px; }
            .lead-text { font-size: 2rem; }
            .logo-img { max-width: 220px; margin-bottom: 30px; }
        }

        @media (max-width: 992px) {
            .main-container { flex-direction: column; padding: 20px; }
            .content-left { padding-right: 0; text-align: center; margin-bottom: 30px; }
            .top-bar { position: relative; padding: 20px; justify-content: center; }
            .clock-widget { text-align: center; }
            h1 { font-size: 3rem; }
            #video-container { max-width: 400px; }
        }
    </style>
</head>
<body>
    <!-- Clock Header -->
    <div class="top-bar">
        <div class="clock-widget">
            <div class="clock-time" id="clock-time">--:--</div>
            <div class="clock-date" id="clock-date">--</div>
        </div>
    </div>

    <div class="main-container">
        <!-- Left Side: Info -->
        <div class="content-left">
            <img src="../../assets/img/logo.png" class="logo-img" alt="Logo">
            <h1>Bienvenido/a</h1>
            <p class="lead-text">Acerca tu código QR al escáner para registrar tu acceso.</p>
        </div>

        <!-- Right Side: Scanner -->
        <div class="content-right">
                <div id="video-container">
                    <video id="video" autoplay muted playsinline></video>
                    <div id="overlay">
                        <div id="overlay-content"></div>
                    </div>
                </div>
                <div class="text-center" style="margin-top: 20px; color: #555;">
                    <small><i class="bi bi-shield-lock"></i> Esta cámara no graba ni almacena imágenes. Es exclusiva para escaneo de códigos QR.</small>
                </div>
                <!-- Input oculto para capturar lecturas de escáneres USB 2D -->
                <input type="text" id="scanner-input" style="position: absolute; left: -9999px; width: 1px; height: 1px;" />
        </div>
    </div>

    <script>
        // Clock Logic
        function updateClock() {
            const now = new Date();
            const timeOptions = { timeZone: 'America/Mexico_City', hour: '2-digit', minute: '2-digit', hour12: false };
            const dateOptions = { timeZone: 'America/Mexico_City', weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            
            const timeString = now.toLocaleTimeString('es-MX', timeOptions);
            const dateString = now.toLocaleDateString('es-MX', dateOptions);
            
            document.getElementById('clock-time').textContent = timeString;
            document.getElementById('clock-date').textContent = dateString.charAt(0).toUpperCase() + dateString.slice(1);
        }
        setInterval(updateClock, 1000);
        updateClock();

        const video = document.getElementById('video');
        const overlay = document.getElementById('overlay');
        const content = document.getElementById('overlay-content');
        let isProcessing = false;
        let codeReader = null;
        let hideTimeout = null;
        const lastScannedCodes = {};
        const SCAN_COOLDOWN = 3000; // Reducido a 3s para permitir re-escaneo (el servidor maneja la lógica de 15s)

        // Permitir cerrar el mensaje al hacer clic para agilizar el flujo
        overlay.addEventListener('click', () => {
            if (hideTimeout) clearTimeout(hideTimeout);
            overlay.style.display = 'none';
            content.style.color = '#fff';
            isProcessing = false;
        });
        
        // Sonido de confirmación de lectura
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        function playBeep() {
            if (audioCtx.state === 'suspended') audioCtx.resume();
            const oscillator = audioCtx.createOscillator();
            const gainNode = audioCtx.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioCtx.destination);
            oscillator.frequency.value = 800;
            gainNode.gain.value = 0.1;
            oscillator.start();
            oscillator.stop(audioCtx.currentTime + 0.1);
        }

        function initializeScanning() {
            startScanning();
        }

        function startScanning() {
            if (codeReader) {
                codeReader.reset();
            } else {
                codeReader = new ZXing.BrowserMultiFormatReader();
            }

            const constraints = {
                video: { facingMode: "environment" } 
            };
            
            // Usamos decodeFromConstraints para escaneo continuo y estable
            codeReader.decodeFromConstraints(constraints, 'video', (result, err) => {
                if (result && !isProcessing) {
                    handleScan(result.text);
                }
            }).catch(err => {
                console.error('Error starting scanner:', err);
                // Reintentar automáticamente si falla
                setTimeout(startScanning, 2000);
            });
        }

        function handleScan(code) {
            if (!code) return;
            code = code.trim(); // Eliminar espacios que agregan algunos lectores

            // Filtrar lecturas erróneas, ruido de cámara o códigos parciales
            if (!/^\d{10}$/.test(code)) {
               return;
            }

            // Evitar doble lectura inmediata (Entrada -> Salida) para el mismo usuario
            const now = Date.now();
            if (lastScannedCodes[code] && (now - lastScannedCodes[code] < SCAN_COOLDOWN)) {
                return;
            }

            if (isProcessing) return;
            isProcessing = true;
            
            playBeep();
            
            // Mostrar "Procesando..."
            overlay.style.display = 'flex';
            overlay.style.background = 'rgba(0,0,0,0.7)';
            overlay.style.zIndex = '9999';
            content.style.color = '#fff';
            content.innerHTML = '<div class="spinner-border" role="status"></div><h3>Procesando...</h3>';

            // Registrar tiempo de escaneo para el cooldown
            lastScannedCodes[code] = now;

            // Safety timeout: Si la respuesta tarda más de 8 segundos, limpiar automáticamente
            if (fetchTimeoutId) clearTimeout(fetchTimeoutId);
            fetchTimeoutId = setTimeout(() => {
                if (isProcessing) {
                    overlay.style.display = 'none';
                    content.style.color = '#fff';
                    isProcessing = false;
                }
            }, 8000);

            fetch('process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `qrcode=${encodeURIComponent(code)}`
            })
            .then(response => response.json())
            .then(data => {
                // Limpiar el safety timeout
                if (fetchTimeoutId) clearTimeout(fetchTimeoutId);

                let color = '';
                let icon = '';
                let title = '';
                let desc = '';
                let textColor = '#fff'; // Color de texto por defecto (blanco)
                let shouldShowMessage = true; // Flag para decidir si mostrar el overlay

                if (data.notif_id) {
                    _shownNotificationIds.add(data.notif_id);
                }

                if (data.success) {
                    if (data.action === 'exit' || data.status_code === 'exit') {
                        color = 'rgba(54, 162, 235, 0.95)'; // Azul Info
                        icon = '<i class="bi bi-box-arrow-right status-icon" style="color:#fff;"></i>';
                        title = `¡Hasta luego ${data.firstname}!`;
                        desc = 'Salida registrada. ¡Vuelve pronto! <br> MUCHAS GRACIAS POR VENIR!';
                    } else if (data.status_code === 'valid' || data.ticket_status === 'Válido' || data.ticket_status === 'Valid') {
                        color = 'rgba(25, 135, 84, 0.95)'; // Verde Success
                        icon = '<i class="bi bi-check-circle-fill status-icon" style="color:#fff;"></i>';
                        title = `¡Bienvenido/a ${data.firstname}!`;
                        desc = `Entrada registrada. Bienvenido/a ${data.firstname},<br> ¡Disfruta entrenamiento!`;
                    } else {
                        color = 'rgba(14, 89, 228, 0.92)'; 
                        textColor = '#ffffff';
                        icon = '<i class="bi bi-info-circle-fill status-icon" style="color:#fff;"></i>';
                        title = 'Un momento';
                        desc = 'Por favor, acércate a recepción para adquirir tu pase. <br> ¡Estamos aquí para ayudarte!';
                    }
                } else {
                    // Error de usuario no encontrado - NO MOSTRAR (falso positivo)
                    shouldShowMessage = false;
                }

                // Solo mostrar el overlay si la respuesta es válida
                if (shouldShowMessage) {
                    overlay.style.background = color;
                    content.style.color = textColor; // Aplicar color de texto dinámico
                    
                    content.innerHTML = `${icon}<div class="status-title">${title}</div><div class="status-desc">${desc}</div>`;

                    // Tiempos reducidos para agilizar el flujo (3s éxito, 4s aviso)
                    let timeoutTime = (data.success && (data.status_code === 'valid' || data.action === 'exit')) ? 3000 : 4000;

                    hideTimeout = setTimeout(() => {
                        overlay.style.display = 'none';
                        content.style.color = '#fff'; // Reset color
                        isProcessing = false;
                    }, timeoutTime);
                } else {
                    // Si hay error, simplemente cerrar silenciosamente
                    overlay.style.display = 'none';
                    content.style.color = '#fff';
                    isProcessing = false;
                }
            })
            .catch(err => {
                console.error(err);
                // Limpiar el safety timeout
                if (fetchTimeoutId) clearTimeout(fetchTimeoutId);
                
                // En caso de error de conexión, simplemente cerrar silenciosamente
                overlay.style.display = 'none';
                content.style.color = '#fff';
                isProcessing = false;
            });
        }

        // Recargar la página cada minuto automáticamente
        setInterval(() => {
            location.reload();
        }, 60000);

        // Iniciar al cargar
        document.addEventListener('DOMContentLoaded', initializeScanning);

        // ========================================
        // CAPTURA DE INPUT DEL LECTOR USB 2D - DIRECTAMENTE EN DOCUMENTO
        // ========================================
        let scannerBuffer = '';
        let lastScanTime = 0;
        const SCANNER_TIMEOUT = 500; // 500ms para acumular caracteres
        let scannerTimeoutId = null;
        let fetchTimeoutId = null;

        // Capturar directamente en el documento - FUNCIONA INCLUSO SIN FOCUS
        document.addEventListener('keydown', (e) => {
            // Ignorar si estamos procesando
            if (isProcessing) return;
            
            // Ignorar si estamos escribiendo en un input real (que no sea de escáner)
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }
            
            // Si es Enter, procesar el buffer
            if (e.key === 'Enter') {
                e.preventDefault();
                
                if (scannerTimeoutId) {
                    clearTimeout(scannerTimeoutId);
                    scannerTimeoutId = null;
                }
                
                const code = scannerBuffer.trim();
                // Validación: Solo procesar si cumple con los requisitos
                if (/^\d{10}$/.test(code)) {
                    scannerBuffer = '';
                    lastScanTime = 0;
                    handleScan(code);
                } else {
                    // Limpiar buffer si no cumple requisitos
                    scannerBuffer = '';
                    lastScanTime = 0;
                }
                return;
            }
            
            // Acumular caracteres normales (solo caracteres imprimibles)
            if (e.key.length === 1) {
                scannerBuffer += e.key;
                lastScanTime = Date.now();
                
                // Reset del timeout si hay nueva entrada
                if (scannerTimeoutId) {
                    clearTimeout(scannerTimeoutId);
                    scannerTimeoutId = null;
                }
                
                // Si pasa SCANNER_TIMEOUT sin nueva entrada, procesar el buffer
                scannerTimeoutId = setTimeout(() => {
                    const code = scannerBuffer.trim();
                    
                    // Validación CRÍTICA: Solo procesar si tiene longitud válida
                    if (/^\d{10}$/.test(code)) {
                        scannerBuffer = '';
                        lastScanTime = 0;
                        handleScan(code);
                    } else {
                        // Limpiar buffer si es inválido
                        scannerBuffer = '';
                        lastScanTime = 0;
                    }
                    scannerTimeoutId = null;
                }, SCANNER_TIMEOUT);
                
                e.preventDefault();
            } else if (e.key === 'Backspace') {
                // Permitir borrar caracteres
                scannerBuffer = scannerBuffer.slice(0, -1);
                e.preventDefault();
            }
        }, true); // Usar capturing phase para capturar antes que otros

        // ========================================
        // POLLING: Mostrar notificaciones creadas por process.php
        // Esto permite que la pantalla de usuario muestre mensajes aun cuando
        // la ventana no tenga foco y el lector envíe teclas a otra ventana.
        // ========================================
        // Long-poll loop para notificaciones: mejora fiabilidad cuando la página
        // está en otra pantalla o pierde foco. Usa `wait=1` en el endpoint.
        let _notifPollRunning = false;
        const _shownNotificationIds = new Set(); // Rastrear IDs ya mostrados
        async function notificationLongPoll() {
            if (_notifPollRunning) return;
            _notifPollRunning = true;

            while (true) {
                try {
                    const res = await fetch('check_notifications.php?wait=1', { cache: 'no-store', credentials: 'same-origin' });
                    if (!res.ok) {
                        await new Promise(r => setTimeout(r, 1000));
                        continue;
                    }

                    const data = await res.json();
                    if (!data || !data.notifications || data.notifications.length === 0) {
                        // nada nuevo, reiniciar el ciclo
                        continue;
                    }

                    for (const notif of data.notifications) {
                        // Saltar si ya fue mostrada
                        if (_shownNotificationIds.has(notif.id)) {
                            continue;
                        }

                        // Esperar si hay otro mensaje en pantalla
                        while (isProcessing) {
                            await new Promise(r => setTimeout(r, 200));
                        }

                        // Reevaluar por si la petición principal registró el ID mientras esperábamos
                        if (_shownNotificationIds.has(notif.id)) {
                            continue;
                        }

                        let color = '';
                        let icon = '';
                        let title = '';
                        let desc = '';
                        let textColor = '#fff';

                        if (notif.type === 'exit') {
                            color = 'rgba(54, 162, 235, 0.95)';
                            icon = '<i class="bi bi-box-arrow-right status-icon" style="color:#fff;"></i>';
                            title = `¡Hasta luego ${notif.user_name || ''}!`;
                            desc = notif.message || '';
                        } else if (notif.type === 'success') {
                            color = 'rgba(25, 135, 84, 0.95)';
                            icon = '<i class="bi bi-check-circle-fill status-icon" style="color:#fff;"></i>';
                            title = `¡Bienvenido/a ${notif.user_name || ''}!`;
                            desc = notif.message || '';
                        } else {
                            color = 'rgba(14, 89, 228, 0.92)';
                            icon = '<i class="bi bi-info-circle-fill status-icon" style="color:#fff;"></i>';
                            title = notif.user_name ? `Un momento, ${notif.user_name}` : 'Un momento';
                            desc = 'Por favor, acércate a recepción para adquirir tu pase. <br> ¡Estamos aquí para ayudarte!'; // No mostrar el mensaje "Vencido!" del servidor
                        }

                        isProcessing = true;
                        playBeep();
                        overlay.style.display = 'flex';
                        overlay.style.background = color;
                        content.style.color = textColor;
                        content.innerHTML = `${icon}<div class="status-title">${title}</div>${desc ? `<div class="status-desc">${desc}</div>` : ''}`;

                        const displayTime = (notif.type === 'success' || notif.type === 'exit') ? 3000 : 4000;
                        await new Promise(r => setTimeout(r, displayTime));

                        overlay.style.display = 'none';
                        content.style.color = '#fff';
                        isProcessing = false;

                        // Marcar como mostrada después de procesarla
                        _shownNotificationIds.add(notif.id);
                    }
                } catch (e) {
                    console.log('notificationLongPoll error', e);
                    await new Promise(r => setTimeout(r, 1000));
                }
            }
        }

        // Iniciar long-poll al cargar
        document.addEventListener('DOMContentLoaded', () => {
            // start camera scanner
            initializeScanning();
            // start notification long-poll
            notificationLongPoll();
        });
    </script>
</body>
</html>