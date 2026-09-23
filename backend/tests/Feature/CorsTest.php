<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The login page could only say "Login failed" when the browser blocked the
 * API over CORS — e.g. Quasar falling back to :9001 because :9000 was busy.
 */
class CorsTest extends TestCase
{
    private function preflight(string $origin)
    {
        return $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,authorization,x-branch-id,x-http-method-override',
        ]);
    }

    public function test_local_dev_server_on_any_port_is_allowed(): void
    {
        foreach (['http://localhost:9000', 'http://localhost:9001', 'http://127.0.0.1:9002', 'http://localhost'] as $origin) {
            $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_lan_addresses_are_allowed(): void
    {
        foreach (['http://192.168.1.20:9000', 'http://10.0.0.5', 'http://172.16.4.2:8080'] as $origin) {
            $this->preflight($origin)->assertHeader('Access-Control-Allow-Origin', $origin);
        }
    }

    public function test_foreign_origins_are_refused(): void
    {
        foreach (['https://evil.example', 'http://localhost.evil.example:9000', 'http://192.168.1.20.evil.example'] as $origin) {
            $this->preflight($origin)->assertHeaderMissing('Access-Control-Allow-Origin');
        }
    }
}
