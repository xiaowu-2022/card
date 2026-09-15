<?php

namespace App\Application\Kyc;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PlatformKycQuery
{
    public function paginate(?string $company, ?string $search, ?string $status): LengthAwarePaginator
    {
        // Explicit Platform aggregate; owner joins always match company AND resource id.
        return DB::table('kyc_applications as k')
            ->join('tenants as t', 't.id', '=', 'k.tenant_id')
            ->join('users as u', fn ($join) => $join->on('u.id', '=', 'k.user_id')->on('u.tenant_id', '=', 'k.tenant_id'))
            ->when($company, fn ($q) => $q->where('k.tenant_id', $company))
            ->when($status, fn ($q) => $q->where('k.review_status', $status))
            ->when($search, fn ($q) => $q->where(function ($q) use ($search): void {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where('u.account_id', 'like', $pattern)->orWhere('u.email', 'ilike', $pattern)->orWhere('u.phone', 'like', $pattern)->orWhereRaw('u.id::text = ?', [$search]);
            }))
            ->select(['k.id', 'k.tenant_id', 't.name as company_name', 'u.account_id', 'u.email', 'u.phone', 'k.document_country', 'k.review_status', 'k.submitted_at'])
            ->orderByDesc('k.submitted_at')->orderBy('k.id')->paginate(20)->withQueryString()
            ->through(fn ($row): array => [
                'id' => $row->id, 'companyId' => $row->tenant_id, 'companyName' => $row->company_name,
                'user' => ['displayName' => $row->account_id, 'contact' => $row->email ?? $row->phone],
                'documentCountry' => $row->document_country, 'reviewStatus' => $row->review_status,
                'submittedAt' => $row->submitted_at,
            ]);
    }
}
