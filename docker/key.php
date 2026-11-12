<?php
$path=__DIR__.'/../storage/app/container.key';
$lock=fopen($path.'.lock','c');
if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Cannot lock application key');
try {
    if (!is_file($path)) {
        $key=getenv('APP_KEY') ?: 'base64:'.base64_encode(random_bytes(32));
        if (!preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/',$key)) throw new RuntimeException('Invalid APP_KEY');
        if(file_put_contents($path,$key,LOCK_EX)===false) throw new RuntimeException('Cannot save application key');
        chmod($path,0640);
    }
} finally { flock($lock,LOCK_UN);fclose($lock); }
