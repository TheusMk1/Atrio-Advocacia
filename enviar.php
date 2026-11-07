<?php
declare(strict_types=1);

require __DIR__ . '/base.php';

function reply(int $code, string $message): never
{
    http_response_code($code);
    header('Cache-Control: no-store');
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $code === 200, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Solicitação de contato</title><link rel="stylesheet" href="assets/site.css">'
        . '<main class="shell section"><h1>Solicitação de contato</h1><p>' . atrio_escape($message)
        . '</p><p><a class="text-link" href="contato.html#formulario">Voltar ao contato</a></p></main></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    reply(405, 'Use o formulário da página de contato.');
}

$config = atrio_config();
if (!$config || !atrio_secure($config)) {
    reply(503, 'O envio está indisponível. Use o e-mail do escritório.');
}

try {
    $content = atrio_content();
} catch (Throwable $error) {
    error_log('Átrio: falha ao carregar o conteúdo do formulário.');
    reply(503, 'O envio está indisponível. Use o e-mail do escritório.');
}
$to = filter_var($config['contact_to'] ?? '', FILTER_VALIDATE_EMAIL);
$from = filter_var($config['contact_from'] ?? '', FILTER_VALIDATE_EMAIL);
if (($content['demo'] ?? true) || !$to || !$from) {
    reply(503, 'O formulário ainda está em configuração.');
}
if (atrio_post('empresa') !== '') {
    reply(422, 'Revise os campos e tente novamente.');
}
$name = atrio_post('nome');
$email = atrio_post('email');
$phone = atrio_post('telefone');
$message = atrio_post('mensagem');
$area = atrio_post('area');
$areaName = match (true) {
    $area === '' => 'Não informada',
    $area === 'outra' => 'Outro assunto',
    ctype_digit($area) => $content['areas'][(int) $area - 1]['title'] ?? '',
    default => '',
};
if ($name === '' || mb_strlen($name) > 100 || strlen($email) > 160
    || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($phone) > 40
    || mb_strlen($message) < 10 || mb_strlen($message) > 2500
    || $areaName === '' || atrio_post('consentimento') !== 'sim') {
    reply(422, 'Revise os campos obrigatórios e confira seu e-mail.');
}
if (!atrio_limit($config, 'contato', 5, 900)) {
    reply(429, 'O limite temporário de envios foi atingido. Tente mais tarde ou use o e-mail do escritório.');
}

$body = "Nome: $name\r\nE-mail: $email\r\nTelefone: $phone\r\nÁrea: $areaName\r\n\r\nMensagem:\r\n$message";
$headers = [
    'From' => $from,
    'Reply-To' => $email,
    'MIME-Version' => '1.0',
    'Content-Type' => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => 'base64',
];
if (!@mail($to, 'Solicitacao pelo site', chunk_split(base64_encode($body)), $headers)) {
    error_log('Átrio: a hospedagem recusou a entrega do formulário.');
    reply(502, 'Não foi possível enviar agora. Use o e-mail do escritório.');
}
reply(200, 'Solicitação enviada. O escritório responderá pelos contatos informados.');
