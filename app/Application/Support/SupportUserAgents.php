<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportUserAgents
{
    public function profile(string $tenant, string $user): ?object
    {
        return DB::table('support_user_agents')->where('tenant_id', $tenant)->where('user_id', $user)->first();
    }

    public function enabled(string $tenant, string $user): bool
    {
        return (bool) $this->profile($tenant, $user)?->enabled
            && User::where('tenant_id', $tenant)->whereKey($user)->where('status', 'ACTIVE')->exists()
            && Tenant::whereKey($tenant)->where('status', 'ACTIVE')->exists();
    }

    public function authorize(string $tenant, string $user): void
    {
        abort_unless($this->enabled($tenant, $user), 403);
    }

    public function conversation(string $tenant, string $actor, string $id)
    {
        $this->authorize($tenant, $actor);

        return SupportConversation::where('tenant_id', $tenant)->where('user_id', '!=', $actor)->findOrFail($id);
    }

    public function grant(string $tenant, string $user, string $actor, array $input): void
    {
        $data = Validator::make($input, ['enabled' => 'required|boolean', 'revision' => 'required|integer|min:0'])->validate();
        DB::transaction(function () use ($tenant, $user, $actor, $data) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $target = User::where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            app(SupportAccess::class)->platform($actor, 'users.read');
            app(SupportAccess::class)->platform($actor, 'support.agents.manage');
            abort_if($data['enabled'] && ($target->status->value !== 'ACTIVE' || $company->status->value !== 'ACTIVE'), 422);
            $old = $this->profile($tenant, $user);
            abort_if((int) ($old?->revision ?? 0) !== (int) $data['revision'], 409);
            $values = ['enabled' => $data['enabled'], 'revision' => (int) ($old?->revision ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('support_user_agents')->where('tenant_id', $tenant)->where('user_id', $user)->update($values);
            } else {
                DB::table('support_user_agents')->insert($values + ['tenant_id' => $tenant, 'user_id' => $user, 'created_at' => now()]);
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor, 'SUPPORT_USER_AUTHORIZATION', 'user', $user, ['enabled' => (bool) ($old?->enabled ?? false)], $values);
        });
    }

    public function nickname(string $tenant, string $user, array $input): void
    {
        $data = Validator::make($input, ['support_name' => ['nullable', 'string', 'max:30', 'not_regex:/[\x00-\x1F\x7F]/u'], 'revision' => 'required|integer|min:1'])->validate();
        DB::transaction(function () use ($tenant, $user, $data) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $user);
            $old = $this->profile($tenant, $user);
            abort_if($old->revision !== (int) $data['revision'], 409);
            $name = trim($data['support_name'] ?? '') ?: null;
            DB::table('support_user_agents')->where('tenant_id', $tenant)->where('user_id', $user)->update(['support_name' => $name, 'revision' => $old->revision + 1, 'updated_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'USER', $user, 'SUPPORT_NAME_UPDATED', 'user', $user, ['support_name' => $old->support_name], ['support_name' => $name]);
        });
    }

    public function replies(string $tenant, string $user, bool $personal = false, string $search = '')
    {
        $this->authorize($tenant, $user);
        $own = DB::table('support_user_quick_replies')->where('tenant_id', $tenant)->where('user_id', $user)->whereNull('archived_at')->select('id', 'title', 'body', 'revision')->selectRaw("'personal' as scope");
        $shared = DB::table('support_quick_replies')->where('tenant_id', $tenant)->whereNull('archived_at')->select('id', 'title', 'body', 'revision')->selectRaw("'shared' as scope");

        return DB::query()->fromSub($personal ? $own : $own->unionAll($shared), 'replies')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('title', 'ilike', '%'.addcslashes($search, '%_').'%')->orWhere('body', 'ilike', '%'.addcslashes($search, '%_').'%')))->orderBy('title')->orderBy('id')->orderBy('scope');
    }

    public function saveReply(string $tenant, string $user, array $input): void
    {
        $data = Validator::make($input, ['id' => 'required|uuid', 'title' => 'required|string|max:100', 'body' => 'required|string|max:2000', 'revision' => 'required|integer|min:0', 'archived' => 'required|boolean'])->validate();
        abort_if(trim($data['title']) === '' || trim($data['body']) === '', 422);
        DB::transaction(function () use ($tenant, $user, $data) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $user);
            $row = DB::table('support_user_quick_replies')->where('id', $data['id'])->lockForUpdate()->first();
            abort_if($row && ($row->tenant_id !== $tenant || $row->user_id !== $user), 404);
            abort_if((int) ($row?->revision ?? 0) !== (int) $data['revision'], 409);
            $values = ['title' => trim($data['title']), 'body' => trim($data['body']), 'revision' => (int) ($row?->revision ?? 0) + 1, 'archived_at' => $data['archived'] ? now() : null, 'updated_at' => now()];
            if ($row) {
                DB::table('support_user_quick_replies')->where('id', $row->id)->update($values);
            } else {
                DB::table('support_user_quick_replies')->insert($values + ['id' => $data['id'], 'tenant_id' => $tenant, 'user_id' => $user, 'created_at' => now()]);
            }
            app(AuditLogger::class)->record($tenant, 'USER', $user, 'SUPPORT_QUICK_REPLY_SAVED', 'support_user_quick_reply', $data['id'], null, ['revision' => $values['revision'], 'archived' => $data['archived']]);
        });
    }
}
