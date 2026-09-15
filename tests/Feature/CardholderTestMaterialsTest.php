<?php

use App\Application\Card\CardholderTestMaterialsArchive;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->seed();
    Storage::fake('private');
    Http::preventStrayRequests();
    $this->tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->user = User::where('tenant_id', $this->tenant->id)->firstOrFail();
    $this->product = CardProduct::where('provider', 'PHOTONPAY')->firstOrFail();
    $this->data = ['request_id' => (string) Str::uuid(), 'card_product_id' => $this->product->id];
    foreach (['legal_first_name', 'legal_last_name', 'date_of_birth', 'email', 'mobile', 'mobile_country_code', 'nationality_country_code', 'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code', 'document_type'] as $field) {
        $this->data[$field] = '';
    }
    $this->data['legal_first_name'] = 'PrivateTestName';
    $this->data['front'] = kycTestImage();
    $this->data['back'] = kycTestImage();
});

it('retains incomplete test materials encrypted and restores only through an owned no-store endpoint', function (): void {
    $this->actingAs($this->user, 'tenant_user');
    $this->post('http://a.localhost/cards/cardholder/test-materials/save', $this->data)->assertRedirect()->assertSessionHas('success');
    $paths = Storage::disk('private')->allFiles('card-test-materials');
    expect($paths)->toHaveCount(1)->and(Storage::disk('private')->get($paths[0]))->not->toContain('PrivateTestName');
    $response = $this->postJson('http://a.localhost/cards/cardholder/test-materials/'.$this->product->id)->assertOk()->assertJsonPath('fields.legal_first_name', 'PrivateTestName')->assertJsonPath('fields.request_id', $this->data['request_id']);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect(base64_decode($response->json('documents.front.content')))->toBe($this->data['front']->getContent());
    expect(DB::table('provider_cardholders')->count())->toBe(0)->and(DB::table('ledger_entries')->count())->toBe(0);
    $otherTenant = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $otherUser = User::where('tenant_id', $otherTenant->id)->firstOrFail();
    $this->actingAs($otherUser, 'tenant_user')->postJson('http://b.localhost/cards/cardholder/test-materials/'.$this->product->id)->assertNotFound();
    Http::assertNothingSent();
});

it('rejects forged ownership and production retention without sending to providers', function (): void {
    $this->actingAs($this->user, 'tenant_user')->postJson('http://a.localhost/cards/cardholder/test-materials/save', [...$this->data, 'tenant_id' => (string) Str::uuid()])->assertUnprocessable();
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => app(CardholderTestMaterialsArchive::class)->save($this->tenant->id, $this->user->id, $this->data))->toThrow(HttpException::class);
        expect(Storage::disk('private')->allFiles())->toBe([]);
        Http::assertNothingSent();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});
