<?php

namespace Korbytes\AiGateway\Database\Seeders;

use Illuminate\Database\Seeder;
use Korbytes\AiGateway\Services\ProviderImporter;

/**
 * Seeds provider connections (tokens included, encrypted on insert) from the JSON file at
 * config('ai-gateway.seed_file') when it exists (kept out of git). Always ensures the offline `fake` connection exists.
 * Silent: use `php artisan ai:providers:import` to see a summary.
 * Add it to DatabaseSeeder: $this->call(AiProvidersSeeder::class).
 */
class AiProvidersSeeder extends Seeder
{
    public function run(ProviderImporter $importer): void
    {
        $importer->import([[
            'name' => 'Fake (tests and development)',
            'driver' => 'fake',
            'base_url' => 'https://fake.invalid',
            'supports_json_schema' => true,
            'is_active' => true,
            'models' => [['id' => 'fake-model', 'input_price_usd_per_million' => 0, 'output_price_usd_per_million' => 0]],
        ]]);

        $file = (string) config('ai-gateway.seed_file');

        if (is_file($file)) {
            $importer->importFile($file);
        }
    }
}
