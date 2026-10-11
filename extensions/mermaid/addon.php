<?php
declare(strict_types=1);
return [
    'api'=>1, 'type'=>'user',
    'label'=>['en'=>'Mermaid diagrams'],
    'default'=>true,
    'style'=>__DIR__.'/style.css',
    'render'=>static function (DOMDocument $doc,array $context): void {
        require_once __DIR__.'/render.php';
        addon_mermaid_render($doc,$context);
    },
];
