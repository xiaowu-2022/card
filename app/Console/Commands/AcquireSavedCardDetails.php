<?php
namespace App\Console\Commands;
use App\Domain\Card\Models\UserCard;
use App\Jobs\AcquireCardSensitiveDetails;
use Illuminate\Console\Command;
final class AcquireSavedCardDetails extends Command
{
    protected $signature = 'cards:sync-saved-details';
    protected $description = 'Queue acquisition of missing encrypted details for active U1 and PhotonPay cards';
    public function handle(): int
    {
        $count = 0;
        foreach (UserCard::whereNull('sensitive_details_encrypted')->whereNull('archived_at')
            ->whereNotNull('provider_card_id')->where('provider_card_id', '<>', '')
            ->whereIn('provider', ['U1', 'PHOTONPAY'])->whereIn('provider_status', ['normal', 'frozen'])->cursor() as $card) {
            AcquireCardSensitiveDetails::dispatch($card->tenant_id, $card->id)->afterCommit();
            $count++;
        }
        $this->info('Queued '.$count.' missing card detail synchronizations.');
        return self::SUCCESS;
    }
}
