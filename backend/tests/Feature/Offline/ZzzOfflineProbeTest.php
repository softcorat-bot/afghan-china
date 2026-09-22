<?php

namespace Tests\Feature\Offline;

use Illuminate\Support\Facades\Http;

// TEMPORARY CI DEBUG PROBE (runs last: "Zzz"). Documents Http::fake matching
// behavior for query-string URLs. Remove once green.
class ZzzOfflineProbeTest extends OfflineTestCase
{
    public function test_star_pattern_matches_query_url(): void
    {
        Http::fake(['*v1/sync/pull*' => Http::response(['data' => [], 'ok' => true])]);

        $response = Http::timeout(10)->connectTimeout(5)->get('https://central.test/api/v1/sync/pull', ['since_seq' => 0]);

        fwrite(STDERR, "\nOFFLINE-PROBE star-pattern status=".$response->status().' body='.substr((string) $response->body(), 0, 80)."\n");

        $this->assertTrue($response->ok());
        $this->assertSame([], $response->json('data'));
    }

    public function test_unmatched_query_request_behavior(): void
    {
        // Exact pattern (no trailing star) against a query-string URL.
        Http::fake(['*v1/sync/pull' => Http::response(['data' => [], 'ok' => true])]);

        try {
            $response = Http::timeout(10)->connectTimeout(5)->get('https://central.test/api/v1/sync/pull', ['since_seq' => 0]);
            fwrite(STDERR, "\nOFFLINE-PROBE exact-pattern status=".$response->status().' body='.substr((string) $response->body(), 0, 80)."\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nOFFLINE-PROBE exact-pattern threw ".get_class($e).': '.substr($e->getMessage(), 0, 200)."\n");
        }

        $this->assertTrue(true);
    }

    public function test_fake_throw_runtime_exception_behavior(): void
    {
        Http::fake(fn () => throw new \RuntimeException('probe boom'));

        try {
            Http::timeout(10)->connectTimeout(5)->get('https://central.test/api/v1/sync/status');
            fwrite(STDERR, "\nOFFLINE-PROBE fake-throw-runtime: NO THROW (unexpected)\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nOFFLINE-PROBE fake-throw-runtime threw ".get_class($e).': '.substr($e->getMessage(), 0, 120)."\n");
        }

        $this->assertTrue(true);
    }

    public function test_fake_throw_connection_exception_behavior(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('probe network down'));

        try {
            Http::timeout(10)->connectTimeout(5)->get('https://central.test/api/v1/sync/status');
            fwrite(STDERR, "\nOFFLINE-PROBE fake-throw-connection: NO THROW (unexpected)\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, "\nOFFLINE-PROBE fake-throw-connection threw ".get_class($e).': '.substr($e->getMessage(), 0, 120)."\n");
        }

        $this->assertTrue(true);
    }
}
