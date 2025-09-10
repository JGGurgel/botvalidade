<?php
declare(strict_types=1);

// notify_expiring.php
require __DIR__ . '/vendor/autoload.php';
use GuzzleHttp\Client as HttpClient;

const TELEGRAM_TOKEN  = '8431191065:AAHDK7IrJlpwiMT0JuD7tw_EIUx5CFmnTno'; // ex: 123456:ABC-DEF...

function pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dbName = getenv('DB_NAME');
    $dbUser = getenv('DB_USER');
    $dbPass = getenv('DB_PASS');
    $conn   = getenv('CLOUDSQL_CONNECTION_NAME');

    $dsn = sprintf('mysql:unix_socket=/cloudsql/%s;dbname=%s;charset=utf8mb4', $conn, $dbName);
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Cria a tabela se não existir (equivalente ao seu SQLite)
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS confirmations (
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  chat_id VARCHAR(64) NOT NULL,
  user_id BIGINT NULL,
  username VARCHAR(128) NULL,
  tg_file_id VARCHAR(256) NOT NULL,
  gcs_uri VARCHAR(512) NULL,
  ocr_text MEDIUMTEXT NULL,
  options_json JSON NULL,
  confirmed_option VARCHAR(64) NULL,
  expires_on DATE NULL,
  confirmed_at DATETIME NULL,
  notified_30d TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_expires_notified (expires_on, notified_30d)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);

    return $pdo;
}

date_default_timezone_set('America/Sao_Paulo');

function tgApi(string $method, array $params = []): array {
    $client = new HttpClient(['base_uri' => "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/"]);
    $resp = $client->post($method, ['json' => $params]);
    return json_decode((string)$resp->getBody(), true) ?? [];
}

$db = new SQLite3(DB_PATH);
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$target = (new DateTimeImmutable('today +30 days', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');

$q = pdo()->prepare('SELECT id, chat_id, tg_file_id, confirmed_option, expires_on
                     FROM confirmations
                     WHERE expires_on = ? AND notified_30d = 0 AND confirmed_option IS NOT NULL');
$res = $q->execute([$target]);

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
