<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The sign-in door, and the cross-origin headers it depends on.
 *
 * A browser discards an API response whose `Access-Control-Allow-Origin` does
 * not match the page's Origin, and axios reports that as a bare "Network
 * Error" — so a login screen reads as broken even though the API answered 200.
 * The dashboard is served from more than one origin (the dev server on
 * whichever port was free, a till PC on the shop LAN, the packaged desktop app
 * over file://), and each of those has to be allowed.
 */
class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    /** Every origin this app is documented to be served from. */
    public static function allowedOrigins(): array
    {
        return [
            'dev server, default port' => ['http://localhost:9000'],
            'dev server, port 9000 taken' => ['http://localhost:9001'],
            'loopback by IP' => ['http://127.0.0.1:9000'],
            'loopback on another port' => ['http://127.0.0.1:8080'],
            'shop LAN, 192.168/16' => ['http://192.168.1.50:9000'],
            'shop LAN, 10/8' => ['http://10.0.0.7:9000'],
            'shop LAN, 172.16/12' => ['http://172.20.10.4:9000'],
            'packaged desktop app (file://)' => ['null'],
        ];
    }

    public function test_valid_credentials_return_a_token_and_session_payload(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'email'],
                'permissions',
                'roles',
                'branches',
                'is_platform_owner',
            ])
            ->assertJsonPath('user.email', $user->email);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);
    }

    public function test_the_token_issued_at_login_authenticates_the_next_request(): void
    {
        $user = User::factory()->create();

        $token = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_protected_routes_still_refuse_an_anonymous_request(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/auth')->assertOk()->assertJsonPath('auth', false);
    }

    #[DataProvider('allowedOrigins')]
    public function test_preflight_is_answered_for_every_origin_the_app_runs_from(string $origin): void
    {
        // Sent as raw server vars: MakesHttpRequests::call() does not fold in
        // withHeaders()/withHeader() the way the json() helpers do.
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,accept,authorization,x-branch-id',
        ]);

        $response->assertSuccessful();
        // Echoed, never '*': the response is credentialed, and the spec forbids
        // the wildcard together with Access-Control-Allow-Credentials.
        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_the_actual_login_response_carries_the_origin_header(): void
    {
        $user = User::factory()->create();

        $this->withHeader('Origin', 'http://192.168.1.50:9000')
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'http://192.168.1.50:9000');
    }

    public function test_an_unrelated_public_origin_is_not_allowed(): void
    {
        $response = $this->call('OPTIONS', '/api/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
