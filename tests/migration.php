<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/bootstrap.php';

// Acceptance check while the original WordPress is still available.
function source_collection(string $type): array
{
    $items = []; $page = 1;
    do {
        $headers = [];
        $handle = curl_init(BLOG_ORIGIN . '/?rest_route=/wp/v2/' . $type . '&per_page=100&page=' . $page);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>35,CURLOPT_HEADERFUNCTION=>static function ($handle, $line) use (&$headers) { $parts = explode(':', $line, 2); if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); } return strlen($line); }]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status !== 200) { throw new RuntimeException("No se pudo consultar el origen: $error HTTP $status"); }
        $batch = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        array_push($items, ...$batch);
        $pages = (int)($headers['x-wp-totalpages'] ?? 1);
        $page++;
    } while ($page <= $pages);
    return $items;
}

function content_node(string $html, bool $migrated): DOMElement
{
    if (!$migrated) { $html = '<html><head><meta charset="utf-8"></head><body><div class="article-content">' . $html . '</div></body></html>'; }
    $document = html_document($html);
    $xpath = new DOMXPath($document);
    $node = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " article-content ")]')->item(0);
    if (!$node instanceof DOMElement) { throw new RuntimeException('Falta el contenedor del contenido.'); }
    return $node;
}

function normalize_content_text(DOMElement $node): string
{
    return trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
}

$failed = false;
try {
    $posts = source_collection('posts');
    $pages = array_filter(source_collection('pages'), static fn($page) => $page['slug'] === 'about');
    foreach (array_merge($posts, array_values($pages)) as $post) {
        $filename = ($post['type'] ?? '') === 'page' ? BLOG_ROOT . '/sobre-mi.html' : BLOG_ROOT . '/posts/' . $post['slug'] . '.html';
        if (!is_file($filename)) { throw new RuntimeException('Falta ' . basename($filename)); }
        $original = content_node($post['content']['rendered'], false);
        $local = content_node(file_get_contents($filename), true);
        if (normalize_content_text($original) !== normalize_content_text($local)) { throw new RuntimeException('Texto diferente: ' . basename($filename)); }
        foreach (['h1','h2','h3','h4','p','pre','code','table','tr','figure','figcaption','img','ul','ol','li','a'] as $tag) {
            if ($original->getElementsByTagName($tag)->length !== $local->getElementsByTagName($tag)->length) { throw new RuntimeException("Estructura diferente ($tag): " . basename($filename)); }
        }
        $originalPre = $original->getElementsByTagName('pre');
        $localPre = $local->getElementsByTagName('pre');
        foreach ($originalPre as $index=>$block) {
            if ($block->textContent !== $localPre->item($index)->textContent) { throw new RuntimeException('Bloque de código diferente: ' . basename($filename)); }
        }
        echo 'OK: texto, estructura y código preservados: ' . basename($filename) . "\n";
    }
    echo count($posts) . ' artículos y ' . count($pages) . " página personal verificados contra el origen.\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'FALLO: ' . $exception->getMessage() . "\n");
}
exit($failed ? 1 : 0);
