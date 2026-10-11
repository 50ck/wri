<?php
declare(strict_types=1);
db()->exec("ALTER TABLE blogs ADD COLUMN home_markdown TEXT NOT NULL DEFAULT ''");
// Preserve existing sections whose paths now belong to the two built-in pages.
foreach (query('SELECT id,blog_id,slug FROM sections WHERE slug IN (?,?)', ['inicio','blogs'])->fetchAll() as $section) {
    $suffix=2;
    do { $slug=$section['slug'].'-'.$suffix++; }
    while (one('SELECT id FROM sections WHERE blog_id=? AND slug=?',[$section['blog_id'],$slug]));
    foreach (query('SELECT id,slug FROM posts WHERE blog_id=? AND section_id=?',[$section['blog_id'],$section['id']])->fetchAll() as $post) {
        query('INSERT INTO redirects(blog_id,old_path,post_id) VALUES (?,?,?) ON CONFLICT(blog_id,old_path) DO UPDATE SET post_id=excluded.post_id',[$section['blog_id'],$section['slug'].'/'.$post['slug'],$post['id']]);
    }
    query('UPDATE sections SET slug=?,updated_at=? WHERE id=?',[$slug,now(),$section['id']]);
}
