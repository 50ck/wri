<?php
// Socket-free request harness. This file is outside the public document root.
declare(strict_types=1);
$request=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$_SERVER=['REQUEST_METHOD'=>$request['method'],'REQUEST_URI'=>$request['path'],'REMOTE_ADDR'=>$request['ip']??'127.0.0.1','CONTENT_LENGTH'=>$request['length']??0,'CONTENT_TYPE'=>$request['type']??'application/x-www-form-urlencoded'];
parse_str(explode('?',$request['path'],2)[1]??'',$_GET);
$_POST=$request['data']??[];
if (!empty($request['session'])) session_id($request['session']);
ob_start();
register_shutdown_function(function () {
    $body=ob_get_clean();
    echo json_encode(['status'=>http_response_code()?:200,'headers'=>$GLOBALS['response_headers']??[],'session'=>session_id(),'body'=>$body,'cookie_params'=>session_get_cookie_params()],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
});
require dirname(__DIR__).'/public/index.php';
