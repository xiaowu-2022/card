<?php

namespace App\Application\Tenant;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PlatformFundsFilters
{
    public const TIMEZONE = 'Asia/Kuala_Lumpur';

    public function validated(Request $request): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $input = $request->only(['start', 'end', 'scope', 'companies']);
        $input += ['start' => $today->subDays(29)->toDateString(), 'end' => $today->toDateString(), 'scope' => 'all', 'companies' => []];
        $validator = Validator::make($input, [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
            'scope' => ['required', 'in:all,selected'],
            'companies' => ['array', 'max:500', 'required_if:scope,selected'],
            'companies.*' => ['required', 'uuid', 'distinct', 'exists:tenants,id'],
        ], [
            'start.*' => 'Enter a valid start date.',
            'end.required' => 'Enter a valid end date.',
            'end.date_format' => 'Enter a valid end date.',
            'end.after_or_equal' => 'End date must not be before start date.',
            'companies.required_if' => 'Select at least one company.',
            'companies.*' => 'Select valid companies (up to 500).',
            'companies.*.*' => 'Select valid companies (up to 500).',
        ]);
        $validator->after(function ($validator) use ($input): void {
            if (! $validator->errors()->has('start') && ! $validator->errors()->has('end')) {
                $start = CarbonImmutable::parse($input['start'], self::TIMEZONE);
                $end = CarbonImmutable::parse($input['end'], self::TIMEZONE);
                if ($start->addDays(365)->lessThan($end)) {
                    $validator->errors()->add('end', 'Select a date range of at most 366 days.');
                }
            }
        });
        $filters = $validator->validate();
        $filters['companies'] = $filters['scope'] === 'all' ? [] : array_values($filters['companies']);

        return $filters;
    }
}
