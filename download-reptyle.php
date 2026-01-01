<?php

require 'vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;


global $accessToken;
global $refreshToken;

$counter = file_get_contents('app_reptyle_counter.txt');
$accessToken = file_get_contents("app_reptyle_access_token.txt");
$refreshToken = "eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9.eyJpYXQiOjE3NjYwMDAzMjQsImV4cCI6MTc2ODYzMDEyNCwic2lkIjoiNjRkOWE5NzVlZTcwMGRmMGNkYTc4ZmIwLTMxNDE3OTEifQ.fNbTTiy9oMYR-jf1XhCh5lOxsbP3A0AO_GN1kmF3DTGHwINJqoFecM0uYpyYr8VgngRDAVe1L2EhmvomqTPE5kA9BBXbbxy-glvtw4mFVdMKw7YIYYM7t9EMiM4dsrQtsGazw_jSFNju0Z42LLJpR98sXWxEIacJ4Y9f1j7niqcRCCp7SbX9j-w-IgMM83iEcI2paBYV-V1LxUuGEAEiq49qnE7Wfildn-WRYbLrDMkHMiHk0EsdKtYCX_9vz3l7CL_jqMzRzVSpsDzHE5z2Skl9eUkDMXpwzCTUvv0EKK_TVYgbBwEUTdeogQKx3IDE3D7KIDBHB7Qa8jLGzQnQTA";

function getVideoInfo(string $videoId): array
{
    global $accessToken;

    $client = new Client([
        'base_uri' => 'https://api2.reptyle.com',
        'timeout' => 10,
    ]);


    try {
        $response = $client->get("https://api2.reptyle.com/api/v1/movie/$videoId/watch", [
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'es-US,es;q=0.9,en-US;q=0.8,en;q=0.7,es-419;q=0.6',
                'Authorization' => "Bearer $accessToken",
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Referer' => 'https://app.reptyle.com/',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RequestException("Unexpected status code: " . $response->getStatusCode(), $response->getRequest(), $response);
        }

        $body = $response->getBody()->getContents();

        $data = json_decode($body, true);

        return $data;

    } catch (RequestException $e) {
        if (str_contains($e->getMessage(), "Invalid Toke")) {
            echo "Access token expired, refreshing...\n";
            $accessToken = refreshAccessToken();
            return getVideoInfo($videoId);
        }
        echo "Error fetching video info: " . $e->getMessage() . "\n";
    }

    throw new Exception("Failed to get video info.");
}


function refreshAccessToken(): string
{
    global $refreshToken;

    $client = new Client([
        'base_uri' => 'https://api2.reptyle.com',
        'timeout' => 10,
    ]);

    try {
        $response = $client->post('https://auth.reptyle.com/oauth/refresh', [
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'es-US,es;q=0.9,en-US;q=0.8,en;q=0.7,es-419;q=0.6',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Referer' => 'https://app.reptyle.com/',
            ],
            'json' => [
                'refresh_token' => $refreshToken,
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RequestException("Unexpected status code: " . $response->getStatusCode(), $response->getRequest(), $response);
        }

        $body = $response->getBody()->getContents();

        $data = json_decode($body, true);
        $newAccessToken = $data['access_token'];

        file_put_contents("app_reptyle_access_token.txt", $newAccessToken);

        return $newAccessToken;

    } catch (RequestException $e) {
        echo "Error refreshing access token: " . $e->getMessage() . "\n";
        exit(1);
    }
}


function getMetatadataVideo(string $videoId)
{
    global $accessToken;

    $client = new Client([
        'base_uri' => 'https://api2.reptyle.com',
        'timeout' => 10,
    ]);


    try {
        $response = $client->get("https://ma-store.reptyle.com/ts_index/_doc/movie-$videoId/", [
            'headers' => [
                'Accept' => 'application/json, text/plain, */*',
                'Accept-Language' => 'es-US,es;q=0.9,en-US;q=0.8,en;q=0.7,es-419;q=0.6',
                'Authorization' => "Bearer $accessToken",
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
                'Referer' => 'https://app.reptyle.com/',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new RequestException("Unexpected status code: " . $response->getStatusCode(), $response->getRequest(), $response);
        }

        $body = $response->getBody()->getContents();

        $data = json_decode($body, true);

        $title = $data["_source"]["title"] ?? throw new Exception("Title not found");
        $img = $data["_source"]["img"] ?? throw new Exception("Slug not found");

        $parseImgUrl = parse_url($img, PHP_URL_PATH);
        $pathSegments = explode('/', trim($parseImgUrl, '/'));
        $source = $pathSegments[1] ?? throw new Exception("Source segment not found");
        $slug = $pathSegments[2] ?? throw new Exception("Slug segment not found");

        $source = match ($source) {
            "fs" => "familystrokes",
            "fos" => "fostertapes",
            "nmg" => "notmygrandpa",
        };

        return [$title, $source, $slug, $source . "_" . $slug];

    } catch (RequestException $e) {
        if (str_contains($e->getMessage(), "Invalid Toke")) {
            echo "Access token expired, refreshing...\n";
            $accessToken = refreshAccessToken();
            return getMetatadataVideo($videoId);
        }
        echo "Error fetching video info: " . $e->getMessage() . "\n";
    }
}

function downloadImage(string $url, string $destinationPath): void
{
    global $accessToken;
    global $refreshToken;

    $client = new Client([
        'timeout' => 60,
        'verify'  => true,
    ]);

    try {
        $response = $client->request('GET', $url, [
            'headers' => [
                'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept-Language' => 'es-US,es;q=0.9,en-US;q=0.8,en;q=0.7,es-419;q=0.6',
                'Cache-Control'   => 'no-cache',
                'Pragma'          => 'no-cache',
                'DNT'             => '1',
                'Referer'         => 'https://app.reptyle.com/',
                'Upgrade-Insecure-Requests' => '1',
                'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36',

                // Cookies (equivalente a -b en curl)
                'Cookie' => "d_uid=b5ff1bb6-3c2e-a082-0a72-d783376786df; d_uidb=b5ff1bb6-3c2e-a082-0a72-d783376786df; nats_sess=2ff9f6664c1443309ec90666cf7885c0; user_label=phoenix%2Cupsab2; access_token=$accessToken; refresh_token=$refreshToken",
            ],

            // Descarga directa a disco
            'sink' => $destinationPath,
        ]);

        echo 'Archivo descargado correctamente';

    } catch (RequestException $e) {
        if ($e->hasResponse()) {
            echo $e->getResponse()->getStatusCode() . ' - ' .
                $e->getResponse()->getBody()->getContents();
        } else {
            echo $e->getMessage();
        }
    }
}

$videoUrl = $argv[1];

$urlPath = parse_url($videoUrl, PHP_URL_PATH);
$pathSegments = explode('/', trim($urlPath, '/'));
$videoId = end($pathSegments);
$videoInfo = getVideoInfo($videoId);

$streamUrlVideo = $videoInfo["data"]["stream2"]["avc"]["hls"] ?? $videoInfo["data"]["stream"] ?? null;

if (is_null($streamUrlVideo)) {
    echo "No video stream URL found.\n";
    exit(1);
}

list($titleVideo, $source, $slug, $fullSlug) = getMetatadataVideo($videoId);
$titleVideo = str_pad($counter, "0") . " - " . $titleVideo;

file_put_contents("app_reptyle_counter.txt", $counter + 1);

$destinationPath = "E:\\_app_reptyle_pending\\$titleVideo\\$titleVideo.mp4";

// Create directory if it doesn't exist
if (!is_dir("E:\\_app_reptyle_pending\\$titleVideo")) {
    mkdir("E:\\_app_reptyle_pending\\$titleVideo", 0777, true);
}

// DOWNLOAD GALLERY IMAGES

$downloadImage = "https://downloads.reptyle.com/members/" . $source . "/" . $slug . "/pictures_hd/" . $fullSlug . ".zip";
$imagesDestination = "E:\\_app_reptyle_pending\\$titleVideo\\$titleVideo.zip";

echo "Downloading images from: $downloadImage\n";
downloadImage($downloadImage, $imagesDestination);

// DOWNLOAD VIDEO USING YT-DLP

$command = "cd /d F:\\yt && yt-dlp.exe";
$command .= " \"$streamUrlVideo\" --output=\"$destinationPath\"";

echo "Downloading: $command\n";
system($command);