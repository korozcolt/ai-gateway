<?php

namespace Korbytes\AiGateway\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Korbytes\AiGateway\Models\AiProvider;

/** @extends Factory<AiProvider> */
class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    public function definition(): array
    {
        return [
            'driver' => 'fake',
            'name' => fake()->company().' AI',
            'base_url' => 'https://fake.invalid',
            'supports_json_schema' => true,
            'models' => [[
                'id' => 'fake-model-'.fake()->unique()->numerify('###'),
                'input_price_usd_per_million' => 1.0,
                'output_price_usd_per_million' => 2.0,
            ]],
            'budget_usd' => null,
            'is_active' => true,
        ];
    }

    public function gemini(): static
    {
        return $this->state(fn () => [
            'driver' => 'gemini',
            'name' => 'Google Gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'supports_json_schema' => true,
            'models' => [[
                'id' => 'gemini-test-model',
                'input_price_usd_per_million' => 0.30,
                'output_price_usd_per_million' => 2.50,
            ]],
        ]);
    }

    public function nvidia(): static
    {
        return $this->state(fn () => [
            'driver' => 'openai_compatible',
            'name' => 'NVIDIA Build',
            'base_url' => 'https://integrate.api.nvidia.com/v1',
            'supports_json_schema' => false,
            'models' => [[
                'id' => 'nvidia/test-model',
                'input_price_usd_per_million' => 0.50,
                'output_price_usd_per_million' => 1.50,
            ]],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withKey(string $plain): static
    {
        return $this->afterCreating(fn (AiProvider $provider) => $provider->replaceApiKey($plain));
    }
}
