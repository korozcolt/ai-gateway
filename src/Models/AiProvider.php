<?php

namespace Korbytes\AiGateway\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Korbytes\AiGateway\Crypto\AiCredentialCipher;
use Korbytes\AiGateway\Database\Factories\AiProviderFactory;
use Korbytes\AiGateway\ModelPrice;

/**
 * AI provider connection (platform level).
 *
 * @property string $code
 * @property string $driver
 * @property string $name
 * @property string $base_url
 * @property bool $supports_json_schema
 * @property array<int, array<string, mixed>>|null $models
 * @property array<string, mixed>|null $request_options
 * @property bool $is_active
 * @property Carbon|null $verified_at
 * @property string|null $source_url
 *                                   The API key is stored encrypted with the dedicated AI key and never leaves the model in
 *                                   clear text except through apiKey(), which only the driver registry and tests call.
 */
#[Fillable(['driver', 'name', 'base_url', 'supports_json_schema', 'models', 'budget_usd', 'is_active', 'verified_at', 'source_url', 'request_options'])]
#[Hidden(['api_key', 'api_key_key_id'])]
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    protected static function newFactory(): AiProviderFactory
    {
        return AiProviderFactory::new();
    }

    protected function casts(): array
    {
        return [
            'supports_json_schema' => 'boolean',
            'is_active' => 'boolean',
            'models' => 'array',
            'request_options' => 'array',
            'budget_usd' => 'decimal:2',
            'verified_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // The code is assigned by a DB sequence default: load it so callers see it right after create().
        static::created(function (self $provider): void {
            $provider->forceFill(['code' => DB::table('ai_providers')->where('id', $provider->id)->value('code')])
                ->syncOriginalAttribute('code');
        });
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function replaceApiKey(#[\SensitiveParameter] string $plain): void
    {
        $this->forceFill(app(AiCredentialCipher::class)->encrypt($plain));
        $this->save();
    }

    public function hasApiKey(): bool
    {
        return filled($this->getAttributes()['api_key'] ?? null);
    }

    public function apiKey(): ?string
    {
        if (! $this->hasApiKey()) {
            return null;
        }

        $attributes = $this->getAttributes();

        return app(AiCredentialCipher::class)->decrypt($attributes['api_key'], (string) $attributes['api_key_key_id']);
    }

    /** @return list<string> */
    public function modelIds(): array
    {
        return array_values(array_map(fn (array $model): string => (string) $model['id'], $this->models ?? []));
    }

    public function priceFor(string $modelId): ?ModelPrice
    {
        foreach ($this->models ?? [] as $model) {
            if (($model['id'] ?? null) !== $modelId) {
                continue;
            }

            $input = $model['input_price_usd_per_million'] ?? null;
            $output = $model['output_price_usd_per_million'] ?? null;

            if ($input === null || $output === null) {
                return null;
            }

            return new ModelPrice(
                (float) $input,
                (float) $output,
                $this->verified_at?->toDateString(),
                $this->source_url,
            );
        }

        return null;
    }

    /** Serialization drops the secret columns (the `hidden` list only covers toArray/toJson). @return array<string, mixed> */
    public function __serialize(): array
    {
        $vars = get_object_vars($this);

        foreach (['attributes', 'original', 'changes'] as $bag) {
            $vars[$bag] = array_diff_key($vars[$bag] ?? [], array_flip(['api_key', 'api_key_key_id']));
        }

        return $vars;
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        foreach ($data as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return collect($this->attributesToArray())->except(['api_key', 'api_key_key_id'])->all();
    }
}
