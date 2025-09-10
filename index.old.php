<?php

declare(strict_types=1);

// webhook.php
// Recebe updates do Telegram, baixa a foto, roda Google Vision OCR, extrai datas,
// responde com teclado inline para confirmação e registra a escolha do usuário.

require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use GuzzleHttp\Client as HttpClient;
use Google\Cloud\Storage\StorageClient;

// =====================
// CONFIG
// =====================
const TELEGRAM_TOKEN  = '8431191065:AAHDK7IrJlpwiMT0JuD7tw_EIUx5CFmnTno'; // ex: 123456:ABC-DEF...
const SECRET_REQUIRED = true;                      // valide o secret token do webhook
const SECRET_VALUE    = 'um-segredo-aleatorio-32chars';

// Limite de opções de datas para não poluir o teclado
const MAX_DATE_OPTIONS = 8;


echo "Enviado: gs://seu-bucket/{$destino}\n";

// =====================
// FUNÇÕES AUXILIARES
// =====================

function tgApi(string $method, array $params = []): array {
    $client = new HttpClient(['base_uri' => "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/"]);
    $resp = $client->post($method, ['json' => $params]);
    $json = json_decode((string)$resp->getBody(), true);
    return is_array($json) ? $json : [];
}

function downloadTelegramFile(string $fileId): ?string {
    // 1) Descobrir file_path
    $fileData = tgApi('getFile', ['file_id' => $fileId]);
    if (!($fileData['ok'] ?? false)) return null;

    $path = $fileData['result']['file_path'] ?? null;
    if (!$path) return null;

    $url = "https://api.telegram.org/file/bot" . TELEGRAM_TOKEN . "/" . $path;

    $client = new HttpClient();
    $resp = $client->get($url, ['http_errors' => false]);
    if ($resp->getStatusCode() !== 200) return null;

    return (string)$resp->getBody(); // bytes da imagem
}

function extractDatesFromText(string $fullText): array {
    // Normaliza whitespace
    $text = preg_replace('/\s+/', ' ', $fullText);

    // Padrões comuns no BR e ISO
    $patterns = [
        // AAAA-MM-DD ou AAAA/MM/DD
        '/\b(20\d{2})[\/\.-](0?[1-9]|1[0-2])[\/\.-](0?[1-9]|[12]\d|3[01])\b/u',
        // DD/MM/AAAA ou DD-MM-AAAA ou DD.MM.AAAA
        '/\b(0?[1-9]|[12]\d|3[01])[\/\.-](0?[1-9]|1[0-2])[\/\.-](20\d{2})\b/u',
        // DD/MM/AA ou DD-MM-AA
        '/\b(0?[1-9]|[12]\d|3[01])[\/\.-](0?[1-9]|1[0-2])[\/\.-](\d{2})\b/u',
        // MM/AAAA ou MM-AAAA
        '/\b(0?[1-9]|1[0-2])[\/\.-](20\d{2})\b/u',
        // MM/AA
        '/\b(0?[1-9]|1[0-2])[\/\.-](\d{2})\b/u',
    ];

    $found = [];
    foreach ($patterns as $rx) {
        if (preg_match_all($rx, $text, $m)) {
            foreach ($m[0] as $raw) {
                $found[] = trim($raw);
            }
        }
    }

    // Heurística: proximidade de palavras-chave de validade
    $keywords = ['validade', 'val.', 'val ', 'venc', 'vence', 'exp', 'use by', 'best before', 'bb'];
    $scored = [];
    foreach ($found as $d) {
        $pos = mb_stripos($text, $d);
        $score = 0;
        if ($pos !== false) {
            $window = 25;
            $start = max(0, $pos - $window);
            $ctx = mb_strtolower(mb_substr($text, $start, mb_strlen($d) + 2*$window));
            foreach ($keywords as $kw) {
                if (mb_strpos($ctx, $kw) !== false) {
                    $score++;
                }
            }
        }
        $scored[] = ['date' => $d, 'score' => $score];
    }

    // Ordena por score (desc) e tira duplicatas
    usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
    $ordered = array_values(array_unique(array_map(fn($x) => $x['date'], $scored)));

    // Limita quantidade
    if (count($ordered) > MAX_DATE_OPTIONS) {
        $ordered = array_slice($ordered, 0, MAX_DATE_OPTIONS);
    }

    return $ordered;
}

function runVisionOcrOnBytes(string $imageBytes): string {
    $client = new ImageAnnotatorClient();
    try {
        $resp = $client->textDetection($imageBytes);
        $annotation = $resp->getTextAnnotations();
        if (empty($annotation)) return '';
        // O primeiro item = texto completo
        return $annotation[0]->getDescription() ?? '';
    } finally {
        $client->close();
    }
}

function buildInlineKeyboardFromDates(array $dates): array {
    $rows = [];
    foreach ($dates as $d) {
        // callback_data tem limite de 64 bytes. Use índice para segurança.
        // Mas aqui vamos usar direto se couber. Se quiser robusto, use hash/índice.
        $data = 'confirm:' . mb_substr($d, 0, 50);
        $rows[] = [ ['text' => $d, 'callback_data' => $data] ];
    }
    // Botão "Nenhuma está correta"
    $rows[] = [ ['text' => 'Nenhuma está correta', 'callback_data' => 'confirm:none'] ];
    return ['inline_keyboard' => $rows];
}

function validateSecret(): void {
    if (!SECRET_REQUIRED) return;
    $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!hash_equals(SECRET_VALUE, $header)) {
        http_response_code(401);
        echo 'unauthorized';
        exit;
    }
}

// =====================
// HANDLER PRINCIPAL
// =====================

validateSecret();

// Leia o update do Telegram
$raw = file_get_contents('php://input');
$update = json_decode($raw, true);

if (!$update) {
    http_response_code(200);
    echo 'ok';
    exit;
}

// 1) Caso: callback de clique nos botões
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $data = $cb['data'] ?? '';
    $from = $cb['from'] ?? [];
    $chatId = $cb['message']['chat']['id'] ?? null;
    $messageId = $cb['message']['message_id'] ?? null;

    // Confirma visualmente o clique (toast do Telegram)
    tgApi('answerCallbackQuery', [
        'callback_query_id' => $cb['id'],
        'text' => 'Recebido!',
        'show_alert' => false
    ]);

    if ($chatId && $messageId && str_starts_with($data, 'confirm:')) {
        $choice = substr($data, strlen('confirm:'));
        $username = $from['username'] ?? ($from['first_name'] ?? 'Usuário');

        if ($choice === 'none') {
            tgApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => "⚠️ $username indicou que nenhuma data estava correta.\nEnvie uma nova foto (de perto, com boa iluminação) ou digite a data manualmente."
            ]);
        } else {
            // Aqui você pode persistir no seu banco a confirmação
            tgApi('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ $username confirmou a validade: *{$choice}*",
                'parse_mode' => 'Markdown'
            ]);
        }
    }

    http_response_code(200);
    echo 'ok';
    exit;
}

// 2) Caso: mensagem com foto (em grupos, lembre de desabilitar privacy no BotFather)
if (isset($update['message']['photo'])) {
    $msg   = $update['message'];
    $chatId = $msg['chat']['id'];

    // Pega a maior resolução da lista (último item)
    $photos = $msg['photo'];
    $largest = end($photos);
    $fileId = $largest['file_id'] ?? null;

    if (!$fileId) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Não consegui pegar o arquivo da foto. Tente novamente."
        ]);
        http_response_code(200); echo 'ok'; exit;
    }

    // Baixa imagem
    $bytes = downloadTelegramFile($fileId);
    if ($bytes === null) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Houve um erro ao baixar a imagem. Tente novamente."
        ]);
        http_response_code(200); echo 'ok'; exit;
    }

    // OCR (Google Vision)
    try {
        $fullText = runVisionOcrOnBytes($bytes);
    } catch (Throwable $e) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Erro no OCR: " . $e->getMessage()
        ]);
        http_response_code(200); echo 'ok'; exit;
    }

    // Extrai datas prováveis
    $dates = extractDatesFromText($fullText);

    if (empty($dates)) {
        tgApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => "Não encontrei datas na imagem. Tente aproximar a câmera da data de validade e garantir boa iluminação."
        ]);
        http_response_code(200); echo 'ok'; exit;
    }

    // Monta teclado inline para confirmação
    $keyboard = buildInlineKeyboardFromDates($dates);

    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Encontrei as seguintes datas de validade. Qual está correta?",
        'reply_markup' => $keyboard
    ]);

    http_response_code(200);
    echo 'ok';
    exit;
}

// Mensagens que não são foto nem callback: ignore ou ajude
if (isset($update['message']['text'])) {
    $chatId = $update['message']['chat']['id'];
    tgApi('sendMessage', [
        'chat_id' => $chatId,
        'text' => "Envie uma *foto* da data de validade. Vou reconhecer o texto e te mostrar opções para confirmar.",
        'parse_mode' => 'Markdown'
    ]);
}

http_response_code(200);
echo 'ok';
