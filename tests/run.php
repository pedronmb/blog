<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/../app/bootstrap.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';

$count = 0;
$failed = false;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
    echo "OK: $message\n";
}
function fixture(string $title, string $date, string $extra = ''): string
{
    return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' . $title . '</title><meta name="description" content="Criptografía y arquitectura de APIs"><meta property="article:published_time" content="' . $date . '">' . $extra . '</head><body><p>Texto.</p></body></html>';
}
$directory = __DIR__ . '/fixtures-' . bin2hex(random_bytes(5));
if (!mkdir($directory)) { throw new RuntimeException('No se pudo crear el directorio de prueba.'); }
try {
    check(valid_date('2026-10-07T12:00:00-03:00') !== null, 'fecha con zona horaria');
    check(valid_date('2026-10-07T15:00:00Z') !== null, 'fecha UTC');
    check(valid_date('2026-02-30T12:00:00-03:00') === null, 'rechaza fecha inexistente');
    check(valid_date('2026-10-07') === null, 'rechaza fecha sin hora ni zona');
    check(category_slug('Criptografía') === 'criptografia', 'slug de categoría sin acentos');
    file_put_contents($directory . '/uno.html', fixture('Más allá de las APIs', '2025-01-01T12:00:00Z', '<meta property="article:section" content="Criptografía"><meta property="article:section" content="Web"><meta name="blog:wordpress-id" content="42"><meta name="blog:legacy-url" content="https://solidchain.ar/index.php/2025/01/01/uno/">'));
    file_put_contents($directory . '/dos.html', fixture('Segundo artículo', '2026-01-01T12:00:00Z', '<meta property="og:image" content="javascript:alert(1)">'));
    $catalog = catalog($directory);
    check(count($catalog['posts']) === 2 && !$catalog['errors'], 'detecta alta de archivos HTML');
    check($catalog['posts'][0]['slug'] === 'dos', 'orden cronológico descendente');
    check(count($catalog['categories']) === 2, 'categorías múltiples desde el mismo HTML');
    check(count(filter_posts($catalog['posts'], 'criptografia', 'MAS APIS')) === 1, 'búsqueda combinada sin acentos ni mayúsculas');
    check(count(filter_posts($catalog['posts'], 'web', 'inexistente')) === 0, 'búsqueda sin resultados');
    check($catalog['posts'][0]['image'] === '/assets/placeholder.svg', 'fallback para esquema de imagen inseguro');
    check(image_url('../../../../Windows/win.ini', 'posts/uno.html') === '/assets/placeholder.svg', 'imagen fuera del sitio rechazada');
    $old = $catalog['posts'][1];
    check($old['wordpress_id'] === '42' && $old['legacy'][0] === '/index.php/2025/01/01/uno', 'metadatos para redirecciones');
    file_put_contents($directory . '/uno.html', fixture('Título modificado', '2025-01-01T12:00:00Z'));
    check(catalog($directory)['posts'][1]['title'] === 'Título modificado', 'detecta modificación sin regenerar índice');
    unlink($directory . '/dos.html');
    check(count(catalog($directory)['posts']) === 1, 'detecta baja sin regenerar índice');
    file_put_contents($directory . '/invalido.html', fixture('', 'ayer'));
    file_put_contents($directory . '/invalido-fecha.html', fixture('Fecha inválida', '2026-10-07T12:00:00Z', '<meta property="article:modified_time" content="mañana">'));
    $catalog = catalog($directory);
    check(count($catalog['posts']) === 1 && count($catalog['errors']) === 2, 'excluye metadatos inválidos con diagnóstico');
    file_put_contents($directory . '/escape.html', fixture('&lt;img src=x onerror=alert(1)&gt;', '2026-10-07T12:00:00Z'));
    $catalog = catalog($directory);
    check(str_contains(escape($catalog['posts'][0]['title']), '&lt;img'), 'escapa HTML en metadatos');
    check(!str_contains(escape($catalog['posts'][0]['title']), '<img'), 'metadatos nunca se renderizan como etiquetas');
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    check(url('posts/uno.html') === '/blog/posts/uno.html', 'rutas en subdirectorio XAMPP');
    check(listing_url(['q'=>'sal & pepper','categoria'=>'web']) === '/blog/?q=sal%20%26%20pepper&categoria=web', 'codifica parámetros de enlaces');
    $_GET = ['q'=>['x']];
    check(query_value('q') === '', 'parámetros array no producen errores');
    echo "\n$count comprobaciones aprobadas.\n";
} catch (Throwable $exception) {
    $failed = true;
    fwrite(STDERR, 'FALLO: ' . $exception->getMessage() . "\n");
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) { unlink($file); }
    rmdir($directory);
}
exit($failed ? 1 : 0);
