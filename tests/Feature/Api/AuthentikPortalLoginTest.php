<?php

namespace Tests\Feature\Api;

use App\Models\User;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\{Request, Response};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http, Log};
use Spatie\Permission\Models\{Permission, Role};
use Tests\TestCase;

class AuthentikPortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUER = 'https://identity.example.test/application/o/portal/';
    private MockHandler $provider;
    private array $exchanges = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new MockHandler();
        $handler = HandlerStack::create($this->provider);
        $handler->push(Middleware::history($this->exchanges));
        config([
            'services.authentik.enabled' => true,
            'services.authentik.base_url' => 'https://identity.example.test',
            'services.authentik.issuer' => self::ISSUER,
            'services.authentik.client_id' => 'portal-test',
            'services.authentik.client_secret' => 'test-secret',
            'services.authentik.redirect' => 'https://portal.example.test/auth/sso/callback',
            'services.authentik.guzzle.handler' => $handler,
        ]);
        Http::preventStrayRequests();
    }

    private function start(string $path = '/dashboard'): string
    {
        $response = $this->get('/auth/sso?portal=1&return_to='.urlencode($path))->assertRedirect();
        $url = $response->headers->get('Location');
        $this->assertStringStartsWith('https://identity.example.test/application/o/authorize/?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('https://portal.example.test/auth/sso/callback', $query['redirect_uri']);
        $this->assertSame(session('state'), $query['state']);
        $this->assertStringNotContainsString('test-secret', $url);
        return $query['state'];
    }

    private function profile(array $claims): void
    {
        $this->provider->append(
            new Response(200, [], json_encode(['access_token' => 'test-access'])),
            new Response(200, [], json_encode($claims)),
        );
    }

    public function test_valid_login_rotates_session_preserves_account_and_returns_to_an_approved_page(): void
    {
        $existing = User::factory()->create(['email' => 'person@example.test']);
        $role = Role::findOrCreate('article_editor', 'web');
        $role->givePermissionTo(Permission::findOrCreate('articles.update', 'web'));
        $existing->assignRole($role);
        $clubId = DB::table('universities')->insertGetId(['name' => 'Home Club']);
        DB::table('user_universities')->insert(['user' => $existing->id, 'uni' => $clubId, 'admin' => true]);
        $this->profile(['sub' => 'person-subject', 'email' => $existing->email, 'name' => 'New display name', 'email_verified' => true]);
        $state = $this->start('/organisations/12');
        $session = session()->getId();
        $verifier = session('code_verifier');
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/organisations/12')
            ->assertSessionMissing('state')->assertSessionMissing('code_verifier');
        $this->assertAuthenticatedAs($existing);
        $this->assertNotSame($session, session()->getId());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('portal_memberships', 0);
        $this->assertCount(2, $this->exchanges);
        $exchange = $this->exchanges[0]['request'];
        $this->assertSame('/application/o/token/', $exchange->getUri()->getPath());
        parse_str((string) $exchange->getBody(), $form);
        $this->assertSame($verifier, $form['code_verifier']);
        $this->assertSame('Bearer test-access', $this->exchanges[1]['request']->getHeaderLine('Authorization'));
        $me = $this->getJson('/api/v1/me', ['Origin' => 'http://localhost'])->assertOk()
            ->assertJsonPath('data.user.id', $existing->id)->assertJsonPath('data.is_superadmin', false)
            ->assertJsonPath('data.roles', ['article_editor'])->assertJsonPath('data.permissions', ['articles.update'])
            ->assertJsonPath('data.club_ids', [$clubId])->assertJsonPath('data.can_administer', false)
            ->assertJsonCount(0, 'data.organisations')->assertDontSee('test-access')->assertJsonMissingPath('data.user.password');
        $this->assertStringContainsString('no-store', $me->headers->get('Cache-Control'));
        $this->get('/logout')->assertStatus(405);
        $this->assertAuthenticatedAs($existing);
        // Emergency disablement and failed upstream revocation must still permit local logout.
        config(['services.authentik.enabled' => false]);
        Http::fake(['*/application/o/revoke/' => Http::response([], 503)]);
        $this->postJson('/api/v1/portal/logout')->assertOk()->assertJsonPath('data.signed_out', true)->assertSessionMissing('authentik_access_token');
        Http::assertSent(fn ($request) => $request['token'] === 'test-access' && $request['client_id'] === 'portal-test');
        $this->assertGuest();
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/login');
        $this->assertCount(2, $this->exchanges);
        $response = $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_rejects_invalid_callback_before_exchange_and_consumes_the_attempt(): void
    {
        foreach (['code=test-code', 'state=wrong&code=test-code', 'state[]=wrong&code=test-code', 'state=STATE&error=access_denied', 'state=STATE&iss=https://other.test&code=test-code'] as $query) {
            $state = $this->start();
            $this->get('/auth/sso/callback?'.str_replace('STATE', $state, $query))->assertRedirect('/?auth=failed')
                ->assertSessionMissing('state')->assertSessionMissing('code_verifier');
            $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/login');
        }
        $this->assertCount(0, $this->exchanges);
        $this->assertGuest();
    }

    public function test_only_pinned_issuer_and_verified_email_claims_are_used(): void
    {
        $this->profile(['iss' => 'https://different.example.test/', 'sub' => 'subject', 'email' => 'member@example.test', 'email_verified' => true]);
        $state = $this->start();
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/?auth=failed');
        $this->profile(['sub' => 'subject', 'email' => 'member@example.test', 'email_verified' => 'true']);
        $state = $this->start();
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/?auth=reconciliation');
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_disabled_or_misconfigured_portal_never_falls_back_to_legacy_sso(): void
    {
        config(['sso.enabled' => true, 'sso.auth_server' => 'https://legacy.example.test', 'services.authentik.enabled' => false]);
        $this->get('/auth/sso?portal=1')->assertRedirect('/?auth=failed')->assertSessionMissing('sso_state');
        $this->get('/auth/sso')->assertRedirectContains('https://legacy.example.test/sso/authorize?');
        $this->withSession(['sso_portal' => true])->get('/auth/sso/callback?state=old&code=test-code')
            ->assertRedirect('/?auth=failed')->assertSessionMissing('sso_state');
        config(['services.authentik.enabled' => true, 'services.authentik.issuer' => 'https://other.example.test/']);
        $this->get('/auth/sso?portal=1')->assertRedirect('/?auth=failed')->assertSessionMissing('state');
        $this->assertCount(0, $this->exchanges);
        $this->assertGuest();
    }

    public function test_legacy_session_can_sign_in_again_to_use_the_standalone_portal(): void
    {
        $user = User::factory()->create(['email' => 'member@example.test']);
        $this->actingAs($user)->withSession(['sso_access_token' => 'test-old-token'])
            ->getJson('/api/v1/me', ['Origin' => 'http://localhost'])->assertUnauthorized();
        $this->getJson('/api/v1/portal/me')->assertUnauthorized();
        $state = $this->start();
        $this->profile(['sub' => 'member', 'email' => $user->email, 'email_verified' => true]);
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/dashboard')
            ->assertSessionMissing('sso_access_token');
        $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_external_return_target_is_ignored_and_provider_timeouts_are_handled(): void
    {
        Log::shouldReceive('warning')->once()->with('authentik_authentication_failed');
        $this->provider->append(new ConnectException('private provider details', new Request('POST', '/token')));
        $state = $this->start('//outside.example.test/');
        $this->assertSame('/dashboard', session('authentik_return_to'));
        $this->get('/auth/sso/callback?state='.$state.'&code=test-code')->assertRedirect('/?auth=failed')
            ->assertSessionMissing('state')->assertSessionMissing('code_verifier');
        $this->assertGuest();
    }
}
