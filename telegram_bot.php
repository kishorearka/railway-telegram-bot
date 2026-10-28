<?php
// telegram_bot.php
// Runs on Render and uses the existing InfinityFree station API.

$botToken = getenv('BOT_TOKEN');
$apiUrl = 'https://api.telegram.org/bot' . $botToken . '/';
$stationApiUrl = 'https://railwaystationqrcode.infinityfree.me/api.php';

if (!$botToken) {
    http_response_code(500);
    exit('BOT_TOKEN environment variable is missing.');
}

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

// Welcome message
if (preg_match('/^\/start(?:@\w+)?(?:\s|$)/i', $text)) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        "🚆 *Welcome to Indian Railways UTS QR Bot!*\n\n"
        . "Send a station code or station name to find its UTS QR page.\n\n"
        . "Examples: `MAS`, `KLT`, `Chennai Central`\n"
        . "You can also use `/station MAS`.",
        'Markdown'
    );

    http_response_code(200);
    exit('OK');
}

// Accept /station MAS, /station@YourBot MAS, or a plain station search.
$query = preg_replace('/^\/station(?:@\w+)?\s*/i', '', $text);
$query = trim($query);

if (str_starts_with($query, '/')) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        "Unknown command. Send a station code or name, for example `MAS`.",
        'Markdown'
    );

    http_response_code(200);
    exit('OK');
}

if ($query === '') {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        "Please enter a station code or name, for example `MAS`.",
        'Markdown'
    );

    http_response_code(200);
    exit('OK');
}

// Query the existing InfinityFree API.
$result = httpGetJson($stationApiUrl . '?' . http_build_query([
    'q' => $query
]));

if ($result === null || !isset($result['status'])) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Sorry, the station service is temporarily unavailable. Please try again later.'
    );

    http_response_code(200);
    exit('OK');
}

if ($result['status'] === 'error') {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        $result['message'] ?? 'Please enter a valid station code or name.'
    );

    http_response_code(200);
    exit('OK');
}

if (!in_array($result['status'], ['success', 'fallback'], true)) {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        'Sorry, I could not process that station search.'
    );

    http_response_code(200);
    exit('OK');
}

$station = $result['data'] ?? [];

$stnCode = trim((string)($station['code'] ?? ''));
$stnName = trim((string)($station['name'] ?? ''));
$stnState = trim((string)($station['state'] ?? 'India'));
$stnZone = trim((string)($station['zone'] ?? 'IR'));
$stnDivision = trim((string)($station['division'] ?? ''));

if ($stnCode === '' || $stnName === '' || $result['status'] === 'fallback') {
    sendTelegramMessage(
        $apiUrl,
        $chatId,
        "❌ Station '" . $query . "' was not found. Please check the station code or name."
    );

    http_response_code(200);
    exit('OK');
}

// Link to your existing station page on InfinityFree.
$portalUrl = 'https://railwaystationqrcode.infinityfree.me/station.php?' .
    http_build_query(['code' => $stnCode]);

$reply = "✅ *Station Found!*\n\n"
    . "🚉 *" . mdEscape($stnName) . "* (`" . mdEscape($stnCode) . "`)\n"
    . "📍 State: " . mdEscape($stnState) . "\n"
    . "🔀 Zone: " . mdEscape($stnZone);

if ($stnDivision !== '') {
    $reply .= "\n🏢 Division: " . mdEscape($stnDivision);
}

$reply .= "\n\n🔗 [View & Download UTS QR Code](" . $portalUrl . ")";

sendTelegramMessage($apiUrl, $chatId, $reply, 'Markdown');

http_response_code(200);
echo 'OK';
exit;


// Fetch JSON from the InfinityFree API.
function httpGetJson(string $url): ?array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        return null;
    }

    $data = json_decode($response, true);

    return is_array($data) ? $data : null;
}


// Send a message through the Telegram Bot API.
function sendTelegramMessage(
    string $apiUrl,
    int|string $chatId,
    string $text,
    ?string $parseMode = null
): void {
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => false,
    ];

    if ($parseMode !== null) {
        $data['parse_mode'] = $parseMode;
    }

    $ch = curl_init($apiUrl . 'sendMessage');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);

    curl_exec($ch);
    curl_close($ch);
}


// Escape Markdown special characters in station fields.
function mdEscape(string $value): string
{
    return str_replace(
        ['\\', '_', '*', '`', '['],
        ['\\\\', '\\_', '\\*', '\\`', '\\['],
        $value
    );
}
