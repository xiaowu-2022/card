<?php

namespace App\Application\Partners;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Platform-only presentation fields; IDs remain the immutable action targets. */
final class PlatformPartnerIdentity
{
    public function users(string $tenant, array $accounts): Collection
    {
        return DB::table('users as u')
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenant)->whereIn('u.account_id', array_values(array_unique(array_filter($accounts))))
            ->get(['u.account_id', 'u.email', 'p.display_name'])->keyBy('account_id');
    }

    public function stock(string $tenant, array $report): array
    {
        $paths = ['journal.items', 'risks.active.items', 'risks.expired.items', 'flowDetails.items'];
        $accounts = [$report['accountId']];
        foreach ($paths as $path) {
            foreach (data_get($report, $path, []) as $row) {
                $accounts[] = data_get($row, 'account_id');
                $accounts[] = data_get($row, 'direct_account_id');
            }
        }
        $users = $this->users($tenant, $accounts);
        $report['displayName'] = $users->get($report['accountId'])?->display_name;
        $report['email'] = $users->get($report['accountId'])?->email;
        foreach ($paths as $path) {
            if (data_get($report, $path) === null) {
                continue;
            }
            $rows = array_map(function ($row) use ($users) {
                $row = (array) $row;
                $user = $users->get($row['account_id']);
                $row['display_name'] = $user?->display_name;
                $row['email'] = $user?->email;
                if (array_key_exists('direct_account_id', $row)) {
                    $row['direct_display_name'] = $users->get($row['direct_account_id'])?->display_name;
                }

                return $row;
            }, data_get($report, $path));
            data_set($report, $path, $rows);
        }

        return $report;
    }
}
