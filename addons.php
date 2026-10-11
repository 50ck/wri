<?php
declare(strict_types=1);
// Operator-installed PHP only. This directory must not be writable by PHP-FPM.
function addons(?string $type=null): array {
    static $registry;
    if ($registry===null) {
        $registry=[];$root=__DIR__.'/extensions';
        foreach (glob($root.'/*/addon.php') ?: [] as $file) {
            $id=basename(dirname($file));$real=realpath($file);
            if (!preg_match('/\A[a-z][a-z0-9-]{0,31}\z/',$id) || !$real || !str_starts_with($real,$root.'/')) continue;
            try {
                $addon=(static fn($path)=>require $path)($file);
                if (!is_array($addon) || ($addon['api']??null)!==1 || !is_string($addon['label']['es']??null) || !in_array($addon['type']??null,['user','admin'],true)) throw new RuntimeException('Invalid addon');
                foreach (['slots','routes'] as $map) if (isset($addon[$map]) && !is_array($addon[$map])) throw new RuntimeException('Invalid callback map');
                foreach (['parser','render','request_finished'] as $hook) if (isset($addon[$hook]) && !is_callable($addon[$hook])) throw new RuntimeException('Invalid callback');
                if ($addon['type']==='user' && isset($addon['request_finished'])) throw new RuntimeException('Admin callback required');
                $slots=$addon['type']==='admin' ? ['nav','post_after','home_after'] : ['nav','post_after'];
                foreach ($addon['slots']??[] as $slot=>$callback) if (!in_array($slot,$slots,true) || !is_callable($callback)) throw new RuntimeException('Invalid slot');
                $scopes=$addon['type']==='admin' ? ['public','manage','platform'] : ['public','manage'];
                foreach ($addon['routes']??[] as $scope=>$routes) {
                    if (!in_array($scope,$scopes,true) || !is_array($routes)) throw new RuntimeException('Invalid route scope');
                    foreach ($routes as $method=>$callback) if (!in_array($method,['GET','POST'],true) || !is_callable($callback)) throw new RuntimeException('Invalid route');
                }
                if (isset($addon['style']) && (!is_string($addon['style']) || realpath($addon['style'])!==dirname($real).'/style.css')) throw new RuntimeException('Invalid stylesheet');
                $registry[$id]=$addon;
            } catch (Throwable) { error_log('Addon unavailable: '.$id); }
        }
    }
    return $type===null ? $registry : array_filter($registry,static fn($addon)=>$addon['type']===$type);
}
function active_addons(?array $blog=null): array {
    if ($blog===null) return addons('admin');
    $ids=query('SELECT addon_id FROM blog_addons WHERE blog_id=?',[$blog['id']])->fetchAll(PDO::FETCH_COLUMN);
    $selected=array_fill_keys($ids,true);
    return array_filter(addons(),static fn($addon,$id)=>$addon['type']==='admin' || isset($selected[$id]),ARRAY_FILTER_USE_BOTH);
}
function enable_default_addons(int $blog): void {
    foreach (addons('user') as $id=>$addon) if ($addon['default']??false) query('INSERT INTO blog_addons(blog_id,addon_id) VALUES (?,?)',[$blog,$id]);
}
function save_addons(array $blog,mixed $ids): void {
    if (!is_owner($blog)) fail(403,'forbidden');
    $installed=addons('user');
    if (!is_array($ids) || count($ids)>count($installed)) fail(422,'invalid');
    foreach ($ids as $id) if (!is_string($id) || !isset($installed[$id])) fail(422,'invalid');
    transaction(function () use($blog,$ids) {
        query('DELETE FROM blog_addons WHERE blog_id=?',[$blog['id']]);
        foreach (array_unique($ids) as $id) query('INSERT INTO blog_addons VALUES (?,?)',[$blog['id'],$id]);
    });
}
function addon_styles(array $blog): string {
    $css='';
    foreach (active_addons($blog) as $addon) {
        $inBlog=$addon['type']==='user' || isset($addon['parser']) || isset($addon['render']) || isset($addon['slots']['nav']) || isset($addon['slots']['post_after']);
        if ($inBlog && isset($addon['style'])) $css.=file_get_contents($addon['style'])."\n";
    }
    return $css;
}
function addon_slot(string $slot,?array $blog=null,?array $post=null): void {
    foreach (active_addons($blog) as $addon) if (isset($addon['slots'][$slot])) ($addon['slots'][$slot])(['blog'=>$blog,'post'=>$post]);
}
function addon_route(?array $blog,string $scope,array $tail): void {
    if ($scope==='manage') { if (!$blog || !is_owner($blog)) fail(403,'forbidden'); }
    elseif ($scope!=='platform') { if (!$blog) fail(404,'not_found');can_read($blog); }
    $id=array_shift($tail);$addon=active_addons($blog)[$id??'']??null;
    if ($scope==='platform' && $tail===['style.css'] && isset($addon['style'])) {
        methods(['GET']);headers_for();send_header('Content-Type: text/css; charset=utf-8');echo file_get_contents($addon['style']);return;
    }
    $routes=$addon['routes'][$scope]??[];
    if (!$routes) fail(404,'not_found');
    methods(array_keys($routes));
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf();rate_limit('addon-'.$id,($blog['id']??'platform').':'.($_SERVER['REMOTE_ADDR']??''),30,3600);
    }
    // Admin means global activation, not a privileged audience. Routes remain public
    // unless a callback requires authentication/ownership; no platform admin role exists.
    if (($routes[$_SERVER['REQUEST_METHOD']])(['addon'=>$id,'blog'=>$blog,'path'=>$tail])!==true) fail(404,'not_found');
}
function addon_observe_requests(): void {
    $observers=array_filter(addons('admin'),static fn($addon)=>isset($addon['request_finished']));
    if (!$observers) return;
    $start=hrtime(true);$path=substr(explode('?',$_SERVER['REQUEST_URI']??'/',2)[0],0,256);$method=$_SERVER['REQUEST_METHOD'];
    register_shutdown_function(static function () use($observers,$start,$path,$method): void {
        $context=['path'=>$path,'method'=>$method,'status'=>http_response_code()?:200,'duration_ms'=>(hrtime(true)-$start)/1e6];
        foreach ($observers as $id=>$addon) {
            $level=ob_get_level();ob_start();
            try { ($addon['request_finished'])($context); }
            catch (Throwable) { error_log('Addon observer failed: '.$id); }
            finally { while (ob_get_level()>$level) ob_end_clean(); }
        }
    });
}
