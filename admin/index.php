<?php
declare(strict_types=1);

require dirname(__DIR__) . '/base.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$config = atrio_config();
$hash = $config['admin_password_hash'] ?? '';
$ready = is_string($hash) && password_get_info($hash)['algo'] !== null && atrio_secure($config);
$error = '';
$signedIn = false;
$content = [];
$groups = [];
$photoTargets = [
    'hero' => ['hero'],
    'office' => ['office'],
    'team0' => ['team', 0],
    'team1' => ['team', 1],
];

function save_content(array $content, array $config, string $revision): void
{
    $guard = @fopen($config['private_dir'] . '/atrio-edicao.lock', 'c');
    if (!$guard) {
        throw new RuntimeException('A pasta privada não permite gravação.');
    }
    $temporary = null;
    $uploads = [];
    $saved = false;
    try {
        if (!flock($guard, LOCK_EX) || !hash_equals(hash_file('sha256', ATRIO_CONTENT), $revision)) {
            throw new RuntimeException('O conteúdo mudou em outra sessão. Recarregue o painel e reaplique suas alterações.');
        }
        global $photoTargets;
        foreach ($photoTargets as $field => $path) {
            $file = $_FILES[$field] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $info = $file['error'] === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? '';
            if (!$ext || $file['size'] > 5 * 1024 * 1024 || max($info[0], $info[1]) > 5000) {
                throw new RuntimeException('Use fotos JPG, PNG ou WebP de até 5 MB e 5.000 pixels por lado.');
            }
            $relative = 'assets/midia/' . bin2hex(random_bytes(12)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], ATRIO_ROOT . '/' . $relative)) {
                throw new RuntimeException('A hospedagem não conseguiu salvar a imagem.');
            }
            $uploads[] = $relative;
            $item =& $content;
            foreach ($path as $part) {
                $item =& $item[$part];
            }
            $item['image'] = $relative;
            unset($item);
        }
        $payload = 'window.ATRIO = ' . json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_THROW_ON_ERROR) . ";\n";
        $temporary = ATRIO_ROOT . '/assets/.edicao-' . bin2hex(random_bytes(12));
        if (file_put_contents($temporary, $payload, LOCK_EX) !== strlen($payload) || !rename($temporary, ATRIO_CONTENT)) {
            throw new RuntimeException('A hospedagem não conseguiu gravar o conteúdo.');
        }
        $saved = true;
    } finally {
        if ($temporary && is_file($temporary)) {
            unlink($temporary);
        }
        if (!$saved) {
            foreach ($uploads as $relative) {
                unlink(ATRIO_ROOT . '/' . $relative);
            }
        }
        fclose($guard);
    }
}

function leaf(array $content, string $key): string
{
    foreach (explode('.', $key) as $part) {
        $content = $content[$part];
    }
    return $content;
}

function update_leaf(array &$content, string $key, string $value): void
{
    foreach (explode('.', $key) as $part) {
        $content =& $content[$part];
    }
    $content = $value;
}

function field_value(array $submitted, string $key, string $label, int $length): string
{
    $value = $submitted[$key] ?? null;
    if (!is_string($value) || !mb_check_encoding($value) || mb_strlen(trim($value)) > $length) {
        throw new RuntimeException('Confira o campo ' . $label . '.');
    }
    return trim($value);
}

function allowed_social(string $url, array $hosts): bool
{
    if ($url === '') {
        return true;
    }
    $parts = parse_url($url);
    return filter_var($url, FILTER_VALIDATE_URL) !== false
        && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower($parts['host'] ?? ''), $hosts, true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && trim($parts['path'] ?? '', '/') !== '';
}

if (!$ready) {
    http_response_code(503);
} else {
    ini_set('session.use_strict_mode', '1');
    session_name('ATRIOADMIN');
    session_set_cookie_params([
        'path' => rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/',
        'secure' => PHP_SAPI !== 'cli-server' || !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    $fingerprint = hash('sha256', $hash);
    $signedIn = ($_SESSION['identity'] ?? '') === $fingerprint && ($_SESSION['expires'] ?? 0) > time();

    if ($signedIn) {
        $_SESSION['expires'] = time() + 1800;
        $groups = [
            'Identidade e contatos' => [
                ['brand', 'Nome do escritório', 55], ['signature', 'Complemento do nome', 65],
                ['location', 'Cidade no cabeçalho', 65], ['email', 'E-mail público', 160],
                ['phone', 'Telefone com DDD', 40], ['whatsapp', 'WhatsApp com DDI e DDD', 18],
                ['instagram', 'URL do Instagram', 240], ['linkedin', 'URL do LinkedIn', 240],
                ['address', 'Endereço completo', 180], ['hours', 'Horário de atendimento', 100],
            ],
            'Página inicial' => [
                ['hero.tag', 'Chamada acima do título', 70], ['hero.line1', 'Primeira linha do título', 45],
                ['hero.line2', 'Segunda linha do título', 45], ['hero.text', 'Texto de abertura', 220],
                ['hero.alt', 'Descrição acessível da foto', 160], ['hero.caption', 'Legenda da foto', 65],
            ],
            'O escritório' => [
                ['office.title', 'Título de apresentação', 100], ['office.intro', 'Apresentação breve', 360],
                ['office.text', 'Complemento da apresentação', 650], ['office.alt', 'Descrição acessível da foto', 160],
                ['office.caption', 'Legenda da foto', 65],
            ],
        ];
        try {
            $content = atrio_content();
            $revision = hash_file('sha256', ATRIO_CONTENT);
            foreach ($content['office']['methods'] as $index => $_method) {
                $groups['Método de trabalho'][] = ["office.methods.$index.title", 'Etapa ' . ($index + 1), 65];
                $groups['Método de trabalho'][] = ["office.methods.$index.text", 'Descrição da etapa ' . ($index + 1), 260];
            }
            foreach ($content['areas'] as $index => $_area) {
                $groups['Área ' . ($index + 1)] = [
                    ["areas.$index.title", 'Nome da área', 75],
                    ["areas.$index.intro", 'Resumo', 260],
                    ["areas.$index.detail", 'Descrição', 650],
                ];
            }
            foreach ($content['team'] as $index => $_member) {
                $groups['Profissional ' . ($index + 1)] = [
                    ["team.$index.name", 'Nome completo', 95],
                    ["team.$index.role", 'Cargo e atuação', 115],
                    ["team.$index.registration", 'Inscrição na OAB', 65],
                    ["team.$index.bio", 'Biografia', 650],
                ];
            }
            $groups['Privacidade'] = [
                ['privacy.title', 'Título do aviso', 100],
                ['privacy.text', 'Texto do aviso', 1800],
            ];
        } catch (Throwable $failure) {
            $error = 'Não foi possível ler o conteúdo do site.';
            $content = [];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = atrio_post('action');
        if (!hash_equals($_SESSION['csrf'], atrio_post('csrf'))) {
            $error = 'A sessão expirou. Atualize a página e tente novamente.';
        } elseif ($action === 'login' && !$signedIn) {
            $password = $_POST['password'] ?? null;
            if (!atrio_limit($config, 'login', 8, 900)) {
                $error = 'Aguarde alguns minutos antes de tentar novamente.';
            } elseif (is_string($password) && strlen($password) <= 256 && password_verify($password, $hash)) {
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'identity' => $fingerprint, 'expires' => time() + 1800];
                header('Location: index.php', true, 303);
                exit;
            } else {
                $error = 'Senha incorreta.';
            }
        } elseif ($signedIn && $action === 'logout') {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php', true, 303);
            exit;
        } elseif ($signedIn && $content && $action === 'save') {
            $submitted = $_POST['fields'] ?? null;
            try {
                if (!is_array($submitted)) {
                    throw new RuntimeException('Nenhum campo foi recebido.');
                }
                $next = $content;
                foreach ($groups as $fields) {
                    foreach ($fields as [$key, $label, $length]) {
                        update_leaf($next, $key, field_value($submitted, $key, $label, $length));
                    }
                }
                $next['demo'] = atrio_post('demo') === 'sim';
                if ($next['brand'] === '' || $next['hero']['line1'] === ''
                    || !filter_var($next['email'], FILTER_VALIDATE_EMAIL)
                    || (!$next['demo'] && str_ends_with(strtolower($next['email']), '.example'))) {
                    throw new RuntimeException('Preencha o nome do escritório, o título inicial e um e-mail válido para o modo escolhido.');
                }
                if ($next['whatsapp'] !== '' && !preg_match('/^[1-9][0-9]{9,14}$/D', $next['whatsapp'])) {
                    throw new RuntimeException('No WhatsApp, use apenas números com DDI e DDD.');
                }
                if (!allowed_social($next['instagram'], ['instagram.com', 'www.instagram.com'])
                    || !allowed_social($next['linkedin'], ['linkedin.com', 'www.linkedin.com', 'br.linkedin.com'])) {
                    throw new RuntimeException('Use o endereço HTTPS do perfil no Instagram ou LinkedIn.');
                }
                save_content($next, $config, atrio_post('revision'));
                header('Location: index.php?salvo=1', true, 303);
                exit;
            } catch (RuntimeException $failure) {
                $error = $failure->getMessage();
                $content = $next ?? $content;
            } catch (Throwable $failure) {
                error_log('Átrio: falha ao salvar alterações do painel.');
                $error = 'Não foi possível salvar as alterações.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Painel do escritório</title>
<link rel="stylesheet" href="../assets/site.css">
<link rel="stylesheet" href="painel.css">
</head>
<body>
<main class="shell admin">
<header class="admin-header">
<div><span class="eyebrow">Administração</span><h1>Painel do escritório</h1></div>
<a class="text-link" href="../index.html">Ver site ↗</a>
</header>
<?php if (!$ready): ?>
<p>O painel estará disponível após a configuração da hospedagem e do acesso por HTTPS.</p>
<?php else: ?>
<?php if ($error): ?><p class="notice" role="alert"><?= atrio_escape($error) ?></p><?php endif ?>
<?php if (!$signedIn): ?>
<form method="post" class="login">
<input type="hidden" name="csrf" value="<?= atrio_escape($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="login">
<label>Senha de administração<input type="password" name="password" required autocomplete="current-password" maxlength="256"></label>
<button class="button button-dark" type="submit">Entrar</button>
</form>
<?php elseif ($content): ?>
<div class="admin-tools">
<p>Edite os campos abaixo e salve ao final. Mantenha uma cópia do conteúdo e das imagens.</p>
<form method="post">
<input type="hidden" name="csrf" value="<?= atrio_escape($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="logout">
<button class="button button-outline" type="submit">Sair</button>
</form>
</div>
<?php if (isset($_GET['salvo']) && !$error): ?><p class="notice" role="status">Alterações salvas. Atualize o site para conferir.</p><?php endif ?>
<form method="post" enctype="multipart/form-data" class="editor">
<input type="hidden" name="csrf" value="<?= atrio_escape($_SESSION['csrf']) ?>">
<input type="hidden" name="revision" value="<?= atrio_escape($revision) ?>">
<input type="hidden" name="action" value="save">
<label class="mode"><input type="checkbox" name="demo" value="sim" <?= $content['demo'] ? 'checked' : '' ?>> Modo demonstrativo</label>
<?php foreach ($groups as $group => $fields): ?>
<details <?= $group === 'Identidade e contatos' ? 'open' : '' ?>>
<summary><?= atrio_escape($group) ?></summary>
<div class="edit-fields">
<?php foreach ($fields as [$key, $label, $length]): ?>
<label><?= atrio_escape($label) ?>
<?php if ($length > 250): ?>
<textarea name="fields[<?= atrio_escape($key) ?>]" maxlength="<?= $length ?>" rows="3"><?= atrio_escape(leaf($content, $key)) ?></textarea>
<?php else: ?>
<input type="text" name="fields[<?= atrio_escape($key) ?>]" value="<?= atrio_escape(leaf($content, $key)) ?>" maxlength="<?= $length ?>">
<?php endif ?>
</label>
<?php endforeach ?>
</div>
</details>
<?php endforeach ?>
<details>
<summary>Imagens</summary>
<p>JPG, PNG ou WebP de até 5 MB. Deixe em branco para manter a imagem atual.</p>
<div class="edit-fields">
<?php foreach (['hero' => 'Foto de abertura', 'office' => 'Foto do escritório', 'team0' => 'Foto do profissional 1', 'team1' => 'Foto do profissional 2'] as $field => $label): ?>
<label><?= $label ?><input type="file" name="<?= $field ?>" accept=".jpg,.jpeg,.png,.webp"></label>
<?php endforeach ?>
</div>
</details>
<button class="button button-dark" type="submit">Salvar alterações</button>
</form>
<?php endif ?>
<?php endif ?>
</main>
</body>
</html>
