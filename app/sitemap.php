<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
echo '<url><loc>' . escape(absolute_url('')) . '</loc></url>';
if (is_file(BLOG_ROOT . '/sobre-mi.html')) { echo '<url><loc>' . escape(absolute_url('sobre-mi.html')) . '</loc></url>'; }
foreach ($catalog['posts'] as $post) {
    echo '<url><loc>' . escape(absolute_url($post['path'])) . '</loc><lastmod>' . escape($post['modified']) . '</lastmod></url>';
}
foreach ($catalog['categories'] as $slug=>$category) {
    echo '<url><loc>' . escape(absolute_url('') . '?categoria=' . rawurlencode($slug)) . '</loc></url>';
}
echo '</urlset>';
