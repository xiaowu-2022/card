<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Domain\Media\OssConfiguration;
if (!app()->environment('local') || DB::connection()->getDatabaseName()!=='card_mock') throw new RuntimeException('Local card_mock only');
$images=app(ImageStorage::class);
$config=$images->active();
if (in_array('--activate',$argv,true) && !$config) {
    $config=OssConfiguration::whereNotNull('verified_at')->latest()->firstOrFail();
    $actor=App\Domain\Admin\Models\AdminUser::findOrFail($config->created_by);
    app(App\Application\Media\OssSettings::class)->activate($config,$actor);
}
$counts=[];$seen=[];
foreach (app(ImageReferences::class)->all() as $ref) {
    $id=$ref['disk'].':'.$ref['key']; if(isset($seen[$id]))continue; $seen[$id]=true;
    $record=$images->record($ref['disk'],$ref['key']);
    $state=$record?->configuration_id?'oss':(Storage::disk($ref['disk'])->exists($ref['key'])?'local':'missing');
    $counts[$ref['purpose']][$state]=($counts[$ref['purpose']][$state]??0)+1;
}
$cutoff=in_array('--baseline',$argv,true)?json_decode(file_get_contents(__DIR__.'/checkpoint.json'),true)['cutoff']:now()->toIso8601String();
$business=[];
foreach (['ledger_entries','kyc_applications','provider_cardholders','support_messages','card_issue_orders'] as $table) {
    $h=hash_init('sha256');$n=0;
    foreach(DB::table($table)->where('created_at','<=',$cutoff)->orderBy('id')->cursor() as $row){hash_update($h,json_encode($row));$n++;}
    $business[$table]=['count'=>$n,'sha256'=>hash_final($h)];
}
echo json_encode(['environment'=>app()->environment(),'database'=>DB::connection()->getDatabaseName(),'active_configuration'=>$config?->id,'images'=>$counts,'cutoff'=>$cutoff,'business'=>$business],JSON_PRETTY_PRINT).PHP_EOL;
