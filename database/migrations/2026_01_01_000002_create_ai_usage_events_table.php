<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usage ledger of every AI driver call (append-only). It holds NO prompt or response content: only
 * counters, a price snapshot, cost, latency and an outcome code. context_type/context_id optionally
 * attribute the call to an app entity. Rows can only be removed by ai_usage_events_purge() (retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->foreignId('ai_provider_id')->constrained('ai_providers');
            $table->string('context_type', 60)->nullable();
            $table->unsignedBigInteger('context_id')->nullable();
            $table->string('model', 120);
            $table->string('purpose', 40);
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->integer('thinking_tokens')->default(0);
            $table->integer('cached_tokens')->default(0);
            $table->decimal('price_input_usd_per_million', 12, 6)->nullable();
            $table->decimal('price_output_usd_per_million', 12, 6)->nullable();
            $table->decimal('cost_usd', 14, 8)->nullable();
            $table->decimal('reported_cost_usd', 14, 8)->nullable();
            $table->integer('latency_ms')->default(0);
            $table->string('outcome', 24);
            $table->timestampTz('occurred_at');

            $table->index(['ai_provider_id', 'occurred_at']);
            $table->index(['ai_provider_id', 'model', 'occurred_at']);
            $table->index(['context_type', 'context_id', 'occurred_at']);
            $table->index('occurred_at');
        });

        DB::unprepared(<<<'SQL'
DROP SEQUENCE IF EXISTS ai_usage_event_code_seq CASCADE;
CREATE SEQUENCE ai_usage_event_code_seq AS bigint OWNED BY ai_usage_events.code;

ALTER TABLE ai_usage_events
    ALTER COLUMN code SET DEFAULT ('AUE-' || lpad(nextval('ai_usage_event_code_seq')::text, 8, '0'));

ALTER TABLE ai_usage_events ADD CONSTRAINT ai_usage_events_tokens_check
    CHECK (input_tokens >= 0 AND output_tokens >= 0 AND thinking_tokens >= 0 AND cached_tokens >= 0);

CREATE OR REPLACE FUNCTION ai_usage_events_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' AND current_setting('ai_gateway.usage_purge', true) = 'on' THEN
        RETURN OLD;
    END IF;
    RAISE EXCEPTION 'ai_usage_events is append-only';
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER ai_usage_events_no_change
    BEFORE UPDATE OR DELETE ON ai_usage_events
    FOR EACH ROW EXECUTE FUNCTION ai_usage_events_guard();

CREATE OR REPLACE FUNCTION ai_usage_events_purge(cutoff timestamptz) RETURNS bigint AS $$
DECLARE n bigint;
BEGIN
    PERFORM set_config('ai_gateway.usage_purge', 'on', true);
    DELETE FROM ai_usage_events WHERE occurred_at < cutoff;
    GET DIAGNOSTICS n = ROW_COUNT;
    PERFORM set_config('ai_gateway.usage_purge', 'off', true);
    RETURN n;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS ai_usage_events_no_change ON ai_usage_events;
DROP FUNCTION IF EXISTS ai_usage_events_purge(timestamptz);
DROP FUNCTION IF EXISTS ai_usage_events_guard();
SQL);
        Schema::dropIfExists('ai_usage_events');
        DB::unprepared('DROP SEQUENCE IF EXISTS ai_usage_event_code_seq');
    }
};
