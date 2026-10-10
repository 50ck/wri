<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/parsedown/Parsedown.php';
function markdown(string $body): string {
    $parser = new Parsedown();
    $html = $parser->setSafeMode(true)->setMarkupEscaped(true)->setUrlsLinked(false)->text($body);
    // Defense in depth: retain only the small authoring vocabulary. No images/embeds.
    $doc = new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="markdown-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed=['p','h1','h2','h3','h4','h5','h6','em','strong','a','ul','ol','li','blockquote','pre','code','hr','br','del'];
    foreach (iterator_to_array($doc->getElementsByTagName('*')) as $node) {
        if ($node->getAttribute('id')==='markdown-root') continue;
        if (!in_array($node->nodeName,$allowed,true)) { $node->parentNode?->removeChild($node); continue; }
        foreach (iterator_to_array($node->attributes) as $attr) {
            if ($node->nodeName==='a' && $attr->name==='href') {
                $href=trim($attr->value);
                if (preg_match('~\A(?:https?://|mailto:|/|\#)~i',$href) && !str_starts_with($href,'//') && !preg_match('/[\x00-\x20\\\\]/',$href)) continue;
            }
            $node->removeAttribute($attr->name);
        }
        if ($node->nodeName==='a') $node->setAttribute('rel','nofollow noreferrer');
    }
    $root=$doc->getElementById('markdown-root'); $result='';
    if ($root) foreach ($root->childNodes as $child) $result .= $doc->saveHTML($child);
    return $result;
}
