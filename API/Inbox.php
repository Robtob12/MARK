<?php

class Inbox
{
    # Puerto donde Evolution enviará los webhooks #
    # Si Evolution corre en Docker, cambia 127.0.0.1 por 0.0.0.0 #
    private const ADDRESS = 'tcp://0.0.0.0:8001';

    # Tamaño de la imagen en el terminal (columnas x filas) #
    private const IMAGE_SIZE = '60x30';

    private static $port = null;

    # Mensajes recibidos que aún no están en el historial de chat.php #
    private static array $received = [];

    # Abre el puerto una sola vez #
    private static function openPort()
    {
        if (self::$port === null) {
            self::$port = @stream_socket_server(self::ADDRESS, $errno, $errstr);

            if (!self::$port) {
                throw new RuntimeException("No se pudo abrir el puerto 8001: $errstr");
            }
        }

        return self::$port;
    }

    # Lee la petición HTTP completa (las imágenes en base64 son grandes) #
    private static function readRequest($connection): string
    {
        stream_set_timeout($connection, 5);

        $request = '';

        // 1. Leer hasta terminar las cabeceras
        while (!str_contains($request, "\r\n\r\n")) {
            $chunk = fread($connection, 8192);

            if ($chunk === false || $chunk === '') {
                return $request;
            }

            $request .= $chunk;
        }

        // 2. Leer el cuerpo completo según Content-Length
        [$headers, $body] = explode("\r\n\r\n", $request, 2);

        if (preg_match('/Content-Length:\s*(\d+)/i', $headers, $match)) {
            while (strlen($body) < (int) $match[1]) {
                $chunk = fread($connection, 65536);

                if ($chunk === false || $chunk === '') {
                    break;
                }

                $body .= $chunk;
            }
        }

        return $body;
    }

    # Pide la imagen a la API de Evolution (por si el webhook no la trae) #
    private static function fetchBase64(string $instance, string $messageId): ?string
    {
        $url = rtrim($_ENV['EVOLUTION_URL'] ?? 'http://localhost:8080', '/')
            . "/chat/getBase64FromMediaMessage/$instance";

        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'apikey: ' . ($_ENV['EVOLUTION_API_KEY'] ?? ''),
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode([
                'message'      => ['key' => ['id' => $messageId]],
                'convertToMp4' => false,
            ]),
        ]);

        $response = curl_exec($curl);
        curl_close($curl);

        $data = json_decode((string) $response, true);

        return $data['base64'] ?? null;
    }

    # Muestra una imagen (base64) en el terminal con chafa #
    private static function showImage(string $base64): void
    {
        // Quitar el prefijo "data:image/...;base64," si existe
        $base64 = preg_replace('#^data:[^,]+,#', '', $base64);

        $binary = base64_decode($base64, true);

        if ($binary === false || $binary === '') {
            return;
        }

        $file = sys_get_temp_dir() . '/mark_' . uniqid() . '.img';
        file_put_contents($file, $binary);

        passthru('chafa --size=' . self::IMAGE_SIZE . ' ' . escapeshellarg($file) . ' 2>/dev/null');

        unlink($file);
    }

    # Lee un webhook de Evolution y muestra el mensaje #
    private static function notify(string $prompt): void
    {
        $port = self::openPort();

        // 1. Aceptar la conexión de Evolution
        $connection = @stream_socket_accept($port, 1);

        if (!$connection) {
            return;
        }

        // 2. Leer todo lo que envió
        $body = self::readRequest($connection);

        // 3. Responder OK (si no, Evolution reintenta)
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK");
        fclose($connection);

        $data = json_decode($body, true);

        // 4. Ignorar lo que no sea un mensaje nuevo o lo que enviaste tú
        if (($data['event'] ?? '') !== 'messages.upsert') {
            return;
        }

        if ($data['data']['key']['fromMe'] ?? false) {
            return;
        }

        // 5. Sacar quién escribió y qué dijo
        $from    = $data['data']['pushName'] ?? 'Desconocido';
        $message = $data['data']['message'] ?? [];

        $text = $message['conversation']
            ?? $message['extendedTextMessage']['text']
            ?? null;

        // ¿Es una imagen o un sticker?
        $isImage = isset($message['imageMessage']) || isset($message['stickerMessage']);
        $base64  = $message['base64'] ?? $data['data']['base64'] ?? null;

        // Si el webhook no trajo la imagen, pedirla a la API de Evolution
        if ($isImage && $base64 === null) {
            $base64 = self::fetchBase64(
                $data['instance'] ?? $_ENV['EVOLUTION_INSTANCE'] ?? 'mark',
                $data['data']['key']['id'] ?? ''
            );
        }

        if ($isImage) {
            $caption = $message['imageMessage']['caption'] ?? '';
            $text    = $caption !== '' ? "[imagen] $caption" : '[imagen]';
        }

        $text ??= '[mensaje multimedia]';

        // 6. Mostrarlo (texto + imagen) y repintar el prompt
        SAY("\r\033[K" . COLOR("📩 $from: $text", "yellow") . "\n\n");

        if ($isImage && $base64 !== null) {
            self::showImage($base64);
        }

        echo $prompt;

        // 7. Narrarlo y guardarlo para el historial
        Voice::VOICE($isImage
            ? "Recibiste una imagen de $from. " . ($caption ?? '')
            : "Recibiste un mensaje de $from, dice: $text");

        self::$received[] = "WhatsApp ($from): $text";
    }

    # Devuelve los mensajes recibidos y los vacía (para agregarlos al historial) #
    public static function RECEIVED(): array
    {
        $messages = self::$received;
        self::$received = [];

        return $messages;
    }

    # Espera teclado o webhook, lo que llegue primero #
    public static function READ_OR_NOTIFY(string $prompt): string
    {
        $port = self::openPort();

        echo $prompt;

        while (true) {
            $watch = [STDIN, $port];
            $write = $except = null;

            // Duerme hasta que haya actividad en el teclado o en el puerto
            if (@stream_select($watch, $write, $except, null) === false) {
                continue;
            }

            // El usuario escribió algo
            if (in_array(STDIN, $watch)) {
                $line = fgets(STDIN);
                return $line === false ? '' : trim($line);
            }

            // Llegó un webhook
            self::notify($prompt);
        }
    }
}