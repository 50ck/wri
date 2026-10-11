<?php
// Contract probe installed only into the tests' temporary application copy.
$route=static function (array $context): bool {
    if ($context['path']!==['ping']) return false;
    page('addons',static function () use($context): void {
        echo '<p>probe blog '.e($context['blog']['id']).'</p><form method="post">';token_field();button('save');echo '</form>';
    });
    return true;
};
return [
    'api'=>1,'label'=>['es'=>'prueba del contrato'],
    'style'=>__DIR__.'/style.css',
    'parser'=>static function (AddonMarkdown $parser): void {
        $parser->add_inline(['%'],static function ($excerpt) {
            if (str_starts_with($excerpt['text'],'%probe%')) return ['extent'=>7,'element'=>['name'=>'code','attributes'=>['class'=>'addon-probe'],'text'=>'probe']];
        });
    },
    'render'=>static function (DOMDocument $doc,array $context): void {
        foreach (iterator_to_array($doc->getElementsByTagName('code')) as $code) if ($code->getAttribute('class')==='addon-probe') $code->nodeValue='PROBE';
    },
    'slots'=>[
        'nav'=>static function (array $context): void { echo '<a class="addon-probe" href="/'.e($context['blog']['username']).'/_addons/probe/ping">prueba</a>'; },
        'post_after'=>static function (array $context): void { echo '<p class="addon-probe">probe post '.e($context['post']['id']).'</p>'; },
    ],
    'routes'=>['public'=>['GET'=>$route,'POST'=>$route],'manage'=>['GET'=>$route,'POST'=>$route]],
];
