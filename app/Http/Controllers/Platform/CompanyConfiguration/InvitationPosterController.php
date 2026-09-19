<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class InvitationPosterController extends Controller
{
    public function save(Tenant $tenant, Request $request, PaidPromotionRules $rules, AuditLogger $audit)
    {
        $actor = $request->user('platform_admin');
        $rules->platform($actor, 'tenant.manage');
        $request->validate(['background' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192', 'dimensions:min_width=300,min_height=300,max_width=4000,max_height=6000']]);
        $path = $request->file('background')->store("invitation-posters/{$tenant->id}", 'private');
        try {
            DB::transaction(function () use ($tenant, $path, $rules, $actor, $audit) {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $rules->platform($actor, 'tenant.manage');
                $settings = $tenant->businessSettings()->lockForUpdate()->firstOrFail();
                $before = $settings->invitation_poster_background;
                $settings->update(['invitation_poster_background' => $path]);
                $audit->record($tenant->id, 'ADMIN', $actor->id, 'INVITATION_POSTER_CONFIGURED', 'tenant_business_settings', $tenant->id, ['background' => $before], ['background' => $path]);
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }

        return back()->with('success', 'Saved.');
    }

    public function platformImage(Tenant $tenant, Request $request, PaidPromotionRules $rules)
    {
        $rules->platform($request->user('platform_admin'), 'tenant.manage');

        return $this->image($tenant);
    }

    public function userImage(TenantContext $context)
    {
        return $this->image(Tenant::findOrFail($context->id()));
    }

    private function image(Tenant $tenant)
    {
        $path = $tenant->businessSettings->invitation_poster_background;
        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return response()->file(Storage::disk('private')->path($path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
