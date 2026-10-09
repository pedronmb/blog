<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/bootstrap.php';
$base = rtrim($argv[1] ?? 'http://localhost/blog', '/');
$count = 0;
$created = [];

function http(string $path): array
{
    global $base;
    $headers = [];
    $handle = curl_init($base . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_HEADERFUNCTION=>static function ($handle, $line) use (&$headers) { $parts = explode(':', $line, 2); if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); } return strlen($line); }]);
    $body = curl_exec($handle);
    if ($body === false) { throw new RuntimeException(curl_error($handle)); }
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status'=>$status,'body'=>$body,'headers'=>$headers];
}
function assert_http(bool $condition, string $message): void
{
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
    echo "OK: $message\n";
}
function create_http_fixture(string $relative, string $content): void
{
    global $created;
    $filename = BLOG_ROOT . '/' . $relative;
    if (file_exists($filename)) { throw new RuntimeException('La prueba no reemplaza un archivo existente: ' . $relative); }
    file_put_contents($filename, $content);
    $created[] = $filename;
}

$failed = false;
try {
    $catalog = catalog();
    assert_http(count($catalog['posts']) > 0, 'hay artículos para verificar');
    $home = http('/');
    assert_http($home['status'] === 200 && !str_contains($home['body'], 'Warning:'), 'portada devuelve 200 sin avisos PHP');
    assert_http(substr_count($home['body'], 'class="post-card"') === min(12, count($catalog['posts'])), 'portada muestra 12 tarjetas como máximo');
    assert_http(str_contains($home['body'], '/blog/assets/site.css'), 'rutas de recursos en localhost/blog');
    $page2 = http('/?pagina=2');
    assert_http(count($catalog['posts']) <= 12 ? $page2['status'] === 404 : ($page2['status'] === 200 && substr_count($page2['body'], 'class="post-card"') === min(12, count($catalog['posts']) - 12)), 'segunda página respeta el tamaño del catálogo');
    $search = http('/?q=CRIPTOGRAFIA&categoria=criptografia');
    assert_http($search['status'] === 200 && substr_count($search['body'], 'class="post-card"') === $catalog['categories']['criptografia']['count'], 'búsqueda y categoría combinadas sin acentos');
    $empty = http('/?q=palabrainexistentexyz');
    assert_http($empty['status'] === 200 && str_contains($empty['body'], 'No encontramos artículos'), 'estado vacío de búsqueda');
    assert_http(http('/?q%5B%5D=x')['status'] === 200, 'parámetro array tratado sin fallo');
    foreach (['/no-existe','/?pagina=99999','/?pagina=-1','/?categoria=inexistente','/?p=999999998','/index.php/../../archivo'] as $path) {
        assert_http(http($path)['status'] === 404, '404: ' . $path);
    }
    foreach ($catalog['posts'] as $post) {
        assert_http(http('/' . $post['path'])['status'] === 200, 'HTML estático: ' . $post['slug']);
        $old = http($post['legacy'][0] . '/');
        assert_http($old['status'] === 301 && str_ends_with($old['headers']['location'] ?? '', '/' . $post['path']), '301 de URL antigua: ' . $post['slug']);
        $id = http('/?p=' . $post['wordpress_id']);
        assert_http($id['status'] === 301 && str_ends_with($id['headers']['location'] ?? '', '/' . $post['path']), '301 de ID: ' . $post['wordpress_id']);
    }
    foreach (array_keys($catalog['categories']) as $slug) {
        $category = http('/index.php/category/' . $slug . '/');
        assert_http($category['status'] === 301 && str_contains($category['headers']['location'] ?? '', '?categoria=' . $slug), '301 categoría: ' . $slug);
    }
    $about = http('/index.php/about/');
    assert_http($about['status'] === 301 && str_ends_with($about['headers']['location'] ?? '', '/sobre-mi.html'), '301 About');
    assert_http(http('/sobre-mi.html')['status'] === 200, 'Sobre mí disponible');
    $sitemap = http('/sitemap.xml');
    $xml = simplexml_load_string($sitemap['body']);
    assert_http($sitemap['status'] === 200 && $xml !== false && count($xml->url) === count($catalog['posts']) + count($catalog['categories']) + 2, 'sitemap XML completo');
    $feed = http('/feed.xml');
    $rss = simplexml_load_string($feed['body']);
    assert_http($feed['status'] === 200 && $rss !== false && count($rss->channel->item) === count($catalog['posts']), 'RSS válido y completo');
    foreach (['/app/bootstrap.php','/tools/validate.php','/tests/run.php','/templates/articulo.html','/.htaccess','/README.md'] as $path) {
        assert_http(http($path)['status'] === 403, 'acceso interno bloqueado: ' . $path);
    }
    foreach (['posts/prueba-ejecucion.php','media/prueba-ejecucion.php','assets/prueba-ejecucion.php.jpg','posts/prueba-ejecucion.php.html'] as $relative) {
        create_http_fixture($relative, '<?php echo "PHP_EJECUTADO";');
        assert_http(http('/' . $relative)['status'] === 403, 'ejecución bloqueada: ' . $relative);
    }
    $demo = file_get_contents(BLOG_ROOT . '/templates/articulo.html');
    create_http_fixture('posts/prueba-interactiva.html', $demo);
    $demoResponse = http('/posts/prueba-interactiva.html');
    assert_http($demoResponse['status'] === 200 && str_contains($demoResponse['body'], '<script>') && str_contains($demoResponse['body'], 'type="range"'), 'HTML autónomo conserva su interacción JavaScript');
    assert_http(str_contains(http('/?q=idea%20interactiva')['body'], 'prueba-interactiva.html'), 'alta de HTML aparece en búsqueda inmediatamente');
    assert_http(str_contains(http('/sitemap.xml')['body'], 'prueba-interactiva.html'), 'alta de HTML aparece en sitemap inmediatamente');
    $escaped = '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>&lt;img src=x onerror=alert(1)&gt;</title><meta name="description" content="prueba-escape"><meta property="article:published_time" content="2026-10-07T12:00:00-03:00"></head><body>Estático: <?php echo "NO_DEBE_EJECUTARSE"; ?></body></html>';
    create_http_fixture('posts/prueba-escape.html', $escaped);
    $escape = http('/?q=prueba-escape');
    assert_http(str_contains($escape['body'], '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($escape['body'], '<img src=x onerror'), 'metadatos escapados en portada');
    assert_http(str_contains(http('/posts/prueba-escape.html')['body'], '<?php echo'), 'HTML servido sin evaluar código PHP');
    $legacyResource = http('/wp-content/ql-cache/quicklatex.com-d4907955b0bf785cf895e5406af68e1a_l3.png');
    assert_http($legacyResource['status'] === 301, 'redirección de recursos antiguos');
    echo "\n$count comprobaciones HTTP aprobadas.\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'FALLO: ' . $exception->getMessage() . "\n");
} finally {
    foreach ($created as $filename) { unlink($filename); }
}
exit($failed ? 1 : 0);
