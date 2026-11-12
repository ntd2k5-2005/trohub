<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan,DB};
// Only the new, self-contained delivery database can be initialized here.
if (config('database.default')!=='mysql' || config('database.connections.mysql.host')!=='mysql'
    || config('database.connections.mysql.database')!=='trohub') throw new RuntimeException('Unsafe delivery database target');
$marker=storage_path('app/delivery-initialized.json');
if(is_file($marker)){echo "Delivery already initialized; existing data retained.\n";exit(0);}
// A non-empty unmarked database must never be overwritten or automatically adopted.
if(DB::table('users')->exists()) throw new RuntimeException('Unmarked non-empty database; manual review required. No seed performed.');
// Only supplied synthetic housing images are copied, never private uploads from the working app.
$images=base_path('docker/demo-images');
if(is_dir($images)){
    $destination=storage_path('app/private/housing-images/demo-v1');
    if(!is_dir($destination) && !mkdir($destination,0775,true)) throw new RuntimeException('Cannot create demo image directory');
    foreach(glob($images.'/property-*-*.png')as$source){
        if(!copy($source,$destination.'/'.basename($source))) throw new RuntimeException('Cannot copy demo image');
    }
}
config(['data_stores.mongodb.enabled'=>false,'data_stores.redis.enabled'=>false,'data_stores.cassandra.enabled'=>false,'data_stores.neo4j.enabled'=>false,'data_stores.meilisearch.enabled'=>false]);
DB::transaction(function(){
    Artisan::call('db:seed',['--class'=>'DatabaseSeeder','--force'=>true]);echo Artisan::output();
    // Reuse the existing bounded importer; approval is scoped to the guarded delivery DB only.
    putenv('TROHUB_DEMO_APPLY_APPROVED=TROHUB_DEMO_V1');
    try { (new Database\Seeders\TroHubDemoV1Seeder)->run(); }
    finally { putenv('TROHUB_DEMO_APPLY_APPROVED'); }
    (new Database\Seeders\PresentationCleanupSeeder)->run();
    DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at'=>now()]);
});
if(file_put_contents($marker,json_encode(['initialized_at'=>now()->toIso8601String(),'mode'=>'existing-seeders','users'=>DB::table('users')->count()],JSON_PRETTY_PRINT))===false)throw new RuntimeException('Cannot save initialization marker');
echo "Delivery initialized. Existing users are verified; new registrations follow the normal verification workflow.\n";
