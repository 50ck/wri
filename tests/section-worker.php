<?php
declare(strict_types=1);
require dirname(__DIR__).'/app.php';
$blog=one('SELECT * FROM blogs WHERE id=?',[(int)$argv[1]]);
try { add_section($blog,'Concurrent '.$argv[2],''); exit(0); }
catch(HttpError $e) { exit($e->status===422 ? 2 : 3); }
catch(Throwable) { exit(4); }
