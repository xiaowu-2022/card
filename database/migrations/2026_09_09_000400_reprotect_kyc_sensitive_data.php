<?php

use App\Domain\Kyc\Services\IdentityHashGenerator;
use App\Domain\Kyc\Services\IdentityNumberNormalizer;
use App\Domain\Kyc\Services\KycDataCipher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $normalizer = app(IdentityNumberNormalizer::class);
        $hashes = app(IdentityHashGenerator::class);
        $cipher = null;

        foreach (['kyc_applications', 'identity_records'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $normalizer, $hashes, &$cipher): void {
                $cipher ??= app(KycDataCipher::class);
                foreach ($rows as $row) {
                    $normalized = $normalizer->normalize(Crypt::decryptString($row->identity_number_encrypted));
                    DB::table($table)->where('id', $row->id)->update([
                        'identity_number_encrypted' => $cipher->encrypt($normalized),
                        'identity_hash' => $hashes->generate($row->tenant_id, $row->document_type, $row->document_country, $normalized),
                    ]);
                }
            }, 'id');
        }

        DB::table('kyc_applications')->whereNotNull('ocr_result_encrypted')->orderBy('id')->chunkById(100, function ($rows) use ($normalizer, &$cipher): void {
            $cipher ??= app(KycDataCipher::class);
            foreach ($rows as $row) {
                $oldPayload = json_decode(Crypt::decryptString($row->ocr_result_encrypted), true, 8, JSON_THROW_ON_ERROR);
                $submitted = $cipher->decrypt($row->identity_number_encrypted);
                DB::table('kyc_applications')->where('id', $row->id)->update([
                    'ocr_result_encrypted' => $cipher->encrypt(json_encode($this->minimalOcrPayload($oldPayload, $submitted, $normalizer), JSON_THROW_ON_ERROR)),
                ]);
            }
        }, 'id');
    }

    public function down(): void
    {
        $normalizer = app(IdentityNumberNormalizer::class);
        $cipher = null;
        $key = (string) config('kyc.identity_hash_key');
        if (strlen($key) < 32) {
            throw new RuntimeException('KYC_IDENTITY_HASH_KEY must contain at least 32 characters.');
        }

        foreach (['kyc_applications', 'identity_records'] as $table) {
            DB::table($table)->orderBy('id')->chunkById(100, function ($rows) use ($table, $normalizer, $key, &$cipher): void {
                $cipher ??= app(KycDataCipher::class);
                foreach ($rows as $row) {
                    $normalized = $normalizer->normalize($cipher->decrypt($row->identity_number_encrypted));
                    DB::table($table)->where('id', $row->id)->update([
                        'identity_number_encrypted' => Crypt::encryptString($normalized),
                        'identity_hash' => hash_hmac('sha256', $row->tenant_id.':'.$normalized, $key),
                    ]);
                }
            }, 'id');
        }

        DB::table('kyc_applications')->whereNotNull('ocr_result_encrypted')->orderBy('id')->chunkById(100, function ($rows) use (&$cipher): void {
            $cipher ??= app(KycDataCipher::class);
            foreach ($rows as $row) {
                $payload = json_decode($cipher->decrypt($row->ocr_result_encrypted), true, 8, JSON_THROW_ON_ERROR);
                DB::table('kyc_applications')->where('id', $row->id)->update([
                    'ocr_result_encrypted' => Crypt::encryptString(json_encode([
                        'candidate_identity_number' => null,
                        'candidate_name' => $payload['candidate_name'] ?? null,
                        'confidence' => $payload['confidence'] ?? null,
                    ], JSON_THROW_ON_ERROR)),
                ]);
            }
        }, 'id');
    }

    /** @param array<string, mixed> $payload @return array<string, string|null> */
    private function minimalOcrPayload(array $payload, string $submittedIdentity, IdentityNumberNormalizer $normalizer): array
    {
        $candidate = is_string($payload['candidate_identity_number'] ?? null) ? $payload['candidate_identity_number'] : null;
        $match = 'UNKNOWN';
        if ($candidate !== null && trim($candidate) !== '') {
            try {
                $match = hash_equals($submittedIdentity, $normalizer->normalize($candidate)) ? 'MATCH' : 'MISMATCH';
            } catch (Throwable) {
                $match = 'UNKNOWN';
            }
        }
        $name = is_string($payload['candidate_name'] ?? null) ? mb_substr(trim(strip_tags($payload['candidate_name'])), 0, 200) : null;
        $confidence = is_string($payload['confidence'] ?? null) && preg_match('/^(0(\.\d{1,4})?|1(\.0{1,4})?)$/', $payload['confidence']) ? $payload['confidence'] : null;

        return ['candidate_identity_match' => $match, 'candidate_name' => $name, 'confidence' => $confidence];
    }
};
