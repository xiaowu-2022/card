<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local')||Illuminate\Support\Facades\DB::connection()->getDatabaseName()!=='card_mock')throw new RuntimeException('Local only');
// The assigned worker has already recorded its single failed poster attempt and
// moved on. This targets that retained object only; no new remote key is created.
$ref=null;foreach(app(App\Application\Media\ImageReferences::class)->all() as $candidate){if($candidate['purpose']==='poster'){$ref=$candidate;break;}}
if(!$ref)exit;
$images=app(App\Application\Media\ImageStorage::class);
$gateway=new class extends App\Infrastructure\Storage\OssImages {
 public function put(App\Domain\Media\OssConfiguration $c,string $key,string $bytes,string $mime):void { $start=microtime(true);try{parent::put($c,$key,$bytes,$mime);echo json_encode(['put_seconds'=>round(microtime(true)-$start,2),'result'=>'ok']).PHP_EOL;}catch(Throwable $e){echo json_encode(['put_seconds'=>round(microtime(true)-$start,2),'result'=>'failed']).PHP_EOL;throw $e;} }
 public function get(App\Domain\Media\OssConfiguration $c,string $key):string { $start=microtime(true);try{$bytes=parent::get($c,$key);echo json_encode(['get_seconds'=>round(microtime(true)-$start,2),'result'=>'ok']).PHP_EOL;return $bytes;}catch(Throwable $e){echo json_encode(['get_seconds'=>round(microtime(true)-$start,2),'result'=>'failed']).PHP_EOL;throw $e;} }
};
try{$record=(new App\Application\Media\MigrateImages($images,$gateway))->migrate($ref);echo json_encode(['result'=>'migrated','remote'=>(bool)$record->configuration_id]).PHP_EOL;}
catch(Throwable){echo json_encode(['result'=>'failed_original_retained']).PHP_EOL;}
