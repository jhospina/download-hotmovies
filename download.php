<?php
require 'vendor/autoload.php';

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;


$videoUrl = $argv[1];

echo "Iniciando script...\n";

// Archivo donde se guarda la URI del socket del navegador ya iniciado
$socketFile = __DIR__ . DIRECTORY_SEPARATOR . 'browser_socket_uri.txt';

// Intentar conectarse a un navegador ya existente
$browser = null;
$browserFactory = null;
$titleVideo = null;

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
$attachNetworkLogging = function ($sessionToUse, string $contextLabel = 'main') use (&$playerUrl, $browser, $page) {
    // Habilitar Network en la sesión objetivo
    try {
        $sessionToUse->sendMessage(new Message('Network.enable'));
    } catch (\Throwable $e) {
        echo "[$contextLabel] Error enviando Network.enable: " . $e->getMessage() . "\n";
    }

    // Escuchar requests
    $sessionToUse->on('method:Network.requestWillBeSent', function ($params) use (&$playerUrl, $browser, $contextLabel, $page) {

        global $titleVideo;

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
            && str_contains($url, 'type=scene')
        ) {
            $titleVideo = str_replace(" - HotMovies", "", $page->evaluate('document.title')->getReturnValue());

            echo "Título del video: $titleVideo\n";

            $playerUrl = $url;
            echo "********** IFRAME DEL PLAYER DETECTADO, ABRIENDO NUEVA PÁGINA **********\n";
            echo "Player URL: $playerUrl\n";

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
            echo "[$contextLabel] ********** REQUEST ESPECIAL DETECTADA **********\n";
            echo "URL: $url\n";
            echo "TYPE: $type | FRAME: $frameId | INITIATOR: $initiator | METHOD: $method\n";
            echo "[$contextLabel] ***********************************************\n";

            echo "[$contextLabel] Iniciando descarga con yt-dlp...\n";
            echo "Comando: cd /d F:\yt && yt-dlp.exe \"$url\" --output=\"$titleVideo.mp4\"\n";
            system("cd /d F:\yt && yt-dlp.exe \"$url\" --output=\"$titleVideo.mp4\"");
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

echo "Script finalizado (el navegador sigue abierto).
";
