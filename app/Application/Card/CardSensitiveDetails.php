<?php
namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\DTOs\ProviderSensitiveCardDTO;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class CardSensitiveDetails
{
    private function identity(UserCard $card): array
    {
        return [$card->id, $card->tenant_id, $card->user_id, $card->provider, $card->provider_card_id];
    }
    private function validate(UserCard $card, ProviderSensitiveCardDTO $details): void
    {
        if (! preg_match('/^\d{12,19}$/D', $details->displayPan) || ($card->last4 !== null && substr($details->displayPan, -4) !== $card->last4)
            || ! preg_match('/^\d{3,4}$/D', $details->displayCvv) || ! is_string($details->expiry)
            || ! preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/D', $details->expiry)) {
            throw new \RuntimeException('Card details do not match the saved card.');
        }
    }
    public function store(UserCard $card, ProviderSensitiveCardDTO $details): void
    {
        $this->validate($card, $details);
        DB::transaction(function () use ($card, $details): void {
            $current = UserCard::where('tenant_id', $card->tenant_id)->where('user_id', $card->user_id)->whereKey($card->id)->lockForUpdate()->firstOrFail();
            if ($this->identity($current) !== $this->identity($card)) throw new \RuntimeException('Card identity changed.');
            $this->validate($current, $details);
            $encrypted = Crypt::encryptString(json_encode(['identity' => $this->identity($current),
                'pan' => $details->displayPan, 'cvv' => $details->displayCvv, 'expiry' => $details->expiry], JSON_THROW_ON_ERROR));
            DB::table('user_cards')->where('id', $current->id)->update(['sensitive_details_encrypted' => $encrypted, 'last4' => substr($details->displayPan, -4),
                'masked_pan' => '•••• '.substr($details->displayPan, -4), 'expiry' => $details->expiry]);
        });
    }
    public function read(UserCard $card): ProviderSensitiveCardDTO
    {
        if (! $card->sensitive_details_encrypted) throw new \RuntimeException('Card details have not been synchronized.');
        $data = json_decode(Crypt::decryptString($card->sensitive_details_encrypted), true, flags: JSON_THROW_ON_ERROR);
        if (($data['identity'] ?? null) !== $this->identity($card)) throw new \RuntimeException('Stored card identity mismatch.');
        $details = new ProviderSensitiveCardDTO($data['pan'], $data['cvv'], false, $data['expiry']);
        $this->validate($card, $details);
        return $details;
    }
    public function acquire(UserCard $card): void
    {
        if ($card->sensitive_details_encrypted || $card->archived_at || ! in_array($card->provider_status, ['normal', 'frozen'], true)) return;
        $this->store($card, app(CardProductProviderRouter::class)->forCard($card)->revealCard($card->provider_card_id));
    }
}
