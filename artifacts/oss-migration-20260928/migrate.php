<?php
// Bounded local migration runner. Parent retains the normal global advisory lock;
// independent workers own disjoint disk/key hashes and call the unchanged service.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Application\Media\MigrateImages;
use App\Domain\Media\StoredImage;
if(!app()->environment('local')||DB::connection()->getDatabaseName()!=='card_mock')throw new RuntimeException('Local card_mock only');
$images=app(ImageStorage::class);$active=$images->active();
if(!$active?->verified_at)throw new RuntimeException('Verified active OSS required');
if(($argv[1]??'')==='--worker'){
    if(getenv('OSS_MIGRATION_RUN')!==($argv[3]??null)||$active->id!==($argv[4]??null))throw new RuntimeException('Parent required');
    $worker=(int)$argv[2];$total=['migrated'=>0,'failed'=>0,'skipped'=>0];$seen=[];
    foreach(app(ImageReferences::class)->all() as $ref){
        $identity=$ref['disk'].':'.$ref['key'];
        if(isset($seen[$identity])||crc32($identity)%8!==$worker)continue;$seen[$identity]=true;
        $record=$images->record($ref['disk'],$ref['key']);
        if($record?->configuration_id){$total['skipped']++;continue;}
        try{app(MigrateImages::class)->migrate($ref);$total['migrated']++;}
        catch(Throwable){
            StoredImage::firstOrCreate(['source_disk'=>$ref['disk'],'source_key'=>$ref['key']],['tenant_id'=>$ref['tenant'],'purpose'=>$ref['purpose'],'business_reference'=>$ref['reference'],'object_key'=>$ref['key'],'mime'=>'application/octet-stream','size'=>0,'sha256'=>str_repeat('0',64),'codec'=>$ref['codec'],'state'=>'ready'])->update(['last_error'=>'MIGRATION_FAILED']);
            $total['failed']++;
        }
        if(($total['migrated']+$total['failed'])%25===0)echo json_encode(['worker'=>$worker,'progress'=>$total]).PHP_EOL;
    }
    echo json_encode(['worker'=>$worker,'final'=>$total]).PHP_EOL;exit($total['failed']?1:0);
}
if(($argv[1]??'')!=='--execute')throw new RuntimeException('Explicit --execute required');
if(!DB::selectOne('select pg_try_advisory_lock(20260927,140000) as acquired')->acquired)throw new RuntimeException('Migration already running');
$children=[];$run=bin2hex(random_bytes(16));putenv('OSS_MIGRATION_RUN='.$run);
try{
    for($i=0;$i<8;$i++){
        $children[]=proc_open([PHP_BINARY,__FILE__,'--worker',(string)$i,$run,$active->id],[0=>['file','/dev/null','r'],1=>['file',__DIR__.'/worker-'.$i.'.log','a'],2=>['file',__DIR__.'/worker-'.$i.'.log','a']],$pipes);
    }
    $results=[];foreach($children as $child)$results[]=proc_close($child);
    echo json_encode(['worker_exit_codes'=>$results]).PHP_EOL;
}finally{DB::select('select pg_advisory_unlock(20260927,140000)');}
