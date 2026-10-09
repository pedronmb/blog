<?php
declare(strict_types=1);
header('Content-Type: application/rss+xml; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>Solidchain</title><link>' . escape(absolute_url('')) . '</link><description>Ideas sobre software, seguridad y blockchain.</description><language>es-ar</language><atom:link href="' . escape(absolute_url('feed.xml')) . '" rel="self" type="application/rss+xml"/>';
if ($catalog['posts']) { echo '<lastBuildDate>' . escape((new DateTimeImmutable($catalog['posts'][0]['modified']))->format(DATE_RSS)) . '</lastBuildDate>'; }
foreach ($catalog['posts'] as $post) {
    $link = escape(absolute_url($post['path']));
    echo '<item><title>' . escape($post['title']) . '</title><link>' . $link . '</link><guid isPermaLink="true">' . $link . '</guid><description>' . escape($post['description']) . '</description><pubDate>' . escape((new DateTimeImmutable($post['date']))->format(DATE_RSS)) . '</pubDate>';
    foreach ($post['categories'] as $name) { echo '<category>' . escape($name) . '</category>'; }
    echo '</item>';
}
echo '</channel></rss>';
