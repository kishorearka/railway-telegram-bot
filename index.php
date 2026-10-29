

<?php
declare(strict_types=1);

error_log("MY_RAILWAY_BOT_INDEX_PHP_IS_RUNNING");

date_default_timezone_set('Asia/Kolkata');
header('Content-Type: text/plain; charset=utf-8');


/**
 * Write diagnostic messages to Render application logs.
 * Never log the bot token.
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
logMessage('PHP script started', [
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'
]);

// Health check when opening the website in a browser.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ||
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    logMessage('Health check request received');

    echo "Railway Telegram Bot is running.\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "Station database: " .
        (is_file(__DIR__ . '/stations.json') ? 'Found' : 'Missing') . "\n";

    exit;
}

// Read the Telegram webhook request.
$rawInput = file_get_contents('php://input');

logMessage('Incoming POST body received', [
    'body_length' => strlen($rawInput ?: ''),
    'body_preview' => substr($rawInput ?: '', 0, 1500)
]);

if ($rawInput === false || trim($rawInput) === '') {
    logMessage('ERROR: Empty request body');
    http_response_code(400);
    echo 'Empty request body';
    exit;
}

// Decode JSON from Telegram.
$update = json_decode($rawInput, true);

if (!is_array($update)) {
    logMessage('ERROR: Invalid JSON', [
        'json_error' => json_last_error_msg()
    ]);

    http_response_code(400);
    echo 'Invalid JSON';
    exit;
}

logMessage('Telegram update decoded', [
    'update_id' => $update['update_id'] ?? null,
    'update_keys' => array_keys($update)
]);

// Read the bot token from Render environment variables.
$token = getenv('BOT_TOKEN') ?: '';

if ($token === '') {
    logMessage('CRITICAL ERROR: BOT_TOKEN is missing');

    // A non-2xx response tells Telegram delivery failed.
    http_response_code(500);
    echo 'BOT_TOKEN is missing';
    exit;
}

logMessage('BOT_TOKEN environment variable is present');

// Send a message using the Telegram Bot API.
function sendTelegramMessage(
    string $token,
    int|string $chatId,
    string $text
): bool {
    if (!function_exists('curl_init')) {
        logMessage('CRITICAL ERROR: PHP cURL extension is unavailable');
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        logMessage('ERROR: Could not encode sendMessage payload', [
            'json_error' => json_last_error_msg()
        ]);
        return false;
    }

    logMessage('Sending Telegram reply', [
        'chat_id' => $chatId,
        'text_length' => mb_strlen_safe($text)
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
        logMessage('ERROR: Telegram API cURL request failed', [
            'http_status' => $httpStatus,
            'curl_error' => $curlError
        ]);
        return false;
    }

    $result = json_decode($response, true);

    logMessage('Telegram sendMessage response', [
        'http_status' => $httpStatus,
        'telegram_ok' => $result['ok'] ?? null,
        'telegram_description' => $result['description'] ?? null,
        'response_preview' => substr($response, 0, 1200)
    ]);

    if ($httpStatus < 200 || $httpStatus >= 300 ||
        !is_array($result) || ($result['ok'] ?? false) !== true) {
        logMessage('ERROR: Telegram did not accept the message');
        return false;
    }

    logMessage('SUCCESS: Telegram message sent', [
        'chat_id' => $chatId
    ]);

    return true;
}

function mb_strlen_safe(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

// Load the station database from the local JSON file.
function loadStations(): array
{
    $file = __DIR__ . '/stations.json';

    logMessage('Loading station database', [
        'file' => $file,
        'exists' => is_file($file)
    ]);

    if (!is_file($file)) {
        logMessage('ERROR: stations.json not found');
        return [];
    }

    $json = file_get_contents($file);

    if ($json === false) {
        logMessage('ERROR: Could not read stations.json');
        return [];
    }

    $stations = json_decode($json, true);

    if (!is_array($stations)) {
        logMessage('ERROR: Invalid stations.json', [
            'json_error' => json_last_error_msg()
        ]);
        return [];
    }

    // Also support {"stations": [...]} format.
    if (isset($stations['stations']) &&
        is_array($stations['stations'])) {
        $stations = $stations['stations'];
    }

    // Validate and remove invalid entries.
    $stations = array_values(array_filter(
        $stations,
        static fn($station): bool =>
            is_array($station) &&
            isset($station['code']) &&
            trim((string)$station['code']) !== ''
    ));

    logMessage('Station database loaded', [
        'station_count' => count($stations)
    ]);

    return $stations;
}

// Format station information for Telegram.
function formatStation(array $station): string
{
    $code = strtoupper(trim((string)($station['code'] ?? '')));
    $name = trim((string)($station['name'] ?? 'Unknown'));
    $state = trim((string)($station['state'] ?? 'India'));
    $zone = trim((string)($station['zone'] ?? 'IR'));
    $division = trim((string)($station['division'] ?? ''));
    $uts = trim((string)($station['uts_payload'] ?? ''));

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

// Process the incoming Telegram update.
$message = $update['message'] ?? null;

if (!is_array($message)) {
    logMessage('Update has no standard message object', [
        'update_id' => $update['update_id'] ?? null
    ]);

    // Other update types are not handled by this bot.
    echo 'OK';
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$text = trim((string)($message['text'] ?? ''));

logMessage('Incoming message parsed', [
    'chat_id' => $chatId,
    'message_id' => $message['message_id'] ?? null,
    'text' => substr($text, 0, 500)
]);

if ($chatId === null) {
    logMessage('ERROR: Incoming message has no chat ID');
    echo 'OK';
    exit;
}

if ($text === '') {
    logMessage('Message contains no text');

    sendTelegramMessage(
        $token,
        $chatId,
        'Please send a station code or name. Example: /station MBM'
    );

    echo 'OK';
    exit;
}

// /start command.
if (preg_match('/^\/start(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
    logMessage('Processing /start command');

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

// /help command.
if (preg_match('/^\/help(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
    logMessage('Processing /help command');

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

// Extract the query from /station CODE or /station@BotName CODE.
$query = preg_replace(
    '/^\/station(?:@[A-Za-z0-9_]+)?\s*/i',
    '',
    $text
);

$query = trim((string)$query);

// Ignore unsupported slash commands.
if (str_starts_with($text, '/')) {
    if (!preg_match('/^\/station(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
        logMessage('Unsupported bot command', [
            'command' => $text
        ]);

        sendTelegramMessage(
            $token,
            $chatId,
            "Unknown command.\nSend /start to see how to use this bot."
        );

        echo 'OK';
        logMessage('========== REQUEST END ==========');
        exit;
    }
}

if ($query === '') {
    logMessage('Station search query is empty');

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
    logMessage('ERROR: No station records available');

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

// 1. Exact station-code match.
foreach ($stations as $station) {
    $code = strtoupper(trim((string)($station['code'] ?? '')));

    if ($code === $queryUpper) {
        $matches = [$station];
        logMessage('Exact station-code match found', [
            'code' => $code
        ]);
        break;
    }
}

// 2. Exact station-name match.
if ($matches === []) {
    foreach ($stations as $station) {
        $name = trim((string)($station['name'] ?? ''));

        if ($name !== '' && strcasecmp($name, $query) === 0) {
            $matches = [$station];
            logMessage('Exact station-name match found', [
                'name' => $name
            ]);
            break;
        }
    }
}

// 3. Partial code or name matches.
if ($matches === []) {
    foreach ($stations as $station) {
        $code = strtoupper(trim((string)($station['code'] ?? '')));
        $name = trim((string)($station['name'] ?? ''));

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

// Return the search results.
if (count($matches) === 1) {
    $reply = formatStation($matches[0]);

    logMessage('Sending single station result', [
        'code' => $matches[0]['code'] ?? ''
    ]);

    sendTelegramMessage($token, $chatId, $reply);

} elseif (count($matches) > 1) {
    $reply = "🔎 Multiple stations found for: {$query}\n\n";

    foreach ($matches as $station) {
        $code = trim((string)($station['code'] ?? ''));
        $name = trim((string)($station['name'] ?? 'Unknown'));

        $reply .= "• {$name} ({$code})\n";
        $reply .= "/station {$code}\n\n";
    }

    logMessage('Sending multiple station results', [
        'match_count' => count($matches)
    ]);

    sendTelegramMessage($token, $chatId, trim($reply));

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
