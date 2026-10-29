<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Railway Telegram Bot
|--------------------------------------------------------------------------
| Requirements:
|   - PHP 8.2+
|   - PHP cURL extension
|   - BOT_TOKEN environment variable configured in Render
|   - stations.json in the same directory as this file
|--------------------------------------------------------------------------
*/

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

date_default_timezone_set('Asia/Kolkata');

const STATIONS_FILE = __DIR__ . '/stations.json';
const TELEGRAM_API  = 'https://api.telegram.org';

/*
|--------------------------------------------------------------------------
| Logging
|--------------------------------------------------------------------------
*/

function logMessage(string $message, array $context = []): void
{
    // Never log the bot token.
    $entry = [
        'time'    => date('Y-m-d H:i:s'),
        'message' => $message,
        'context' => $context,
    ];

    error_log(
        '[RAILWAY_BOT] ' .
        json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

/*
|--------------------------------------------------------------------------
| HTTP response helpers
|--------------------------------------------------------------------------
*/

function respond(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

/*
|--------------------------------------------------------------------------
| Load station database
|--------------------------------------------------------------------------
*/

function loadStations(): array
{
    if (!is_file(STATIONS_FILE)) {
        logMessage('stations.json does not exist');
        return [];
    }

    $json = file_get_contents(STATIONS_FILE);

    if ($json === false || trim($json) === '') {
        logMessage('stations.json is empty or unreadable');
        return [];
    }

    $stations = json_decode($json, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        logMessage('Invalid JSON in stations.json', [
            'error' => json_last_error_msg(),
        ]);
        return [];
    }

    /*
     * Accept either:
     *   [ {...}, {...} ]
     * or:
     *   { "stations": [ {...}, {...} ] }
     */
    if (isset($stations['stations']) && is_array($stations['stations'])) {
        $stations = $stations['stations'];
    }

    if (!is_array($stations)) {
        logMessage('Unexpected stations.json structure');
        return [];
    }

    // Keep only station records represented as arrays.
    return array_values(array_filter($stations, 'is_array'));
}

/*
|--------------------------------------------------------------------------
| Read fields from different common JSON column names
|--------------------------------------------------------------------------
*/

function getStationField(array $station, array $possibleKeys): string
{
    foreach ($possibleKeys as $key) {
        if (isset($station[$key]) && is_scalar($station[$key])) {
            $value = trim((string) $station[$key]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function stationCode(array $station): string
{
    return getStationField($station, [
        'station_code',
        'stationCode',
        'stn_code',
        'code',
        'stationcode',
        'STATION_CODE',
        'CODE',
    ]);
}

function stationName(array $station): string
{
    return getStationField($station, [
        'station_name',
        'stationName',
        'stn_name',
        'name',
        'station',
        'stationname',
        'STATION_NAME',
        'NAME',
    ]);
}

/*
|--------------------------------------------------------------------------
| Find stations by code or name
|--------------------------------------------------------------------------
*/

function findStations(string $query, array $stations): array
{
    $query = trim($query);

    if ($query === '') {
        return [];
    }

    $queryUpper = strtoupper($query);
    $exactMatches = [];
    $partialMatches = [];

    foreach ($stations as $station) {
        $code = stationCode($station);
        $name = stationName($station);

        if ($code === '' && $name === '') {
            continue;
        }

        // Exact station-code match has highest priority.
        if ($code !== '' && strtoupper($code) === $queryUpper) {
            $exactMatches[] = $station;
            continue;
        }

        // Exact station-name match.
        if ($name !== '' && strcasecmp($name, $query) === 0) {
            $exactMatches[] = $station;
            continue;
        }

        // Partial station-code or station-name match.
        if (
            ($code !== '' && stripos($code, $query) !== false) ||
            ($name !== '' && stripos($name, $query) !== false)
        ) {
            $partialMatches[] = $station;
        }
    }

    $matches = array_merge($exactMatches, $partialMatches);

    // Avoid overwhelming Telegram with too many results.
    return array_slice($matches, 0, 15);
}

/*
|--------------------------------------------------------------------------
| Format station search results
|--------------------------------------------------------------------------
*/

function formatStationResults(string $query, array $matches): string
{
    if (count($matches) === 0) {
        return
            "No railway station found for: " . $query . "\n\n" .
            "Try a station code or part of the station name.\n" .
            "Example: /station MBM\n" .
            "Example: /station Chennai";
    }

    $text = "Railway station search results\n";
    $text .= "Search: " . $query . "\n\n";

    foreach ($matches as $index => $station) {
        $code = stationCode($station);
        $name = stationName($station);

        $text .= ($index + 1) . ". ";

        if ($name !== '') {
            $text .= $name;
        } else {
            $text .= 'Station name unavailable';
        }

        if ($code !== '') {
            $text .= " (" . $code . ")";
        }

        $text .= "\n";
    }

    $text .= "\nSearch another station using /station CODE";

    return $text;
}

/*
|--------------------------------------------------------------------------
| Call Telegram Bot API
|--------------------------------------------------------------------------
*/

function telegramRequest(string $method, array $parameters): array
{
    $token = getenv('BOT_TOKEN');

    if ($token === false || trim($token) === '') {
        logMessage('BOT_TOKEN environment variable is missing');

        return [
            'ok' => false,
            'description' => 'BOT_TOKEN is not configured in Render.',
        ];
    }

    if (!function_exists('curl_init')) {
        logMessage('PHP cURL extension is unavailable');

        return [
            'ok' => false,
            'description' => 'PHP cURL extension is unavailable.',
        ];
    }

    $url = TELEGRAM_API . '/bot' . trim($token) . '/' . $method;

    $curl = curl_init($url);

    if ($curl === false) {
        logMessage('Unable to initialize cURL');

        return [
            'ok' => false,
            'description' => 'Unable to initialize cURL.',
        ];
    }

    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($parameters),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        logMessage('Telegram API network error', [
            'method' => $method,
            'error' => $curlError,
        ]);

        return [
            'ok' => false,
            'description' => 'Telegram network request failed.',
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        logMessage('Telegram API returned invalid JSON', [
            'method' => $method,
            'http_code' => $httpCode,
        ]);

        return [
            'ok' => false,
            'description' => 'Telegram returned an invalid response.',
        ];
    }

    // Log the result without logging the URL or bot token.
    logMessage('Telegram API response', [
        'method' => $method,
        'http_code' => $httpCode,
        'ok' => $decoded['ok'] ?? false,
        'description' => $decoded['description'] ?? '',
    ]);

    return $decoded;
}

/*
|--------------------------------------------------------------------------
| Send a message to Telegram
|--------------------------------------------------------------------------
*/

function sendTelegramMessage(int|string $chatId, string $message): void
{
    $result = telegramRequest('sendMessage', [
        'chat_id' => $chatId,
        'text' => $message,
    ]);

    if (!($result['ok'] ?? false)) {
        logMessage('Failed to send Telegram message', [
            'chat_id' => $chatId,
            'description' => $result['description'] ?? 'Unknown error',
        ]);
    } else {
        logMessage('Telegram message sent successfully', [
            'chat_id' => $chatId,
        ]);
    }
}

/*
|--------------------------------------------------------------------------
| Handle Telegram commands and messages
|--------------------------------------------------------------------------
*/

function handleTelegramUpdate(array $update): void
{
    $message = $update['message']
        ?? $update['edited_message']
        ?? null;

    if (!is_array($message)) {
        logMessage('Update has no supported message', [
            'update_id' => $update['update_id'] ?? null,
        ]);
        return;
    }

    $chatId = $message['chat']['id'] ?? null;
    $text = trim((string) ($message['text'] ?? ''));

    if ($chatId === null) {
        logMessage('Incoming message has no chat ID');
        return;
    }

    logMessage('Incoming Telegram message', [
        'update_id' => $update['update_id'] ?? null,
        'chat_id' => $chatId,
        'text' => mb_substr($text, 0, 150),
    ]);

    if ($text === '') {
        sendTelegramMessage(
            $chatId,
            'Please send a station code or station name. Example: MBM'
        );
        return;
    }

    // Remove bot username from commands such as /start@YourBot.
    $firstLine = preg_split('/\R/', $text, 2)[0] ?? $text;
    $firstLine = trim($firstLine);

    if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $firstLine)) {
        $reply =
            "Welcome to the Indian Railway Station Bot!\n\n" .
            "You can search railway stations by station code or name.\n\n" .
            "Examples:\n" .
            "/station MBM\n" .
            "/station Chennai\n" .
            "/station NDLS\n\n" .
            "You can also simply send a station code or station name.";

        sendTelegramMessage($chatId, $reply);
        return;
    }

    if (preg_match('/^\/help(?:@\w+)?(?:\s|$)/i', $firstLine)) {
        $reply =
            "How to use this bot\n\n" .
            "1. Search by station code:\n" .
            "/station MBM\n\n" .
            "2. Search by station name:\n" .
            "/station Chennai\n\n" .
            "3. Or send a code or name directly:\n" .
            "MBM\n\n" .
            "The bot searches the station records in its local database.";

        sendTelegramMessage($chatId, $reply);
        return;
    }

    // Support /station MBM and /station@YourBot MBM.
    if (preg_match('/^\/station(?:@\w+)?(?:\s+(.+))?$/i', $firstLine, $commandMatch)) {
        $query = trim($commandMatch[1] ?? '');

        if ($query === '') {
            sendTelegramMessage(
                $chatId,
                "Please provide a station code or name.\n\nExample: /station MBM"
            );
            return;
        }
    } elseif (str_starts_with($firstLine, '/')) {
        sendTelegramMessage(
            $chatId,
            "Unknown command.\n\nUse /start, /help, or /station MBM."
        );
        return;
    } else {
        // Plain-text searches such as MBM or Chennai.
        $query = $firstLine;
    }

    $stations = loadStations();

    if (count($stations) === 0) {
        logMessage('Station database unavailable or empty');

        sendTelegramMessage(
            $chatId,
            "Sorry, the station database is unavailable right now. Please try again later."
        );
        return;
    }

    $matches = findStations($query, $stations);
    $reply = formatStationResults($query, $matches);

    sendTelegramMessage($chatId, $reply);
}

/*
|--------------------------------------------------------------------------
| Main entry point
|--------------------------------------------------------------------------
*/

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Health check for Render.
    if ($method === 'GET') {
        $stationFileExists = is_file(STATIONS_FILE);
        $stationCount = count(loadStations());

        header('Content-Type: text/plain; charset=utf-8');

        echo "Railway Telegram Bot is running.\n";
        echo "PHP version: " . PHP_VERSION . "\n";
        echo "stations.json: " .
            ($stationFileExists ? 'Found' : 'Missing') . "\n";
        echo "Loaded station records: " . $stationCount . "\n";

        exit;
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(405, 'Method not allowed');
    }

    $rawBody = file_get_contents('php://input');

    if ($rawBody === false || trim($rawBody) === '') {
        logMessage('Incoming POST request has an empty body');
        respond(400, 'Empty request body');
    }

    $update = json_decode($rawBody, true);

    if (!is_array($update)) {
        logMessage('Incoming POST body is invalid JSON', [
            'json_error' => json_last_error_msg(),
        ]);

        respond(400, 'Invalid JSON');
    }

    logMessage('Incoming Telegram update received', [
        'update_id' => $update['update_id'] ?? null,
    ]);

    /*
     * Return HTTP 200 to Telegram after accepting a valid update.
     * Message processing is performed below in this request.
     */
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OK';

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    handleTelegramUpdate($update);
} catch (Throwable $exception) {
    logMessage('Unhandled PHP exception', [
        'type' => get_class($exception),
        'message' => $exception->getMessage(),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ]);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo 'Internal server error';
}
