<?php
// htdocs/telegram_bot.php
require_once __DIR__ . '/db.php';

// REPLACE WITH YOUR TELEGRAM BOT TOKEN FROM BOTFATHER
$botToken = '';
$apiUrl = "https://api.telegram.org/bot{$botToken}/";

// Read incoming Telegram webhook update
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update || !isset($update['message'])) {
    exit;
}

$chatId = $update['message']['chat']['id'];
$text = trim($update['message']['text'] ?? '');

if (empty($text)) {
    exit;
}

// Handle /start command
if (str_starts_with($text, '/start')) {
    $welcome = "🚆 *Welcome to Indian Railways UTS QR Bot!*\n\n"
             . "Send me any **Station Code** (e.g., `MAS`, `KLT`, `TBM`) or **Station Name** to instantly receive its official UTS booking QR code link!";
    sendTelegramMessage($apiUrl, $chatId, $welcome);
    exit;
}

// Clean query input
$query = trim(preg_replace('/^\/station\s+/i', '', $text));
$upperQuery = strtoupper($query);
$likeName = "%{$query}%";

// Search station in database
$stmt = $pdo->prepare("
    SELECT * FROM railway_stations 
    WHERE code = ? 
       OR UPPER(name) = ? 
       OR name LIKE ? 
    ORDER BY (code = ?) DESC 
    LIMIT 1
");
$stmt->execute([$upperQuery, strtoupper($query), $likeName, $upperQuery]);
$station = $stmt->fetch();

if ($station) {
    $stnCode = $station['code'];
    $stnName = $station['name'];
    $stnZone = $station['zone'] ?? 'IR';
    $stnState = $station['state'] ?? 'India';
    
    // Direct link to your hosted station portal page
    $portalUrl = "https://railwaystationqrcode.infinityfree.me/station.php?code=" . urlencode($stnCode);

    $reply = "✅ *Station Found!*\n\n"
           . "🚉 *{$stnName}* (`{$stnCode}`)\n"
           . "📍 State: {$stnState}\n"
           . "🔀 Zone: {$stnZone}\n\n"
           . "🔗 [View & Download UTS QR Code]({$portalUrl})";
} else {
    $reply = "❌ Station *'{$query}'* not found in our database. Please check the station code or name and try again.";
}

sendTelegramMessage($apiUrl, $chatId, $reply);

function sendTelegramMessage($apiUrl, $chatId, $text) {
    $url = $apiUrl . "sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => false
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}
?>
