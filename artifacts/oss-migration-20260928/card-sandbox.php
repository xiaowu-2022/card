<?php
// One new virtual card only, stable request IDs, local verified sandbox, <=25 USDT.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Application\Card\CardholderTestMaterialsArchive;
use App\Application\Card\SubmitProviderCardholderAction;
use App\Application\Card\SyncProviderCardholderAction;
use App\Application\Card\CreateCardIssueAction;
use App\Application\Card\SyncCardIssueAction;
use App\Application\Card\CardProductProviderRouter;
use App\Application\CardProduct\ConfigureTenantCardProductAction;
use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Ledger\ValueObjects\Money;
use App\Application\Media\ImageStorage;
use Illuminate\Http\UploadedFile;
use Ramsey\Uuid\Uuid;
if(!app()->environment('local')||DB::connection()->getDatabaseName()!=='card_mock')throw new RuntimeException('Local card_mock only');
$execute=($argv[1]??'')==='--execute';
$tenant='01a09996-8c36-7288-bf98-889103088ba6';$user='01a09996-8ff2-71c9-84ee-9d6157ee5576';$productId='01a09f97-2e67-7335-a853-e61b0b7d3b11';
$intent=fn($kind)=>Uuid::uuid5(Uuid::NAMESPACE_URL,'card-oss-acceptance-20260928/'.$productId.'/'.$user.'/'.$kind)->toString();
$out=['environment'=>'sandbox','execute'=>$execute];$restore=null;$temporary=null;
try{
 $product=CardProduct::findOrFail($productId);$merchant=$product->cardProviderReference;
 $connection=json_decode(Crypt::decryptString($merchant->photonpay_issuing_encrypted),true,512,JSON_THROW_ON_ERROR);
 if(($connection['base_url']??'')!==PhotonPayAccounts::BASES['sandbox']||($merchant->photonpay_identity['base_url']??'')!==PhotonPayAccounts::BASES['sandbox']||$merchant->photonpay_check_status!=='VERIFIED'||!app(CardProductProviderRouter::class)->isSandbox($product))throw new RuntimeException('Verified sandbox required');
 if(!app(ImageStorage::class)->active()?->verified_at)throw new RuntimeException('OSS must be active');
 if(Money::of($product->opening_fee,'USDT')->compare(Money::of('5','USDT'))>0||Money::of($product->minimum_initial_load,'USDT')->compare(Money::of('20','USDT'))>0)throw new RuntimeException('Budget exceeded');
 $issue=CardIssueOrder::where('tenant_id',$tenant)->where('user_id',$user)->where('request_id',$intent('issue'))->first();
 $holder=ProviderCardholder::where('tenant_id',$tenant)->where('user_id',$user)->where('request_id',$intent('holder'))->first();
 $config=TenantCardProductConfig::where('tenant_id',$tenant)->where('card_product_id',$productId)->sole();
 $count=CardIssueOrder::where('tenant_id',$tenant)->where('user_id',$user)->where('card_product_id',$productId)->where('status','!=','FAILED')->count();
 $out['opening_fee']=$product->opening_fee;$out['initial_amount']='20.00';$out['existing_issue_count']=$count;$out['original_limit']=$config->max_cards_per_user;
 if($execute&&!$issue){
  if($config->max_cards_per_user!==1||$count>=5)throw new RuntimeException('Unexpected card capacity, stop');
  $actor=AdminUser::where('email','owner@platform.local')->sole();
  $restore=$config->only(['display_name','max_cards_per_user','sort_order']);$restore['status']=$config->status->value;
  $temporary=$count+1;
  app(ConfigureTenantCardProductAction::class)->execute($tenant,$productId,[...$restore,'max_cards_per_user'=>$temporary],$actor,$intent('limit-open'));
  if(!$holder){
   $archive=app(CardholderTestMaterialsArchive::class)->read($tenant,$user,$productId);$data=$archive['fields'];$data['request_id']=$intent('holder');$data['form_factor']='virtual_card';
   foreach($archive['documents'] as $side=>$document){
    if(!in_array($side,['front','back'],true)||!in_array($document['mime'],['image/png','image/jpeg'],true))throw new RuntimeException('Invalid test document');
    $bytes=base64_decode($document['content'],true);if(!is_string($bytes)||$bytes==='')throw new RuntimeException('Missing test document');
    $data[$side]=UploadedFile::fake()->createWithContent($side.($document['mime']==='image/png'?'.png':'.jpg'),$bytes);
   }
   $holder=app(SubmitProviderCardholderAction::class)->execute($tenant,$user,$data);unset($data,$archive,$bytes);
  }
  if($holder->status->value!=='READY'&&$holder->provider_cardholder_id)$holder=app(SyncProviderCardholderAction::class)->execute($tenant,$user,$holder->id);
  $out['holder_status']=$holder->status->value;
  if($holder->status->value!=='READY')throw new RuntimeException('Holder not confirmed ready');
  $issue=app(CreateCardIssueAction::class)->execute($tenant,$user,$intent('issue'),$productId,'20.00',$holder->id);
 }
 if($execute&&$issue&&in_array($issue->status->value,['PROCESSING','UNKNOWN'],true))$issue=app(SyncCardIssueAction::class)->execute($tenant,$issue->id,$user);
 if($holder){
  $materials=json_decode(app(App\Domain\Card\Services\CardholderMaterials::class)->decrypt($holder->materials_encrypted),true,512,JSON_THROW_ON_ERROR);
  $checks=[];foreach($materials['documents']??[] as $key){$image=app(ImageStorage::class)->record('private',$key);$bytes=app(ImageStorage::class)->read('private',$key,'card');$checks[]=['oss'=>(bool)$image?->configuration_id,'checksum'=>$image&&hash_equals($image->sha256,hash('sha256',$bytes))];}
  $out['documents']=$checks;$out['holder_id']=$holder->id;$out['holder_status']=$holder->status->value;
 }
 $out['issue']=$issue?->only(['id','status','initial_load_amount','opening_fee']);
 if($issue?->provider_card_id){$card=UserCard::where('tenant_id',$tenant)->where('user_id',$user)->where('provider_card_id',$issue->provider_card_id)->first();$out['card']=$card?->only(['id','provider_status']);}
 $out['result']=$issue?->status->value==='SUCCEEDED'?'PASS':($execute?'UNCONFIRMED':'INSPECTED');
}catch(Throwable $e){$out['result']='BLOCKED_OR_FAILED';$out['error_class']=get_class($e);if($e instanceof App\Support\Errors\DomainException)$out['error_code']=$e->errorCode;}
finally{
 if($restore!==null){
  DB::transaction(function()use($tenant,$productId,$restore,$temporary,$actor,$intent,&$out){
   CardProduct::whereKey($productId)->lockForUpdate()->firstOrFail();$current=TenantCardProductConfig::where('tenant_id',$tenant)->where('card_product_id',$productId)->lockForUpdate()->sole();
   if($current->max_cards_per_user!==$temporary)throw new RuntimeException('Concurrent card-limit change; stop for review');
   $restored=$current->only(['display_name','sort_order']);$restored['status']=$current->status->value;$restored['max_cards_per_user']=$restore['max_cards_per_user'];
   app(ConfigureTenantCardProductAction::class)->execute($tenant,$productId,$restored,$actor,$intent('limit-restore'));$out['card_limit_restored']=true;
  });
 }
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
