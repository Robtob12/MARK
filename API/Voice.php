<?php

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();

class Voice
{
    private const SAMPLE_RATE = 44100;

    /** Handle cURL reutilizado (solo aplica cuando no se usa fork). */
    private static ?CurlHandle $curl = null;

    /**
     * Narra el texto sin bloquear (si hay pcntl). Se usa igual que antes.
     */
    public static function VOICE(string $text): bool
    {
        // Sin pcntl: ejecuta de forma normal, bloqueando
        if (!function_exists('pcntl_fork')) {
            return self::speak($text);
        }

        // Evita procesos zombie al terminar los hijos
        pcntl_signal(SIGCHLD, SIG_IGN);

        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('No se pudo crear el proceso de voz.');
        }

        if ($pid > 0) {
            return true; // padre: continúa al instante
        }

        // Hijo: genera y reproduce el audio, luego termina
        try {
            self::speak($text);
        } catch (Throwable) {
            // el hijo no puede propagar errores al padre
        }

        exit(0);
    }

    /**
     * Pide el audio a Cartesia y lo envía en streaming a mpv.
     */
    private static function speak(string $text): bool
    {
        $apiKey = $_ENV['CARTESIA_API_KEY'] ?? '';

        if ($apiKey === '') {
            throw new RuntimeException('CARTESIA_API_KEY no está configurada.');
        }

        $payload = json_encode([
            'model_id'   => 'sonic-3.6',
            'transcript' => $text,
            'voice'      => $_ENV['VOICE'],
            // PCM crudo: sin cabecera WAV, se puede reproducir en streaming
            'output_format' => [
                'container'   => 'raw',
                'encoding'    => 'pcm_s16le',
                'sample_rate' => self::SAMPLE_RATE,
            ],
            'language'          => 'es',
            'generation_config' => ['volume' => 1, 'speed' => 1],
        ], JSON_THROW_ON_ERROR);

        $curl = self::$curl ??= curl_init();
        curl_reset($curl);

        $proc      = null;
        $stdin     = null;
        $ok        = null;   // null = aún no sabemos el código HTTP
        $errorBody = '';

        curl_setopt_array($curl, [
            CURLOPT_URL            => 'https://api.cartesia.ai/tts/bytes',
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_TCP_NODELAY    => true,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_HTTPHEADER     => [
                'X-API-Key: ' . $apiKey,
                'Cartesia-Version: 2026-08-14',
                'Content-Type: application/json',
            ],
            // Cada fragmento recibido va directo al reproductor
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$ok, &$proc, &$stdin, &$errorBody) {
                if ($ok === null) {
                    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $ok   = $code >= 200 && $code < 300;

                    if ($ok) {
                        [$proc, $stdin] = self::startPlayer();
                    }
                }

                if (!$ok) {
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }

                if (@fwrite($stdin, $chunk) === false) {
                    return 0; // aborta la transferencia
                }

                return strlen($chunk);
            },
        ]);

        $result   = curl_exec($curl);
        $error    = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        // Cerrar stdin = EOF para mpv; sigue sonando lo que ya recibió
        if (is_resource($stdin)) {
            fclose($stdin);
        }
        if (is_resource($proc)) {
            proc_close($proc); // vuelve al instante: mpv quedó en segundo plano
        }

        if ($result === false) {
            throw new RuntimeException('Error cURL: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Error de Cartesia HTTP $httpCode: " . $errorBody);
        }

        return true;
    }

    /**
     * Lanza mpv en segundo plano leyendo PCM crudo por stdin.
     *
     * @return array{0: resource, 1: resource}
     */
    private static function startPlayer(): array
    {
        $mpv = 'mpv --no-config --no-terminal --no-video --really-quiet'
            . ' --cache=no --audio-buffer=0 --demuxer-readahead-secs=0'
            . ' --demuxer=rawaudio'
            . ' --demuxer-rawaudio-format=s16le'
            . ' --demuxer-rawaudio-rate=' . self::SAMPLE_RATE
            . ' --demuxer-rawaudio-channels=1'
            . ' -';

        $proc = proc_open(
            ['sh', '-c', $mpv . ' <&0 >/dev/null 2>&1 &'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            throw new RuntimeException('No se pudo iniciar mpv.');
        }

        stream_set_write_buffer($pipes[0], 0);

        return [$proc, $pipes[0]];
    }
}