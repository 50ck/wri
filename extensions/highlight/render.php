<?php
declare(strict_types=1);
function highlight_code(string $language, string $code): ?string {
    $aliases=['js'=>'javascript','ts'=>'typescript','html'=>'xml','sh'=>'bash','shell'=>'bash','py'=>'python','yml'=>'yaml','md'=>'markdown'];
    $language=$aliases[$language] ?? $language;
    if (!in_array($language,['bash','css','diff','ini','javascript','json','markdown','php','python','sql','typescript','xml','yaml'],true)) return null;
    static $highlighter=null;
    if ($highlighter===null) {
        require_once __DIR__.'/vendor/Highlight/Autoloader.php';
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

function addon_highlight_render(DOMDocument $doc,array $context): void {
    $budget=65536;
    foreach (iterator_to_array($doc->getElementsByTagName('code')) as $code) {
        if ($code->parentNode?->nodeName!=='pre' || !str_starts_with($code->getAttribute('class'),'language-')) continue;
        $language=substr($code->getAttribute('class'),9);
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
}
