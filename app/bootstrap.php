<?php
declare(strict_types=1);

const BLOG_ROOT = __DIR__ . '/..';
const BLOG_NAME = 'Solidchain';
const BLOG_ORIGIN = 'https://solidchain.ar';

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(): string
{
    $directory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    // PATH_INFO can make SCRIPT_NAME include the old WordPress path.
    $position = strpos($directory, '/index.php');
    if ($position !== false) {
        $directory = substr($directory, 0, $position);
    }
    return $directory === '/' || $directory === '.' ? '' : rtrim($directory, '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function absolute_url(string $path): string
{
    return rtrim(getenv('SOLIDCHAIN_BASE_URL') ?: BLOG_ORIGIN, '/') . '/' . ltrim($path, '/');
}

function fold(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    return strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
}

function category_slug(string $name): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', fold($name)) ?? '', '-');
}

function query_value(string $key): string
{
    $value = $_GET[$key] ?? '';
    return is_string($value) ? trim(mb_substr($value, 0, 250)) : '';
}

function listing_url(array $parameters = []): string
{
    $parameters = array_filter($parameters, static fn($v) => $v !== '' && $v !== null);
    return url() . ($parameters ? '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986) : '');
}

function valid_date(string $date): ?DateTimeImmutable
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
        return null;
    }
    try {
        $parsed = new DateTimeImmutable($date);
        $errors = DateTimeImmutable::getLastErrors();
        return ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) ? null : $parsed;
    } catch (Exception) {
        return null;
    }
}

function html_document(string $html): DOMDocument
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument('1.0', 'UTF-8');
    $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $document;
}

/** Resolve an image without letting a metadata value select a file outside the site. */
function image_url(string $image, string $htmlRelativePath): string
{
    $fallback = url('assets/placeholder.svg');
    if ($image === '' || preg_match('/[\x00-\x1f\\\\]/', $image)) {
        return $fallback;
    }
    $parts = parse_url($image);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
        return $fallback;
    }
    if (isset($parts['scheme']) || isset($parts['host'])) {
        if (!in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return $fallback;
        }
        if (strtolower($parts['host'] ?? '') !== 'solidchain.ar') {
            return $image;
        }
    }
    $path = rawurldecode($parts['path'] ?? '');
    if ($path === '') {
        return $fallback;
    }
    $relative = str_starts_with($path, '/') ? ltrim($path, '/') : dirname($htmlRelativePath) . '/' . $path;
    $resolved = realpath(BLOG_ROOT . '/' . $relative);
    $root = realpath(BLOG_ROOT);
    if (!$resolved || !$root || !is_file($resolved) || !str_starts_with(str_replace('\\', '/', $resolved), str_replace('\\', '/', $root) . '/')) {
        return $fallback;
    }
    $relative = substr(str_replace('\\', '/', $resolved), strlen(str_replace('\\', '/', $root)) + 1);
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'avif'], true)) {
        return $fallback;
    }
    return url(implode('/', array_map('rawurlencode', explode('/', $relative))));
}

function read_post(string $filename, array &$errors): ?array
{
    $relative = 'posts/' . basename($filename);
    if (is_link($filename) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*\.html$/D', basename($filename))) {
        $errors[] = "$relative: nombre inválido o enlace simbólico.";
        return null;
    }
    $html = file_get_contents($filename);
    if ($html === false) {
        $errors[] = "$relative: no se pudo leer.";
        return null;
    }
    $document = html_document($html);
    $xpath = new DOMXPath($document);
    $title = trim($xpath->evaluate('string(/html/head/title)'));
    $metadata = [];
    foreach ($xpath->query('/html/head/meta') as $meta) {
        $key = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
        $metadata[$key][] = trim($meta->getAttribute('content'));
    }
    $description = $metadata['description'][0] ?? '';
    $date = $metadata['article:published_time'][0] ?? '';
    $published = valid_date($date);
    $problems = [];
    if ($title === '') { $problems[] = 'falta <title>'; }
    if ($description === '') { $problems[] = 'falta description'; }
    if (!$published) { $problems[] = 'article:published_time inválido (ISO 8601 con zona horaria)'; }
    $modified = $metadata['article:modified_time'][0] ?? '';
    if ($modified !== '' && !valid_date($modified)) { $problems[] = 'article:modified_time inválido'; }
    if ($problems) {
        $errors[] = "$relative: " . implode('; ', $problems) . '.';
        return null;
    }
    $categories = [];
    foreach (array_unique($metadata['article:section'] ?? []) as $name) {
        $slug = category_slug($name);
        if ($name !== '' && $slug !== '') { $categories[$slug] = $name; }
    }
    $legacy = [];
    foreach ($metadata['blog:legacy-url'] ?? [] as $oldUrl) {
        $path = parse_url($oldUrl, PHP_URL_PATH);
        if (is_string($path) && str_starts_with($path, '/') && !str_contains($path, '..')) {
            $legacy[] = rtrim($path, '/');
        }
    }
    $id = $metadata['blog:wordpress-id'][0] ?? '';
    return [
        'slug' => basename($filename, '.html'), 'path' => $relative,
        'title' => $title, 'description' => $description, 'date' => $date,
        'timestamp' => $published->getTimestamp(), 'modified' => $modified ?: $date,
        'categories' => $categories, 'image' => image_url($metadata['og:image'][0] ?? '', $relative),
        'legacy' => $legacy, 'wordpress_id' => ctype_digit($id) ? $id : '',
        'search' => fold($title . ' ' . $description . ' ' . implode(' ', $categories)),
    ];
}

function catalog(?string $directory = null): array
{
    $errors = [];
    $posts = [];
    foreach (glob(($directory ?? BLOG_ROOT . '/posts') . '/*.html') ?: [] as $filename) {
        if (is_file($filename) && ($post = read_post($filename, $errors))) { $posts[] = $post; }
    }
    usort($posts, static fn($a, $b) => ($b['timestamp'] <=> $a['timestamp']) ?: strcmp($a['slug'], $b['slug']));
    $categories = [];
    foreach ($posts as $post) {
        foreach ($post['categories'] as $slug => $name) {
            $categories[$slug] ??= ['name'=>$name, 'count'=>0];
            $categories[$slug]['count']++;
        }
    }
    uasort($categories, static fn($a, $b) => strcmp(fold($a['name']), fold($b['name'])));
    return ['posts'=>$posts, 'categories'=>$categories, 'errors'=>$errors];
}

function filter_posts(array $posts, string $category, string $search): array
{
    $terms = preg_split('/\s+/u', fold($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_filter($posts, static function ($post) use ($category, $terms) {
        if ($category !== '' && !isset($post['categories'][$category])) { return false; }
        foreach ($terms as $term) {
            if (!str_contains($post['search'], $term)) { return false; }
        }
        return true;
    }));
}

function spanish_date(string $date): string
{
    $parsed = new DateTimeImmutable($date);
    $parsed = $parsed->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'));
    $months = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
    return $parsed->format('j') . ' ' . $months[(int)$parsed->format('n') - 1] . ' ' . $parsed->format('Y');
}

function redirect_to(string $destination): never
{
    header('Location: ' . $destination, true, 301);
    exit;
}

function page_header(string $title, string $description, string $canonical = ''): void
{
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape($title) ?></title>
    <meta name="description" content="<?= escape($description) ?>">
    <?php if ($canonical !== ''): ?><link rel="canonical" href="<?= escape($canonical) ?>"><?php endif ?>
    <script src="<?= escape(url('assets/theme.js')) ?>"></script>
    <link rel="stylesheet" href="<?= escape(url('assets/site.css?v=3')) ?>">
    <link rel="stylesheet" href="<?= escape(url('assets/theme.css?v=3')) ?>">
    <link rel="icon" href="<?= escape(url('assets/favicon.svg?v=3')) ?>" type="image/svg+xml">
    <link rel="alternate" type="application/rss+xml" title="Solidchain" href="<?= escape(url('feed.xml')) ?>">
</head>
<body>
<a class="skip-link" href="#contenido">Ir al contenido</a>
<header class="site-header"><div class="container header-inner">
    <a class="brand" href="<?= escape(url()) ?>" aria-label="Solidchain, inicio"><img class="brand-mark" src="<?= escape(url('assets/favicon.svg?v=3')) ?>" alt="" width="35" height="35" aria-hidden="true"> Solidchain</a>
    <nav class="main-nav" aria-label="Navegación principal"><a href="<?= escape(url()) ?>">Artículos</a><a href="<?= escape(url('sobre-mi.html')) ?>">Sobre mí <span aria-hidden="true">↗</span></a></nav>
</div></header>
<?php
}

function page_footer(): void
{
    ?>
<footer class="site-footer"><div class="container footer-inner"><a class="footer-brand" href="<?= escape(url()) ?>">Solidchain<span>.</span></a><p>Ideas, código y lo que hay entre ambos.</p><a href="<?= escape(url('feed.xml')) ?>">RSS <span aria-hidden="true">↗</span></a></div></footer>
</body></html>
<?php
}

function not_found(): never
{
    http_response_code(404);
    page_header('Página no encontrada · Solidchain', 'La página solicitada no existe.');
    echo '<main id="contenido" class="container empty-page"><p class="eyebrow">404 / PÁGINA NO ENCONTRADA</p><h1>Este camino termina acá.</h1><p>Podés seguir explorando los artículos del blog.</p><a class="button" href="' . escape(url()) . '">Volver al inicio →</a></main>';
    page_footer();
    exit;
}
