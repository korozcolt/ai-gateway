<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI provider connections. The code (AIP-0001) comes from a DB sequence and is immutable.
 * api_key holds ciphertext only. Connections are deactivated, never deleted (usage ledger references them).
 * Requires PostgreSQL (sequence default, jsonb, guard trigger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 12)->unique();
            $table->string('driver', 40);
            $table->string('name', 120);
            $table->string('base_url', 255);
            $table->text('api_key')->nullable();
            $table->string('api_key_key_id', 40)->nullable();
            $table->boolean('supports_json_schema')->default(false);
            $table->jsonb('models')->default('[]');
            $table->jsonb('request_options')->nullable();
            $table->decimal('budget_usd', 12, 2)->nullable();
            $table->boolean('is_active')->default(false);
            $table->date('verified_at')->nullable();
            $table->string('source_url', 255)->nullable();
            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
DROP SEQUENCE IF EXISTS ai_provider_code_seq CASCADE;
CREATE SEQUENCE ai_provider_code_seq AS bigint OWNED BY ai_providers.code;

ALTER TABLE ai_providers
    ALTER COLUMN code SET DEFAULT ('AIP-' || lpad(nextval('ai_provider_code_seq')::text, 4, '0'));

ALTER TABLE ai_providers ADD CONSTRAINT ai_providers_driver_not_empty CHECK (length(btrim(driver)) > 0);
ALTER TABLE ai_providers ADD CONSTRAINT ai_providers_base_url_https CHECK (base_url ~ '^https://');
ALTER TABLE ai_providers ADD CONSTRAINT ai_providers_budget_non_negative CHECK (budget_usd IS NULL OR budget_usd >= 0);

CREATE OR REPLACE FUNCTION ai_providers_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'ai_providers rows cannot be deleted; deactivate the connection instead';
    END IF;

    IF NEW.code IS DISTINCT FROM OLD.code THEN
        RAISE EXCEPTION 'ai_providers.code is assigned automatically and immutable';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS ai_providers_guard ON ai_providers;
CREATE TRIGGER ai_providers_guard
    BEFORE UPDATE OR DELETE ON ai_providers
    FOR EACH ROW EXECUTE FUNCTION ai_providers_guard();
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS ai_providers_guard ON ai_providers;
DROP FUNCTION IF EXISTS ai_providers_guard();
SQL);
        Schema::dropIfExists('ai_providers');
        DB::unprepared('DROP SEQUENCE IF EXISTS ai_provider_code_seq');
    }
};
