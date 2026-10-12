<?php

namespace App\Http\Controllers\Platform;

use App\Application\Partners\PartnerInvitationReport;
use App\Application\User\PlatformUserSummary;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class InvitationDataController extends Controller
{
    public function index(Request $request, PartnerInvitationReport $reports)
    {
        $data = $request->validate([
            'company' => 'nullable|uuid|exists:tenants,id', 'category' => 'nullable|in:all,users,partners',
            'search' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1|max:100000',
            'subject' => 'nullable|uuid', 'subject_company' => 'nullable|required_with:subject|uuid|exists:tenants,id',
            'invitation_kind' => 'nullable|required_with:invitation_rank|in:ANNUAL,ACTIVATION',
            'invitation_rank' => 'nullable|required_with:invitation_kind|integer|min:0',
            'invitation_page' => 'nullable|integer|min:1|max:100000',
            'tenant_id' => 'prohibited', 'user_id' => 'prohibited',
        ]);
        $company = $data['company'] ?? null;
        $category = $data['category'] ?? 'all';
        $search = trim($data['search'] ?? '');
        $outer = DB::transactionLevel();
        $result = DB::transaction(function () use ($company, $category, $search, $reports, $outer, $data) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $base = DB::table('users as u')->join('tenants as t', 't.id', 'u.tenant_id')
                ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', 'u.id')->on('profile.tenant_id', 'u.tenant_id'))
                ->leftJoin('partner_configurations as p', fn ($j) => $j->on('p.user_id', 'u.id')->on('p.tenant_id', 'u.tenant_id')->where('p.enabled', true))
                ->when($company, fn ($q) => $q->where('u.tenant_id', $company))
                ->when($category === 'users', fn ($q) => $q->whereNull('p.id'))
                ->when($category === 'partners', fn ($q) => $q->whereNotNull('p.id'));
            $selected = null;
            if (isset($data['subject'])) {
                $selected = (clone $base)->where('u.id', $data['subject'])->where('u.tenant_id', $data['subject_company'])
                    ->first(['u.id', 'u.tenant_id', 'u.account_id', 'profile.display_name']);
                abort_unless($selected, 404);
            }
            $page = $base->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('strpos(u.account_id, ?) > 0', [$search])
                ->orWhereRaw("strpos(lower(COALESCE(profile.display_name, '')), lower(?)) > 0", [$search])
                ->orWhereRaw("strpos(lower(COALESCE(u.email, '')), lower(?)) > 0", [$search])))
                ->orderBy('t.name')->orderBy('u.account_id')->orderBy('u.id')
                ->paginate(20, ['u.id as userId', 'u.tenant_id', 'u.account_id', 'profile.display_name', 't.name as company_name', 'p.id as partner_id'])->withQueryString();
            $ranks = [];
            $page->through(function ($row) use ($reports, &$ranks) {
                $report = $reports->readUser($row->userId, $row->tenant_id);
                foreach ($report['summary']['teamByLevel'] as $level) {
                    $ranks[$level['rank']] = $level['rank'];
                }

                return (array) $row + ['commission' => $report['commission'], 'summary' => $report['summary']];
            });
            sort($ranks);

            return ['accounts' => PlatformUserSummary::page($page), 'ranks' => $ranks,
                'selected' => $selected ? (array) $selected : null];
        });

        return Inertia::render('platform/InvitationData', $result + [
            'companies' => Tenant::orderBy('name')->get(['id', 'name']),
            'companyId' => $company, 'category' => $category, 'search' => $search,
        ])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function report(Request $request, string $tenant, string $user, PartnerInvitationReport $reports)
    {
        $data = $request->validate(['kind' => 'nullable|required_with:rank|in:ANNUAL,ACTIVATION', 'rank' => 'nullable|required_with:kind|integer|min:0',
            'page' => 'nullable|integer|min:1|max:100000', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'company' => 'prohibited']);

        return response()->json($reports->readUser($user, $tenant, $data['kind'] ?? null, isset($data['rank']) ? (int) $data['rank'] : null, $request->integer('page', 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
