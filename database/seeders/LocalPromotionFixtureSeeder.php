<?php

namespace Database\Seeders;

use App\Application\Kyc\ApproveKycAction;
use App\Application\Kyc\SubmitKycApplicationAction;
use App\Application\Payment\CreateWalletTopupAction;
use App\Application\Payment\CreditWalletTopupAction;
use App\Application\Payment\InitiateWalletTopupPaymentAction;
use App\Application\Payment\QueryPaymentStatusAction;
use App\Application\Promotion\ConfigurePromotionAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionQuery;
use App\Application\SecurityDeposit\AllocateInitialDepositAction;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Application\Wallet\ActivateUserWalletAction;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Models\IdentityRecord;
use App\Domain\Kyc\Models\KycApplication;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Payment\Enums\MockPaymentMode;
use App\Domain\Payment\Models\PaymentProviderTransaction;
use App\Domain\Payment\Services\PaymentStateTransitionPolicy;
use App\Domain\Promotion\Models\PromotionLevel;
use App\Domain\SecurityDeposit\Models\InitialDepositIntent;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;
use App\Infrastructure\Providers\Payment\MockPaymentProvider;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Explicit, local-only fixture runner. Never included in ordinary database seeding. */
final class LocalPromotionFixtureSeeder
{
    public function run(string $tenantId, string $rootUserId, string $actorId, int $count = 500, ?\Closure $progress = null): array
    {
        abort_unless(app()->environment('local', 'testing') && in_array(DB::connection()->getDatabaseName(), ['card_mock', 'card_ui_test'], true), 403);
        abort_unless(config('payment.driver') === 'mock' && $count >= 10 && $count <= 500 && $count % 5 === 0, 403);
        abort_if(Schema::hasTable('paid_promotion_cycles'), 409, 'Legacy promotion fixtures are retired after paid promotion activation.');
        $actor = AdminUser::query()->findOrFail($actorId);
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $tenant = Tenant::query()->whereKey($tenantId)->where('status', 'ACTIVE')->firstOrFail();
        $root = User::query()->where('tenant_id', $tenantId)->whereKey($rootUserId)->where('status', 'ACTIVE')->firstOrFail();
        abort_unless($tenant->default_asset === 'USDT', 422);
        $deposit = $tenant->businessSettings()->firstOrFail()->required_security_deposit_amount;
        abort_unless(is_string($deposit) && BigDecimal::of($deposit)->isPositive(), 422);
        $batch = 'promotion-20260915-'.$root->account_id;
        $lock = DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS acquired', [$batch]);
        abort_unless($lock->acquired, 409);
        $originalClock = Date::getTestNow();
        $originalQueue = Queue::getFacadeRoot();
        try {
            $audit = app(AuditLogger::class);
            $started = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('resource_id', $rootUserId)->where('action', 'LOCAL_PROMOTION_FIXTURE_STARTED')->first();
            $completed = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('resource_id', $rootUserId)->where('action', 'LOCAL_PROMOTION_FIXTURE_COMPLETED')->exists();
            if ($started) {
                $meta = json_decode($started->after_data, true, flags: JSON_THROW_ON_ERROR);
                abort_unless($meta['batch'] === $batch && $meta['count'] === $count, 409);
            } else {
                $meta = ['batch' => $batch, 'count' => $count, 'anchor' => CarbonImmutable::now($tenant->timezone)->startOfDay()->toIso8601String(), 'deposit' => $deposit];
                $audit->record($tenantId, 'ADMIN', $actorId, 'LOCAL_PROMOTION_FIXTURE_STARTED', 'user', $rootUserId, null, $meta);
            }
            if ($completed) {
                return app(PromotionQuery::class)->execute($tenantId, $rootUserId, null);
            }
            abort_unless($meta['deposit'] === $deposit, 409);
            // Only this process uses a simulator; do not change live bindings or environment files.
            Queue::fake();
            $provider = new MockPaymentProvider(MockPaymentMode::Succeeded, Str::random(48));
            $transitions = app(PaymentStateTransitionPolicy::class);
            $payments = new CreateWalletTopupAction($provider, app(KycStatusService::class), $audit, new InitiateWalletTopupPaymentAction($provider, $transitions));
            $queryPayment = new QueryPaymentStatusAction($provider, $transitions);
            $configure = app(ConfigurePromotionAction::class);
            $members = app(PromotionMembershipAction::class);
            $levels = [];
            foreach ([50, 60, 70, 80, 90, 100, 110, 120] as $offset => $reward) {
                $rank = $offset + 1;
                $existing = PromotionLevel::query()->where('tenant_id', $tenantId)->where('rank', $rank)->first();
                $levels[$rank] = $existing && $existing->name === (string) $reward && BigDecimal::of($existing->reward_amount)->isEqualTo($reward)
                    ? $existing : $configure->level($tenantId, $actorId, $rank, (string) $reward, (string) $reward, $existing?->revision);
            }
            $rootMember = $configure->memberLevel($tenantId, $actorId, $rootUserId, $levels[8]->id);
            $anchor = CarbonImmutable::parse($meta['anchor']);
            $directCount = intdiv($count, 5);
            $parents = [];
            // Random inaccessible credentials; fake contacts/documents never belong to real people.
            $passwordHash = Hash::make(Str::random(64));
            $testImage = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
            for ($index = 1; $index <= $count; $index++) {
                $direct = $index <= $directCount;
                $age = $direct ? 60 - intdiv(($index - 1) * 14, $directCount) : 45 - intdiv(($index - $directCount - 1) * 44, $count - $directCount);
                $joined = $anchor->subDays($age)->addHours(8 + $index % 10)->addMinutes(($index * 17) % 60)->utc();
                Date::setTestNow($joined);
                $parent = $direct ? $rootMember : $parents[1 + (($index - $directCount - 1) % $directCount)];
                $parentRank = $direct ? 8 : $parent['rank'];
                $inviter = $direct ? $parent : $parent['member'];
                $rank = $direct ? 1 + (($index - 1) % 7) : ($index % $parentRank);
                $email = $batch.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT).'@fixture.invalid';
                $user = DB::transaction(function () use ($tenantId, $email, $passwordHash, $index, $members, $inviter) {
                    $user = User::query()->firstOrCreate(['tenant_id' => $tenantId, 'email' => $email], ['password_hash' => $passwordHash, 'status' => 'ACTIVE', 'email_verified_at' => now()]);
                    UserProfile::query()->firstOrCreate(['tenant_id' => $tenantId, 'user_id' => $user->id], ['display_name' => '测试成员 '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)]);
                    $members->ensure($tenantId, $user->id, $inviter->id);

                    return $user;
                });
                $member = $members->ensure($tenantId, $user->id, $inviter->id);
                $levelId = $rank === 0 ? null : $levels[$rank]->id;
                if ($member->level_id !== $levelId) {
                    $member = $configure->memberLevel($tenantId, $actorId, $user->id, $levelId);
                }
                if ($direct) {
                    $parents[$index] = ['rank' => $rank, 'member' => $member];
                }
                if ($index % 5 !== 4) {
                    Date::setTestNow($joined->addMinutes(35));
                    if (! IdentityRecord::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->exists()) {
                        $application = KycApplication::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->first();
                        $application ??= app(SubmitKycApplicationAction::class)->execute($tenant, $user, 'MY', 'LOCAL-PROMO-'.$user->id,
                            UploadedFile::fake()->createWithContent('LOCAL-TEST-front.png', $testImage), UploadedFile::fake()->createWithContent('LOCAL-TEST-back.png', $testImage));
                        if ($application->review_status->value === 'PENDING') {
                            app(ApproveKycAction::class)->execute($tenantId, $application->id, $actor);
                        }
                    }
                    $wallet = app(ActivateUserWalletAction::class)->execute($tenantId, $user->id)->wallet;
                    if ($index % 5 < 3) {
                        Date::setTestNow($joined->addHours(2));
                        $topup = $payments->execute($tenantId, $user->id, $wallet->id, $deposit, 'USDT', $user->id, 'http://a.localhost/wallet/top-ups/fixture/return');
                        if ($topup->order->status->value !== 'CREDITED') {
                            // Do not simulate confirmation of an earlier process's unresolved payment.
                            abort_unless($topup->created, 409, 'An incomplete fixture payment needs inspection before resuming.');
                            $transaction = PaymentProviderTransaction::query()->where('tenant_id', $tenantId)->where('wallet_topup_order_id', $topup->order->id)->firstOrFail();
                            $queryPayment->execute($tenantId, $transaction->id);
                            app(CreditWalletTopupAction::class)->execute($tenantId, $topup->order->id);
                        }
                        $intent = InitialDepositIntent::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->firstOrFail();
                        app(AllocateInitialDepositAction::class)->execute($tenantId, $intent->id);
                    }
                }
                if ($progress && $index % 25 === 0) {
                    $progress($index);
                }
            }
            Date::setTestNow($originalClock);
            $report = app(PromotionQuery::class)->execute($tenantId, $rootUserId, null);
            $audit->record($tenantId, 'ADMIN', $actorId, 'LOCAL_PROMOTION_FIXTURE_COMPLETED', 'user', $rootUserId, null, $meta + ['direct' => $directCount, 'funded' => intdiv($count * 3, 5)]);

            return $report;
        } finally {
            Date::setTestNow($originalClock);
            Queue::swap($originalQueue);
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$batch]);
        }
    }
}
