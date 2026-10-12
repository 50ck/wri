<?php
declare(strict_types=1);

function native_diagram(string $source,string $binary,float $deadline): ?string {
    if (strlen($source)>8192 || !mb_check_encoding($source,'UTF-8') || str_contains($source,"\0") || !is_executable($binary) || microtime(true)>=$deadline) return null;
    // Array arguments bypass the shell. Source travels only through standard input.
    $process=proc_open([$binary,'-i','-','-e','svg','-t','dark'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['HOME'=>sys_get_temp_dir(),'PATH'=>'/usr/bin:/bin','LANG'=>'C.UTF-8']);
    if (!is_resource($process)) return null;
    fwrite($pipes[0],$source);fclose($pipes[0]);
    stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
    $svg=''; $limit=min($deadline,microtime(true)+2); $valid=true;
    do {
        $svg.=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);
        $status=proc_get_status($process);
        if (strlen($svg)>524288 || microtime(true)>$limit) { proc_terminate($process,9);$valid=false;break; }
        if (!$status['running']) break;
        usleep(10000);
    } while (true);
    $svg.=stream_get_contents($pipes[1]);
    fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
    return $valid && strlen($svg)<=524288 && ($status['exitcode'] ?? -1)===0 ? sanitize_diagram_svg($svg,$source) : null;
}
function sanitize_diagram_svg(string $svg,string $source): ?string {
    if (strlen($svg)>524288 || stripos($svg,'<!DOCTYPE')!==false || stripos($svg,'<!ENTITY')!==false) return null;
    $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
    $valid=$doc->loadXML($svg,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);
    if (!$valid || $doc->documentElement?->localName!=='svg') return null;
    $allowed=['svg','g','defs','marker','path','rect','circle','ellipse','polygon','polyline','line','text','tspan','title','desc'];
    $attributes=['x','y','x1','y1','x2','y2','cx','cy','r','rx','ry','width','height','viewBox','d','points','transform','fill','stroke','stroke-width','stroke-dasharray','stroke-linecap','stroke-linejoin','fill-opacity','stroke-opacity','opacity','text-anchor','font-family','font-size','font-weight','dominant-baseline','dx','dy','refX','refY','markerUnits','markerWidth','markerHeight','orient'];
    $ids=[]; $prefix='diagram-'.bin2hex(random_bytes(8)).'-';
    foreach ($doc->getElementsByTagName('*') as $node) {
        $id=$node->getAttribute('id');if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_.:-]{0,80}\z/',$id)) $ids[$id]=$prefix.$id;
    }
    foreach (iterator_to_array($doc->getElementsByTagName('*')) as $node) {
        if (!in_array($node->localName,$allowed,true) || $node->namespaceURI!=='http://www.w3.org/2000/svg') { $node->parentNode?->removeChild($node); continue; }
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $name=$attribute->name; $value=$attribute->value;
            if ($name==='id' && isset($ids[$value])) { $node->setAttribute('id',$ids[$value]);continue; }
            if (in_array($name,['marker-start','marker-mid','marker-end'],true) && preg_match('/\Aurl\(#([a-zA-Z_][a-zA-Z0-9_.:-]{0,80})\)\z/',$value,$match) && isset($ids[$match[1]])) { $node->setAttribute($name,'url(#'.$ids[$match[1]].')');continue; }
            if (!in_array($name,$attributes,true) || preg_match('/(?:url\s*\(|[\\\\<>\x00-\x1f])/i',$value)) { $node->removeAttribute($name);continue; }
            // Color fields accept colors only, never paint-server/external references.
            if (in_array($name,['fill','stroke'],true) && !preg_match('/\A(?:#[a-f0-9]{3,8}|[a-z]+|rgba?\([0-9.,% ]+\))\z/i',$value)) $node->removeAttribute($name);
        }
    }
    // Serialize without XML namespaces: the HTML parser supplies SVG's namespace.
    $out=new DOMDocument();
    $copy=function(DOMNode $node) use (&$copy,$out): ?DOMNode {
        if ($node->nodeType===XML_TEXT_NODE) return $out->createTextNode($node->textContent);
        if (!$node instanceof DOMElement) return null;
        $element=$out->createElement($node->localName);
        foreach ($node->attributes as $attribute) $element->setAttribute($attribute->name,$attribute->value);
        foreach ($node->childNodes as $child) if ($import=$copy($child)) $element->appendChild($import);
        return $element;
    };
    $root=$copy($doc->documentElement);$out->appendChild($root);
    $view=$root->getAttribute('viewBox');
    if (!preg_match('/\A[-+0-9.eE ]+\z/',$view)) return null;
    $numbers=preg_split('/\s+/',trim($view));
    if (count($numbers)!==4 || (float)$numbers[2]<=0 || (float)$numbers[3]<=0 || (float)$numbers[2]>50000 || (float)$numbers[3]>50000) return null;
    // Remove only the canvas rectangle; retain node boxes and label backgrounds.
    foreach (iterator_to_array($root->childNodes) as $child) {
        if (!$child instanceof DOMElement || $child->tagName!=='rect') continue;
        $coversCanvas=true;
        foreach (['x','y','width','height'] as $index=>$attribute) {
            $value=$child->hasAttribute($attribute) ? $child->getAttribute($attribute) : ($index<2 ? '0' : '');
            if (!is_numeric($value) || abs((float)$value-(float)$numbers[$index])>.001) { $coversCanvas=false;break; }
        }
        if ($coversCanvas) $root->removeChild($child);
        break;
    }
    $root->setAttribute('class','mermaid-diagram mermaid-native');$root->setAttribute('role','img');$root->setAttribute('aria-label',t('diagram'));
    $root->setAttribute('width',$numbers[2]);$root->setAttribute('height',$numbers[3]);
    $title=$out->createElement('title');$title->appendChild($out->createTextNode(t('diagram')));$root->insertBefore($title,$root->firstChild);
    $description=$out->createElement('desc');$description->appendChild($out->createTextNode($source));$root->insertBefore($description,$root->firstChild);
    return $out->saveXML($root);
}
function addon_mermaid_render(DOMDocument $doc,array $context): void {
    $diagramCount=0;$diagramDeadline=microtime(true)+6;
    $binary=getenv('BLOG_MERMAID_BIN') ?: (config()['mermaid']['binary'] ?? '');
    if ($binary==='') $binary=__DIR__.'/bin/mmdr';
    elseif (!str_starts_with($binary,'/')) $binary=dirname(config()['config_file']).'/'.$binary;
    foreach (iterator_to_array($doc->getElementsByTagName('code')) as $code) {
        $language=substr($code->getAttribute('class'),9);
        if ($code->parentNode?->nodeName!=='pre') continue;
        if ($language==='mermaid') {
            $svg=$diagramCount++<16 ? native_diagram($code->textContent,$binary,$diagramDeadline) : null;
            if ($svg!==null) {
                $fragment=$doc->createDocumentFragment();
                // HTML establishes the SVG namespace; avoid libxml's default: prefix.
                if ($fragment->appendXML(str_replace(' xmlns="http://www.w3.org/2000/svg"','',$svg))) {
                    $wrapper=$doc->createElement('div'); $wrapper->setAttribute('class','blog-diagram');
                    $wrapper->setAttribute('tabindex','0'); $wrapper->setAttribute('role','region');
                    $wrapper->setAttribute('aria-label',t('diagram')); $wrapper->appendChild($fragment);
                    $pre=$code->parentNode; $pre->parentNode->replaceChild($wrapper,$pre);
                }
            } else {
                $notice=$doc->createElement('p'); $notice->appendChild($doc->createTextNode(t('diagram_unsupported')));
                $code->parentNode->parentNode->insertBefore($notice,$code->parentNode);
            }
            continue;
        }
    }
}
