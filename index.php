<?php
declare(strict_types=1);

/*
 * Railway Station Telegram Bot
 * Hosted on Render
 * QR pages hosted on InfinityFree
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

const STATIONS_FILE = __DIR__ . '/stations.json';
const WEBSITE_URL = 'https://railwaystationqrcode.infinityfree.me/station.php';

function botLog(string $message, array $data = []): void
{
    error_log('[RAILWAY_BOT] ' . json_encode([
        'time' => date('c'),
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function telegram(string $method, array $data): array
{
    $token = getenv('BOT_TOKEN');

    if (!$token) {
        botLog('BOT_TOKEN is missing');
        return ['ok' => false, 'description' => 'BOT_TOKEN missing'];
    }

    $ch = curl_init(
        'https://api.telegram.org/bot' . trim($token) . '/' . $method
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        botLog('Telegram request failed', [
            'method' => $method,
            'error' => $error,
        ]);
        return ['ok' => false, 'description' => 'Network error'];
    }

    $result = json_decode($response, true);

    botLog('Telegram API result', [
        'method' => $method,
        'http_status' => $status,
        'ok' => $result['ok'] ?? false,
        'description' => $result['description'] ?? '',
    ]);

    return is_array($result) ? $result : ['ok' => false];
}

function sendReply(int|string $chatId, string $text, ?string $url = null): void
{
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
    ];

    if ($url !== null) {
        $data['reply_markup'] = [
            'inline_keyboard' => [[
                [
                    'text' => '📱 Open Station QR Code',
                    'url' => $url,
                ],
            ]],
        ];
    }

    telegram('sendMessage', $data);
}

function loadStations(): array
{
    if (!is_file(STATIONS_FILE)) {
        botLog('stations.json not found');
        return [];
    }

    $json = file_get_contents(STATIONS_FILE);
    $data = json_decode($json ?: '', true);

    if (!is_array($data)) {
        botLog('Invalid stations.json', [
            'error' => json_last_error_msg(),
        ]);
        return [];
    }

    if (isset($data['stations']) && is_array($data['stations'])) {
        $data = $data['stations'];
    }

    return array_values(array_filter($data, 'is_array'));
}

function field(array $station, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($station[$key]) && is_scalar($station[$key])) {
            $value = trim((string) $station[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function codeOf(array $station): string
{
    return field($station, [
        'station_code', 'stationCode', 'stn_code',
        'code', 'stationcode', 'CODE',
    ]);
}

function nameOf(array $station): string
{
    return field($station, [
        'station_name', 'stationName', 'stn_name',
        'name', 'station', 'stationname', 'NAME',
    ]);
}

function searchStation(string $query, array $stations): ?array
{
    $query = trim($query);

    // Support input like "Mambalam (MBM)".
    if (preg_match('/\(([A-Za-z0-9]+)\)\s*$/', $query, $m)) {
        $query = strtoupper($m[1]);
    }

    foreach ($stations as $station) {
        if (
            codeOf($station) !== '' &&
            strcasecmp(codeOf($station), $query) === 0
        ) {
            return $station;
        }
    }

    foreach ($stations as $station) {
        if (
            nameOf($station) !== '' &&
            strcasecmp(nameOf($station), $query) === 0
        ) {
            return $station;
        }
    }

    $matches = [];

    foreach ($stations as $station) {
        if (
            stripos(codeOf($station), $query) !== false ||
            stripos(nameOf($station), $query) !== false
        ) {
            $matches[] = $station;
        }
    }

    return count($matches) === 1 ? $matches[0] : null;
}

function handleUpdate(array $update): void
{
    $message = $update['message'] ?? null;

    if (!is_array($message)) {
        botLog('Update does not contain a message');
        return;
    }

    $chatId = $message['chat']['id'] ?? null;
    $text = trim((string) ($message['text'] ?? ''));

    if ($chatId === null) {
        return;
    }

    botLog('Message received', [
        'update_id' => $update['update_id'] ?? null,
        'chat_id' => $chatId,
    ]);

    if ($text === '' || preg_match('/^\/start(?:@\w+)?$/i', $text)) {
        sendReply(
            $chatId,
            "🚆 Welcome to Railway Station QR Bot!\n\n" .
            "Send a station code or name to get its QR page.\n\n" .
            "Examples:\n" .
            "MBM\n" .
            "/station MBM\n" .
            "/station Mambalam\n\n" .
            "Use /help for instructions."
        );
        return;
    }

    if (preg_match('/^\/help(?:@\w+)?$/i', $text)) {
        sendReply(
            $chatId,
            "🔎 Search a railway station\n\n" .
            "Send a station code or name:\n" .
            "MBM\n" .
            "Mambalam\n\n" .
            "Or use:\n" .
            "/station MBM"
        );
        return;
    }

    if (preg_match('/^\/station(?:@\w+)?(?:\s+(.+))?$/i', $text, $m)) {
        $query = trim($m[1] ?? '');

        if ($query === '') {
            sendReply($chatId, "Please provide a station code or name.\nExample: /station MBM");
            return;
        }
    } elseif (str_starts_with($text, '/')) {
        sendReply($chatId, "Unknown command. Use /help.");
        return;
    } else {
        $query = $text;
    }

    $stations = loadStations();

    if (!$stations) {
        sendReply(
            $chatId,
            "The station database is unavailable. Please try again later."
        );
        return;
    }

    $station = searchStation($query, $stations);

    if ($station === null) {
        sendReply(
            $chatId,
            "❌ No unique station found for: " . $query .
            "\n\nTry its exact station code or full station name."
        );
        return;
    }

    $code = codeOf($station);
    $name = nameOf($station);

    if ($code === '') {
        sendReply($chatId, "The station record has no station code.");
        return;
    }

    // Open the station.php page on InfinityFree.
    $qrPage = WEBSITE_URL . '?code=' . rawurlencode($code);

    $reply =
        "🚉 Railway Station Found\n\n" .
        "Station: " . ($name ?: 'Name unavailable') . "\n" .
        "Station Code: " . $code . "\n\n" .
        "Tap the button below to open the station's QR code page on our website.";

    sendReply($chatId, $reply, $qrPage);
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        header('Content-Type: text/plain; charset=utf-8');

        echo "Railway Telegram Bot is running.\n";
        echo 'PHP: ' . PHP_VERSION . "\n";
        echo 'stations.json: ' .
            (is_file(STATIONS_FILE) ? 'Found' : 'Missing') . "\n";
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        exit('Method not allowed');
    }

    $body = file_get_contents('php://input');
    $update = json_decode($body ?: '', true);

    if (!is_array($update)) {
        http_response_code(400);
        exit('Invalid JSON');
    }

    // Acknowledge the webhook update.
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    handleUpdate($update);
} catch (Throwable $e) {
    botLog('Unhandled error', [
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
    }
}

