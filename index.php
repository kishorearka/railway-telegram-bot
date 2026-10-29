
<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Railway Station Telegram Bot
|--------------------------------------------------------------------------
| Required Render environment variable:
| BOT_TOKEN = your Telegram bot token
|
| Local data file:
| stations.json
|--------------------------------------------------------------------------
*/

header('Content-Type: text/plain; charset=utf-8');

$token = getenv('BOT_TOKEN') ?: '';

if ($token === '') {
    http_response_code(500);
    exit('BOT_TOKEN environment variable is missing.');
}

// Telegram sends updates as JSON.
$rawInput = file_get_contents('php://input');
$update = json_decode($rawInput ?: '', true);

// Allow a simple browser health check.
if (!is_array($update)) {
    echo "Railway Telegram bot is running.";
    exit;
}

$message = $update['message'] ?? null;

if (!is_array($message)) {
    echo 'OK';
    exit;
}

$chatId = $message['chat']['id'] ?? null;
$text = trim((string)($message['text'] ?? ''));

if ($chatId === null || $text === '') {
    echo 'OK';
    exit;
}

// Send a Telegram message.
function sendTelegramMessage(
    string $token,
    int|string $chatId,
    string $text
): void {
    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        error_log('Unable to encode Telegram message.');
        return;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log(
            'Telegram sendMessage failed. HTTP=' . $status .
            ' Error=' . $error .
            ' Response=' . (string)$response
        );
    }
}

// Load the local station database.
function loadStations(): array
{
    $file = __DIR__ . '/stations.json';

    if (!is_file($file)) {
        error_log('stations.json was not found: ' . $file);
        return [];
    }

    $json = file_get_contents($file);

    if ($json === false) {
        error_log('Unable to read stations.json.');
        return [];
    }

    $stations = json_decode($json, true);

    if (!is_array($stations)) {
        error_log('stations.json contains invalid JSON.');
        return [];
    }

    // Support either a plain list or {"stations": [...]}.
    if (isset($stations['stations']) && is_array($stations['stations'])) {
        $stations = $stations['stations'];
    }

    return $stations;
}

// Format a station record into a Telegram reply.
function formatStation(array $station): string
{
    $code = strtoupper(trim((string)($station['code'] ?? '')));
    $name = trim((string)($station['name'] ?? ''));
    $state = trim((string)($station['state'] ?? 'India'));
    $zone = trim((string)($station['zone'] ?? 'IR'));
    $division = trim((string)($station['division'] ?? ''));
    $utsPayload = trim((string)($station['uts_payload'] ?? ''));

    $reply = "🚉 Railway Station Details\n\n";
    $reply .= "Station Name: " . ($name !== '' ? $name : 'Not available') . "\n";
    $reply .= "Station Code: " . ($code !== '' ? $code : 'Not available') . "\n";
    $reply .= "State: " . ($state !== '' ? $state : 'India') . "\n";
    $reply .= "Zone: " . ($zone !== '' ? $zone : 'Not available') . "\n";

    if ($division !== '') {
        $reply .= "Division: {$division}\n";
    }

    if ($utsPayload !== '' && strtoupper($utsPayload) !== 'UNAVAILABLE') {
        $reply .= "UTS Payload: {$utsPayload}\n";
    }

    $reply .= "\nStation website:\n";
    $reply .= "https://railwaystationqrcode.infinityfree.me/station.php?code="
        . rawurlencode($code);

    return $reply;
}

// Handle /start and /help.
$command = strtolower($text);

if ($command === '/start' || str_starts_with($command, '/start ')) {
    $reply = "🚆 Welcome to Railway Station Bot!\n\n";
    $reply .= "Search for an Indian railway station using its code or name.\n\n";
    $reply .= "Examples:\n";
    $reply .= "/station MBM\n";
    $reply .= "/station Mambalam\n";
    $reply .= "/station Chennai Central\n\n";
    $reply .= "You can also send a station code or name directly.";
    sendTelegramMessage($token, $chatId, $reply);
    echo 'OK';
    exit;
}

if ($command === '/help') {
    $reply = "How to use this bot:\n\n";
    $reply .= "/station MBM\n";
    $reply .= "/station Mambalam\n";
    $reply .= "Or send a station code/name directly.";
    sendTelegramMessage($token, $chatId, $reply);
    echo 'OK';
    exit;
}

// Accept /station MBM and /station@YourBot MBM.
$query = preg_replace('/^\/station(?:@[A-Za-z0-9_]+)?\s*/i', '', $text);
$query = trim((string)$query);

// Ignore other slash commands.
if (str_starts_with($text, '/') &&
    !preg_match('/^\/station(?:@[A-Za-z0-9_]+)?(?:\s|$)/i', $text)) {
    sendTelegramMessage(
        $token,
        $chatId,
        "Unknown command.\nUse /start for instructions."
    );
    echo 'OK';
    exit;
}

if ($query === '') {
    sendTelegramMessage(
        $token,
        $chatId,
        "Please enter a station code or name.\nExample: /station MBM"
    );
    echo 'OK';
    exit;
}

$stations = loadStations();

if ($stations === []) {
    sendTelegramMessage(
        $token,
        $chatId,
        "The station database is unavailable. Please try again later."
    );
    echo 'OK';
    exit;
}

$queryUpper = strtoupper($query);
$matches = [];

// Prefer exact station-code matches, then exact names, then partial matches.
foreach ($stations as $station) {
    if (!is_array($station)) {
        continue;
    }

    $code = strtoupper(trim((string)($station['code'] ?? '')));
    $name = trim((string)($station['name'] ?? ''));

    if ($code !== '' && $code === $queryUpper) {
        $matches = [$station];
        break;
    }
}

if ($matches === []) {
    foreach ($stations as $station) {
        if (!is_array($station)) {
            continue;
        }

        $name = trim((string)($station['name'] ?? ''));

        if ($name !== '' && strcasecmp($name, $query) === 0) {
            $matches = [$station];
            break;
        }
    }
}

if ($matches === []) {
    foreach ($stations as $station) {
        if (!is_array($station)) {
            continue;
        }

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
}

if (count($matches) === 1) {
    sendTelegramMessage(
        $token,
        $chatId,
        formatStation($matches[0])
    );
} elseif (count($matches) > 1) {
    $reply = "🔎 Multiple stations found for: {$query}\n\n";

    foreach ($matches as $station) {
        $code = trim((string)($station['code'] ?? ''));
        $name = trim((string)($station['name'] ?? ''));

        $reply .= "• {$name} ({$code})\n";
        $reply .= "/station {$code}\n\n";
    }

    sendTelegramMessage($token, $chatId, trim($reply));
} else {
    sendTelegramMessage(
        $token,
        $chatId,
        "❌ No station found for: {$query}\n\n" .
        "Try another station code or name."
    );
}

echo 'OK';
