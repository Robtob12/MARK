<?php

require_once __DIR__ . "/../API/Controler.php";
require_once __DIR__ . "/../API/Voice.php";
require_once __DIR__ . "/../Mini-Tools/dev.php";
require_once __DIR__ . "/../API/Inbox.php";

$history = [];
CLEAR();

SAY("

╔════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
║                                                                                                                                                    ║
║  ███╗   ███╗ █████╗ ██████╗ ██╗  ██╗                                                                                                               ║
║  ████╗ ████║██╔══██╗██╔══██╗██║ ██╔╝                                                                                                               ║
║  ██╔████╔██║███████║██████╔╝█████╔╝                                                                                                                ║
║  ██║╚██╔╝██║██╔══██║██╔══██╗██╔═██╗                                                                                                                ║
║  ██║ ╚═╝ ██║██║  ██║██║  ██║██║  ██╗                                                                                                               ║
║  ╚═╝     ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝                                                                                                               ║
║                                                                                                                                                    ║
║ STATUS[".COLOR("+","red")."] ".COLOR("ACTIVE","yellow")."                                                                                                                                   ║
║ ".COLOR("READ IN START","blue")."                                                                                                                                      ║
║ VERSION 1.2                                                                                                                                        ║
║                                                                                                                                                    ║
╚════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
");

while(true){

    # OBTENER PROMPT (o MOSTRAR NOTIFICACIÓN) #
    $input = Inbox::READ_OR_NOTIFY(COLOR("\n\n\n    : ","red"));

    if(strtolower($input) === 'exit'){
        exit;
        break;
    }

    # GUARDAR HISTORIAL #
    $history[] = "Usuario: ".$input;
    $historia = implode("\n",$history);

    # LIMPIAR TERMINAL #
    CLEAR();

    # ENVIAR PROMPT #
    $return = MARK::INPUT($historia, $input);    

    # ABRIR APLICACIOM
    $return = MARK::OPEN($return);
    $return = MARK::CLOSE($return);
    SAY("
    
    ╔════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╗
    ║                                                                                                                                                    ║
    ║  ███╗   ███╗ █████╗ ██████╗ ██╗  ██╗                                                                                                               ║
    ║  ████╗ ████║██╔══██╗██╔══██╗██║ ██╔╝                                                                                                               ║
    ║  ██╔████╔██║███████║██████╔╝█████╔╝                                                                                                                ║
    ║  ██║╚██╔╝██║██╔══██║██╔══██╗██╔═██╗                                                                                                                ║
    ║  ██║ ╚═╝ ██║██║  ██║██║  ██║██║  ██╗                                                                                                               ║
    ║  ╚═╝     ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝╚═╝  ╚═╝                                                                                                               ║
    ║                                                                                                                                                    ║
    ║ STATUS[".COLOR("+","red")."] ".COLOR("ACTIVE","yellow")."                                                                                                                                   ║
    ║ ".COLOR("READ IN START","blue")."                                                                                                                                      ║
    ║ VERSION 1.2                                                                                                                                        ║
    ║                                                                                                                                                    ║
    ╚════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════════╝
    ");

    # MOSTRAR RESPUESTA #
    SAY(COLOR($return, "green"));

    # GUARDAR HISTORIAL #
    $history[] = "Mark:". $return;
    $historia = implode("\n",$history);

    # NARRAR RESPUESTA #
    Voice::VOICE($return);
}