<?php
declare(strict_types=1);

// A bounded flowchart subset, rendered locally with no scripts or external services.
// Unsupported Mermaid syntax remains readable as escaped source in Markdown.
function mermaid_flowchart(string $source): ?array {
    if (strlen($source)>8192 || !mb_check_encoding($source,'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$source)) return null;
    $source=str_replace(["\r\n","\r"],"\n",$source);
    if (!preg_match('/\A\s*(?:flowchart|graph)[ \t]+(LR|RL|TB|TD|BT)(?=\s|;|$)/',$source,$header)) return null;
    $offset=strlen($header[0]); $nodes=[]; $edges=[];
    while ($offset<strlen($source)) {
        if (preg_match('/\G[\s;]+/',$source,$space,0,$offset)) $offset+=strlen($space[0]);
        if ($offset===strlen($source)) break;
        if (substr($source,$offset,2)==='%%') { $offset=strpos($source,"\n",$offset) ?: strlen($source); continue; }
        $from=mermaid_node($source,$offset,$nodes);
        if ($from===null) return null;
        while (true) {
            if (preg_match('/\G[ \t]+/',$source,$space,0,$offset)) $offset+=strlen($space[0]);
            if (substr($source,$offset,3)!=='-->') break;
            $offset+=3;
            if (preg_match('/\G[ \t]+/',$source,$space,0,$offset)) $offset+=strlen($space[0]);
            $label='';
            if (substr($source,$offset,1)==='|') {
                if (!preg_match('/\G\|([^|\n]{0,240})\|/u',$source,$match,0,$offset) || mb_strlen($match[1],'UTF-8')>60) return null;
                $label=trim($match[1]); $offset+=strlen($match[0]);
            }
            $to=mermaid_node($source,$offset,$nodes);
            if ($to===null || count($edges)>=80) return null;
            $edges[]=['from'=>$from,'to'=>$to,'label'=>$label]; $from=$to;
        }
        if ($offset<strlen($source) && !in_array($source[$offset],["\n",';'],true) && substr($source,$offset,2)!=='%%') return null;
    }
    return $nodes ? ['direction'=>$header[1],'nodes'=>$nodes,'edges'=>$edges] : null;
}
function mermaid_node(string $source,int &$offset,array &$nodes): ?string {
    if (!preg_match('/\G[ \t]*([A-Za-z_][A-Za-z0-9_]{0,63})(?:[ \t]*(?:\[("[^"\n]*"|[^\[\]\n]*)\]|\{("[^"\n]*"|[^{}\n]*)\}|\(("[^"\n]*"|[^()\n]*)\)))?/u',$source,$match,0,$offset)) return null;
    $offset+=strlen($match[0]); $id=$match[1];
    if (!isset($nodes[$id]) && count($nodes)>=40) return null;
    $nodes[$id]??=['label'=>$id,'shape'=>'box'];
    foreach ([2=>'box',3=>'diamond',4=>'rounded'] as $index=>$shape) {
        if (!array_key_exists($index,$match) || $match[$index]==='') continue;
        $label=trim($match[$index]);
        if (str_starts_with($label,'"') && str_ends_with($label,'"')) $label=substr($label,1,-1);
        if (mb_strlen($label,'UTF-8')>120) return null;
        $nodes[$id]=['label'=>$label,'shape'=>$shape]; break;
    }
    return $id;
}
function mermaid_lines(string $label,int $limit): array {
    $lines=[]; $line='';
    foreach (preg_split('/\s+/u',trim($label)) as $word) {
        while (mb_strlen($word,'UTF-8')>$limit) {
            if ($line!=='') { $lines[]=$line; $line=''; }
            $lines[]=mb_substr($word,0,$limit,'UTF-8'); $word=mb_substr($word,$limit,null,'UTF-8');
        }
        if ($line!=='' && mb_strlen($line.' '.$word,'UTF-8')>$limit) { $lines[]=$line; $line=''; }
        $line=$line==='' ? $word : $line.' '.$word;
    }
    $lines[]=$line;
    return $lines;
}
function mermaid_svg(string $source): ?string {
    $graph=mermaid_flowchart($source);
    if (!$graph) return null;
    $nodes=$graph['nodes']; $edges=$graph['edges']; $adj=[];
    foreach ($edges as $index=>$edge) $adj[$edge['from']][]=$index;
    // Ignore DFS back edges for ranks; draw those separately as return arrows.
    $state=[]; $order=[];
    $visit=function(string $id) use (&$visit,&$state,&$order,&$edges,$adj): void {
        $state[$id]=1;
        foreach ($adj[$id] ?? [] as $index) {
            $to=$edges[$index]['to'];
            $edges[$index]['back']=($state[$to] ?? 0)===1;
            if (!isset($state[$to])) $visit($to);
        }
        $state[$id]=2; $order[]=$id;
    };
    foreach ($nodes as $id=>$node) if (!isset($state[$id])) $visit($id);
    $ranks=array_fill_keys(array_keys($nodes),0);
    foreach (array_reverse($order) as $id) foreach ($adj[$id] ?? [] as $index) {
        $edge=$edges[$index];
        if (!$edge['back']) $ranks[$edge['to']]=max($ranks[$edge['to']],$ranks[$id]+1);
    }
    $groups=[]; $height=96; $width=240;
    foreach ($nodes as $id=>&$node) {
        $node['lines']=mermaid_lines($node['label'],$node['shape']==='diamond' ? 18 : 26);
        $height=max($height,(count($node['lines'])*18+24)*($node['shape']==='diamond' ? 2 : 1));
        $groups[$ranks[$id]][]=$id;
    }
    unset($node);
    $vertical=in_array($graph['direction'],['TB','TD','BT'],true);
    $reverse=in_array($graph['direction'],['RL','BT'],true);
    $maxRank=max($ranks); $rows=max(array_map('count',$groups));
    $gap=100;
    foreach ($edges as $edge) $gap=max($gap,mb_strlen($edge['label'],'UTF-8')*8+24);
    $step=($vertical ? $height : $width)+$gap; $cross=($vertical ? $width : $height)+60;
    $backCount=count(array_filter($edges,fn($edge)=>$edge['back']));
    $canvasWidth=64+($vertical ? ($rows-1)*$cross+$width+$backCount*26 : $maxRank*$step+$width);
    $canvasHeight=64+($vertical ? $maxRank*$step+$height : ($rows-1)*$cross+$height+$backCount*26);
    foreach ($groups as $rank=>$ids) foreach ($ids as $row=>$id) {
        $primary=32+($reverse ? $maxRank-$rank : $rank)*$step+($vertical ? $height : $width)/2;
        $secondary=32+(($rows-count($ids))/2+$row)*$cross+($vertical ? $width : $height)/2;
        $nodes[$id]['x']=$vertical ? $secondary : $primary;
        $nodes[$id]['y']=$vertical ? $primary : $secondary;
    }
    $escape=fn(string $value)=>htmlspecialchars($value,ENT_QUOTES|ENT_XML1,'UTF-8');
    $svg='<svg xmlns="http://www.w3.org/2000/svg" class="mermaid-diagram" role="img" aria-label="'.$escape(t('flowchart')).'" width="'.$canvasWidth.'" height="'.$canvasHeight.'" viewBox="0 0 '.$canvasWidth.' '.$canvasHeight.'"><title>'.$escape(t('flowchart')).'</title><desc>'.$escape($source).'</desc>';
    $labels=''; $lane=0;
    foreach ($edges as $edge) {
        $a=$nodes[$edge['from']]; $b=$nodes[$edge['to']]; $sign=$reverse ? -1 : 1;
        if ($edge['back']) {
            $lane++;
            if ($vertical) {
                $sx=$a['x']+$width/2; $sy=$a['y']; $ex=$b['x']+$width/2; $ey=$b['y'];
                $route=$canvasWidth-32-($lane-1)*26;
                $path="M $sx $sy H $route V $ey H $ex"; $dx=-1; $dy=0; $lx=$route-12; $ly=($sy+$ey)/2;
            } else {
                $sx=$a['x']; $sy=$a['y']+$height/2; $ex=$b['x']; $ey=$b['y']+$height/2;
                $route=$canvasHeight-32-($lane-1)*26;
                $path="M $sx $sy V $route H $ex V $ey"; $dx=0; $dy=-1; $lx=($sx+$ex)/2; $ly=$route-12;
            }
            if ($edge['from']===$edge['to']) {
                $sx=$a['x']+$width/2; $sy=$a['y']; $ex=$a['x']; $ey=$a['y']+$height/2;
                $rx=$sx+20; $ry=$ey+20;
                $path="M $sx $sy H $rx V $ry H $ex V $ey"; $dx=0; $dy=-1; $lx=$rx; $ly=$ry;
            }
        } elseif ($vertical) {
            $sx=$a['x']; $sy=$a['y']+$sign*$height/2; $ex=$b['x']; $ey=$b['y']-$sign*$height/2; $mid=($sy+$ey)/2;
            $path="M $sx $sy C $sx $mid $ex $mid $ex $ey"; $dx=0; $dy=$sign; $lx=($sx+$ex)/2; $ly=$mid;
        } else {
            $sx=$a['x']+$sign*$width/2; $sy=$a['y']; $ex=$b['x']-$sign*$width/2; $ey=$b['y']; $mid=($sx+$ex)/2;
            $path="M $sx $sy C $mid $sy $mid $ey $ex $ey"; $dx=$sign; $dy=0; $lx=$mid; $ly=($sy+$ey)/2;
        }
        $p1x=$ex-9*$dx+4*$dy; $p1y=$ey-9*$dy-4*$dx; $p2x=$ex-9*$dx-4*$dy; $p2y=$ey-9*$dy+4*$dx;
        $svg.='<path class="mermaid-edge" d="'.$path.'"/><polygon class="mermaid-arrow" points="'.$ex.','.$ey.' '.$p1x.','.$p1y.' '.$p2x.','.$p2y.'"/>';
        if ($edge['label']!=='') {
            $labelWidth=mb_strlen($edge['label'],'UTF-8')*8+12;
            $labels.='<rect class="mermaid-label-background" x="'.($lx-$labelWidth/2).'" y="'.($ly-12).'" width="'.$labelWidth.'" height="24"/><text class="mermaid-label" x="'.$lx.'" y="'.($ly+5).'">'.$escape($edge['label']).'</text>';
        }
    }
    $svg.=$labels;
    foreach ($nodes as $node) {
        $x=$node['x']; $y=$node['y']; $left=$x-$width/2; $top=$y-$height/2;
        $svg.='<g class="mermaid-node">';
        if ($node['shape']==='diamond') $svg.='<polygon points="'.$x.','.$top.' '.($x+$width/2).','.$y.' '.$x.','.($y+$height/2).' '.$left.','.$y.'"/>';
        else $svg.='<rect x="'.$left.'" y="'.$top.'" width="'.$width.'" height="'.$height.'"'.($node['shape']==='rounded' ? ' rx="12"' : '').'/>';
        $svg.='</g><text class="mermaid-label" x="'.$x.'" y="'.($y-(count($node['lines'])-1)*9+5).'">';
        foreach ($node['lines'] as $index=>$line) $svg.='<tspan x="'.$x.'"'.($index ? ' dy="18"' : '').'>'.$escape($line).'</tspan>';
        $svg.='</text>';
    }
    return $svg.'</svg>';
}
