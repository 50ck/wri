<?php
declare(strict_types=1);
require_once __DIR__.'/vendor/parsedown/Parsedown.php';
function markdown(string $body,?array $blog=null): string {
    $active=active_addons($blog);
    $parser=new AddonMarkdown();
    foreach ($active as $addon) if (isset($addon['parser'])) ($addon['parser'])($parser);
    $html = $parser->setSafeMode(true)->setMarkupEscaped(true)->setUrlsLinked(false)->text($body);
    // Defense in depth: no embeds, resource-loading elements or active attributes.
    $doc = new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="markdown-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed=['p','h1','h2','h3','h4','h5','h6','em','strong','a','ul','ol','li','blockquote','pre','code','hr','br','del','table','thead','tbody','tr','th','td'];
    $headings=[]; $tables=[]; $quotes=[];
    foreach (iterator_to_array($doc->getElementsByTagName('*')) as $node) {
        if ($node->getAttribute('id')==='markdown-root') continue;
        if ($node->nodeName==='img') {
            $node->parentNode?->replaceChild($doc->createTextNode($node->getAttribute('alt')), $node);
            continue;
        }
        if (!in_array($node->nodeName,$allowed,true)) { $node->parentNode?->removeChild($node); continue; }
        $alignment=null; $language=null; $marker=null;
        if ($node->nodeName==='code' && preg_match('/\Aaddon-[a-z0-9-]+\z/',$node->getAttribute('class'))) $marker=$node->getAttribute('class');
        if ($node->nodeName==='code' && $node->parentNode->nodeName==='pre' && preg_match('/\Alanguage-([a-z0-9_-]+)\z/i',$node->getAttribute('class'),$match)) $language=strtolower($match[1]);
        if (in_array($node->nodeName,['th','td'],true) && preg_match('/\Atext-align: (left|center|right);\z/', $node->getAttribute('style'), $match)) $alignment=$match[1];
        foreach (iterator_to_array($node->attributes) as $attr) {
            if ($node->nodeName==='a' && $attr->name==='title') continue;
            if ($node->nodeName==='ol' && $attr->name==='start' && preg_match('/\A[0-9]{1,9}\z/', $attr->value)) continue;
            if ($node->nodeName==='a' && $attr->name==='href') {
                $href=trim($attr->value);
                if (preg_match('~\A(?:https?://|mailto:|/|\#)~i',$href) && !str_starts_with($href,'//') && !preg_match('/[\x00-\x20\\\\]/',$href)) continue;
            }
            $node->removeAttribute($attr->name);
        }
        if ($marker || $language) $node->setAttribute('class',$marker ?? 'language-'.$language);
        if ($alignment) $node->setAttribute('class','markdown-align-'.$alignment);
        if ($node->nodeName==='a') $node->setAttribute('rel','nofollow noreferrer');
        if (preg_match('/\Ah[1-6]\z/', $node->nodeName)) $headings[]=$node;
        if ($node->nodeName==='table') $tables[]=$node;
        if ($node->nodeName==='blockquote') $quotes[]=$node;
    }
    foreach ($quotes as $quote) {
        $first=$quote->firstChild;
        while ($first && $first->nodeType===XML_TEXT_NODE && trim($first->textContent)==='') $first=$first->nextSibling;
        $text=$first?->firstChild;
        if ($first?->nodeName!=='p' || $text?->nodeType!==XML_TEXT_NODE || !preg_match('/\A\[!(NOTE|TIP|WARNING)\](?:\r?\n|$)/',$text->textContent,$match)) continue;
        $type=strtolower($match[1]);
        $text->nodeValue=substr($text->textContent,strlen($match[0]));
        if (trim($first->textContent)==='' && !$first->getElementsByTagName('*')->length) $quote->removeChild($first);
        $quote->setAttribute('class','markdown-alert markdown-alert-'.$type);
        $title=$doc->createElement('p'); $title->setAttribute('class','markdown-alert-title');
        $title->appendChild($doc->createTextNode(t('alert_'.$type)));
        $quote->insertBefore($title,$quote->firstChild);
    }
    foreach ($active as $addon) if (isset($addon['render'])) ($addon['render'])($doc,['blog'=>$blog]);
    foreach ($doc->getElementsByTagName('code') as $code) $code->removeAttribute('class');
    // IDs originate exclusively from escaped heading text, never raw user HTML.
    $used=['main'=>true,'markdown-root'=>true]; $targets=[];
    foreach ($headings as $heading) {
        $base=mb_strtolower(trim($heading->textContent),'UTF-8');
        $base=preg_replace('/[^\p{L}\p{N}\p{M}_\s-]/u','',$base);
        $base=preg_replace('/\s+/u','-',$base) ?: 'seccion';
        $id=$base; $suffix=1;
        while (isset($used[$id])) $id=$base.'-'.$suffix++;
        $used[$id]=true; $targets[$base]??=$id; $targets[$id]??=$id;
        $heading->setAttribute('id',$id);
    }
    foreach ($doc->getElementsByTagName('a') as $link) {
        $href=$link->getAttribute('href');
        if (str_starts_with($href,'#')) {
            $target=$targets[rawurldecode(substr($href,1))] ?? null;
            if ($target) $link->setAttribute('href','#'.rawurlencode($target));
        }
    }
    foreach ($tables as $table) {
        $wrapper=$doc->createElement('div');
        $wrapper->setAttribute('class','blog-table');
        $wrapper->setAttribute('tabindex','0');
        $wrapper->setAttribute('role','region');
        $wrapper->setAttribute('aria-label',t('table'));
        $table->parentNode->replaceChild($wrapper,$table);$wrapper->appendChild($table);
    }
    $root=$doc->getElementById('markdown-root'); $result='';
    if ($root) foreach ($root->childNodes as $child) $result .= $doc->saveHTML($child);
    return $result;
}


// Small adapter for Parsedown's native rule dispatch; extensions register callbacks.
class AddonMarkdown extends Parsedown {
    private array $rules=[];
    public function add_inline(array $markers,callable $callback): void {
        $type='Addon'.count($this->rules);$this->rules['inline'.$type]=$callback;
        foreach ($markers as $marker) {
            $this->InlineTypes[$marker]??=[];array_unshift($this->InlineTypes[$marker],$type);
            if (!str_contains($this->inlineMarkerList,$marker)) $this->inlineMarkerList.=$marker;
        }
    }
    public function add_block(array $markers,callable $start,?callable $continue=null,?callable $complete=null): void {
        $type='Addon'.count($this->rules);$this->rules['block'.$type]=$start;
        if ($continue) $this->rules['block'.$type.'Continue']=$continue;
        if ($complete) $this->rules['block'.$type.'Complete']=$complete;
        foreach ($markers as $marker) { $this->BlockTypes[$marker]??=[];array_unshift($this->BlockTypes[$marker],$type); }
    }
    public function __call(string $method,array $arguments): mixed {
        if (!isset($this->rules[$method])) throw new LogicException('Unknown Markdown rule');
        return ($this->rules[$method])(...$arguments);
    }
    protected function isBlockContinuable($type) { return isset($this->rules['block'.$type.'Continue']) || parent::isBlockContinuable($type); }
    protected function isBlockCompletable($type) { return isset($this->rules['block'.$type.'Complete']) || parent::isBlockCompletable($type); }
}
