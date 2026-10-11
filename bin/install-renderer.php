<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
// Pin the official release and verify its published archive digest before extraction.
if (PHP_OS_FAMILY!=='Linux' || php_uname('m')!=='x86_64') {
    fwrite(STDERR,"Install mmdr 0.3.1 for your platform and set BLOG_MERMAID_BIN. See README.md.\n");exit(1);
}
$root=dirname(__DIR__);$dir=sys_get_temp_dir().'/blog-renderer-'.bin2hex(random_bytes(8));mkdir($dir,0700);
$exitCode=0;
try {
    $url='https://github.com/1jehuang/mermaid-rs-renderer/releases/download/v0.3.1/mmdr-x86_64-unknown-linux-gnu.tar.gz';
    $context=stream_context_create(['http'=>['timeout'=>30,'user_agent'=>'blogs installer'],'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]]);
    $stream=fopen($url,'rb',false,$context);if (!$stream) throw new RuntimeException('Download failed');
    $archive=stream_get_contents($stream,16777217);fclose($stream);
    if (strlen($archive)>16777216 || !hash_equals('e1da47b758769bff21b82a480fad640a3b893a90fc0dc321551486f8b50c7200',hash('sha256',$archive))) throw new RuntimeException('Archive checksum mismatch');
    file_put_contents($dir.'/mmdr.tar.gz',$archive);
    $process=proc_open(['tar','-xzf',$dir.'/mmdr.tar.gz','-C',$dir,'./mmdr'],[0=>['file','/dev/null','r'],1=>STDOUT,2=>STDERR],$pipes);
    if (!is_resource($process) || proc_close($process)!==0 || !is_file($dir.'/mmdr') || is_link($dir.'/mmdr')) throw new RuntimeException('Extraction failed');
    chmod($dir.'/mmdr',0755);
    // Copy then rename in the destination filesystem for an atomic installation.
    if (!copy($dir.'/mmdr',$root.'/bin/mmdr.new') || !chmod($root.'/bin/mmdr.new',0755) || !rename($root.'/bin/mmdr.new',$root.'/bin/mmdr')) throw new RuntimeException('Installation failed');
    echo "mmdr 0.3.1 installed in bin/mmdr.\n";
} catch (Throwable $error) { fwrite(STDERR,$error->getMessage()."\n");$exitCode=1; }
finally { foreach (glob($dir.'/*') as $file) unlink($file);rmdir($dir); }
exit($exitCode);
