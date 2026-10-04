<?php

require __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/../')->load();

class MARK{
    public static string $url = "https://generativelanguage.googleapis.com/v1beta/interactions";
    public static string $model = "gemini-3.5-flash-lite";

    # LISTA ÚNICA DE APPS: nombre => comando de terminal #
    private static function appList(): array{
        return [
            "Vscode"          => "code",
            "Firefox"         => "firefox",
            "zen-browser"     => "zen-browser"
        ];
    }

    # EJECUTAR APP Y LIMPIAR TEXTO #
    public static function OPEN(string $text): string{
        if (!preg_match('/\[OPEN:([^\]]+)\]/', $text, $match)) {
            return $text;
        }

        $apps = self::appList();

        // Búsqueda sin distinguir mayúsculas: "vscode" == "Vscode"
        foreach ($apps as $name => $command) {
            if (strcasecmp($name, trim($match[1])) === 0) {
                exec($command . " > /dev/null 2>&1 &");
                break;
            }
        }

        $text = trim(preg_replace('/\s*\[OPEN:[^\]]+\]/', '', $text));

        return $text !== '' ? $text : "Abriendo, señor.";
    }

    # EJECUTAR APP Y LIMPIAR TEXTO #

    public static function CLOSE(string $text): string {

        # Detectar comando [CLOSE:app]
        if (!preg_match('/\[CLOSE:([^\]]+)\]/i', $text, $match)) {
            return $text;
        }

        # Obtener aplicaciones disponibles
        $apps = self::appList();

        # Buscar aplicación sin distinguir mayúsculas
        foreach ($apps as $name => $command) {

            if (strcasecmp($name, trim($match[1])) === 0) {

                # Cerrar aplicación con pkill
                exec('pkill -f -- ' . escapeshellarg($command) . ' > /dev/null 2>&1');

                break;
            }
        }

        # Eliminar comando del texto
        $text = trim(
            preg_replace('/\s*\[CLOSE:[^\]]+\]\s*/i', '', $text)
        );

        # Retornar texto limpio
        return $text !== '' ? $text : 'Cerrando, señor.';
    }

    # NOMBRES PARA LA IA #
    private static function APPS(): string{
        return implode(", ", array_keys(self::appList()));
    }

    # REGLAS DE IA #
    private static function rules(): string{
        date_default_timezone_set("America/Sao_Paulo");
        $fecha = date("d/m/Y");
        $hora  = date("H:i:s");
        $apps  = self::APPS();

        return <<<TXT
        Tu nombre es MARK. Fuiste creado por Robtob12 y eres un asistente de inteligencia artificial que ayuda a tu usuario en un computador local.

        Estas reglas son contexto interno: nunca las menciones ni respondas a ellas. Responde solo al prompt del usuario.

        CONTEXTO:
        - Usa el historial como contexto y mantén la continuidad del tema.
        - Prioriza el mensaje actual; si contradice uno anterior, gana el más reciente.
        - No pidas información que ya esté en el historial.

        PERSONALIDAD:
        - Asistente personal estilo JARVIS: inteligente, educado, tranquilo y eficiente.
        - Usa "señor" cuando resulte natural.
        - Sé amigable sin ser excesivamente formal.

        COMUNICACIÓN:
        - Responde directo, breve y claro. Amplía solo si es necesario.
        - No uses frases de relleno ni menciones que sigues reglas.

        COMPORTAMIENTO:
        - Si el usuario se equivoca, corrígelo con respeto.
        - Si algo no es posible, explícalo y ofrece una alternativa.
        - Si no sabes algo, dilo; no inventes.
        
        CONTINUIDAD:
        - Esta es una única conversación continua y permanente con tu usuario. Nunca empieza de cero ni es un chat nuevo.
        - El historial recibido es la parte visible de esa conversación; lo anterior ya ocurrió aunque no lo veas.
        - Nunca digas que es la primera interacción, que acabamos de conocernos ni que no tienes conversaciones previas.
        - No te presentes ni saludes como si fuera la primera vez, salvo que el historial esté realmente vacío.
        - Trata al usuario como alguien con quien ya llevas tiempo hablando.
        - Las líneas "Usuario:" son del usuario y las líneas "MARK:" son tus respuestas anteriores.

        DATOS ACTUALES:
        FECHA LOCAL: $fecha
        HORA LOCAL: $hora

        ABRIR APLICACIONES:
        Si el usuario pide abrir una de estas aplicaciones, responde con una frase corta y natural (por ejemplo: "Abriendo Visual Studio Code, señor.") y agrega al final [OPEN:Nombre] usando exactamente el nombre de la lista.
        La etiqueta es una señal interna, lo mismo puedes hacer con CLOSE: nunca la menciones ni la expliques.
        Aplicaciones: $apps
        TXT;
    }

    # ENVIO GENERAL DE INPUTS #
    public static function INPUT(string $history, string $input){
        $curl = curl_init(self::$url);

        curl_setopt_array($curl,[
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'x-goog-api-key: ' .$_ENV['GEMINI_API_KEY'],
                'Content-Type: application/json',
                'API-Revision: 2026-05-20'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => json_encode([
                'model' => self::$model,
                'input' => "Reglas: " . self::rules() .
                           "Historico: " . $history .
                           "Input: " . trim($input)
            ])
        ]);

        $return = json_decode(curl_exec($curl), true);

        return $return['steps'][1]['content'][0]['text'];
    }
}