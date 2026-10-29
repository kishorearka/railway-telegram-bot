
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| RAILWAY STATION TELEGRAM BOT
|--------------------------------------------------------------------------
| Hosting: Render with PHP 8.2 + Apache
| Environment variable: BOT_TOKEN
| Station database: stations.json
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: text/plain; charset=utf-8');

/**
 * Log diagnostic information to Render logs.
 * Never log the Telegram bot token.
 */
function logMessage(string $message, array $context = []): void
{
    $timestamp = date('Y-m-d H:i:s');

    $contextText = '';
    if ($context !== []) {
        $contextText = ' ' . json_encode(
            $context,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES |
            JSON_INVALID_UTF8_SUBSTITUTE
        );
    }

    error_log("[RAILWAY_BOT][$timestamp IST] $message$contextText");
}

logMessage('========== REQUEST START ==========');
logMessage('MY_RAILWAY_BOT_INDEX_PHP_IS_RUNNING', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
]);

// Health check for browser and hosting monitors.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ||
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {

    logMessage('Health check request');

    echo "Railway Telegram Bot is running.\n";
    echo 'PHP version: ' . PHP_VERSION . "\n";
    echo 'stations.json: ' .
        (is_file(__DIR__ . '/stations.json') ? 'Found' : 'Missing') . "\n";

    exit;
}

// Read incoming Telegram webhook request.
$rawInput = file_get_contents('php://input');

logMessage('Incoming request body received', [
    'length' => strlen($rawInput ?: ''),
    'preview' => substr($rawInput ?: '', 0, 1200)
]);

if ($rawInput === false || trim($rawInput) === '') {
    logMessage('ERROR: Empty request body');
    http_response_code(400);
    echo 'Empty request body';
    exit;
}

// Decode Telegram JSON update.
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    logMessage('ERROR: Invalid JSON', [
        'error' => json_last_error_msg()
    ]);

    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

logMessage('Telegram update decoded', [
    'update_id' => $update['update_id'] ?? null,
    'keys' => array_keys($update)
]);

// Load token from Render environment.
$token = getenv('BOT_TOKEN') ?: '';

if ($token === '') {
    logMessage('CRITICAL ERROR: BOT_TOKEN is missing');
    http_response_code(500);
    echo 'BOT_TOKEN environment variable is missing';
    exit;
}

logMessage('BOT_TOKEN is configured');

/**
 * Send a Telegram message and log the API result.
 */
function sendTelegramMessage(
    string $token,
    int|string $chatId,
    string $text
): bool {
    if (!function_exists('curl_init')) {
        logMessage('CRITICAL ERROR: PHP cURL extension is missing');
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        logMessage('ERROR: JSON encoding failed', [
            'error' => json_last_error_msg()
        ]);
        return false;
    }

    logMessage('Sending Telegram message', [
        'chat_id' => $chatId,
        'message_length' => strlen($text)
    ]);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false) {
        logMessage('ERROR: Telegram cURL request failed', [
            'http_status' => $httpStatus,
            'curl_error' => $curlError
        ]);
        return false;
    }

    $result = json_decode($response, true);

    logMessage('Telegram API response', [
        'http_status' => $httpStatus,
        'telegram_ok' => $result['ok'] ?? null,
        'description' => $result['description'] ?? null,
        'response' => substr($response, 0, 1000)
    ]);

    if (
        $httpStatus < 200 ||
        $httpStatus >= 300 ||
        !is_array($result) ||
        ($result['ok'] ?? false) !== true
    ) {
        logMessage('ERROR: Telegram rejected the message');
        return false;
    }

    logMessage('SUCCESS: Telegram accepted the message', [
        'chat_id' => $chatId
    ]);

    return true;
}

/**
 * Load local station records.
 *
 * Supported formats:
 *   [ { "code": "MBM", ... } ]
 *   { "stations": [ { "code": "MBM", ... } ] }
 */
function loadStations(): array
{
    $file = __DIR__ . '/stations.json';

    logMessage('Loading station database', [
        'path' => $file,
        'exists' => is_file($file)
    ]);

    if (!is_file($file)) {
        logMessage('ERROR: stations.json does not exist');
        return [];
    }

    $json = file_get_contents($file);

    if ($json === false) {
        logMessage('ERROR: Unable to read stations.json');
        return [];
    }

    $stations = json_decode($json, true);

    if (!is_array($stations)) {
        logMessage('ERROR: Invalid stations.json', [
            'json_error' => json_last_error_msg()
        ]);
        return [];
    }

    if (isset($stations['stations']) &&
        is_array($stations['stations'])) {
        $stations = $stations['stations'];
    }

    $stations = array_values(array_filter(
        $stations,
        static function ($station): bool {
            return is_array($station)
                && isset($station['code'])
                && trim((string) $station['code']) !== '';
        }
    ));

    logMessage('Station database loaded', [
        'count' => count($stations)
    ]);

    return $stations;
}

/**
 * Format a station record.
 */
function formatStation(array $station): string
{
    $code = strtoupper(trim((string) ($station['code'] ?? '')));
    $name = trim((string) ($station['name'] ?? 'Unknown'));
    $state = trim((string) ($station['state'] ?? 'India'));
    $zone = trim((string) ($station['zone'] ?? 'IR'));
    $division = trim((string) ($station['division'] ?? ''));
    $uts = trim((string) ($station['uts_payload'] ?? ''));

    $reply = "🚉 Railway Station Details\n\n";
    $reply .= "Station Name: {$name}\n";
    $reply .= "Station Code: {$code}\n";
    $reply .= "State: {$state}\n";
    $reply .= "Zone: {$zone}\n";

    if ($division !== '') {
        $reply .= "Division: {$division}\n";
    }

    if ($uts !== '' && strtoupper($uts) !== 'UNAVAILABLE') {
        $reply .= "UTS Payload: {$uts}\n";
    }

    $reply .= "\nStation website:\n";
    $reply .= 'https://railwaystationqrcode.infinityfree.me/station.php?code=';
    $reply .= rawurlencode($code);

    return $reply;
}

/**
 * Process an incoming Telegram message.
 */
$message = $update['message'] ?? null;

if (!is_array($message)) {
    logMessage('Update does not contain a standard message', [
        'update_id' => $update['update_id'] ?? null
    ]);

    echo 'OK';
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$text = trim((string) ($message['text'] ?? ''));

logMessage('Incoming Telegram message', [
    'chat_id' => $chatId,
    'message_id' => $message['message_id'] ?? null,
    'text' => substr($text, 0, 500)
]);

if ($chatId === null) {
    logMessage('ERROR: No chat ID in message');
    echo 'OK';
    exit;
}

// Handle messages without text.
if ($text === '') {
    logMessage('Message has no text');

    sendTelegramMessage(
        $token,
        $chatId,
        'Please send a station code or name. Example: /station MBM'
    );

    echo 'OK';
    exit;
}

// /start
if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
    logMessage('Processing /start');

    $reply = "🚆 Welcome to Railway Station Bot!\n\n";
    $reply .= "Search for an Indian railway station by code or name.\n\n";
    $reply .= "Examples:\n";
    $reply .= "/station MBM\n";
    $reply .= "/station Mambalam\n";
    $reply .= "/station Chennai Central\n\n";
    $reply .= "You can also send a station code or name directly.";

    sendTelegramMessage($token, $chatId, $reply);

    echo 'OK';
    logMessage('========== REQUEST END ==========');
    exit;
}

// /help
if (preg_match('/^\/help(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
    logMessage('Processing /help');

    $reply = "Railway Station Bot Help\n\n";
    $reply .= "Search using a station code or name.\n\n";
    $reply .= "Examples:\n";
    $reply .= "/station MBM\n";
    $reply .= "/station Mambalam";

    sendTelegramMessage($token, $chatId, $reply);

    echo 'OK';
    logMessage('========== REQUEST END ==========');
    exit;
}

// Extract station search query.
$query = preg_replace(
    '/^\/station(?:@[A-Za-z0-9_]+)?\s*/i',
    '',
    $text
);

$query = trim((string) $query);

// Ignore unknown slash commands.
if (str_starts_with($text, '/') &&
    !preg_match('/^\/station(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {

    logMessage('Unknown command', [
        'text' => $text
    ]);

    sendTelegramMessage(
        $token,
        $chatId,
        "Unknown command.\nSend /start for instructions."
    );

    echo 'OK';
    logMessage('========== REQUEST END ==========');
    exit;
}

// Handle /station without a search term.
if ($query === '') {
    logMessage('Station query is empty');

    sendTelegramMessage(
        $token,
        $chatId,
        "Please enter a station code or name.\nExample: /station MBM"
    );

    echo 'OK';
    logMessage('========== REQUEST END ==========');
    exit;
}

logMessage('Searching for station', [
    'query' => $query
]);

$stations = loadStations();

if ($stations === []) {
    logMessage('ERROR: Station database is empty or unavailable');

    sendTelegramMessage(
        $token,
        $chatId,
        "The station database is unavailable or empty.\nPlease try again later."
    );

    echo 'OK';
    logMessage('========== REQUEST END ==========');
    exit;
}

$queryUpper = strtoupper($query);
$matches = [];

// First: exact station code.
foreach ($stations as $station) {
    $code = strtoupper(trim((string) ($station['code'] ?? '')));

    if ($code !== '' && $code === $queryUpper) {
        $matches = [$station];

        logMessage('Exact station code matched', [
            'code' => $code
        ]);

        break;
    }
}

// Second: exact station name.
if ($matches === []) {
    foreach ($stations as $station) {
        $name = trim((string) ($station['name'] ?? ''));

        if ($name !== '' && strcasecmp($name, $query) === 0) {
            $matches = [$station];

            logMessage('Exact station name matched', [
                'name' => $name
            ]);

            break;
        }
    }
}

// Third: partial code or name.
if ($matches === []) {
    foreach ($stations as $station) {
        $code = strtoupper(trim((string) ($station['code'] ?? '')));
        $name = trim((string) ($station['name'] ?? ''));

        if (
            ($code !== '' && str_contains($code, $queryUpper)) ||
            ($name !== '' && stripos($name, $query) !== false)
        ) {
            $matches[] = $station;
        }

        if (count($matches) >= 10) {
            break;
        }
    }

    logMessage('Partial search completed', [
        'match_count' => count($matches)
    ]);
}

// Send one station result.
if (count($matches) === 1) {
    logMessage('Returning station result', [
        'code' => $matches[0]['code'] ?? null
    ]);

    sendTelegramMessage(
        $token,
        $chatId,
        formatStation($matches[0])
    );

} elseif (count($matches) > 1) {
    // Multiple matches: let the user select a station code.
    $reply = "🔎 Multiple stations found for: {$query}\n\n";

    foreach ($matches as $station) {
        $code = strtoupper(trim((string) ($station['code'] ?? '')));
        $name = trim((string) ($station['name'] ?? 'Unknown'));

        $reply .= "• {$name} ({$code})\n";
        $reply .= "/station {$code}\n\n";
    }

    logMessage('Returning multiple station matches', [
        'count' => count($matches)
    ]);

    sendTelegramMessage(
        $token,
        $chatId,
        trim($reply)
    );

} else {
    logMessage('No station found', [
        'query' => $query
    ]);

    sendTelegramMessage(
        $token,
        $chatId,
        "❌ No station found for: {$query}\n\nTry another station code or name."
    );
}

echo 'OK';

logMessage('========== REQUEST END ==========');
