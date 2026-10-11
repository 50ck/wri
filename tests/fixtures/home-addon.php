<?php
return [
    'api'=>1,'type'=>'admin','label'=>['es'=>'prueba solo portada'],
    'style'=>__DIR__.'/style.css',
    'slots'=>['home_after'=>static function (array $context): void { echo '<p class="home-probe">home only</p>'; }],
    'request_finished'=>static function (array $context): void {
        file_put_contents(config()['database'].'.secondary-observations',json_encode($context)."\n",FILE_APPEND|LOCK_EX);
    },
];
