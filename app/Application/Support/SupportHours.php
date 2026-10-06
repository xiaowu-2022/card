<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportHours
{
    public function configuration(string $tenant): array
    {
        $company = Tenant::findOrFail($tenant);
        $row = DB::table('support_hours')->where('tenant_id', $tenant)->first();

        return ['timezone' => $company->timezone ?: 'UTC', 'revision' => (int) ($row?->revision ?? 0),
            'weekly' => $row ? json_decode($row->weekly, true) : array_fill(0, 7, [['start' => '00:00', 'end' => '24:00']])];
    }

    public function availability(string $tenant, ?CarbonImmutable $at = null): array
    {
        $config = $this->configuration($tenant);
        $now = ($at ?? CarbonImmutable::now())->setTimezone($config['timezone']);
        $open = $this->isOpen($config['weekly'], $now);
        $next = null;
        if (! $open) {
            // Resolve civil start times against every offset occurring this week. This
            // handles repeated autumn times and skipped spring times without minute polling.
            $transitions = $now->getTimezone()->getTransitions($now->timestamp - 86400, $now->timestamp + 9 * 86400) ?: [];
            $offsets = array_unique([$now->offset, ...array_column($transitions, 'offset')]);
            $candidates = [];
            for ($day = 0; $day <= 7; $day++) {
                $date = $now->startOfDay()->addDays($day);
                foreach ($config['weekly'][$date->dayOfWeekIso - 1] as $slot) {
                    $civil = $date->format('Y-m-d').' '.$slot['start'];
                    $wall = CarbonImmutable::parse($civil, 'UTC')->timestamp;
                    foreach ($offsets as $offset) {
                        $candidate = CarbonImmutable::createFromTimestampUTC($wall - $offset)->setTimezone($config['timezone']);
                        if ($candidate->format('Y-m-d H:i') === $civil) {
                            $candidates[] = $candidate;
                        }
                    }
                }
            }
            foreach ($transitions as $transition) {
                $candidates[] = CarbonImmutable::createFromTimestampUTC($transition['ts'])->setTimezone($config['timezone']);
            }
            foreach ($candidates as $candidate) {
                if ($candidate > $now && (! $next || $candidate < $next) && $this->isOpen($config['weekly'], $candidate)) {
                    $next = $candidate;
                }
            }
        }

        return ['available' => $open, 'timezone' => $config['timezone'], 'weekly' => $config['weekly'], 'nextOpenAt' => $next?->toIso8601String()];
    }

    public function assertAvailable(string $tenant): void
    {
        $state = $this->availability($tenant);
        if (! $state['available']) {
            throw new DomainException('SUPPORT_OFFLINE', 'Customer support is currently offline.', 409, ['humanSupport' => $state]);
        }
    }

    public function save(string $actor, string $tenant, array $input): void
    {
        app(SupportAccess::class)->platform($actor, 'support.hours.manage');
        $data = Validator::make($input, ['revision' => 'required|integer|min:0', 'weekly' => 'required|array|size:7',
            'weekly.*' => 'present|array|max:6', 'weekly.*.*.start' => ['required', 'regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/'],
            'weekly.*.*.end' => ['required', 'regex:/^(?:(?:[01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/']])->validate();
        abort_unless(array_is_list($input['weekly']), 422);
        // Validator can reorder numeric keys when expanding nested rules. Preserve day positions.
        $data['weekly'] = $input['weekly'];
        $occupied = [];
        foreach ($data['weekly'] as $day => &$slots) {
            abort_unless(array_is_list($slots), 422);
            foreach ($slots as &$slot) {
                $slot = ['start' => $slot['start'], 'end' => $slot['end']];
                $start = $this->minutes($slot['start']);
                $end = $this->minutes($slot['end']);
                abort_if($start === $end, 422, 'Use 00:00–24:00 for all day service.');
                if ($end < $start) {
                    $end += 1440;
                }
                for ($minute = $day * 1440 + $start; $minute < $day * 1440 + $end; $minute++) {
                    $key = $minute % 10080;
                    abort_if(isset($occupied[$key]), 422, 'Service periods overlap.');
                    $occupied[$key] = true;
                }
            }
            unset($slot);
            usort($slots, fn ($a, $b) => strcmp($a['start'], $b['start']));
        }
        unset($slots);
        DB::transaction(function () use ($actor, $tenant, $data) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            app(SupportAccess::class)->platform($actor, 'support.hours.manage');
            $old = $this->configuration($tenant);
            abort_if($old['revision'] !== (int) $data['revision'], 409);
            DB::table('support_hours')->updateOrInsert(['tenant_id' => $tenant], ['weekly' => json_encode($data['weekly']), 'revision' => $old['revision'] + 1, 'updated_at' => now()]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor, 'SUPPORT_HOURS_SAVED', 'tenant', $tenant, $old, $data);
        });
    }

    private function isOpen(array $weekly, CarbonImmutable $now): bool
    {
        $minute = $now->hour * 60 + $now->minute;
        $day = $now->dayOfWeekIso - 1;
        foreach ([$day, ($day + 6) % 7] as $index) {
            foreach ($weekly[$index] as $slot) {
                $start = $this->minutes($slot['start']);
                $end = $this->minutes($slot['end']);
                if ($index === $day ? ($end > $start ? $minute >= $start && $minute < $end : $minute >= $start) : ($end < $start && $minute < $end)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
