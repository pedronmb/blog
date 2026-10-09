<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$catalog = catalog();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$base = base_path();
if ($base !== '' && str_starts_with($path, $base . '/')) { $path = substr($path, strlen($base)); }
$path = rtrim(rawurldecode($path), '/');

if ($path === '/sitemap.xml') { require __DIR__ . '/app/sitemap.php'; exit; }
if (in_array($path, ['/feed.xml','/feed','/index.php/feed'], true)) {
    if ($path !== '/feed.xml') { redirect_to(url('feed.xml')); }
    require __DIR__ . '/app/feed.php'; exit;
}
if (in_array($path, ['/about','/index.php/about'], true)) { redirect_to(url('sobre-mi.html')); }
if (preg_match('#^/(?:index\.php/)?category/([a-z0-9-]+)$#D', $path, $match)) {
    if (!isset($catalog['categories'][$match[1]])) { not_found(); }
    redirect_to(listing_url(['categoria'=>$match[1]]));
}
if (preg_match('#^/(?:index\.php/)?page/(\d+)$#D', $path, $match)) { redirect_to(listing_url(['pagina'=>$match[1]])); }
foreach ($catalog['posts'] as $post) {
    if (in_array($path, $post['legacy'], true)) { redirect_to(url($post['path'])); }
}
if (!in_array($path, ['', '/index.php'], true)) { not_found(); }
$oldId = query_value('p');
if ($oldId !== '') {
    foreach ($catalog['posts'] as $post) {
        if ($post['wordpress_id'] === $oldId) { redirect_to(url($post['path'])); }
    }
    not_found();
}
$category = query_value('categoria');
$search = query_value('q');
if ($category !== '' && !isset($catalog['categories'][$category])) { not_found(); }
$posts = filter_posts($catalog['posts'], $category, $search);
$perPage = 12;
$pages = max(1, (int)ceil(count($posts) / $perPage));
$pageParameter = query_value('pagina');
if ($pageParameter !== '' && (!ctype_digit($pageParameter) || (int)$pageParameter < 1)) { not_found(); }
$page = $pageParameter === '' ? 1 : (int)$pageParameter;
if ($page > $pages) { not_found(); }
$visible = array_slice($posts, ($page - 1) * $perPage, $perPage);
$selectedName = $category !== '' ? $catalog['categories'][$category]['name'] : 'Todos los artículos';
$title = $search !== '' ? 'Búsqueda: ' . $search : ($category !== '' ? $selectedName : 'Ideas sobre software, seguridad y blockchain');
$canonical = absolute_url('') . ($category !== '' || $page > 1 ? '?' . http_build_query(array_filter(['categoria'=>$category,'pagina'=>$page > 1 ? $page : ''])) : '');
page_header($title . ' · Solidchain', 'Notas y exploraciones sobre desarrollo de software, arquitectura, criptografía y blockchain.', $search === '' ? $canonical : '');
?>
<main id="contenido">
    <section class="container intro" aria-labelledby="intro-title">
        <div class="intro-copy"><p class="eyebrow"><span class="status-dot" aria-hidden="true"></span> UN BLOG PERSONAL SOBRE TECNOLOGÍA</p><h1 id="intro-title">Pensar. Construir.<br><span>Compartir.</span></h1><p class="intro-description">Notas sobre software, seguridad y blockchain.<br> Ideas que empiezan con una pregunta y terminan en código.</p></div>
        <div class="intro-aside" aria-hidden="true"><span class="aside-label">APRENDER EN PÚBLICO</span><span class="code-symbol">{ <span>...</span> }</span><span class="aside-caption">Una idea a la vez.</span></div>
    </section>
    <section class="container articles-section" aria-labelledby="articles-title">
        <div class="section-heading"><div><p class="eyebrow">EL ARCHIVO</p><h2 id="articles-title"><?= escape($search !== '' ? 'Resultados de búsqueda' : $selectedName) ?><span class="article-count"><?= count($posts) ?></span></h2></div>
            <form class="search-form" role="search" method="get" action="<?= escape(url()) ?>"><label class="sr-only" for="search">Buscar artículos</label><span class="search-icon" aria-hidden="true">⌕</span><input id="search" type="search" name="q" value="<?= escape($search) ?>" placeholder="Buscar una idea…" maxlength="250"><?php if ($category !== ''): ?><input type="hidden" name="categoria" value="<?= escape($category) ?>"><?php endif ?><button type="submit" aria-label="Buscar">→</button></form>
        </div>
        <nav class="category-nav" aria-label="Filtrar por categoría"><a class="category-chip <?= $category === '' ? 'active' : '' ?>" href="<?= escape(listing_url(['q'=>$search])) ?>" <?= $category === '' ? 'aria-current="page"' : '' ?>>Todos</a><?php foreach ($catalog['categories'] as $slug=>$item): ?><a class="category-chip <?= $category === $slug ? 'active' : '' ?>" href="<?= escape(listing_url(['categoria'=>$slug,'q'=>$search])) ?>" <?= $category === $slug ? 'aria-current="page"' : '' ?>><?= escape($item['name']) ?></a><?php endforeach ?></nav>
        <?php if ($search !== ''): ?><p class="search-summary">Resultados para <strong>«<?= escape($search) ?>»</strong>. <a href="<?= escape(listing_url(['categoria'=>$category])) ?>">Limpiar búsqueda</a></p><?php endif ?>
        <?php if (!$visible): ?><div class="empty-state"><h3>No encontramos artículos.</h3><p>Probá con otras palabras o explorá todas las categorías.</p><a class="text-link" href="<?= escape(url()) ?>">Ver todos los artículos →</a></div><?php else: ?>
        <div class="article-grid">
            <?php foreach ($visible as $post): ?>
            <article class="post-card"><a class="card-image-link" href="<?= escape(url($post['path'])) ?>" tabindex="-1" aria-hidden="true"><img class="card-image" src="<?= escape($post['image']) ?>" alt="" loading="lazy" decoding="async" width="768" height="432"><span class="image-arrow" aria-hidden="true">↗</span></a><div class="card-body"><div class="card-meta"><span><?= escape(implode(' / ', $post['categories'])) ?></span><time datetime="<?= escape($post['date']) ?>"><?= escape(spanish_date($post['date'])) ?></time></div><h3><a href="<?= escape(url($post['path'])) ?>"><?= escape($post['title']) ?></a></h3><p class="card-description"><?= escape($post['description']) ?></p><a class="card-read" href="<?= escape(url($post['path'])) ?>" aria-label="<?= escape('Leer ' . $post['title']) ?>">Leer artículo <span aria-hidden="true">→</span></a></div></article>
            <?php endforeach ?>
        </div>
        <?php endif ?>
        <?php if ($pages > 1): ?><nav class="pagination" aria-label="Páginas de artículos"><?php if ($page > 1): ?><a class="page-direction" rel="prev" href="<?= escape(listing_url(['categoria'=>$category,'q'=>$search,'pagina'=>$page-1])) ?>">← Anterior</a><?php else: ?><span></span><?php endif ?><div class="page-numbers"><?php for ($n=1; $n<=$pages; $n++): ?><a href="<?= escape(listing_url(['categoria'=>$category,'q'=>$search,'pagina'=>$n])) ?>" <?= $n === $page ? 'aria-current="page"' : '' ?>><?= $n ?></a><?php endfor ?></div><?php if ($page < $pages): ?><a class="page-direction" rel="next" href="<?= escape(listing_url(['categoria'=>$category,'q'=>$search,'pagina'=>$page+1])) ?>">Siguiente →</a><?php else: ?><span></span><?php endif ?></nav><?php endif ?>
    </section>
    <aside class="container about-strip"><p>Detrás de estas notas hay alguien<br>que sigue aprendiendo.</p><a class="text-link" href="<?= escape(url('sobre-mi.html')) ?>">Un poco sobre mí <span aria-hidden="true">↗</span></a></aside>
</main>
<?php page_footer(); ?>
