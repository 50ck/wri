<?php
declare(strict_types=1);
umask(0077);
require __DIR__.'/config.php';
require __DIR__.'/strings.php';
require __DIR__.'/database.php';
require __DIR__.'/security.php';
require __DIR__.'/content.php';
require __DIR__.'/markdown.php';
require __DIR__.'/rendering.php';
require __DIR__.'/routing.php';
function run(): void {
    ini_set('display_errors','0');ini_set('log_errors','1');error_reporting(E_ALL & ~E_DEPRECATED);
    ob_start();
    try {
        headers_for();
        if ((int)($_SERVER['CONTENT_LENGTH']??0)>config()['request_limit'] || strlen(file_get_contents('php://input', false, null, 0, config()['request_limit']+1)) > config()['request_limit']) fail(413,'too_large');
        if ($_SERVER['REQUEST_METHOD']==='POST' && stripos($_SERVER['CONTENT_TYPE']??'','application/x-www-form-urlencoded')!==0) fail(400,'invalid');
        start_session();route();
    } catch (HttpError $e) {
        ob_clean();
        http_response_code($e->status);page('error',function () use($e) {echo '<p>'.e($e->getMessage()).'</p><p>'.link_to('/','home').' '.link_to('/login','login').'</p>';});
    } catch (Throwable $e) {
        ob_clean();
        // Never log request values, passwords, cookies, or exception messages containing data.
        error_log('Blog error '.get_class($e).' in '.basename($e->getFile()).':'.$e->getLine());
        if (config()['debug']) foreach ($e->getTrace() as $frame) error_log('Blog trace '.basename($frame['file'] ?? '').':'.($frame['line'] ?? 0).' '.($frame['function'] ?? ''));
        http_response_code(500);page('error',function () {echo '<p>'.e(t('server_error')).'</p>';});
    } finally { ob_end_flush(); }
}
