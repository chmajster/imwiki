<?php
declare(strict_types=1);

$docroot=(string)getenv('SMOKE_DOCROOT');
if($docroot===''){
    http_response_code(500);
    echo 'SMOKE_DOCROOT missing';
    return true;
}
$path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH)?:'/';
$file=rtrim($docroot,'/').$path;
if($path!=='/'&&is_file($file)){
    return false;
}
require $docroot.'/index.php';
return true;
