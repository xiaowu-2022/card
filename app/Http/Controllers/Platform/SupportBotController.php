<?php

namespace App\Http\Controllers\Platform;

use App\Application\Support\SupportBot;
use App\Application\Support\SupportFaqs;
use App\Application\Tenant\PlatformListFilters;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class SupportBotController extends Controller
{
    public function index(Request $request, PlatformListFilters $lists, SupportBot $bot)
    {
        $filters = $lists->validated($request);
        $company = $filters['company'] ?? null;
        $rows = DB::table('support_faqs')->whereNull('overrides_id')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($company, fn ($q) => $q->orWhere('tenant_id', $company)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('question', 'ilike', '%'.addcslashes($v, '%_').'%'))
            ->orderByDesc('created_at')->orderBy('id')->paginate(30)->withQueryString();
        $overrides = $company ? DB::table('support_faqs')->where('tenant_id', $company)->whereIn('overrides_id', $rows->pluck('id'))->get()->keyBy('overrides_id') : collect();
        $rows->through(function ($row) use ($overrides) {
            $override = $overrides->get($row->id);

            return ['id' => $row->id, 'question' => $row->question, 'scope' => $row->tenant_id ? 'company' : 'public',
                'overridden' => $override && ! $override->archived_at, 'enabled' => $row->enabled && ! $row->archived_at && (! $override || $override->archived_at || $override->enabled), 'archived' => (bool) $row->archived_at];
        });

        return Inertia::render('platform/SupportBot', ['faqs' => $rows, 'filters' => $filters, 'companies' => $lists->companies(),
            'settings' => $company ? $bot->settings($company) : null])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $faq, PlatformListFilters $lists)
    {
        $company = $lists->validated($request)['company'] ?? null;
        $row = DB::table('support_faqs')->where('id', $faq)->where(fn ($q) => $q->whereNull('tenant_id')->when($company, fn ($q) => $q->orWhere('tenant_id', $company)))->firstOrFail();
        $parent = $company && ! $row->tenant_id ? $row : null;
        if ($parent) {
            $row = DB::table('support_faqs')->where('tenant_id', $company)->where('overrides_id', $parent->id)->first() ?? $row;
        }

        return response()->json(['id' => $parent && ! $row->tenant_id ? null : $row->id, 'overrides_id' => $parent?->id ?? $row->overrides_id,
            'revision' => $parent && ! $row->tenant_id ? 0 : $row->revision, 'question' => $parent?->question ?? $row->question,
            'variants' => json_decode($parent?->variants ?? $row->variants, true), 'keywords' => json_decode($parent?->keywords ?? $row->keywords, true),
            'answer' => $row->answer, 'enabled' => $row->enabled, 'archived' => (bool) $row->archived_at])->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, PlatformListFilters $lists, SupportFaqs $faqs)
    {
        $company = $lists->validated($request)['company'] ?? null;
        $data = $request->validate(['id' => 'nullable|uuid']);
        $id = $faqs->save($request->user('platform_admin')->id, $company, $data['id'] ?? null, $request->all());

        return response()->json(['id' => $id]);
    }

    public function configure(Request $request, SupportBot $bot)
    {
        $data = $request->validate(['company' => 'required|uuid|exists:tenants,id', 'enabled' => 'required|boolean', 'revision' => 'required|integer|min:0']);
        $bot->configure($data['company'], $request->user('platform_admin')->id, $data['enabled'], $data['revision']);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function preview(Request $request, SupportFaqs $faqs)
    {
        $data = $request->validate(['company' => 'required|uuid|exists:tenants,id', 'question' => 'required|string|max:2000']);

        return response()->json($faqs->match($data['company'], $data['question']))->header('Cache-Control', 'private, no-store');
    }
}
