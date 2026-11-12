<?php
try {
    $key=trim(file_get_contents(__DIR__.'/../storage/app/container.key'));
    putenv('APP_KEY='.$key);$_ENV['APP_KEY']=$_SERVER['APP_KEY']=$key;
    require dirname(__DIR__).'/vendor/autoload.php';
    $app=require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::select('SELECT 1');
    if (!is_file(storage_path('app/delivery-initialized.json'))) exit(1);
    $response=@file_get_contents('http://127.0.0.1/up');
    exit($response===false?1:0);
}catch(Throwable){exit(1);}
