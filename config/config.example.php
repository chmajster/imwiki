<?php
declare(strict_types=1);

// Ten plik jest wyłącznie przykładem dla developerów.
// Produkcyjny config/config.php tworzy install.php.
return [
    'app' => ['installed'=>false,'name'=>'imWiki','url'=>'http://localhost/imwiki','base_path'=>'/imwiki','secret'=>'CHANGE_ME','language'=>'pl','timezone'=>'Europe/Warsaw','debug'=>true],
    'db' => ['host'=>'localhost','port'=>3306,'database'=>'imwiki','username'=>'imwiki','password'=>'','prefix'=>''],
];
