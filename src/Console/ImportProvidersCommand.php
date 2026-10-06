<?php

namespace Korbytes\AiGateway\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Korbytes\AiGateway\Services\ProviderImporter;

class ImportProvidersCommand extends Command
{
    protected $signature = 'ai:providers:import {file? : JSON seed file (default: config ai-gateway.seed_file)} {--rotate-keys : Replace the stored token of existing connections}';

    protected $description = 'Imports AI provider connections from a JSON file; tokens are encrypted before they are stored';

    public function handle(ProviderImporter $importer): int
    {
        $file = (string) ($this->argument('file') ?? config('ai-gateway.seed_file'));

        try {
            $result = $importer->importFile($file, (bool) $this->option('rotate-keys'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$result['created']} created, {$result['updated']} updated, {$result['keys_set']} tokens encrypted.");
        $this->comment('Delete or keep the seed file out of git: it contains clear-text tokens.');

        return self::SUCCESS;
    }
}
