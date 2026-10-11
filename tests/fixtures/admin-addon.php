<?php
// Test-only global addon. This is not a guestbook or an analytics feature.
$platform=static function (array $context): bool {
    if ($context['path']===['redirect']) redirect('/');
    if (!in_array($context['path'],[['ping'],['observer-error']],true)) return false;
    page('addons',static function (): void {
        echo '<p>admin route</p><form method="post">';token_field();button('save');echo '</form>';
    },addon:$context['addon']);
    return true;
};
$blogRoute=static function (array $context): bool {
    if ($context['path']!==['ping']) return false;
    page('addons',static function () use($context): void { echo '<p>global blog '.e($context['blog']['id']).'</p>'; });
    return true;
};
return [
    'api'=>1,'type'=>'admin','label'=>['es'=>'prueba global'],
    'default'=>true, // Must never put an admin addon into blog_addons.
    'style'=>__DIR__.'/style.css',
    'parser'=>static function (AddonMarkdown $parser): void {
        $parser->add_inline(['%'],static function ($excerpt) {
            if (str_starts_with($excerpt['text'],'%global%')) return ['extent'=>8,'element'=>['name'=>'code','attributes'=>['class'=>'addon-admin-probe'],'text'=>'global']];
        });
    },
    'render'=>static function (DOMDocument $doc,array $context): void {
        foreach (iterator_to_array($doc->getElementsByTagName('code')) as $code) if ($code->getAttribute('class')==='addon-admin-probe') $code->nodeValue='GLOBAL';
    },
    'slots'=>[
        'home_after'=>static function (array $context): void { echo '<section class="admin-probe">admin home</section>'; },
        'nav'=>static function (array $context): void { echo '<a class="admin-probe" href="/'.e($context['blog']['username']).'/_addons/admin-probe/ping">global</a>'; },
        'post_after'=>static function (array $context): void { echo '<p>admin global post</p>'; },
    ],
    'routes'=>['platform'=>['GET'=>$platform,'POST'=>$platform],'public'=>['GET'=>$blogRoute],'manage'=>['GET'=>$blogRoute]],
    'request_finished'=>static function (array $context): void {
        file_put_contents(config()['database'].'.observations',json_encode($context)."\n",FILE_APPEND|LOCK_EX);
        echo 'OBSERVER OUTPUT MUST NOT LEAK';
        if ($context['path']==='/_addons/admin-probe/observer-error') throw new RuntimeException('test-only observer failure');
    },
];
