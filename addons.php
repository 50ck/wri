<?php
declare(strict_types=1);
// Operator-installed PHP only. This directory must not be writable by PHP-FPM.
function addons(): array {
    static $registry;
    if ($registry!==null) return $registry;
    $registry=[];$root=__DIR__.'/extensions';
    foreach (glob($root.'/*/addon.php') ?: [] as $file) {
        $id=basename(dirname($file));$real=realpath($file);
        if (!preg_match('/\A[a-z][a-z0-9-]{0,31}\z/',$id) || !$real || !str_starts_with($real,$root.'/')) continue;
        try {
            $addon=(static fn($path)=>require $path)($file);
            if (!is_array($addon) || ($addon['api']??null)!==1 || !is_string($addon['label']['es']??null)) throw new RuntimeException('Invalid addon');
            foreach (['parser','render'] as $hook) if (isset($addon[$hook]) && !is_callable($addon[$hook])) throw new RuntimeException('Invalid callback');
            foreach ($addon['slots']??[] as $slot=>$callback) if (!in_array($slot,['nav','post_after'],true) || !is_callable($callback)) throw new RuntimeException('Invalid slot');
            foreach ($addon['routes']??[] as $scope=>$routes) {
                if (!in_array($scope,['public','manage'],true) || !is_array($routes)) throw new RuntimeException('Invalid route scope');
                foreach ($routes as $method=>$callback) if (!in_array($method,['GET','POST'],true) || !is_callable($callback)) throw new RuntimeException('Invalid route');
            }
            if (isset($addon['style']) && (!is_string($addon['style']) || realpath($addon['style'])!==dirname($real).'/style.css')) throw new RuntimeException('Invalid stylesheet');
            $registry[$id]=$addon;
        } catch (Throwable) { error_log('Addon unavailable: '.$id); }
    }
    return $registry;
}
function active_addons(array $blog): array {
    $ids=query('SELECT addon_id FROM blog_addons WHERE blog_id=?',[$blog['id']])->fetchAll(PDO::FETCH_COLUMN);
    return array_intersect_key(addons(),array_fill_keys($ids,true));
}
function enable_default_addons(int $blog): void {
    foreach (addons() as $id=>$addon) if ($addon['default']??false) query('INSERT INTO blog_addons(blog_id,addon_id) VALUES (?,?)',[$blog,$id]);
}
function save_addons(array $blog,mixed $ids): void {
    if (!is_owner($blog)) fail(403,'forbidden');
    if (!is_array($ids) || count($ids)>count(addons())) fail(422,'invalid');
    foreach ($ids as $id) if (!is_string($id) || !isset(addons()[$id])) fail(422,'invalid');
    transaction(function () use($blog,$ids) {
        query('DELETE FROM blog_addons WHERE blog_id=?',[$blog['id']]);
        foreach (array_unique($ids) as $id) query('INSERT INTO blog_addons VALUES (?,?)',[$blog['id'],$id]);
    });
}
function addon_styles(array $blog): string {
    $css='';
    foreach (active_addons($blog) as $addon) if (isset($addon['style'])) $css.=file_get_contents($addon['style'])."\n";
    return $css;
}
function addon_slot(string $slot,array $blog,?array $post=null): void {
    foreach (active_addons($blog) as $addon) if (isset($addon['slots'][$slot])) ($addon['slots'][$slot])(['blog'=>$blog,'post'=>$post]);
}
function addon_route(array $blog,string $scope,array $tail): void {
    if ($scope==='manage') { if (!is_owner($blog)) fail(403,'forbidden'); }
    else can_read($blog);
    $id=array_shift($tail);$addon=active_addons($blog)[$id??'']??null;
    $routes=$addon['routes'][$scope]??[];
    if (!$routes) fail(404,'not_found');
    methods(array_keys($routes));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf();rate_limit('addon-'.$id,$blog['id'].':'.($_SERVER['REMOTE_ADDR']??''),30,3600);
    }
    // Callbacks use page() for forms, never the customizable blog rendering area.
    if (($routes[$_SERVER['REQUEST_METHOD']])(['blog'=>$blog,'path'=>$tail])!==true) fail(404,'not_found');
}
