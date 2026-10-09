<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/bootstrap.php';

$catalog = catalog();
$errors = $catalog['errors'];
$resourceCount = 0;
$files = glob(BLOG_ROOT . '/posts/*.html') ?: [];
if (is_file(BLOG_ROOT . '/sobre-mi.html')) { $files[] = BLOG_ROOT . '/sobre-mi.html'; }
$root = str_replace('\\', '/', realpath(BLOG_ROOT));
foreach ($files as $filename) {
    $document = html_document(file_get_contents($filename));
    $xpath = new DOMXPath($document);
    $references = [];
    foreach ($xpath->query('//*[@src or @poster or @srcset] | //link[@rel="stylesheet" or @rel="icon"]') as $node) {
        foreach (['src','poster','href'] as $attribute) {
            if ($node->hasAttribute($attribute)) { $references[] = $node->getAttribute($attribute); }
        }
        if ($node->hasAttribute('srcset')) {
            foreach (explode(',', $node->getAttribute('srcset')) as $entry) { $references[] = preg_split('/\s+/', trim($entry))[0]; }
        }
    }
    foreach ($references as $reference) {
        if ($reference === '' || str_starts_with($reference, 'data:')) { continue; }
        $parts = parse_url($reference);
        if ($parts === false) { $errors[] = basename($filename) . ': URL inválida: ' . $reference; continue; }
        if (isset($parts['host']) || isset($parts['scheme'])) {
            $errors[] = basename($filename) . ': recurso externo: ' . $reference;
            continue;
        }
        $path = rawurldecode($parts['path'] ?? '');
        $candidate = str_starts_with($path, '/') ? BLOG_ROOT . $path : dirname($filename) . '/' . $path;
        $resolved = realpath($candidate);
        if (!$resolved || !is_file($resolved) || !str_starts_with(str_replace('\\', '/', $resolved), $root . '/')) {
            $errors[] = basename($filename) . ': recurso local faltante o inválido: ' . $reference;
        } else { $resourceCount++; }
    }
    if ($xpath->evaluate('string(/html/@lang)') !== 'es') { $errors[] = basename($filename) . ': falta lang="es".'; }
}
$legacyUrls = []; $wordpressIds = [];
foreach ($catalog['posts'] as $post) {
    foreach ($post['legacy'] as $legacy) {
        if (isset($legacyUrls[$legacy])) { $errors[] = "URL antigua duplicada: $legacy"; }
        $legacyUrls[$legacy] = true;
    }
    if ($post['wordpress_id'] !== '') {
        if (isset($wordpressIds[$post['wordpress_id']])) { $errors[] = 'ID de WordPress duplicado: ' . $post['wordpress_id']; }
        $wordpressIds[$post['wordpress_id']] = true;
    }
}
if (is_file(__DIR__ . '/migration-report.json')) {
    $report = json_decode(file_get_contents(__DIR__ . '/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
    array_push($errors, ...($report['errors'] ?? []));
    if (count($catalog['posts']) < ($report['source_post_count'] ?? 0)) { $errors[] = 'El catálogo tiene menos artículos que el inventario de migración.'; }
    foreach ($report['resources'] ?? [] as $relative=>$resource) {
        $filename = BLOG_ROOT . '/' . $relative;
        if (!is_file($filename) || hash_file('sha256', $filename) !== ($resource['sha256'] ?? '')) { $errors[] = "Recurso cambiado o faltante: $relative"; }
    }
}
echo count($catalog['posts']) . ' artículos válidos; ' . count($catalog['categories']) . ' categorías; ' . $resourceCount . " referencias locales verificadas.\n";
foreach ($errors as $error) { fwrite(STDERR, "ERROR: $error\n"); }
echo count($errors) . " errores.\n";
exit($errors ? 1 : 0);
