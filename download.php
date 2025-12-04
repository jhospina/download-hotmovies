<?php
require 'vendor/autoload.php';

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;


$videoUrl = $argv[1];
$quality = $argv[2] ?? null; // Null -> The best available

$quality = match ($quality) {
    "1080p" => '-f "bv*+ba/b" --exec "cmd /c ffmpeg -y -i {} -vf \"hwupload_cuda,scale_cuda=w=-2:h=1080,hwdownload,format=yuv420p\" -c:v h264_nvenc -preset p1 -rc vbr_hq -c:a aac -b:a 192k {}-1080p.mp4 || exit /b 0"',
    "720p" => '-f "bv*+ba/b" --exec "cmd /c ffmpeg -y -i {} -vf \"hwupload_cuda,scale_cuda=w=-2:h=720,hwdownload,format=yuv420p\" -c:v h264_nvenc -preset p1 -rc vbr_hq -c:a aac -b:a 192k {}-720p.mp4 || exit /b 0"',
    "480p" => '-f "bv*+ba/b" --exec "cmd /c ffmpeg -y -i {} -vf \"hwupload_cuda,scale_cuda=w=-2:h=480,hwdownload,format=yuv420p\" -c:v h264_nvenc -preset p1 -rc vbr_hq -c:a aac -b:a 192k {}-480p.mp4 || exit /b 0"',
    "360p" => '-f "bv*+ba/b" --exec "cmd /c ffmpeg -y -i {} -vf \"hwupload_cuda,scale_cuda=w=-2:h=360,hwdownload,format=yuv420p\" -c:v h264_nvenc -preset p1 -rc vbr_hq -c:a aac -b:a 192k {}-360p.mp4 || exit /b 0"',
    default => null,
};

echo "Iniciando script...\n";

// Archivo donde se guarda la URI del socket del navegador ya iniciado
$socketFile = __DIR__ . DIRECTORY_SEPARATOR . 'browser_socket_uri.txt';

// Intentar conectarse a un navegador ya existente
$browser = null;
$browserFactory = null;
$titleVideo = null;
// Evita ejecutar yt-dlp/ffmpeg más de una vez por ejecución
$downloadStarted = false;

if (is_file($socketFile)) {
    $uri = trim(file_get_contents($socketFile));
    if ($uri !== '') {
        echo "Intentando conectarse al navegador existente: $uri\n";
        try {
            $browser = BrowserFactory::connectToBrowser($uri);
        } catch (\Throwable $e) {
            echo "No se pudo conectar al navegador existente: " . $e->getMessage() . "\n";
            $browser = null; // forzar creación de uno nuevo
        }
    }
}

// Si no hay navegador existente disponible, iniciar uno nuevo
if ($browser === null) {
    echo "No hay navegador existente. Iniciando uno nuevo...\n";

    // Intentar obtener ruta al binario de Chrome/Brave desde la variable de entorno
    $chromePath = getenv('CHROME_PATH') ?: null;

    // rutas comunes en Windows
    if (!$chromePath) {
        $candidates = [
            'C:\\Program Files\\BraveSoftware\\Brave-Browser\\Application\\brave.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files\\Chromium\\Application\\chrome.exe',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                $chromePath = $p;
                break;
            }
        }
    }

    if ($chromePath) {
        echo "Usando navegador en: $chromePath\n";
    } else {
        echo "No se encontró CHROME_PATH; se usará la detección automática de la librería.\n";
    }

    $browserFactory = new BrowserFactory($chromePath);

    try {
        echo "Iniciando navegador (headless=false, keepAlive=true)...\n";
        $browser = $browserFactory->createBrowser([
            'headless' => false,
            'keepAlive' => true,
        ]);

        // Guardar la URI del socket para futuras ejecuciones
        $socketUri = $browser->getSocketUri();
        if ($socketUri) {
            file_put_contents($socketFile, $socketUri);
            echo "Navegador iniciado. Socket URI guardada en: $socketFile\n";
        }
    } catch (\Throwable $e) {
        echo "Error al iniciar el navegador: " . $e->getMessage() . "\n";
        echo "Asegúrate de tener Chrome/Chromium/Brave instalado y/o fija la ruta en la variable de entorno CHROME_PATH.\n";
        exit(1);
    }
} else {
    echo "Conectado al navegador existente.\n";
}

// En este punto, SIEMPRE hay un $browser válido y NO se cerrará al final del script.

// Crear una nueva pestaña/página en cada ejecución
$page = $browser->createPage();

// Obtener sesión DevTools (Session ya es la sesión de protocolo) de la página principal de HotMovies
$session = $page->getSession();

// Variable para guardar la URL del player si aparece
$playerUrl = null;

// Habilitar logs de red en la página actual
try {
    $session->sendMessage(new Message('Network.enable'));
} catch (\Throwable $e) {
    echo "Error enviando Network.enable: " . $e->getMessage() . "\n";
}

// Función auxiliar para enganchar listeners de Network en una sesión dada
$attachNetworkLogging = function ($sessionToUse, string $contextLabel = 'main') use (&$playerUrl, $browser, $page, &$downloadStarted) {
    // Habilitar Network en la sesión objetivo
    try {
        $sessionToUse->sendMessage(new Message('Network.enable'));
    } catch (\Throwable $e) {
        echo "[$contextLabel] Error enviando Network.enable: " . $e->getMessage() . "\n";
    }

    // Escuchar requests
    $sessionToUse->on('method:Network.requestWillBeSent', function ($params) use (&$playerUrl, $browser, $contextLabel, $page, &$downloadStarted) {

        global $titleVideo;
        global $quality;

        $url = $params['request']['url'] ?? '';
        $method = $params['request']['method'] ?? '';
        $type = $params['type'] ?? '';
        $frameId = $params['frameId'] ?? '';
        $initiator = $params['initiator']['type'] ?? '';

        echo "[$contextLabel] ➡ [$type] frame=$frameId initiator=$initiator $method $url\n";

        // Detectar IFRAME del player sólo en el contexto principal (no re-disparar dentro del propio player)
        if (
            $contextLabel === 'main'
            && $playerUrl === null
            && str_starts_with($url, 'https://www.adultempire.com/gw/player/')
        ) {
            $titleVideo = substr(str_replace([" - HotMovies", "'"], "", $page->evaluate('document.title')->getReturnValue()), 0, 60);
            // Sanear título para ser nombre de archivo válido en Windows
            $titleVideo = preg_replace('/[<>:"\/\\\\|?*]+/', '_', $titleVideo);
            $titleVideo = trim(preg_replace('/\s+/', ' ', $titleVideo));

            echo "Título del video: $titleVideo\n";

            $playerUrl = $url;
            echo "********** IFRAME DEL PLAYER DETECTADO, ABRIENDO NUEVA PÁGINA **********\n";
            echo "Player URL: $playerUrl\n";

            if ($playerUrl == "https://www.adultempire.com/gw/player/aeplayer.js" || $url == "https://www.adultempire.com/gw/player/aeplayer.js") {
                return;
            }

            try {
                // Creamos una nueva pestaña para el player y enganchamos logging ANTES de navegar
                $playerPage = $browser->createPage();
                $playerSession = $playerPage->getSession();

                echo "[player] Adjuntando logging de red (Network.enable + listeners)...\n";
                // Adjuntamos listeners de red en la pestaña del player
                // Importante: esto se hace ANTES de la navegación para no perdernos las primeras peticiones
                ($GLOBALS['attachNetworkLoggingForPlayer'])($playerSession, 'player');

                echo "[player] Navegando a player URL...\n";
                $playerPage->navigate($playerUrl)->waitForNavigation();

                echo "[player] Intentando iniciar reproducción (click/play)...\n";
                try {
                    // Esperar un poco a que cargue el DOM del player
                    sleep(3);

                    // Click en el centro por si acaso es un overlay
                    $playerPage->mouse()->move(400, 300);
                    $playerPage->mouse()->click();
                    usleep(500);
                    $playerPage->mouse()->move(400, 300);
                    $playerPage->mouse()->click();
                    usleep(500);
                    $playerPage->close();

                } catch (\Throwable $e) {
                    echo "Error en interacción con player: " . $e->getMessage() . "\n";
                }
            } catch (\Throwable $e) {
                echo "Error creando/navegando página del player: " . $e->getMessage() . "\n";
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $isHls = str_ends_with($path, '.m3u8');

        if ($isHls) {
            // Evitar múltiples ejecuciones si ya iniciamos una descarga en esta ejecución
            if ($downloadStarted) {
                echo "[$contextLabel] Descarga ya iniciada previamente. Ignorando URL HLS adicional.\n";
                return;
            }

            echo "[$contextLabel] ********** REQUEST ESPECIAL DETECTADA **********\n";
            echo "URL: $url\n";
            echo "TYPE: $type | FRAME: $frameId | INITIATOR: $initiator | METHOD: $method\n";
            echo "[$contextLabel] ***********************************************\n";

            echo "[$contextLabel] Iniciando descarga con yt-dlp...\n";
            $downloadStarted = true;

            $command = "cd /d F:\\yt && yt-dlp.exe";
            $command .= " \"$url\" --output=\"$titleVideo.mp4\"";

            if (!is_null($quality)) {
                $command .= " $quality";
            }

            echo "Comando: $command\n";
            system($command);
        }
    });
};

// Guardamos una referencia global específica para el player para poder usarla dentro del closure principal sin recursión
$GLOBALS['attachNetworkLoggingForPlayer'] = $attachNetworkLogging;

// Enganchar logging en la página principal
$attachNetworkLogging($session, 'main');

try {
    // Navegar a la escena principal de HotMovies
    echo "Navegando a " . $videoUrl . "...\n";
    $page->navigate($videoUrl)->waitForNavigation();

    // Mostrar URL final
    $currentUrl = $page->getCurrentUrl();
    echo "URL final tras navegación: $currentUrl\n";

    sleep(5);

} catch (\Throwable $e) {
    echo "Error durante la navegación: " . $e->getMessage() . "\n";
}

// NO cerramos el navegador para reutilizar la instancia en futuras ejecuciones
// Opcionalmente se podría cerrar sólo la pestaña creada:
$page->close();

echo "Script finalizado (el navegador sigue abierto).\n";
