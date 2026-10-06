<?php

namespace App\Application\Support;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SupportFaqs
{
    public function save(string $actor, ?string $tenant, ?string $id, array $input): string
    {
        app(SupportAccess::class)->platform($actor, 'support.bot.manage');
        if ($tenant !== null) {
            Tenant::findOrFail($tenant);
        }
        $data = Validator::make($input, [
            'question' => 'required|string|max:200', 'variants' => 'present|array|max:20', 'variants.*' => 'required|string|max:200',
            'keywords' => 'present|array|max:20', 'keywords.*' => 'required|string|min:2|max:100',
            'answer' => 'required|string|max:2000', 'enabled' => 'required|boolean',
            'revision' => 'required|integer|min:0', 'overrides_id' => 'nullable|uuid', 'archived' => 'sometimes|boolean',
        ])->validate();

        return DB::transaction(function () use ($actor, $tenant, $id, $data) {
            if ($tenant !== null) {
                Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            }
            app(SupportAccess::class)->platform($actor, 'support.bot.manage');
            $row = $id ? DB::table('support_faqs')->where('tenant_id', $tenant)->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            abort_if(($row?->revision ?? 0) !== (int) $data['revision'], 409, 'FAQ changed. Refresh before saving.');
            $parentId = $data['overrides_id'] ?? null;
            abort_if($row && $row->overrides_id !== $parentId, 422);
            $parent = $parentId ? DB::table('support_faqs')->whereNull('tenant_id')->whereNull('archived_at')->where('id', $parentId)->sharedLock()->firstOrFail() : null;
            abort_if($parent && ! $tenant, 422);
            if ($parent && ! $row) {
                abort_if(DB::table('support_faqs')->where('tenant_id', $tenant)->where('overrides_id', $parentId)->exists(), 409, 'A company override already exists.');
            }
            $id ??= (string) Str::uuid();
            $values = ['question' => $parent?->question ?? trim($data['question']),
                'variants' => $parent?->variants ?? json_encode(array_values(array_unique(array_map('trim', $data['variants']))), JSON_UNESCAPED_UNICODE),
                'keywords' => $parent?->keywords ?? json_encode(array_values(array_unique(array_map('trim', $data['keywords']))), JSON_UNESCAPED_UNICODE),
                'answer' => trim($data['answer']), 'enabled' => $data['enabled'], 'revision' => ($row?->revision ?? 0) + 1,
                'archived_at' => ($data['archived'] ?? false) ? now() : null, 'updated_at' => now()];
            abort_if($this->normalize($values['question']) === '' || $values['answer'] === '', 422);
            if ($row) {
                DB::table('support_faqs')->where('id', $id)->update($values);
            } else {
                DB::table('support_faqs')->insert($values + ['id' => $id, 'tenant_id' => $tenant, 'overrides_id' => $parentId, 'created_at' => now()]);
            }
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor, 'SUPPORT_FAQ_SAVED', 'support_faq', $id,
                $row ? (array) $row : null, $values);

            return $id;
        });
    }

    public function effective(string $tenant): array
    {
        $rows = DB::table('support_faqs')->whereNull('archived_at')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant))->get();
        $overrides = $rows->whereNotNull('overrides_id')->keyBy('overrides_id');
        $result = [];
        foreach ($rows as $row) {
            if ($row->overrides_id) {
                continue;
            }
            $answer = $row->tenant_id === null ? ($overrides->get($row->id) ?? $row) : $row;
            if (! $row->enabled || ! $answer->enabled) {
                continue;
            }
            $result[] = ['id' => $answer->id, 'revision' => $answer->revision, 'question' => $row->question,
                'variants' => json_decode($row->variants, true), 'keywords' => json_decode($row->keywords, true),
                'answer' => $answer->answer, 'scope' => $answer->tenant_id ? 'company' : 'public'];
        }

        return $result;
    }

    public function match(string $tenant, string $text): array
    {
        $question = $this->normalize($text);
        if ($question === '') {
            return ['answer' => null, 'candidates' => []];
        }
        $candidates = [];
        foreach ($this->effective($tenant) as $faq) {
            $exact = false;
            $score = 0.0;
            foreach ([$faq['question'], ...$faq['variants']] as $variant) {
                $normal = $this->normalize($variant);
                $exact = $exact || $question === $normal;
                $score = max($score, $this->dice($question, $normal));
            }
            foreach ($faq['keywords'] as $keyword) {
                $normal = $this->normalize($keyword);
                if (mb_strlen($normal) >= 2 && str_contains($question, $normal)) {
                    $score = max($score, 0.85);
                }
            }
            $candidates[] = $faq + ['score' => $score, 'exact' => $exact];
        }
        usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: strcmp($a['id'], $b['id']));
        $exacts = array_values(array_filter($candidates, fn ($row) => $row['exact']));
        $best = $candidates[0] ?? null;
        $answer = count($exacts) === 1 ? $exacts[0] : (count($exacts) === 0 && $best && $best['score'] >= 0.75 && 0.10 - 1e-9 <= $best['score'] - ($candidates[1]['score'] ?? 0) ? $best : null);

        return ['answer' => $answer, 'candidates' => array_slice($candidates, 0, 5)];
    }

    private function normalize(string $text): string
    {
        return preg_replace('/[\s\p{P}\p{Z}]+/u', '', mb_strtolower(trim($text))) ?? '';
    }

    private function dice(string $a, string $b): float
    {
        if ($a === $b) {
            return 1;
        }
        if (mb_strlen($a) < 2 || mb_strlen($b) < 2) {
            return 0;
        }
        $grams = function ($text) {
            $result = [];
            for ($i = 0; $i < mb_strlen($text) - 1; $i++) {
                $result[] = mb_substr($text, $i, 2);
            }

            return array_values(array_unique($result));
        };
        $left = $grams($a);
        $right = $grams($b);

        return 2 * count(array_intersect($left, $right)) / (count($left) + count($right));
    }
}
