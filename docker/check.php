<?php
// Read-only diagnostic for the isolated delivery stack. No credentials printed.
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{DB,Hash,Http};
if(config('database.connections.mysql.host')!=='mysql'||config('database.connections.mysql.database')!=='trohub')throw new RuntimeException('Not the delivery database');
$result=['php'=>PHP_VERSION,'laravel'=>Illuminate\Foundation\Application::VERSION,'mysql'=>DB::selectOne('SELECT VERSION() v')->v,'counts'=>[],'accounts'=>[],'native'=>[]];
$result['key_fingerprint']=hash_file('sha256',storage_path('app/container.key'));
$result['initialization_marker']=json_decode(file_get_contents(storage_path('app/delivery-initialized.json')),true);
foreach(['users','workspaces','properties','rooms','listings','rentals','invoices','domain_events']as$table)$result['counts'][$table]=DB::table($table)->count();
foreach(['supreme','platform','system','owner','tenant','staff','accountant','maintenance','user']as$key){
    $u=App\Models\User::where('email',$key.'@trohub.test')->first();
    $result['accounts'][$key]=['exists'=>(bool)$u,'verified'=>(bool)$u?->email_verified_at,'password_hashed'=>$u?Hash::check(config('demo.password'),$u->password):false];
}
foreach(['mongodb','redis','cassandra','neo4j','meilisearch']as$name){
    if(!config('data_stores.'.$name.'.enabled')){$result['native'][$name]=['enabled'=>false];continue;}
    try{
        $r=['enabled'=>true,'status'=>'PASS'];
        switch($name){
            case 'mongodb':
                $m=new MongoDB\Driver\Manager(config('data_stores.mongodb.uri'));
                foreach(['roommate_profiles','review_details','event_documents']as$c)$r[$c]=$m->executeCommand('trohub',new MongoDB\Driver\Command(['count'=>$c]))->toArray()[0]->n;
                break;
            case 'redis':$r['ping']=(string)Illuminate\Support\Facades\Redis::connection('cache')->ping();break;
            case 'cassandra':
                $workspace=(int)(DB::table('domain_events')->whereNotNull('projected_at')->orderBy('id')->value('workspace_id')??0);
                $r['sample_workspace']=$workspace;
                $r['history_rows']=count((new App\Modules\DataPlatform\Adapters\CassandraProjection)->history($workspace,'2020','2040',10));break;
            case 'neo4j':
                $j=Http::withBasicAuth('neo4j',config('data_stores.neo4j.password'))->post('http://neo4j:7474/db/neo4j/tx/commit',['statements'=>[['statement'=>'MATCH (n) RETURN count(n)']]])->throw()->json();
                if(!empty($j['errors']))throw new RuntimeException('Graph query rejected');$r['nodes']=$j['results'][0]['data'][0]['row'][0];break;
            case 'meilisearch':$r['documents']=Http::withToken(config('data_stores.meilisearch.key'))->get('http://meilisearch:7700/indexes/trohub_listings/stats')->throw()->json('numberOfDocuments');break;
        }
        $result['native'][$name]=$r;
    }catch(Throwable$e){$result['native'][$name]=['enabled'=>true,'status'=>'FAIL','error_class'=>$e::class];}
}
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
exit(count(array_filter($result['accounts'],fn($r)=>!$r['exists']||!$r['verified']||!$r['password_hashed']))||count(array_filter($result['native'],fn($r)=>($r['status']??'PASS')!=='PASS'))?1:0);
