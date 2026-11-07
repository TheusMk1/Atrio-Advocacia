<?php
declare(strict_types=1);

require __DIR__ . '/base.php';

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$route = str_ends_with($path, '/') ? 'index.html' : basename($path);
$pages = [
    'index.html' => '',
    'escritorio.html' => 'O escritório',
    'atuacao.html' => 'Áreas de atuação',
    'contato.html' => 'Contato',
];
if (!isset($pages[$route]) && $route !== 'sitemap.xml') {
    http_response_code(404);
    exit;
}
$config = atrio_config();

try {
    $content = atrio_content();
    $base = rtrim($config['site_url'] ?? '', '/');
    $indexable = !$content['demo']
        && str_starts_with($base, 'https://')
        && filter_var($base, FILTER_VALIDATE_URL)
        && strpbrk($base, '?#') === false;
    header('Cache-Control: no-cache');

    if ($route === 'sitemap.xml') {
        if (!$indexable) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($pages as $file => $_label) {
            $url = $base . '/' . ($file === 'index.html' ? '' : $file);
            echo '<url><loc>' . htmlspecialchars($url, ENT_XML1, 'UTF-8') . '</loc></url>';
        }
        echo '</urlset>';
        exit;
    }

    $html = file_get_contents(ATRIO_ROOT . '/' . $route);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    foreach (iterator_to_array($dom->childNodes) as $node) {
        if ($node->nodeType === XML_PI_NODE) {
            $dom->removeChild($node);
        }
    }
    $xpath = new DOMXPath($dom);
    $get = static function (string $key) use ($content) {
        $value = $content;
        foreach (explode('.', $key) as $part) {
            $value = $value[$part] ?? null;
        }
        return $value;
    };
    foreach ($xpath->query('//*[@data-text]') as $element) {
        $value = $get($element->getAttribute('data-text'));
        if (is_string($value)) {
            $element->textContent = $value;
        }
    }
    foreach ($xpath->query('//*[@data-image]') as $element) {
        $item = $get($element->getAttribute('data-image'));
        if (!empty($item['image'])) {
            $element->setAttribute('src', $item['image']);
            $element->setAttribute('alt', $item['alt'] ?? $item['name'] ?? '');
            $element->removeAttribute('hidden');
        }
    }
    foreach ($xpath->query('//*[@data-initials]') as $element) {
        $member = $content['team'][(int) $element->getAttribute('data-initials')];
        if ($member['image']) {
            $element->setAttribute('hidden', '');
        }
        $names = preg_split('/\s+/u', $member['name'], 3);
        $element->textContent = implode('', array_map(static fn ($word) => mb_substr($word, 0, 1), array_slice($names, 0, 2)));
    }
    $channels = [
        'email' => $content['demo'] ? '' : 'mailto:' . $content['email'],
        'phone' => $content['phone'] ? 'tel:' . preg_replace('/[^+0-9]/', '', $content['phone']) : '',
        'whatsapp' => $content['whatsapp'] ? 'https://wa.me/' . $content['whatsapp'] : '',
        'instagram' => $content['instagram'],
        'linkedin' => $content['linkedin'],
    ];
    foreach ($xpath->query('//*[@data-channel]') as $element) {
        $channel = $element->getAttribute('data-channel');
        $target = $channels[$channel] ?? '';
        if ($target !== '') {
            $element->setAttribute('href', $target);
            $element->removeAttribute('hidden');
            if (str_starts_with($target, 'https://')) {
                $element->setAttribute('target', '_blank');
                $element->setAttribute('rel', 'noopener noreferrer');
            }
        } elseif ($channel === 'email') {
            $element->removeAttribute('href');
        }
    }
    foreach ($xpath->query('//*[@data-social]') as $element) {
        if ($channels['instagram'] || $channels['linkedin']) {
            $element->removeAttribute('hidden');
        }
    }
    if (!$content['demo']) {
        foreach ($xpath->query('//*[@data-demo]') as $element) {
            $element->setAttribute('hidden', '');
        }
    }
    if ($channels['phone'] || $channels['whatsapp']) {
        $xpath->query('//*[@data-missing="phone"]')->item(0)?->setAttribute('hidden', '');
    }
    $tag = $xpath->query('//*[@data-hero-tag]')->item(0);
    if ($tag) {
        $tag->textContent = $content['hero']['tag'];
    }
    $folio = $xpath->query('//*[@data-folio]')->item(0);
    if ($folio) {
        $folio->textContent = '01 / ' . mb_strtoupper($content['brand'], 'UTF-8');
    }
    $title = trim($content['brand'] . ' ' . $content['signature']);
    $title = $route === 'index.html' ? $title . ' | ' . $content['hero']['tag'] : $pages[$route] . ' | ' . $title;
    $description = match ($route) {
        'index.html' => $content['hero']['text'],
        'escritorio.html' => $content['office']['intro'],
        'atuacao.html' => 'Áreas de atuação: ' . implode(', ', array_column($content['areas'], 'title')) . '.',
        'contato.html' => 'Canais de atendimento de ' . $content['brand'] . ' em ' . $content['location'] . '.',
    };
    $dom->getElementsByTagName('title')->item(0)->textContent = $title;
    $xpath->query('//meta[@name="description"]')->item(0)->setAttribute('content', $description);
    $xpath->query('//meta[@property="og:title"]')->item(0)->setAttribute('content', $title);
    $xpath->query('//meta[@property="og:description"]')->item(0)->setAttribute('content', $description);
    if ($indexable) {
        $xpath->query('//meta[@name="robots"]')->item(0)->setAttribute('content', 'index, follow');
        $canonical = $dom->createElement('link');
        $canonical->setAttribute('rel', 'canonical');
        $canonical->setAttribute('href', $base . '/' . ($route === 'index.html' ? '' : $route));
        $dom->getElementsByTagName('head')->item(0)->appendChild($canonical);
        $ogImage = $dom->createElement('meta');
        $ogImage->setAttribute('property', 'og:image');
        $ogImage->setAttribute('content', $base . '/' . $content['hero']['image']);
        $dom->getElementsByTagName('head')->item(0)->appendChild($ogImage);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo $dom->saveHTML();
} catch (Throwable $failure) {
    error_log('Átrio: não foi possível montar a página.');
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Página temporariamente indisponível.';
}
