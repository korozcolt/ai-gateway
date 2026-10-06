<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Korbytes\AiGateway\ModelPrice;
use Korbytes\AiGateway\Models\AiProvider;

const AI_TEST_SECRET = 'sk-distinctive-test-secret-7f3a91';

it('assigns a DB-generated code to a new connection', function () {
    $first = AiProvider::factory()->create();

    expect($first->fresh()->code)->toMatch('/^AIP-\d{4}$/')
        ->and($first->hasApiKey())->toBeFalse();
});

it('assigns codes from the DB sequence and ignores a crafted code on mass assignment', function () {
    $provider = AiProvider::create([
        'driver' => 'gemini', 'name' => 'G', 'base_url' => 'https://example.test', 'code' => 'HACK-1',
        'models' => [],
    ]);

    expect($provider->fresh()->code)->toMatch('/^AIP-\d{4}$/')
        ->and($provider->fresh()->code)->not->toBe('HACK-1');
});

it('rejects an update of code and any delete at the database level', function () {
    $provider = AiProvider::factory()->create();

    expect(dbRejects(fn () => DB::table('ai_providers')->where('id', $provider->id)->update(['code' => 'AIP-9999'])))->toBeTrue()
        ->and(dbRejects(fn () => DB::table('ai_providers')->where('id', $provider->id)->delete()))->toBeTrue();
});

it('rejects non-https base urls and negative budgets', function () {
    expect(dbRejects(fn () => AiProvider::factory()->create(['base_url' => 'http://plain.example'])))->toBeTrue()
        ->and(dbRejects(fn () => AiProvider::factory()->create(['budget_usd' => -1])))->toBeTrue();
});

it('has no tenant_id column', function () {
    expect(Schema::hasColumn('ai_providers', 'tenant_id'))->toBeFalse();
});

it('encrypts the key, round-trips it and replaces it', function () {
    $provider = AiProvider::factory()->create();
    $provider->replaceApiKey(AI_TEST_SECRET);
    $first = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');

    expect($first)->not->toContain(AI_TEST_SECRET)
        ->and($provider->fresh()->apiKey())->toBe(AI_TEST_SECRET)
        ->and($provider->fresh()->hasApiKey())->toBeTrue();

    $provider->replaceApiKey(AI_TEST_SECRET);
    expect(DB::table('ai_providers')->where('id', $provider->id)->value('api_key'))->not->toBe($first);
});

it('never leaks the key or ciphertext through any serialization path', function () {
    $provider = AiProvider::factory()->withKey(AI_TEST_SECRET)->create()->fresh();
    $cipher = DB::table('ai_providers')->where('id', $provider->id)->value('api_key');
    Log::spy();

    $dumps = [
        json_encode($provider->toArray()),
        $provider->toJson(),
        json_encode($provider),
        serialize($provider),
        var_export($provider->__debugInfo(), true),
        print_r($provider->__debugInfo(), true),
    ];

    foreach ($dumps as $dump) {
        expect($dump)->not->toContain(AI_TEST_SECRET)->and($dump)->not->toContain($cipher);
    }
    expect(array_key_exists('api_key', $provider->toArray()))->toBeFalse();
});

it('keeps api_key, key id and code out of fillable', function () {
    $fillable = (new AiProvider)->getFillable();

    expect($fillable)->not->toContain('code')->not->toContain('api_key')->not->toContain('api_key_key_id');
});

it('resolves model prices and the cost formula', function () {
    $provider = AiProvider::factory()->create([
        'verified_at' => '2026-10-01',
        'source_url' => 'https://example.test/pricing',
        'models' => [
            ['id' => 'm1', 'input_price_usd_per_million' => 2.0, 'output_price_usd_per_million' => 8.0],
            ['id' => 'm2', 'input_price_usd_per_million' => null, 'output_price_usd_per_million' => null],
        ],
    ]);

    $price = $provider->priceFor('m1');

    expect($price)->toBeInstanceOf(ModelPrice::class)
        ->and($price->verifiedAt)->toBe('2026-10-01')
        ->and($provider->priceFor('m2'))->toBeNull()
        ->and($provider->priceFor('absent'))->toBeNull()
        ->and($provider->modelIds())->toBe(['m1', 'm2'])
        ->and(AiProvider::query()->active()->count())->toBeGreaterThanOrEqual(1);
});
