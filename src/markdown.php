<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/parsedown/Parsedown.php';
require_once __DIR__.'/mermaid.php';
function markdown(string $body): string {
    $parser = new Parsedown();
    $html = $parser->setSafeMode(true)->setMarkupEscaped(true)->setUrlsLinked(false)->text($body);
    // Defense in depth: no embeds, resource-loading elements or active attributes.
    $doc = new DOMDocument();
    $previous=libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="markdown-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $allowed=['p','h1','h2','h3','h4','h5','h6','em','strong','a','ul','ol','li','blockquote','pre','code','hr','br','del','table','thead','tbody','tr','th','td'];
    $headings=[]; $tables=[]; $blocks=[]; $quotes=[];
    foreach (iterator_to_array($doc->getElementsByTagName('*')) as $node) {
        if ($node->getAttribute('id')==='markdown-root') continue;
        if ($node->nodeName==='img') {
            $node->parentNode?->replaceChild($doc->createTextNode($node->getAttribute('alt')), $node);
            continue;
        }
        if (!in_array($node->nodeName,$allowed,true)) { $node->parentNode?->removeChild($node); continue; }
        $alignment=null; $language=null;
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
        if ($alignment) $node->setAttribute('class','markdown-align-'.$alignment);
        if ($node->nodeName==='a') $node->setAttribute('rel','nofollow noreferrer');
        if (preg_match('/\Ah[1-6]\z/', $node->nodeName)) $headings[]=$node;
        if ($node->nodeName==='table') $tables[]=$node;
        if ($language) $blocks[]=[$node,$language];
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
    // Explicit languages only; oversized blocks stay escaped plain text.
    $budget=65536; $diagramCount=0;
    foreach ($blocks as [$code,$language]) {
        if ($language==='mermaid') {
            $svg=$diagramCount++<8 ? mermaid_svg($code->textContent) : null;
            if ($svg!==null) {
                $fragment=$doc->createDocumentFragment();
                // HTML establishes the SVG namespace; avoid libxml's default: prefix.
                if ($fragment->appendXML(str_replace(' xmlns="http://www.w3.org/2000/svg"','',$svg))) {
                    $wrapper=$doc->createElement('div'); $wrapper->setAttribute('class','blog-diagram');
                    $wrapper->setAttribute('tabindex','0'); $wrapper->setAttribute('role','region');
                    $wrapper->setAttribute('aria-label',t('flowchart')); $wrapper->appendChild($fragment);
                    $pre=$code->parentNode; $pre->parentNode->replaceChild($wrapper,$pre);
                }
            } else {
                $notice=$doc->createElement('p'); $notice->appendChild($doc->createTextNode(t('diagram_unsupported')));
                $code->parentNode->parentNode->insertBefore($notice,$code->parentNode);
            }
            continue;
        }
        $size=strlen($code->textContent);
        if ($size>16384 || $size>$budget) continue;
        $budget-=$size;
        $highlighted=highlight_code($language,$code->textContent);
        if ($highlighted===null) continue;
        $fragment=$doc->createDocumentFragment();
        if (!$fragment->appendXML($highlighted)) continue;
        // Only text and known token spans can enter the sanitized document.
        $valid=true;
        foreach ($fragment->childNodes as $child) if (!highlight_node_safe($child)) { $valid=false; break; }
        if (!$valid || $fragment->textContent!==$code->textContent) continue;
        while ($code->firstChild) $code->removeChild($code->firstChild);
        $code->appendChild($fragment);
    }
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

function highlight_code(string $language, string $code): ?string {
    $aliases=['js'=>'javascript','ts'=>'typescript','html'=>'xml','sh'=>'bash','shell'=>'bash','py'=>'python','yml'=>'yaml','md'=>'markdown'];
    $language=$aliases[$language] ?? $language;
    if (!in_array($language,['bash','css','diff','ini','javascript','json','markdown','php','python','sql','typescript','xml','yaml'],true)) return null;
    static $highlighter=null;
    if ($highlighter===null) {
        require_once dirname(__DIR__).'/vendor/highlight/Highlight/Autoloader.php';
        spl_autoload_register([\Highlight\Autoloader::class,'load']);
        $highlighter=new \Highlight\Highlighter();
    }
    try { return $highlighter->highlight($language,$code)->value; }
    catch (Throwable $error) { error_log('Syntax highlighting failed: '.$error->getMessage()); return null; }
}
function highlight_node_safe(DOMNode $node): bool {
    if ($node->nodeType===XML_TEXT_NODE) return true;
    if (!$node instanceof DOMElement || $node->tagName!=='span' || $node->attributes->length!==1 || !preg_match('/\Ahljs-[a-z0-9_-]+(?: hljs-[a-z0-9_-]+)*\z/',$node->getAttribute('class'))) return false;
    foreach ($node->childNodes as $child) if (!highlight_node_safe($child)) return false;
    return true;
}
