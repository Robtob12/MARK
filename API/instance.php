<?php

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();

# CONFIGURACIÓN (.env) #
$apiKey   = $_ENV['EVOLUTION_API_KEY'] ?? '';
$baseUrl  = rtrim($_ENV['EVOLUTION_URL'] ?? 'http://localhost:8080', '/');
$instance = $_ENV['EVOLUTION_INSTANCE'] ?? 'mark';

# Webhook hacia tu chat.php (puerto 8001) #
# Si Evolution corre en Docker usa: http://host.docker.internal:8001 #
$webhookUrl = $_ENV['EVOLUTION_WEBHOOK'] ?? 'http://127.0.0.1:8001';

if ($apiKey === '') {
    die('EVOLUTION_API_KEY no está configurada en el .env');
}

# PETICIÓN A EVOLUTION #
function evolution(string $method, string $url, string $apiKey, ?array $body = null): array
{
    $curl = curl_init();

    $options = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $apiKey,
            'Content-Type: application/json',
        ],
    ];

    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $error    = curl_error($curl);
    $code     = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($response === false) {
        die('Error cURL: ' . htmlspecialchars($error));
    }

    return [$code, json_decode($response, true) ?? []];
}

# 1. CREAR INSTANCIA #
[$code, $data] = evolution('POST', "$baseUrl/instance/create", $apiKey, [
    'instanceName' => $instance,
    'qrcode'       => true,
    'integration'  => 'WHATSAPP-BAILEYS',
    'webhook'      => [
        'url'      => $webhookUrl,
        'byEvents' => false,
        'base64'   => false,
        'events'   => ['MESSAGES_UPSERT'],
    ],
]);

$qr = $data['qrcode']['base64'] ?? null;

# 2. SI YA EXISTE, PEDIR UN QR NUEVO #
if ($qr === null && ($code === 403 || $code === 409)) {
    [$code, $data] = evolution('GET', "$baseUrl/instance/connect/$instance", $apiKey);
    $qr = $data['base64'] ?? null;
}

# 3. PREPARAR LA IMAGEN #
if ($qr !== null && !str_starts_with($qr, 'data:image')) {
    $qr = 'data:image/png;base64,' . $qr;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Conectar WhatsApp</title>
    <style>
        body { font-family: sans-serif; text-align: center; margin-top: 40px; }
        img  { width: 300px; height: 300px; }
    </style>
</head>
<body>
    <h2>Instancia: <?= htmlspecialchars($instance) ?></h2>

    <?php if ($qr !== null): ?>
        <p>Abre WhatsApp &rarr; Dispositivos vinculados &rarr; Vincular dispositivo</p>
        <img src="<?= htmlspecialchars($qr) ?>" alt="QR de WhatsApp">
    <?php else: ?>
        <p>No se obtuvo el QR (HTTP <?= (int) $code ?>).</p>
        <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    <?php endif; ?>
</body>
</html>