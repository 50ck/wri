<?php
declare(strict_types=1);
return [
    'api'=>1,
    'label'=>['es'=>'syntax highlighting'],
    'default'=>true,
    'style'=>__DIR__.'/style.css',
    'render'=>static function (DOMDocument $doc,array $context): void {
        require_once __DIR__.'/render.php';
        addon_highlight_render($doc,$context);
    },
];
