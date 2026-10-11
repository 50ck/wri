<?php
// For the PHP development server only: expose exactly the two platform assets.
$path=explode('?',$_SERVER['REQUEST_URI'],2)[0];
if (in_array($path,['/assets/base.css','/assets/blog.css'],true)) return false;
require dirname(__DIR__).'/public/index.php';
