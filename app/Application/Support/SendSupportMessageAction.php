<?php

namespace App\Application\Support;

use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class SendSupportMessageAction
{
    public function __construct(private SupportAccess $access, private SupportImageStorage $images) {}

    public function user(string $tenantId, string $userId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] ?UploadedFile $image = null): string
    {
        return $this->send($tenantId, $userId, null, null, $requestId, $message, $image);
    }

    public function admin(string $tenantId, string $adminId, string $conversationId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] ?UploadedFile $image = null): string
    {
        $this->access->admin($tenantId, $adminId);
        $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->whereKey($conversationId)->firstOrFail();

        return $this->send($tenantId, $conversation->user_id, $adminId, $conversationId, $requestId, $message, $image);
    }

    private function send(string $tenantId, string $userId, ?string $adminId, ?string $conversationId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] ?UploadedFile $upload): string
    {
        $message = trim($message);
        $image = $this->images->prepare($upload);
        if (! Str::isUuid($requestId) || ($message === '' && ! $image) || mb_strlen($message) > 2000) {
            throw new DomainException('SUPPORT_MESSAGE_INVALID', 'Enter a message of 1–2000 characters.');
        }

        $stagedPath = null;
        $messageId = (string) Str::uuid();
        $bodyCompleted = false;
        $outerLevel = DB::transactionLevel();
        try {
            return DB::transaction(function () use ($tenantId, $userId, $adminId, $conversationId, $requestId, $message, $image, $messageId, &$stagedPath, &$bodyCompleted): string {
                $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
                abort_unless($tenant->status === TenantStatus::Active, 403);
                if ($adminId) {
                    $this->access->admin($tenantId, $adminId);
                } else {
                    abort_unless($user->status === UserStatus::Active, 403);
                }
                $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();
                $existing = SupportMessage::query()->where('tenant_id', $tenantId)
                    ->where($adminId ? 'sender_admin_id' : 'sender_user_id', $adminId ?? $userId)
                    ->where('request_id', $requestId)->first();
                if ($existing) {
                    if ($existing->conversation_id !== $conversation?->id || ! hash_equals($existing->support_message, $message)
                        || ! hash_equals($existing->image_hash ?? '', $image['hash'] ?? '')) {
                        throw new DomainException('SUPPORT_REQUEST_REUSED', 'This message request was already used. Refresh before sending a new message.', 409);
                    }

                    return $existing->conversation_id;
                }
                if ($conversationId) {
                    abort_unless($conversation?->id === $conversationId, 404);
                }
                $conversation ??= SupportConversation::query()->create([
                    'tenant_id' => $tenantId, 'user_id' => $userId, 'last_sequence' => 0, 'last_sender' => 'USER',
                ]);
                $sequence = $conversation->last_sequence + 1;
                $stagedPath = $image ? $this->images->store($tenantId, $image) : null;
                SupportMessage::query()->create([
                    'id' => $messageId,
                    'tenant_id' => $tenantId, 'conversation_id' => $conversation->id, 'sequence' => $sequence,
                    'sender_user_id' => $adminId ? null : $userId, 'sender_admin_id' => $adminId,
                    'request_id' => $requestId, 'support_message' => $message,
                    'image_object_key' => $stagedPath,
                    'image_hash' => $image['hash'] ?? null, 'image_mime' => $image['mime'] ?? null,
                ]);
                $conversation->update(['last_sequence' => $sequence, 'last_sender' => $adminId ? 'ADMIN' : 'USER']);
                $bodyCompleted = true;

                return $conversation->id;
            });
        } catch (\Throwable $error) {
            // A commit exception is ambiguous: never delete in that case.
            if ($stagedPath && ! $bodyCompleted && DB::transactionLevel() === $outerLevel) {
                try {
                    DB::transaction(function () use ($tenantId, $messageId, $stagedPath): void {
                        Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                        if (! SupportMessage::query()->where('tenant_id', $tenantId)->whereKey($messageId)->exists()) {
                            $this->images->discardStaged($tenantId, $stagedPath);
                        }
                    });
                } catch (\Throwable) {
                    // No path, message, upload bytes or exception details in logs.
                    Log::warning('Support staged-image cleanup could not be confirmed.');
                }
            }
            throw $error;
        }
    }
}
