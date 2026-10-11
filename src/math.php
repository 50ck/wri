<?php
declare(strict_types=1);

// Keep TeX out of Markdown's emphasis/escaping handlers, including inside quotes/lists.
class MathMarkdown extends Parsedown {
    public function __construct() {
        $this->InlineTypes['$']=['Math'];
        array_unshift($this->InlineTypes['\\'],'Math');
        $this->inlineMarkerList.='$';
        $this->BlockTypes['$']=['Math']; $this->BlockTypes['\\']=['Math'];
    }
    protected function inlineMath($excerpt) {
        $text=$excerpt['text']; $display='inline'; $match=[];
        if (str_starts_with($text,'$$')) {
            if (!preg_match('/\A\$\$(.+?)\$\$/s',$text,$match)) return;
            $display='block';
        } elseif (str_starts_with($text,'\\(')) {
            $end=strpos($text,'\\)',2); if ($end===false) return;
            $match=[substr($text,0,$end+2),substr($text,2,$end-2)];
        } elseif (str_starts_with($text,'\\[')) {
            $end=strpos($text,'\\]',2); if ($end===false) return;
            $match=[substr($text,0,$end+2),substr($text,2,$end-2)]; $display='block';
        } elseif (!preg_match('/\A\$(?![\s$])((?:\\\\.|[^$\\\\\n])+?)(?<!\s)\$(?![\d$])/',$text,$match)) return;
        return ['extent'=>strlen($match[0]),'element'=>['name'=>'code','attributes'=>['class'=>'math-'.$display],'text'=>$match[1]]];
    }
    protected function blockMath($line) {
        $text=trim($line['text']);
        $open=str_starts_with($text,'$$') ? '$$' : (str_starts_with($text,'\\[') ? '\\[' : null);
        if (!$open) return;
        $close=$open==='$$' ? '$$' : '\\]'; $rest=substr($text,2);
        $complete=$rest!=='' && str_ends_with($rest,$close);
        return ['close'=>$close,'open'=>$open,'complete'=>$complete,'element'=>['name'=>'pre','element'=>['name'=>'code','attributes'=>['class'=>'math-block'],'text'=>$complete ? substr($rest,0,-2) : $rest]]];
    }
    protected function blockMathContinue($line,$block) {
        if ($block['complete']) return;
        $text=$line['body'];
        if (isset($block['interrupted'])) { $block['element']['element']['text'].=str_repeat("\n",$block['interrupted']);unset($block['interrupted']); }
        if (str_ends_with(rtrim($text),$block['close'])) {
            $block['element']['element']['text'].="\n".substr(rtrim($text),0,-2); $block['complete']=true;
        } else $block['element']['element']['text'].="\n".$text;
        return $block;
    }
    protected function blockMathComplete($block) {
        if (!$block['complete']) {
            unset($block['element']['element']['attributes']);
            $block['element']['element']['text']=$block['open'].$block['element']['element']['text'];
        }
        return $block;
    }
}
function math_element(DOMDocument $doc,string $tex,string $display): ?DOMElement {
    if (strlen($tex)>4096 || trim($tex)==='' || !mb_check_encoding($tex,'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$tex)) return null;
    $depth=0;
    for ($i=0;$i<strlen($tex);$i++) { $char=$tex[$i];if ($char==='\\') { $i++;continue; } if ($char==='{') $depth++; elseif ($char==='}') $depth--; if ($depth>48 || $depth<0) return null; }
    if ($depth!==0) return null;
    static $loaded=false;
    if (!$loaded) {
        spl_autoload_register(function (string $class): void {
            if (str_starts_with($class,'Latex2MathML\\')) require dirname(__DIR__).'/vendor/latex2mathml/src/'.str_replace('\\','/',substr($class,13)).'.php';
        }); $loaded=true;
    }
    set_error_handler(static function (int $level,string $message): never { throw new ErrorException($message,0,$level); });
    try { $math=\Latex2MathML\Converter::convert_to_element($tex,$doc,display:$display,xmlns:''); }
    catch (Throwable) { return null; }
    finally { restore_error_handler(); }
    $allowed=['math','mrow','mi','mo','mn','mtext','ms','mspace','mfrac','msqrt','mroot','msub','msup','msubsup','munder','mover','munderover','mtable','mtr','mtd','mlabeledtr','mstyle','mpadded','mphantom','menclose','mfenced','mmultiscripts','mprescripts','none'];
    $attributes=['display','stretchy','notation','scriptlevel','width','lspace','rspace','voffset','height','depth','columnalign','rowalign','displaystyle','mathvariant','fence','form','rowspacing','columnspacing','symmetric','linethickness','columnlines','rowlines','accent','accentunder','separator','minsize','maxsize','columnspan','rowspan'];
    foreach (array_merge([$math],iterator_to_array($math->getElementsByTagName('*'))) as $node) {
        if (!in_array($node->tagName,$allowed,true)) return null;
        foreach (iterator_to_array($node->attributes) as $attribute) {
            // These presentation attributes cannot introduce links, CSS, events or resources.
            if (!in_array($attribute->name,$attributes,true) || !preg_match('/\A[a-zA-Z0-9+.% -]{1,160}\z/',$attribute->value)) $node->removeAttribute($attribute->name);
        }
    }
    if (strlen($doc->saveXML($math))>131072) return null;
    return $math;
}
