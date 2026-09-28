<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Domain\Media\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
if(!app()->environment('local')||DB::connection()->getDatabaseName()!=='card_mock')throw new RuntimeException('Local only');
$images=app(ImageStorage::class);$out=['references'=>0,'oss'=>0,'backups_verified'=>0,'new_oss_only'=>0,'failures'=>0,'samples'=>[]];$seen=[];$samples=[];
foreach(app(ImageReferences::class)->all() as $r){
 $id=$r['disk'].':'.$r['key'];if(isset($seen[$id]))continue;$seen[$id]=true;$out['references']++;
 $image=$images->record($r['disk'],$r['key']);
 if(!$image?->configuration_id||$image->state!=='ready'||$image->last_error){$out['failures']++;continue;}$out['oss']++;
 if($image->migration_configuration_id){
  try{$local=Storage::disk($r['disk'])->get($r['key']);$bytes=$images->decode($local,$r['codec']);if(!hash_equals($image->sha256,hash('sha256',$bytes)))throw new RuntimeException;$out['backups_verified']++;}
  catch(Throwable){$out['failures']++;}
 }else{$out['new_oss_only']++;}
 if(!isset($samples[$r['purpose']])||$image->size>$samples[$r['purpose']]['size'])$samples[$r['purpose']]=['ref'=>$r,'size'=>$image->size,'hash'=>$image->sha256];
}
foreach($samples as $purpose=>$s){
 $r=$s['ref'];$start=microtime(true);
 try{
  $bytes=$images->read($r['disk'],$r['key'],$r['codec']);$public=Http::connectTimeout(5)->timeout(60)->withoutRedirecting()->get($images->url($r['disk'],$r['key']));
  $ok=hash_equals($s['hash'],hash('sha256',$bytes))&&$public->successful()&&hash_equals($s['hash'],hash('sha256',$public->body()));
  $out['samples'][$purpose]=['signed_read_and_public_read'=>$ok,'size'=>$s['size'],'seconds'=>round(microtime(true)-$start,2)];if(!$ok)$out['failures']++;
 }catch(Throwable){$out['samples'][$purpose]=['result'=>'failed'];$out['failures']++;}
}
echo json_encode($out,JSON_PRETTY_PRINT).PHP_EOL;
