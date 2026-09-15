<?php

namespace App\Application\Support;

use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\User\Models\User;

final readonly class SupportChatQuery
{
    public function __construct(private SupportAccess $access) {}

    public function user(string $tenantId, string $userId, int $before = 0): array
    {
        User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();

        return $this->thread($tenantId, $conversation, $before);
    }

    public function admin(string $tenantId, string $adminId, string $conversationId, int $before = 0): array
    {
        $this->access->admin($tenantId, $adminId);
        $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->whereKey($conversationId)->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($conversation->user_id)->firstOrFail();

        return $this->thread($tenantId, $conversation, $before, true) + ['accountId' => $user->account_id];
    }

    public function inbox(string $tenantId, string $adminId, int $page = 1): array
    {
        $this->access->admin($tenantId, $adminId);
        $rows = SupportConversation::query()->where('support_conversations.tenant_id', $tenantId)
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'support_conversations.user_id')->on('users.tenant_id', '=', 'support_conversations.tenant_id');
            })->orderByDesc('support_conversations.updated_at')->orderBy('support_conversations.id')
            ->select(['support_conversations.*', 'users.account_id'])->simplePaginate(30, ['*'], 'page', $page);

        return ['page' => $page, 'hasMore' => $rows->hasMorePages(), 'items' => $rows->map(fn ($row): array => [
            'id' => $row->id, 'accountId' => $row->account_id, 'awaitingReply' => $row->last_sender === 'USER',
            'updatedAt' => $row->updated_at->toIso8601String(),
        ])->all()];
    }

    public function image(string $tenantId, string $actorId, string $messageId, bool $admin): array
    {
        if ($admin) {
            $this->access->admin($tenantId, $actorId);
        }
        $message = SupportMessage::query()->where('tenant_id', $tenantId)->whereKey($messageId)->firstOrFail();
        $conversation = SupportConversation::query()->where('tenant_id', $tenantId)->whereKey($message->conversation_id)->firstOrFail();
        abort_unless($admin || $conversation->user_id === $actorId, 404);
        abort_unless($message->image_object_key, 404);

        return ['path' => $message->image_object_key, 'mime' => $message->image_mime];
    }

    private function thread(string $tenantId, ?SupportConversation $conversation, int $before, bool $admin = false): array
    {
        if (! $conversation) {
            return ['id' => null, 'messages' => [], 'before' => 0, 'olderCursor' => null];
        }
        $rows = SupportMessage::query()->where('tenant_id', $tenantId)->where('conversation_id', $conversation->id)
            ->when($before > 0, fn ($query) => $query->where('sequence', '<', $before))
            ->orderByDesc('sequence')->limit(51)->get();
        $visible = $rows->take(50)->reverse()->values();

        return [
            'id' => $conversation->id, 'before' => $before,
            'olderCursor' => $rows->count() > 50 ? $visible->first()->sequence : null,
            'messages' => $visible->map(fn (SupportMessage $message): array => [
                'id' => $message->id, 'sequence' => $message->sequence,
                'fromSupport' => $message->sender_admin_id !== null,
                'text' => $message->support_message, 'createdAt' => $message->created_at->toIso8601String(),
                'imageUrl' => $message->image_mime ? ($admin ? '/admin/support/images/' : '/support/images/').$message->id : null,
            ])->all(),
        ];
    }
}
