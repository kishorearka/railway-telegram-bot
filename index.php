
<?php
// index.php
// Telegram webhook + InfinityFree station API

$botToken = getenv('BOT_TOKEN');

if (!$botToken) {
    error_log('ERROR: BOT_TOKEN environment variable is missing.');
    http_response_code(500);
    exit('BOT_TOKEN environment variable is missing.');
}

$apiUrl = 'https://api.telegram.org/bot' . $botToken . '/';
$stationApiUrl = 'https://railwaystationqrcode.infinityfree.me/api.php';

// Read Telegram webhook update
$content = file_get_contents('php://input');
$update = json_decode($content, true);

if (!is_array($update) || !isset($update['message'])) {
    http_response_code(200);
    exit('OK');
}

$message = $update['message'];
$chatId = $message['chat']['id'] ?? null;
$text = trim($message['text'] ?? '');

if (!$chatId || $text === '') {
    http_response_code(200);
    exit('OK');
}

// Welcome command
if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text)) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        "🚆 Welcome to Indian Railways UTS QR Bot!\n\n" .
        "Send a station code or station name.\n\n" .
        "Examples: MBM, MAS, Mambalam\n" .
        "You can also use /station MBM."
    );

    http_response_code(200);
    exit('OK');
}

// Accept /station MBM or plain text
$query = preg_replace('/^\/station(?:@\w+)?\s*/i', '', $text);
$query = trim($query);

if (str_starts_with($query, '/')) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Unknown command. Send a station code or name, for example MBM.'
    );

    http_response_code(200);
    exit('OK');
}

if ($query === '') {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Please enter a station code or name, for example MBM.'
    );

    http_response_code(200);
    exit('OK');
}

// Call InfinityFree station API
$result = httpGetJson(
    $stationApiUrl . '?' . http_build_query(['q' => $query])
);

if ($result === null || !isset($result['status'])) {
    error_log('ERROR: Station API returned invalid JSON or HTTP failure.');

    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Sorry, the station service is temporarily unavailable. Please try again later.'
    );

    http_response_code(200);
    exit('OK');
}

if ($result['status'] !== 'success') {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        $result['message'] ?? "Station '" . $query . "' was not found."
    );

    http_response_code(200);
    exit('OK');
}

$station = $result['data'] ?? [];

$code = trim((string)($station['code'] ?? ''));
$name = trim((string)($station['name'] ?? ''));

if ($code === '' || $name === '') {
    error_log('ERROR: API success response has missing station code/name.');

    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Station information is incomplete. Please try again later.'
    );

    http_response_code(200);
    exit('OK');
}

$state = trim((string)($station['state'] ?? 'India'));
$zone = trim((string)($station['zone'] ?? 'IR'));
$division = trim((string)($station['division'] ?? ''));

$portalUrl = 'https://railwaystationqrcode.infinityfree.me/station.php?' .
    http_build_query(['code' => $code]);

$reply = "✅ Station Found!\n\n" .
    "🚉 " . $name . " (" . $code . ")\n" .
    "📍 State: " . $state . "\n" .
    "🔀 Zone: " . $zone;

if ($division !== '') {
    $reply .= "\n🏢 Division: " . $division;
}

$reply .= "\n\n🔗 View & Download UTS QR Code:\n" . $portalUrl;

sendTelegramMessage($apiUrl, $chatId, $reply);

http_response_code(200);
echo 'OK';
exit;


// Fetch JSON and write diagnostic details to Render Logs
function httpGetJson(string $url): ?array
{
    error_log('Station API request URL: ' . $url);

    $ch = curl_init($url);

    if ($ch === false) {
        error_log('ERROR: curl_init failed.');
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'RailwayTelegramBot/1.0',
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    curl_close($ch);

    error_log('Station API HTTP status: ' . $statusCode);

    if ($curlError !== '') {
        error_log('Station API cURL error: ' . $curlError);
    }

    if ($response === false) {
        error_log('Station API response: cURL returned false.');
        return null;
    }

    error_log(
        'Station API response body: ' .
        substr((string)$response, 0, 1000)
    );

    if ($statusCode < 200 || $statusCode >= 300) {
        error_log('ERROR: Station API returned non-2xx HTTP status.');
        return null;
    }

    $data = json_decode((string)$response, true);

    if (!is_array($data)) {
        error_log('ERROR: Station API response is not valid JSON.');
        return null;
    }

    return $data;
}


// Send Telegram message
function sendTelegramMessage(
    string $apiUrl,
    int|string $chatId,
    string $text
): void {
    $ch = curl_init($apiUrl . 'sendMessage');

    if ($ch === false) {
        error_log('ERROR: Could not initialize Telegram cURL.');
        return;
    }

    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => false,
    ];

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    $response = curl_exec($ch);
    $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);

    if ($curlError !== '') {
        error_log('Telegram cURL error: ' . $curlError);
    }

    error_log('Telegram sendMessage HTTP status: ' . $statusCode);

    if ($response !== false && $statusCode >= 400) {
        error_log('Telegram API error response: ' . substr($response, 0, 500));
    }

    curl_close($ch);
}
