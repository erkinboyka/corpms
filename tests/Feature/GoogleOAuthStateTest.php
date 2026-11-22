<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SEC-02 / BE-S2 regression: OAuth state is one-time, non-empty and PKCE-bound. */
class GoogleOAuthStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => 'http://localhost:8000/auth/google/callback',
        ]);
    }

    public function test_redirect_creates_state_and_pkce_challenge(): void
    {
        $response = $this->get(route('google.redirect'))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame(session('google_oauth_state'), $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', session('google_oauth_code_verifier'), true)), '+/', '-_'), '='),
            $query['code_challenge'],
        );
        $this->assertNotNull(session('google_oauth_state_at'));
    }

    public function test_callback_without_issued_state_is_rejected_and_does_not_call_google(): void
    {
        Http::fake();

        $this->get(route('google.callback', ['state' => 'forged', 'code' => 'code']))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_callback_consumes_state_and_rejects_replay(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'gid-1',
                'email' => 'oauth@tenant.test',
                'name' => 'OAuth User',
                'email_verified' => true,
                'hd' => 'tenant.test',
            ], 200),
        ]);

        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');
        $verifier = session('google_oauth_code_verifier');

        $this->get(route('google.callback', ['state' => $state, 'code' => 'code-1']))
            ->assertRedirect(route('dashboard'));

        $this->assertNull(session('google_oauth_state'));
        $this->assertNull(session('google_oauth_code_verifier'));
        $this->assertDatabaseHas('users', ['email' => 'oauth@tenant.test', 'google_id' => 'gid-1']);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code_verifier'] === $verifier
            && $request['grant_type'] === 'authorization_code');
        Http::assertSentCount(2);

        Auth::logout();
        $this->get(route('google.callback', ['state' => $state, 'code' => 'code-1']))
            ->assertRedirect(route('login'));
        Http::assertSentCount(2);
        $this->assertGuest();
    }

    public function test_expired_state_is_rejected(): void
    {
        Http::fake();

        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');
        $this->withSession(['google_oauth_state_at' => now()->getTimestamp() - 3600]);

        $this->get(route('google.callback', ['state' => $state, 'code' => 'code-1']))
            ->assertRedirect(route('login'));

        Http::assertNothingSent();
    }

    /** P3/SEC-07: неподтверждённый email и чужой домен не создают учётку. */
    public function test_unverified_email_is_rejected(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'gid-unverified',
                'email' => 'unverified@tenant.test',
                'email_verified' => false,
            ], 200),
        ]);

        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');
        $this->get(route('google.callback', ['state' => $state, 'code' => 'code-1']))
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'unverified@tenant.test']);
        $this->assertGuest();
    }

    public function test_foreign_hosted_domain_is_rejected_when_configured(): void
    {
        config(['services.google.allowed_domain' => 'company.tld']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'tok'], 200),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'gid-foreign',
                'email' => 'user@other.tld',
                'email_verified' => true,
                'hd' => 'other.tld',
            ], 200),
        ]);

        $this->get(route('google.redirect'));
        $state = session('google_oauth_state');
        $this->get(route('google.callback', ['state' => $state, 'code' => 'code-1']))
            ->assertRedirect(route('login'));

        $this->assertDatabaseMissing('users', ['email' => 'user@other.tld']);
        $this->assertGuest();
    }
}
