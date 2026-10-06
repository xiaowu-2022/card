<?php

namespace App\Application\Support;

use App\Application\Media\VerifiedDirectImage;
use App\Domain\Admin\Models\AdminUser;
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

    public function user(string $tenantId, string $userId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] UploadedFile|VerifiedDirectImage|null $image = null): string
    {
        return $this->send($tenantId, $userId, null, null, $requestId, $message, $image);
    }

    public function admin(string $tenantId, string $adminId, string $conversationId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] UploadedFile|VerifiedDirectImage|null $image = null): string
    {
        $this->access->admin($tenantId, $adminId);
        $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->whereKey($conversationId)->firstOrFail();

        return $this->send($tenantId, $conversation->user_id, $adminId, $conversationId, $requestId, $message, $image);
    }

    public function platform(string $tenantId, string $adminId, string $userId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] UploadedFile|VerifiedDirectImage|null $image = null): string
    {
        $this->access->platform($adminId, 'support.send');
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();

        return $this->send($tenantId, $userId, $adminId, null, $requestId, $message, $image, true);
    }

    public function agent(string $tenantId, string $agentId, string $conversationId, string $requestId, #[\SensitiveParameter] string $message, UploadedFile|VerifiedDirectImage|null $image = null): string
    {
        $conversation = app(SupportUserAgents::class)->conversation($tenantId, $agentId, $conversationId);

        return $this->send($tenantId, $conversation->user_id, null, $conversationId, $requestId, $message, $image, false, $agentId);
    }

    private function send(string $tenantId, string $userId, ?string $adminId, ?string $conversationId, string $requestId, #[\SensitiveParameter] string $message, #[\SensitiveParameter] UploadedFile|VerifiedDirectImage|null $upload, bool $platform = false, ?string $agentId = null): string
    {
        $staff = $adminId !== null || $agentId !== null;
        $message = trim($message);
        $image = $this->images->prepare($upload);
        if (! Str::isUuid($requestId) || ($message === '' && ! $image) || mb_strlen($message) > 2000) {
            throw new DomainException('SUPPORT_MESSAGE_INVALID', 'Enter a message of 1–2000 characters.');
        }

        // Persist the upload intent before the message transaction so rollback cannot lose OSS cleanup work.
        $tenant = Tenant::findOrFail($tenantId);
        $user = User::where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        abort_unless($tenant->status === TenantStatus::Active, 403);
        if ($platform) {
            abort_unless($user->status === UserStatus::Active, 403);
        }
        if ($adminId) {
            $platform ? $this->access->platform($adminId, 'support.send') : $this->access->admin($tenantId, $adminId);
        } else {
            abort_unless($user->status === UserStatus::Active, 403);
        }
        if ($agentId) {
            app(SupportUserAgents::class)->conversation($tenantId, $agentId, $conversationId);
        }
        // A completed direct-upload retry must reuse the existing message before claiming its image again.
        if ($upload instanceof VerifiedDirectImage) {
            $existing = SupportMessage::where('tenant_id', $tenantId)->where($agentId ? 'sender_support_user_id' : 'sender_user_id', $agentId ?? $userId)
                ->where('request_id', $requestId)->first();
            if ($existing && ! $adminId) {
                $conversation = SupportConversation::where('tenant_id', $tenantId)->where('user_id', $userId)->first();
                if ($existing->conversation_id !== $conversation?->id || ! hash_equals($existing->support_message, $message)
                    || ! hash_equals($existing->image_hash ?? '', $image['hash'] ?? '')) {
                    throw new DomainException('SUPPORT_REQUEST_REUSED', 'This message request was already used. Refresh before sending a new message.', 409);
                }

                return $existing->conversation_id;
            }
        }
        $messageId = (string) Str::uuid();
        $stagedPath = $image ? $this->images->store($tenantId, $image, $messageId) : null;
        $bodyCompleted = false;
        $outerLevel = DB::transactionLevel();
        try {
            $result = DB::transaction(function () use ($tenantId, $userId, $adminId, $conversationId, $requestId, $message, $image, $messageId, $platform, $agentId, $staff, &$stagedPath, &$bodyCompleted): string {
                $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
                abort_unless($tenant->status === TenantStatus::Active, 403);
                if ($platform) {
                    abort_unless($user->status === UserStatus::Active, 403);
                }
                if ($adminId) {
                    $platform ? $this->access->platform($adminId, 'support.send') : $this->access->admin($tenantId, $adminId);
                } else {
                    abort_unless($user->status === UserStatus::Active, 403);
                }
                if ($agentId) {
                    app(SupportUserAgents::class)->conversation($tenantId, $agentId, $conversationId);
                }
                $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->lockForUpdate()->first();
                $existing = SupportMessage::query()->where('tenant_id', $tenantId)
                    ->where($agentId ? 'sender_support_user_id' : ($adminId ? 'sender_admin_id' : 'sender_user_id'), $agentId ?? $adminId ?? $userId)
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
                    'mode' => ! $staff && app(SupportBot::class)->settings($tenantId)['enabled'] ? 'BOT' : 'HUMAN', 'revision' => 1,
                ]);
                $sequence = $conversation->last_sequence + 1;

                SupportMessage::query()->create([
                    'id' => $messageId,
                    'tenant_id' => $tenantId, 'conversation_id' => $conversation->id, 'sequence' => $sequence,
                    'support_name' => $agentId ? app(SupportUserAgents::class)->profile($tenantId, $agentId)->support_name : ($adminId ? AdminUser::findOrFail($adminId)->support_name : null),
                    'sender_user_id' => $staff ? null : $userId, 'sender_admin_id' => $adminId, 'sender_support_user_id' => $agentId,
                    'request_id' => $requestId, 'support_message' => $message,
                    'image_object_key' => $stagedPath,
                    'image_hash' => $image['hash'] ?? null, 'image_mime' => $image['mime'] ?? null,
                ]);
                if ($staff) {
                    app(SupportBot::class)->takeover($conversation, $agentId ?? $adminId, $requestId, $agentId ? 'USER' : 'ADMIN');
                }
                $conversation->fill(['last_sequence' => $sequence, 'last_sender' => $agentId ? 'AGENT' : ($adminId ? 'ADMIN' : 'USER'), 'revision' => $conversation->revision + 1])->save();
                if (! $staff) {
                    app(SupportBot::class)->reply($conversation, $messageId, $message);
                }
                $bodyCompleted = true;

                return $conversation->id;
            });
            if ($stagedPath && ! $bodyCompleted) {
                $this->images->discardStaged($tenantId, $stagedPath);
            }

            return $result;
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
