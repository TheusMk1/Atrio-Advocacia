<?php
declare(strict_types=1);

const ATRIO_ROOT = __DIR__;
const ATRIO_CONTENT = ATRIO_ROOT . '/assets/conteudo.js';

function atrio_config(): array
{
    $path = getenv('ATRIO_CONFIG_PATH') ?: dirname(ATRIO_ROOT) . '/atrio-config.php';
    $path = realpath($path);
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? ATRIO_ROOT) ?: ATRIO_ROOT;
    if (!$path || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $private = strtolower(str_replace('\\', '/', $path));
    $public = rtrim(strtolower(str_replace('\\', '/', $root)), '/');
    if (str_starts_with($private, $public . '/')) {
        return [];
    }
    $config = require $path;
    return is_array($config) ? $config + ['private_dir' => dirname($path)] : [];
}

function atrio_secure(array $config): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return in_array($https, ['on', '1'], true)
        || (($config['local_test'] ?? false) === true
            && PHP_SAPI === 'cli-server'
            && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true));
}

function atrio_content(): array
{
    $raw = file_get_contents(ATRIO_CONTENT);
    if (!is_string($raw) || !preg_match('/\Awindow\.ATRIO = (\{.*\});\s*\z/s', $raw, $parts)) {
        throw new RuntimeException('Arquivo de conteúdo inválido.');
    }
    return json_decode($parts[1], true, 512, JSON_THROW_ON_ERROR);
}

function atrio_post(string $name, array $source = []): string
{
    $value = ($source ?: $_POST)[$name] ?? '';
    return is_string($value) ? trim($value) : '';
}

function atrio_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function atrio_limit(array $config, string $bucket, int $max, int $window): bool
{
    $dir = $config['private_dir'];
    $file = @fopen($dir . '/atrio-' . $bucket . '-limite.json', 'c+');
    if (!$file) {
        return false;
    }
    try {
        if (!flock($file, LOCK_EX)) {
            return false;
        }
        $now = time();
        $entries = json_decode(stream_get_contents($file), true) ?: [];
        $entries = array_filter($entries, static fn ($entry) => ($entry['until'] ?? 0) > $now);
        $key = hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', $config['admin_password_hash'] ?? $dir);
        $entry = $entries[$key] ?? ['count' => 0, 'until' => $now + $window];
        if ($entry['count'] >= $max || (!isset($entries[$key]) && count($entries) >= 4096)) {
            return false;
        }
        $entry['count']++;
        $entries[$key] = $entry;
        $payload = json_encode($entries, JSON_THROW_ON_ERROR);
        return rewind($file) && ftruncate($file, 0) && fwrite($file, $payload) === strlen($payload) && fflush($file);
    } finally {
        fclose($file);
    }
}
