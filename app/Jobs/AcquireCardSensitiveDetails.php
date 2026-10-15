<?php
namespace App\Jobs;
use App\Application\Card\CardSensitiveDetails;
use App\Domain\Card\Models\UserCard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
final class AcquireCardSensitiveDetails implements ShouldQueue
{
    use Queueable;
    public int $tries = 5;
    public int $timeout = 60;
    public function __construct(public readonly string $tenantId, public readonly string $cardId) {}
    public function backoff(): array { return [10, 30, 60, 120]; }
    public function middleware(): array { return [(new WithoutOverlapping('card-details:'.$this->cardId))->releaseAfter(10)->expireAfter(90)]; }
    public function handle(CardSensitiveDetails $details): void
    {
        $card = UserCard::where('tenant_id', $this->tenantId)->whereKey($this->cardId)->first();
        if ($card) $details->acquire($card);
    }
    public function failed(?\Throwable $error): void
    {
        Log::warning('Card details background synchronization failed', ['tenant_id' => $this->tenantId, 'card_id' => $this->cardId, 'exception_class' => $error ? get_class($error) : null]);
    }
}
