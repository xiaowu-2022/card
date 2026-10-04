<?php

namespace App\Http\Controllers\Platform;

use App\Application\Partners\FeeValuation;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class PartnerController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['company' => 'nullable|uuid|exists:tenants,id', 'tenant' => 'nullable|uuid|exists:tenants,id', 'partner' => 'nullable|uuid', 'page' => 'nullable|integer|min:1|max:100000', 'fees_page' => 'nullable|integer|min:1|max:100000', 'flow' => 'nullable|in:inflow,outflow', 'flow_page' => 'nullable|integer|min:1|max:100000']);
        $tenant = $request->query('company', $request->query('tenant'));
        $selected = null;
        $report = null;
        if ($request->filled('partner')) {
            $selected = DB::table('partner_configurations')->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))->where('id', $request->query('partner'))->firstOrFail();
            $report = app(PartnerReport::class)->read($selected->tenant_id, $selected->user_id, false, $request->integer('page', 1), $request->query('flow'), $request->integer('flow_page', 1));
        }

        return Inertia::render('platform/Partners', [
            'companies' => Tenant::orderBy('name')->get(['id', 'name']), 'companyId' => $tenant,
            'partners' => DB::table('partner_configurations as p')->join('users as u', fn ($j) => $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id'))->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))->join('tenants as t', 't.id', '=', 'p.tenant_id')->when($tenant, fn ($q) => $q->where('p.tenant_id', $tenant))->orderBy('u.account_id')->paginate(20, ['p.id', 'p.tenant_id', 't.name as company_name', 'p.enabled', 'p.share_percent', 'u.account_id', 'profile.display_name'])->withQueryString(),
            'reportCompanyId' => $selected?->tenant_id, 'report' => $report,
            'pendingFees' => DB::table('withdrawal_fee_valuations as f')->join('tenants as t', 't.id', '=', 'f.tenant_id')->when($tenant, fn ($q) => $q->where('f.tenant_id', $tenant))->where('f.source', 'PENDING')->orderBy('f.created_at')->orderBy('f.id')->paginate(20, ['f.id', 'f.tenant_id', 't.name as company_name', 'f.withdrawal_id', 'f.asset_code', 'f.original_amount', 'f.created_at'], 'fees_page')->withQueryString(),
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request, Tenant $tenant)
    {
        $data = $request->validate(['search' => 'nullable|string|max:254', 'page' => 'nullable|integer|min:1|max:100000']);
        $search = trim($data['search'] ?? '');
        $users = DB::table('users as u')
            ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenant->id)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('partner_configurations as p')->whereColumn('p.tenant_id', 'u.tenant_id')->whereColumn('p.user_id', 'u.id'))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('strpos(u.account_id, ?) > 0', [$search])->orWhereRaw("strpos(lower(COALESCE(profile.display_name, '')), lower(?)) > 0", [$search])->orWhereRaw("strpos(lower(COALESCE(u.email, '')), lower(?)) > 0", [$search])))
            ->orderBy('u.account_id')->simplePaginate(20, ['u.account_id', 'profile.display_name'], 'page', $request->integer('page', 1));

        return response()->json(['items' => $users->items(), 'hasMore' => $users->hasMorePages()])->header('Cache-Control', 'private, no-store');
    }

    public function configure(Request $request, Tenant $tenant, PartnerManagement $action)
    {
        $action->configure($request->user('platform_admin'), $tenant->id, $request->all());

        return back();
    }

    public function journal(Request $request, Tenant $tenant, string $partner, PartnerManagement $action)
    {
        $action->journal($request->user('platform_admin'), $tenant->id, $partner, $request->all());

        return back();
    }

    public function valueFee(Request $request, Tenant $tenant, string $valuation, FeeValuation $action)
    {
        $action->supplement($request->user('platform_admin'), $tenant->id, $valuation, $request->all());

        return back();
    }
}
