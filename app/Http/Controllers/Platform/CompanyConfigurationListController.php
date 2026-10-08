<?php

namespace App\Http\Controllers\Platform;

use App\Application\Tenant\PlatformListFilters;
use Illuminate\Http\Request;

final class CompanyConfigurationListController
{
    public function __invoke(Request $request, PlatformListFilters $lists)
    {
        $filters = $lists->validated($request, ['status' => 'nullable|in:DRAFT,ACTIVE,SUSPENDED,CLOSED']);
        $query = array_filter($filters, fn ($value) => $value !== null && $value !== '');
        $request->validate(['section' => 'nullable|string|max:80', 'editor' => 'nullable|string|max:2000', 'page' => 'nullable|integer|min:1']);
        if ($request->filled('section')) {
            $query['section'] = $request->input('section');
        }
        if ($request->filled('editor')) {
            $query['editor'] = $request->input('editor');
        } elseif (! empty($filters['company'])) {
            $section = $request->input('section', 'onboarding');
            $allowed = ['onboarding', 'domains', 'card-products', 'team', 'assets', 'settings/branding', 'settings/locales', 'settings/business', 'settings/kyc', 'settings/articles', 'settings/sms', 'settings/email', 'promotion', 'wealth', 'support/hours', 'support/replies', 'support/bot'];
            abort_unless(in_array($section, $allowed, true), 422);
            $query['editor'] = $section === 'assets' ? '/platform/settings/assets?company='.$filters['company']
                : (str_starts_with($section, 'support/') ? '/platform/'.$section.'?company='.$filters['company']
                : '/platform/tenants/'.$filters['company'].'/configuration/'.$section);
        }
        if ($request->filled('page')) {
            $query['page'] = $request->integer('page');
        }

        return redirect('/platform/tenants'.($query ? '?'.http_build_query($query) : ''));
    }
}
