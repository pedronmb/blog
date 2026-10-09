<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/bootstrap.php';

final class WordPressImporter
{
    private array $state;
    private array $report = ['posts'=>[], 'resources'=>[], 'errors'=>[], 'warnings'=>[]];
    private array $downloaded = [];
    private array $postLinks = [];
    private array $categoryLinks = [];
    private array $nextState;
    private string $sourceHost;

    public function __construct(private string $source, private bool $force, private bool $dryRun)
    {
        $host = parse_url($source, PHP_URL_HOST);
        if (!$host || parse_url($source, PHP_URL_SCHEME) !== 'https') { throw new RuntimeException('El origen debe ser una URL HTTPS.'); }
        $this->sourceHost = strtolower($host);
        $this->state = is_file(__DIR__ . '/import-state.json') ? json_decode(file_get_contents(__DIR__ . '/import-state.json'), true, 512, JSON_THROW_ON_ERROR) : [];
        $this->nextState = $this->state;
    }

    private function fetch(string $url): array
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host !== $this->sourceHost || !in_array(parse_url($url, PHP_URL_SCHEME), ['https','http'], true)) { throw new RuntimeException("Origen de recurso no permitido: $url"); }
        $headers = [];
        $lastError = '';
        for ($attempt=0; $attempt<3; $attempt++) {
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>12, CURLOPT_TIMEOUT=>45,
                CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_USERAGENT=>'SolidchainMigration/1.0',
                CURLOPT_HEADERFUNCTION=>static function ($handle, $line) use (&$headers) {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); }
                    return strlen($line);
                },
            ]);
            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($handle) ?: "HTTP $status";
            curl_close($handle);
            if ($body !== false && $status >= 200 && $status < 300) { return ['body'=>$body,'headers'=>$headers]; }
            if ($status >= 400 && $status < 500 && $status !== 429) { break; }
        }
        throw new RuntimeException("No se pudo descargar $url ($lastError).");
    }

    private function collection(string $type): array
    {
        $items = [];
        $page = 1;
        do {
            $response = $this->fetch($this->source . '/?rest_route=/wp/v2/' . $type . '&per_page=100&page=' . $page . ($type === 'posts' ? '&_embed=1' : ''));
            $batch = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($batch) || !array_is_list($batch)) { throw new RuntimeException("Respuesta inválida para $type."); }
            array_push($items, ...$batch);
            $pages = (int)($response['headers']['x-wp-totalpages'] ?? 1);
            $total = (int)($response['headers']['x-wp-total'] ?? count($items));
            $page++;
        } while ($page <= $pages);
        if (count($items) !== $total) { throw new RuntimeException("Inventario incompleto: $type."); }
        return $items;
    }

    private function sourceDate(array $post, string $field): string
    {
        $utc = $post[$field . '_gmt'] ?? '';
        if ($utc !== '' && $utc !== '0000-00-00T00:00:00') { return $utc . 'Z'; }
        return (new DateTimeImmutable($post[$field], new DateTimeZone('America/Argentina/Buenos_Aires')))->format(DATE_ATOM);
    }

    private function plain(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    private function resource(string $url, bool $atRoot): string
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($url, '//')) { $url = 'https:' . $url; }
        elseif (str_starts_with($url, '/')) { $url = $this->source . $url; }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['host'] ?? '') !== $this->sourceHost) {
            throw new RuntimeException("Recurso externo sin migrar: $url");
        }
        $originalPath = rawurldecode($parts['path'] ?? '');
        if (!str_starts_with($originalPath, '/wp-content/') || str_contains($originalPath, '..') || str_contains($originalPath, '\\') || preg_match('/[\x00-\x1f]/', $originalPath)) {
            throw new RuntimeException("Ruta de recurso no permitida: $url");
        }
        $relative = 'media/' . substr($originalPath, strlen('/wp-content/'));
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!in_array($extension, ['png','jpg','jpeg','gif','webp','avif','svg','pdf','mp4','webm','mp3','ogg','wav'], true)) {
            throw new RuntimeException("Tipo de recurso no permitido: $url");
        }
        if (!isset($this->downloaded[$relative])) {
            $filename = BLOG_ROOT . '/' . $relative;
            $expected = $this->state['resources'][$relative] ?? '';
            if (is_file($filename) && $expected !== '' && hash_file('sha256', $filename) === $expected) {
                $this->report['resources'][$relative] = ['url'=>$url,'status'=>'reused','sha256'=>$expected];
            } else {
                if (is_file($filename) && !$this->force) { throw new RuntimeException("Recurso local modificado: $relative. Usar --force solo si se desea reemplazarlo."); }
                $response = $this->fetch($url);
                if ($response['body'] === '') { throw new RuntimeException("Recurso vacío: $url"); }
                if (str_contains(strtolower($response['headers']['content-type'] ?? ''), 'text/html')) { throw new RuntimeException("El recurso devolvió HTML: $url"); }
                $this->writeAtomic($filename, $response['body']);
                $hash = hash('sha256', $response['body']);
                $this->nextState['resources'][$relative] = $hash;
                $this->report['resources'][$relative] = ['url'=>$url,'status'=>'downloaded','bytes'=>strlen($response['body']),'sha256'=>$hash];
                echo "  recurso: $relative\n";
            }
            $this->downloaded[$relative] = true;
        }
        return ($atRoot ? '' : '../') . implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    private function rewriteContent(string $html, bool $atRoot = false): string
    {
        $document = html_document('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="migration-content">' . $html . '</div></body></html>');
        $xpath = new DOMXPath($document);
        $wrapper = $document->getElementById('migration-content');
        if (!$wrapper) { throw new RuntimeException('No se pudo analizar el contenido del artículo.'); }
        foreach (iterator_to_array($xpath->query('//script | //link | //style')) as $node) { $node->parentNode->removeChild($node); }
        foreach ($xpath->query('//*[@src]') as $node) {
            $src = $node->getAttribute('src');
            if ($src !== '' && !str_starts_with($src, 'data:')) { $node->setAttribute('src', $this->resource($src, $atRoot)); }
        }
        foreach ($xpath->query('//*[@srcset]') as $node) {
            $entries = [];
            foreach (explode(',', $node->getAttribute('srcset')) as $entry) {
                $parts = preg_split('/\s+/', trim($entry), 2);
                if (($parts[0] ?? '') !== '') { $entries[] = $this->resource($parts[0], $atRoot) . (isset($parts[1]) ? ' ' . $parts[1] : ''); }
            }
            $node->setAttribute('srcset', implode(', ', $entries));
        }
        foreach ($xpath->query('//*[@poster]') as $node) { $node->setAttribute('poster', $this->resource($node->getAttribute('poster'), $atRoot)); }
        foreach ($xpath->query('//a[@href]') as $node) {
            $href = html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $key = rtrim(preg_replace('/[?#].*$/', '', $href) ?? $href, '/');
            $suffix = parse_url($href, PHP_URL_FRAGMENT);
            if (isset($this->postLinks[$key])) { $node->setAttribute('href', ($atRoot ? '' : '../') . $this->postLinks[$key] . ($suffix ? '#' . $suffix : '')); }
            elseif (isset($this->categoryLinks[$key])) { $node->setAttribute('href', ($atRoot ? './' : '../') . '?categoria=' . $this->categoryLinks[$key]); }
            elseif (strtolower(parse_url($href, PHP_URL_HOST) ?: '') === $this->sourceHost && str_contains($href, '/wp-content/')) { $node->setAttribute('href', $this->resource($href, $atRoot)); }
        }
        $content = '';
        foreach ($wrapper->childNodes as $node) { $content .= $document->saveHTML($node); }
        return $content;
    }

    private function writeAtomic(string $filename, string $contents): void
    {
        if (!is_dir(dirname($filename)) && !mkdir(dirname($filename), 0755, true) && !is_dir(dirname($filename))) { throw new RuntimeException('No se pudo crear ' . dirname($filename)); }
        $temporary = $filename . '.part';
        if (file_put_contents($temporary, $contents) !== strlen($contents)) { throw new RuntimeException("Escritura incompleta: $filename"); }
        if (!rename($temporary, $filename)) { throw new RuntimeException("No se pudo publicar $filename"); }
    }

    private function canReplace(string $relative): bool
    {
        $filename = BLOG_ROOT . '/' . $relative;
        if (!file_exists($filename)) { return true; }
        return $this->force || (isset($this->state['files'][$relative]) && hash_file('sha256', $filename) === $this->state['files'][$relative]);
    }

    private function renderPage(array $post, string $content, array $categories, string $cover, string $thumbnail, bool $about): string
    {
        $title = $about ? 'Sobre mí' : $this->plain($post['title']['rendered']);
        $description = $this->plain($post['excerpt']['rendered'] ?? '');
        if ($description === '') { $description = $this->plain($post['content']['rendered']); }
        $description = preg_replace('/\s*(?:\[&hellip;\]|\[…\]|\[\.\.\.\])\s*$/u', '', $description) ?? $description;
        if (mb_strlen($description) > 240) { $description = mb_substr($description, 0, 237) . '…'; }
        $prefix = $about ? '' : '../';
        $date = $this->sourceDate($post, 'date');
        $modified = $this->sourceDate($post, 'modified');
        $relative = $about ? 'sobre-mi.html' : 'posts/' . $post['slug'] . '.html';
        $meta = '<meta name="description" content="' . escape($description) . '">' . "\n";
        $meta .= '<meta property="article:published_time" content="' . escape($date) . '">' . "\n";
        $meta .= '<meta property="article:modified_time" content="' . escape($modified) . '">' . "\n";
        foreach ($categories as $name) { $meta .= '<meta property="article:section" content="' . escape($name) . '">' . "\n"; }
        $meta .= '<meta name="blog:legacy-url" content="' . escape($post['link']) . '">' . "\n";
        $meta .= '<meta name="blog:wordpress-id" content="' . (int)$post['id'] . '">' . "\n";
        $meta .= '<meta property="og:type" content="' . ($about ? 'website' : 'article') . '">' . "\n";
        $meta .= '<meta property="og:title" content="' . escape($title) . '">' . "\n";
        $meta .= '<meta property="og:description" content="' . escape($description) . '">' . "\n";
        $meta .= '<meta property="og:url" content="' . escape(absolute_url($relative)) . '">' . "\n";
        if ($thumbnail !== '') { $meta .= '<meta property="og:image" content="' . escape(absolute_url(preg_replace('#^\.\./#', '', $thumbnail))) . '">' . "\n"; }
        $meta .= '<meta name="twitter:card" content="summary_large_image">';
        $eyebrow = $about ? 'EL AUTOR' : implode(' / ', $categories);
        $dateLine = $about ? '' : '<p class="article-date"><time datetime="' . escape($date) . '">' . escape(spanish_date($date)) . '</time></p>';
        $coverHtml = $cover === '' || $about ? '' : '<img class="article-cover" src="' . escape($cover) . '" alt="" decoding="async">';
        return '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . escape($title) . '</title>
' . $meta . '
<link rel="canonical" href="' . escape(absolute_url($relative)) . '">
<script src="' . $prefix . 'assets/theme.js"></script>
<link rel="stylesheet" href="' . $prefix . 'assets/site.css?v=3">
<link rel="stylesheet" href="' . $prefix . 'assets/theme.css?v=3">
<link rel="icon" href="' . $prefix . 'assets/favicon.svg?v=3" type="image/svg+xml">
<link rel="alternate" type="application/rss+xml" title="Solidchain" href="' . $prefix . 'feed.xml">
</head>
<body>
<a class="skip-link" href="#contenido">Ir al contenido</a>
<header class="site-header"><div class="container header-inner"><a class="brand" href="' . ($about ? './' : '../') . '"><img class="brand-mark" src="' . $prefix . 'assets/favicon.svg?v=3" alt="" width="35" height="35" aria-hidden="true"> Solidchain</a><nav class="main-nav" aria-label="Navegación principal"><a href="' . ($about ? './' : '../') . '">Artículos</a><a href="' . $prefix . 'sobre-mi.html">Sobre mí <span aria-hidden="true">↗</span></a></nav></div></header>
<main id="contenido" class="article-shell">
<nav class="breadcrumb" aria-label="Ubicación"><a href="' . ($about ? './' : '../') . '">← Todos los artículos</a></nav>
<header class="article-header ' . ($about ? 'about-heading' : '') . '"><p class="eyebrow">' . escape($eyebrow) . '</p><h1>' . escape($title) . '</h1>' . $dateLine . '</header>
' . $coverHtml . '
<div class="article-content ' . ($about ? 'about-content' : '') . '">
' . $content . '
</div>
<div class="article-return"><a href="' . ($about ? './' : '../') . '">← Seguir explorando los artículos</a></div>
</main>
<footer class="site-footer"><div class="container footer-inner"><a class="footer-brand" href="' . ($about ? './' : '../') . '">Solidchain<span>.</span></a><p>Ideas, código y lo que hay entre ambos.</p><a href="' . $prefix . 'feed.xml">RSS ↗</a></div></footer>
</body>
</html>
';
    }

    public function run(): int
    {
        echo "Consultando artículos y categorías de {$this->source}…\n";
        $posts = $this->collection('posts');
        $categories = $this->collection('categories');
        $pages = $this->collection('pages');
        $byId = [];
        foreach ($categories as $category) {
            $byId[$category['id']] = $this->plain($category['name']);
            $this->categoryLinks[rtrim($category['link'], '/')] = category_slug($byId[$category['id']]);
        }
        foreach ($posts as $post) {
            if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $post['slug'])) { throw new RuntimeException('Slug inválido: ' . $post['slug']); }
            $this->postLinks[rtrim($post['link'], '/')] = 'posts/' . $post['slug'] . '.html';
        }
        $sitemap = simplexml_load_string($this->fetch($this->source . '/post-sitemap.xml')['body'], SimpleXMLElement::class, LIBXML_NONET);
        if (!$sitemap) { throw new RuntimeException('Sitemap de artículos inválido.'); }
        $sitemapLinks = array_map(static fn($node) => rtrim((string)$node, '/'), $sitemap->xpath('//*[local-name()="url"]/*[local-name()="loc"]'));
        $apiLinks = array_keys($this->postLinks);
        sort($sitemapLinks); sort($apiLinks);
        if ($sitemapLinks !== $apiLinks) { throw new RuntimeException('La API y el sitemap tienen inventarios distintos; revisar antes de migrar.'); }
        $this->report += ['source'=>$this->source, 'started_at'=>gmdate(DATE_ATOM), 'source_post_count'=>count($posts), 'sitemap_post_count'=>count($sitemapLinks), 'inventory_matches'=>true];
        echo 'Inventario verificado: ' . count($posts) . ' artículos, ' . count($categories) . " categorías.\n";
        if ($this->dryRun) { foreach ($posts as $post) { echo $post['slug'] . "\n"; } return 0; }
        $aboutPages = array_filter($pages, static fn($page) => $page['slug'] === 'about');
        foreach (array_merge($posts, array_values($aboutPages)) as $post) {
            $about = ($post['type'] ?? '') === 'page';
            $relative = $about ? 'sobre-mi.html' : 'posts/' . $post['slug'] . '.html';
            if (!$this->canReplace($relative)) {
                $this->report['posts'][] = ['id'=>$post['id'],'path'=>$relative,'status'=>'skipped_manual_change'];
                $this->report['warnings'][] = "$relative tiene cambios locales; no se reemplazó.";
                echo "Omitido (cambios locales): $relative\n";
                continue;
            }
            try {
                echo "Migrando: $relative\n";
                $content = $this->rewriteContent($post['content']['rendered'], $about);
                $cover = ''; $thumbnail = '';
                $media = $post['_embedded']['wp:featuredmedia'][0] ?? null;
                if (!$about && ($post['featured_media'] ?? 0) && !$media) {
                    $media = json_decode($this->fetch($this->source . '/?rest_route=/wp/v2/media/' . (int)$post['featured_media'])['body'], true, 512, JSON_THROW_ON_ERROR);
                }
                if ($media) {
                    $cover = $this->resource($media['source_url'], $about);
                    $sizes = $media['media_details']['sizes'] ?? [];
                    $thumbnail = $this->resource($sizes['medium_large']['source_url'] ?? $sizes['large']['source_url'] ?? $media['source_url'], $about);
                }
                $names = [];
                foreach ($post['categories'] ?? [] as $id) { if (isset($byId[$id])) { $names[] = $byId[$id]; } }
                $html = $this->renderPage($post, $content, $names, $cover, $thumbnail, $about);
                $this->writeAtomic(BLOG_ROOT . '/' . $relative, $html);
                $hash = hash('sha256', $html);
                $this->nextState['files'][$relative] = $hash;
                $this->report['posts'][] = ['id'=>$post['id'],'source_url'=>$post['link'],'path'=>$relative,'status'=>'imported','sha256'=>$hash,'source_content_sha256'=>hash('sha256', $post['content']['rendered'])];
            } catch (Throwable $exception) {
                $this->report['errors'][] = "$relative: " . $exception->getMessage();
                echo 'ERROR: ' . $exception->getMessage() . "\n";
            }
        }
        $this->report['finished_at'] = gmdate(DATE_ATOM);
        $this->writeAtomic(__DIR__ . '/import-state.json', json_encode($this->nextState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        $this->writeAtomic(__DIR__ . '/migration-report.json', json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        echo 'Finalizado. Recursos: ' . count($this->report['resources']) . '; errores: ' . count($this->report['errors']) . '; avisos: ' . count($this->report['warnings']) . ".\nInforme: tools/migration-report.json\n";
        return $this->report['errors'] ? 1 : 0;
    }
}

try {
    $options = getopt('', ['source:', 'force', 'dry-run']);
    $importer = new WordPressImporter(rtrim($options['source'] ?? BLOG_ORIGIN, '/'), isset($options['force']), isset($options['dry-run']));
    exit($importer->run());
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
