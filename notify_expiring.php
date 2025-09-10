<?php
declare(strict_types=1);

// notify_expiring.php
require __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client as HttpClient;

const TELEGRAM_TOKEN  = '8431191065:AAHDK7IrJlpwiMT0JuD7tw_EIUx5CFmnTno'; // ex: 123456:ABC-DEF...
const DB_PATH = __DIR__ . '/data/bot.db';

date_default_timezone_set('America/Sao_Paulo');

function tgApi(string $method, array $params = []): array {
    $client = new HttpClient(['base_uri' => "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/"]);
    $resp = $client->post($method, ['json' => $params]);
    return json_decode((string)$resp->getBody(), true) ?? [];
}

$db = new SQLite3(DB_PATH);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$target = (new DateTimeImmutable('today +30 days'))->format('Y-m-d');

// Pegue itens com expires_on = hoje+30 e ainda não notificados
$stm = $db->prepare('SELECT id, chat_id, tg_file_id, confirmed_option, expires_on 
                     FROM confirmations 
                     WHERE expires_on = :target AND notified_30d = 0 AND confirmed_option IS NOT NULL');

$stm = $db->prepare('SELECT * FROM confirmations ');
$stm->bindValue(':target', $target, SQLITE3_TEXT);
$res = $stm->execute();

while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
    var_dump($row);
    $chatId = $row['chat_id'];
    $fileId = $row['tg_file_id'];
    $label  = $row['confirmed_option'];
    $iso    = $row['expires_on'];

    $caption = "⏰ *Aviso de validade*\nProduto vence em 30 dias: *{$label}* (ISO: `$iso`).";
    // Enviar a foto original do Telegram
    tgApi('sendPhoto', [
        'chat_id' => $chatId,
        'photo'   => $fileId,  // reusa o file_id
        'caption' => $caption,
        'parse_mode' => 'Markdown'
    ]);

    $upd = $db->prepare('UPDATE confirmations SET notified_30d = 1 WHERE id = :id');
    $upd->bindValue(':id', (int)$row['id'], SQLITE3_INTEGER);
    $upd->execute();
}

echo "done\n";
