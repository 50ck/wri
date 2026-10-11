<?php
declare(strict_types=1);
return [
    'api'=>1,
    'label'=>['es'=>'fórmulas'],
    'default'=>true,
    'style'=>__DIR__.'/style.css',
    'parser'=>static function (AddonMarkdown $parser): void {
        require_once __DIR__.'/render.php';
        $parser->add_inline(['$','\\'],'addon_math_inline');
        $parser->add_block(['$','\\'],'addon_math_start','addon_math_continue','addon_math_complete');
    },
    'render'=>static function (DOMDocument $doc,array $context): void {
        require_once __DIR__.'/render.php';
        addon_math_render($doc,$context);
    },
];
