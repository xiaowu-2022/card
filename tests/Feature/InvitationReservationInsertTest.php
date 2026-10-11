<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('reserves member and company invitation codes with immediate foreign key checks', function () {
    $this->seed();
    DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
    $tenant = Tenant::where('slug', 'tenant-a')->sole();
    $members = app(PromotionMembershipAction::class);
    $user = User::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'reservation-'.Str::uuid().'@example.test',
        'password_hash' => 'unused-test-hash',
        'status' => 'ACTIVE',
    ]);
    $member = $members->ensure($tenant->id, $user->id);
    expect(DB::table('promotion_invitation_reservations')->where('code', $member->invitation_code)
        ->where('tenant_id', $tenant->id)->value('member_id'))->toBe($member->id);

    $company = Tenant::query()->create(['slug' => 'reservation-company', 'name' => 'Reservation test', 'status' => 'ACTIVE']);
    $invitation = $members->companyInvitation($company->id);
    expect(DB::table('promotion_invitation_reservations')->where('code', $invitation->invitation_code)
        ->where('tenant_id', $company->id)->value('company_invitation_id'))->toBe($invitation->id);
});
