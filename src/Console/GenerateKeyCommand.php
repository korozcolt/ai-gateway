<?php

namespace Korbytes\AiGateway\Console;

use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Korbytes\AiGateway\Crypto\AiKeyId;
use Korbytes\AiGateway\Crypto\EnvironmentAiKeyProvider;

class GenerateKeyCommand extends Command
{
    protected $signature = 'ai:generate-key';

    protected $description = 'Generates the AI credentials encryption key with its system-generated key id (never written to disk)';

    public function handle(): int
    {
        $this->line('AI_CREDENTIALS_KEY_ID='.AiKeyId::generate());
        $this->line('AI_CREDENTIALS_KEY=base64:'.base64_encode(Encrypter::generateKey(EnvironmentAiKeyProvider::CIPHER)));
        $this->newLine();
        $this->comment('Copy the id exactly as printed; never type or edit it by hand. On rotation, move the previous id/key pair unchanged into AI_CREDENTIALS_RETIRED_KEYS.');

        return self::SUCCESS;
    }
}
