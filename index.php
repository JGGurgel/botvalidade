<?php
declare(strict_types=1);


// webhook.php
require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Storage\StorageClient;
use GuzzleHttp\Client as HttpClient;

// =====================
// CONFIG
// =====================
const TELEGRAM_TOKEN  = '8431191065:AAHDK7IrJlpwiMT0JuD7tw_EIUx5CFmnTno'; // ex: 123456:ABC-DEF...
const SECRET_REQUIRED = true;
const SECRET_VALUE    = 'um-segredo-aleatorio-32chars';

const GCS_BUCKET      = 'botvalidade'; // <- seu bucket
const DB_PATH         = __DIR__ . '/data/bot.db';
const MAX_DATE_OPTIONS = 8;


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
// =====================
// BOOTSTRAP DB
// =====================
if (!is_dir(dirname(DB_PATH))) {
    mkdir(dirname(DB_PATH), 0775, true);
}
$db = new SQLite3(DB_PATH);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS confirmations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id TEXT NOT NULL,
    user_id INTEGER,
    username TEXT,
    tg_file_id TEXT NOT NULL,
    gcs_uri TEXT,
    ocr_text TEXT,
    options_json TEXT,
    confirmed_option TEXT,
    expires_on TEXT,          -- YYYY-MM-DD
    confirmed_at TEXT,        -- ISO datetime
    notified_30d INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
');

// =====================
// HELPERS
// =====================
function validateSecret(): void {
    if (!SECRET_REQUIRED) return;
    $hdr = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(SECRET_VALUE, $hdr)) {
        http_response_code(401); echo 'unauthorized'; exit;
    }
}

function tgApi(string $method, array $params = []): array {
    $client = new HttpClient(['base_uri' => "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/"]);
    $resp = $client->post($method, ['json' => $params]);
    return json_decode((string)$resp->getBody(), true) ?? [];
}

function downloadTelegramFile(string $fileId): ?string {
    $meta = tgApi('getFile', ['file_id' => $fileId]);
    if (!($meta['ok'] ?? false)) return null;
    $path = $meta['result']['file_path'] ?? null;
    if (!$path) return null;
    $url = "https://api.telegram.org/file/bot" . TELEGRAM_TOKEN . "/" . $path;
    $client = new HttpClient();
    $resp = $client->get($url, ['http_errors' => false]);
    return $resp->getStatusCode() === 200 ? (string)$resp->getBody() : null;
}

function uploadToGcs(string $bytes, string $objectName): string {
    $storage = new StorageClient();
    $bucket  = $storage->bucket(GCS_BUCKET);
    $bucket->upload($bytes, [
        'name' => $objectName,
        // ACL privado; você pode gerar signed URLs quando precisar
        //'predefinedAcl' => 'private',
        'metadata' => ['contentType' => 'image/jpeg']
    ]);
    return "gs://" . GCS_BUCKET . "/" . $objectName;
}

function runVisionOcrOnBytes(string $bytes): string {
    $client = new ImageAnnotatorClient();
    try {
        $resp = $client->textDetection($bytes);
        $ann  = $resp->getTextAnnotations();
        return empty($ann) ? '' : ($ann[0]->getDescription() ?? '');
    } finally {
        $client->close();
    }
}

function extractDatesFromText(string $fullText): array {
    $text = preg_replace('/\s+/', ' ', $fullText);
    $patterns = [
        '/\b(20\d{2})[\/\.-](0?[1-9]|1[0-2])[\/\.-](0?[1-9]|[12]\d|3[01])\b/u', // YYYY-MM-DD
        '/\b(0?[1-9]|[12]\d|3[01])[\/\.-](0?[1-9]|1[0-2])[\/\.-](20\d{2})\b/u', // DD/MM/YYYY
        '/\b(0?[1-9]|[12]\d|3[01])[\/\.-](0?[1-9]|1[0-2])[\/\.-](\d{2})\b/u',   // DD/MM/YY
        '/\b(0?[1-9]|1[0-2])[\/\.-](20\d{2})\b/u',                              // MM/YYYY
        '/\b(0?[1-9]|1[0-2])[\/\.-](\d{2})\b/u',                                // MM/YY
    ];
    $found = [];
    foreach ($patterns as $rx) {
        if (preg_match_all($rx, $text, $m)) {
            foreach ($m[0] as $raw) $found[] = trim($raw);
        }
    }
    // Score por proximidade de palavras-chave
    $keywords = ['validade','val.','val ','venc','vence','exp','use by','best before','bb'];
    $scored = [];
    foreach ($found as $d) {
        $pos = mb_stripos($text, $d);
        $score = 0;
        if ($pos !== false) {
            $ctx = mb_strtolower(mb_substr($text, max(0,$pos-25), mb_strlen($d)+50));
            foreach ($keywords as $kw) if (mb_strpos($ctx, $kw) !== false) $score++;
        }
        $scored[] = ['date'=>$d,'score'=>$score];
    }
    usort($scored, fn($a,$b)=>$b['score']<=>$a['score']);
    $ordered = array_values(array_unique(array_map(fn($x)=>$x['date'],$scored)));
    if (count($ordered) > MAX_DATE_OPTIONS) $ordered = array_slice($ordered, 0, MAX_DATE_OPTIONS);
    return $ordered;
}

function normalizeToISO(string $raw): ?string {
    $raw = trim($raw);
    // YYYY-MM-DD or YYYY/MM/DD or YYYY.MM.DD
    if (preg_match('/^(20\d{2})[\/\.-](\d{1,2})[\/\.-](\d{1,2})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    // DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY
    if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](20\d{2})$/', $raw, $m)) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    // DD/MM/YY -> assume 20YY
    if (preg_match('/^(\d{1,2})[\/\.-](\d{1,2})[\/\.-](\d{2})$/', $raw, $m)) {
        return sprintf('20%02d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    // MM/YYYY -> usar último dia do mês
    if (preg_match('/^(\d{1,2})[\/\.-](20\d{2})$/', $raw, $m)) {
        $ym = sprintf('%04d-%02d', $m[2], $m[1]);
        $lastDay = (int)date('t', strtotime($ym . '-01'));
        return $ym . '-' . sprintf('%02d', $lastDay);
    }
    // MM/YY -> assume 20YY e último dia do mês
    if (preg_match('/^(\d{1,2})[\/\.-](\d{2})$/', $raw, $m)) {
        $y = (int)('20' . $m[2]); $mth = (int)$m[1];
        $lastDay = (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $mth)));
        return sprintf('%04d-%02d-%02d', $y, $mth, $lastDay);
    }
    return null;
}

function kbFromOptions(array $opts, int $cid): array {
    $rows = [];
    foreach ($opts as $i => $label) {
        $rows[] = [[
            'text' => $label,
            'callback_data' => 'c:'.$cid.':'.$i
        ]];
    }
    $rows[] = [[ 'text'=>'Nenhuma está correta','callback_data'=>'c:'.$cid.':none' ]];
    return ['inline_keyboard' => $rows];
}

// =====================
// MAIN
// =====================
date_default_timezone_set('America/Sao_Paulo');
validateSecret();

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) { http_response_code(200); echo 'ok'; exit; }

// 1) CALLBACK: confirmação
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? null;
    $from   = $cb['from'] ?? [];

    tgApi('answerCallbackQuery', ['callback_query_id'=>$cb['id']]);

    if (preg_match('/^c:(\d+):(none|\d+)$/', $data, $m) && $chatId) {
        $cid  = (int)$m[1];
        $opt  = $m[2];

        $row = $db->querySingle("SELECT id, options_json, tg_file_id FROM confirmations WHERE id=$cid", true);
        if (!$row) { tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Registro não encontrado."]); exit; }

        if ($opt === 'none') {
            tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"⚠️ Nenhuma data estava correta. Envie outra foto ou digite a data."]);
            exit;
        }

        $options = json_decode($row['options_json'] ?? '[]', true) ?: [];
        $pickedLabel = $options[(int)$opt] ?? null;
        if (!$pickedLabel) {
            tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Opção inválida."]); exit;
        }

        $iso = normalizeToISO($pickedLabel);
        $nowIso = date('c');
        $uname = $from['username'] ?? ($from['first_name'] ?? 'Usuário');
        $uid   = $from['id'] ?? null;

        // ao confirmar
        $stmt = pdo()->prepare('UPDATE confirmations
           SET user_id=?, username=?, confirmed_option=?, expires_on=?, confirmed_at=NOW()
         WHERE id=?');

        $stmt->execute([$uid, $uname, $pickedLabel, $iso, $cid]);

        $msg = $iso ? "✅ $uname confirmou a validade: *{$pickedLabel}* (ISO: `$iso`)" 
                    : "✅ $uname confirmou a validade: *{$pickedLabel}*";
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>$msg, 'parse_mode'=>'Markdown']);
    }
    echo 'ok'; exit;
}

// 2) MENSAGEM COM FOTO
if (isset($update['message']['photo'])) {
    $msg    = $update['message'];
    $chatId = $msg['chat']['id'];
    $photos = $msg['photo'];
    $largest = end($photos);
    $fileId = $largest['file_id'] ?? null;

    if (!$fileId) {
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Não consegui pegar o arquivo."]);
        echo 'ok'; exit;
    }

    // baixa bytes
    $bytes = downloadTelegramFile($fileId);
    if ($bytes === null) {
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Erro ao baixar a imagem."]);
        echo 'ok'; exit;
    }

    // sobe no GCS
    $objectName = 'uploads/' . date('Y/m/d/') . $fileId . '.jpg';
    try {
        $gcsUri = uploadToGcs($bytes, $objectName);
    } catch (Throwable $e) {
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Falha ao salvar no GCS: ".$e->getMessage()]);
        echo 'ok'; exit;
    }

    // OCR
    try {
        $full = runVisionOcrOnBytes($bytes);
    } catch (Throwable $e) {
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Erro no OCR: ".$e->getMessage()]);
        echo 'ok'; exit;
    }

    // opções de data
    $options = extractDatesFromText($full);
    if (empty($options)) {
        tgApi('sendMessage', ['chat_id'=>$chatId, 'text'=>"Não encontrei datas. Tente aproximar a câmera e melhorar a iluminação."]);
        echo 'ok'; exit;
    }

    // cria registro PENDENTE e mostra teclado
    $stmt = pdo()->prepare('INSERT INTO confirmations (chat_id, tg_file_id, gcs_uri, ocr_text, options_json)
                            VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([
      (string)$chatId,
      $fileId,
      $gcsUri,
      $fullText,
      json_encode($options, JSON_UNESCAPED_UNICODE)
    ]);
    $cid = (int)pdo()->lastInsertId();


    $kb = kbFromOptions($options, $cid);
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text'    => "Encontrei as seguintes datas de validade. Qual está correta?",
        'reply_markup' => $kb
    ]);

    echo 'ok'; exit;
}

// fallback
if (isset($update['message']['text'])) {
    $chatId = $update['message']['chat']['id'];
    tgApi('sendMessage', [
        'chat_id'=>$chatId,
        'text'=>"Envie uma *foto* da data de validade; vou salvar no GCS, extrair as datas e pedir a confirmação.",
        'parse_mode'=>'Markdown'
    ]);
}

echo 'ok';
