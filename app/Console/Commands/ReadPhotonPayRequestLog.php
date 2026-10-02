<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

final class ReadPhotonPayRequestLog extends Command
{
    protected $signature = 'photonpay:request-log {request_id} {--date= : Log date YYYY-MM-DD; defaults to today}';

    protected $description = 'Read encrypted PhotonPay business request/response logs for one request on the server';

    public function handle(): int
    {
        $id = $this->argument('request_id');
        $date = $this->option('date') ?: now()->format('Y-m-d');
        if (! Str::isUuid($id) || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            $this->error('Use a UUID request ID and YYYY-MM-DD date.');

            return self::FAILURE;
        }
        $configured = (string) config('card-provider.photonpay.request_log_key');
        $key = str_starts_with($configured, 'base64:') ? base64_decode(substr($configured, 7), true) : false;
        if (! is_string($key) || strlen($key) !== 32) {
            $this->error('PHOTONPAY_REQUEST_LOG_ENCRYPTION_KEY is missing or invalid.');

            return self::FAILURE;
        }
        $path = storage_path('logs/photonpay-requests-'.$date.'.log');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('No readable payload log for this date. Check photonpay logs for request.payload_unavailable.');

            return self::FAILURE;
        }
        $cipher = new Encrypter($key, 'aes-256-gcm');
        $count = 0;
        foreach (new \SplFileObject($path) as $line) {
            $record = json_decode($line, true);
            $context = $record['context'] ?? [];
            if (($context['request_id'] ?? null) !== $id) {
                continue;
            }
            try {
                $payload = json_decode($cipher->decryptString($context['encrypted_envelope']), true, 512, JSON_THROW_ON_ERROR);
                $body = base64_decode($payload['body_base64'], true);
                if (! is_string($body)) {
                    throw new \RuntimeException;
                }
                // JSON-escape controls rather than render provider-supplied terminal sequences.
                $this->output->writeln(json_encode([
                    'span_id' => $context['span_id'], 'direction' => $payload['direction'],
                    'context' => $payload['context'], 'body' => $body,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
                $count++;
            } catch (\Throwable) {
                $this->error('Cannot decrypt this record. Check the original log key and file integrity.');

                return self::FAILURE;
            }
        }
        if ($count === 0) {
            $this->error('No captured payloads for this request. Historical missing payloads cannot be recovered.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
