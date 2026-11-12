<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\{Artisan,DB,Http};
if (config('database.connections.mysql.host')!=='mysql' || config('database.connections.mysql.database')!=='trohub'
    || config('data_stores.mongodb.uri')!=='mongodb://mongodb:27017'
    || config('data_stores.cassandra.host')!=='cassandra'
    || config('data_stores.neo4j.http_uri')!=='http://neo4j:7474'
    || config('data_stores.meilisearch.host')!=='http://meilisearch:7700') throw new RuntimeException('Unsafe native initialization target');
$marker=storage_path('app/delivery-native-v1.json');
if(is_file($marker)){echo "Native stores already initialized; existing data retained.\n";exit(0);}
$mongo=new MongoDB\Driver\Manager(config('data_stores.mongodb.uri'));
foreach([
    'roommate_profiles'=>[['key'=>['user_id'=>1],'name'=>'user_id_1']],
    'review_details'=>[['key'=>['review_id'=>1],'name'=>'review_id_1']],
    'event_documents'=>[['key'=>['workspace_id'=>1,'event_time'=>-1],'name'=>'workspace_time'],['key'=>['event_type'=>1,'event_time'=>-1],'name'=>'type_time']],
]as$collection=>$indexes){
    $mongo->executeCommand('trohub',new MongoDB\Driver\Command(['createIndexes'=>$collection,'indexes'=>$indexes]));
}
$documents=new App\Modules\DataPlatform\Adapters\MongoDocuments;
$datasetUsers=DB::table('demo_dataset_records')->where('dataset','TROHUB_DEMO_V1')->where('table_name','users')->pluck('record_id');
foreach(App\Models\User::whereIn('id',$datasetUsers)->orderBy('id')->limit(150)->cursor()as$user){
    $need=App\Modules\Roommate\Models\RoommateNeed::where('user_id',$user->id)->first();
    $budget=(int)($need?->budget??3000000);
    $documents->put('roommate_profiles','roommate-profile:'.$user->id,[
        'user_id'=>$user->id,'budget'=>['minimum'=>max(0,$budget-500000),'maximum'=>$budget+500000,'currency'=>'VND','basis'=>'per_person'],
        'preferences'=>['quiet_hours'=>['from'=>'22:00','to'=>'06:00'],'smoking'=>false,'pets'=>false,'interests'=>['Đọc sách','Nấu ăn']],
        'availability'=>['move_in_after'=>today()->toDateString(),'minimum_months'=>6],
    ]);
}
foreach(App\Modules\Trust\Models\Review::orderBy('id')->cursor()as$review){
    $documents->put('review_details','review-details:'.$review->id,[
        'review_id'=>$review->id,'rental_id'=>$review->rental_id,'user_id'=>$review->user_id,
        'aspects'=>['handover'=>['comment'=>'Hai bên kiểm tra phòng và công nợ trước khi bàn giao.'],'shared_areas'=>['suggestions'=>['Bổ sung ánh sáng hành lang']]],
        'would_recommend'=>$review->rating>=4,
    ]);
}
$cluster=(new CassandraNative\Cluster\ClusterBuilder)->withContactPoints(['cassandra'])->withPort(9042)->withConnectTimeout(5)->withRequestTimeout(15)->withMaxConnectionAttempts(1)->withPersistentSessions(false)->build();
try {
    $cluster->connect('system');
    foreach(explode(';',preg_replace('/--[^\n]*/','',file_get_contents(base_path('database/nosql/cassandra.cql'))))as$cql){
        if(trim($cql)!=='')$cluster->execute(new CassandraNative\Statement\SimpleStatement(trim($cql)));
    }
}finally{$cluster->close();}
foreach(explode(';',file_get_contents(base_path('database/nosql/neo4j.cypher')))as$cypher){
    if(trim($cypher)==='')continue;
    $result=Http::connectTimeout(5)->timeout(30)->withBasicAuth('neo4j',config('data_stores.neo4j.password'))
        ->post('http://neo4j:7474/db/neo4j/tx/commit',['statements'=>[['statement'=>trim($cypher)]]])->throw()->json();
    if(!empty($result['errors']))throw new RuntimeException('Neo4j constraint initialization failed');
}
// Derived graph from existing SQL needs; never invent business rows or grant permissions.
$graph=new App\Modules\DataPlatform\Adapters\Neo4jProjection;
foreach(App\Modules\Roommate\Models\RoommateNeed::orderBy('id')->cursor()as$need){
    $graph->put(['event_id'=>'delivery-roommate-'.$need->id,'workspace_id'=>null,'event_time'=>$need->updated_at->toIso8601String(),
        'payload'=>['roommate'=>$need->only(['id','user_id','district','budget','status'])]]);
}
// Only pending deliveries are drained; later events are processed by the container scheduler.
$projection=app(App\Modules\DataPlatform\ProjectionManager::class);
$count=$projection->drain(100);
$index=app(App\Modules\Listing\ListingSearchIndex::class);
$indexed=$index->rebuild();
file_put_contents($marker,json_encode(['initialized_at'=>now()->toIso8601String(),'projected_initial_events'=>$count,'indexed_listings'=>$indexed],JSON_PRETTY_PRINT));
echo "Native structures ready; indexed {$indexed} public listings and projected {$count} initial events.\n";
