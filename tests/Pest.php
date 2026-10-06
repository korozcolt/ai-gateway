<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Korbytes\AiGateway\Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** True when the database rejects the statement (constraint, trigger...), isolated in a savepoint. */
function dbRejects(Closure $callback): bool
{
    try {
        DB::transaction($callback);

        return false;
    } catch (QueryException) {
        return true;
    }
}
