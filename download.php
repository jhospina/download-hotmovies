<?php
require 'vendor/autoload.php';

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Communication\Message;


const VIDEO_URL = 'https://www.hotmovies.com/adult-clips/1622499/sexmex-immoral-family-part-1';


echo "Iniciando script...\n";

// Archivo donde se guarda la URI del socket del navegador ya iniciado
$socketFile = __DIR__ . DIRECTORY_SEPARATOR . 'browser_socket_uri.txt';

// Intentar conectarse a un navegador ya existente
$browser = null;
$browserFactory = null;

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

// Obtener sesión DevTools (Session ya es la sesión de protocolo)
$session = $page->getSession();

// Habilitar logs de red (opcional)
try {
    $session->sendMessage(new Message('Network.enable'));
} catch (\Throwable $e) {
    echo "Error enviando Network.enable: " . $e->getMessage() . "\n";
}

// Escuchar requests (la librería emite eventos con prefijo 'method:')
$session->on('method:Network.requestWillBeSent', function ($params) {
    $url = $params['request']['url'] ?? '';
    $method = $params['request']['method'] ?? '';
    echo "➡ $method $url\n";
});

// Escuchar respuestas
$session->on('method:Network.responseReceived', function ($params) {
    $url = $params['response']['url'] ?? '';
    $status = $params['response']['status'] ?? '';
    echo "⬅ [$status] $url\n";
});

try {
    // Navegar
    echo "Navegando a hotmovies.com...\n";
    $page->navigate(VIDEO_URL)->waitForNavigation();

    sleep(10);

} catch (\Throwable $e) {
    echo "Error durante la navegación: " . $e->getMessage() . "\n";
}

// NO cerramos el navegador para reutilizar la instancia en futuras ejecuciones
// Opcionalmente se podría cerrar sólo la pestaña creada:
// $page->close();

echo "Script finalizado (el navegador sigue abierto).\n";
