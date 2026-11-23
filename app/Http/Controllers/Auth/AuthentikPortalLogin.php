<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\IdentityReconciliationRequired;
use App\Services\AuthentikIdentityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, Http, Log};
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Authentik\Provider;

class AuthentikPortalLogin
{
    public function __construct(private AuthentikIdentityService $identities) {}

    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->forget(['sso_state', 'sso_portal', 'authentik_return_to', 'authentik_issuer', 'state', 'code_verifier']);
        if (! config('services.authentik.enabled')) {
            return $this->failure();
        }
        if (! $this->configured()) {
            Log::warning('authentik_configuration_unavailable');
            return $this->failure();
        }

        $path = $request->input('return_to');
        $destination = is_string($path) && preg_match('#\A/(?:dashboard|admin|organisations/[1-9][0-9]*)\z#', $path) ? $path : '/dashboard';
        $request->session()->put(['authentik_return_to' => $destination, 'authentik_issuer' => config('services.authentik.issuer')]);
        return $this->provider()->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $session = $request->session();
        $destination = $session->pull('authentik_return_to');
        $issuer = $session->pull('authentik_issuer');
        try {
            $code = $request->input('code');
            $state = $request->input('state');
            if (! config('services.authentik.enabled') || ! $this->configured() || $issuer !== config('services.authentik.issuer')
                || ($request->has('iss') && $request->input('iss') !== $issuer)
                || $request->has('error') || ! is_string($code) || $code === '' || strlen($code) > 4096
                || ! is_string($state) || $state === '' || ! $session->has('code_verifier')) {
                return $this->failure();
            }

            // The stateful Socialite driver checks the stored state before exchanging the code.
            $identity = $this->provider()->user();
            $claims = $identity->getRaw();
            if (! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
                || (array_key_exists('iss', $claims) && $claims['iss'] !== $issuer)
                || ! is_string($identity->token) || $identity->token === '') {
                return $this->failure();
            }
            $user = $this->identities->resolve(
                $issuer, $claims['sub'],
                is_string($claims['email'] ?? null) ? $claims['email'] : null,
                ($claims['email_verified'] ?? false) === true,
                is_string($claims['name'] ?? null) ? $claims['name'] : null,
            );

            // Switching from a legacy login must discard its credentials and remember cookie.
            $guard = Auth::guard('web');
            if ($guard->check()) {
                $guard->logout();
            }
            $session->invalidate();
            $guard->login($user);
            $session->regenerateToken();
            $session->put([
                'authentik_session' => ['user_id' => $user->getAuthIdentifier(), 'issuer' => $issuer],
                'authentik_access_token' => $identity->token,
                'authentik_token_origin' => config('services.authentik.base_url'),
            ]);
            return redirect($destination)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
        } catch (IdentityReconciliationRequired) {
            return $this->failure('reconciliation');
        } catch (\Throwable) {
            // Provider errors can contain access tokens, request bodies and identity claims.
            Log::warning('authentik_authentication_failed');
            return $this->failure();
        } finally {
            $session->forget(['state', 'code_verifier']);
        }
    }

    public static function revoke(Request $request): void
    {
        $token = $request->session()->pull('authentik_access_token');
        $origin = $request->session()->pull('authentik_token_origin');
        if (! is_string($token) || ! is_string($origin) || $origin !== config('services.authentik.base_url')) {
            return;
        }
        try {
            Http::asForm()->withoutRedirecting()->timeout(5)->post(rtrim($origin, '/').'/application/o/revoke/', [
                'token' => $token, 'token_type_hint' => 'access_token',
                'client_id' => config('services.authentik.client_id'),
                'client_secret' => config('services.authentik.client_secret'),
            ])->throw();
        } catch (\Throwable) {
            Log::warning('authentik_revocation_unavailable');
        }
    }

    private function provider(): Provider
    {
        // Drivers cache their user. Each callback needs a fresh provider for this request.
        return Socialite::forgetDrivers()->driver('authentik')->setScopes(['openid', 'profile', 'email'])->enablePKCE();
    }

    private function configured(): bool
    {
        if (config('session.driver') === 'cookie' || blank(config('services.authentik.client_id')) || blank(config('services.authentik.client_secret'))) {
            return false;
        }
        $origins = [];
        foreach (['base_url', 'issuer', 'redirect'] as $key) {
            $url = config('services.authentik.'.$key);
            $parts = is_string($url) ? parse_url($url) : false;
            if (! $parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                return false;
            }
            $local = app()->environment('local', 'testing') && in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true);
            if (($parts['scheme'] ?? '') !== 'https' && ! ($local && ($parts['scheme'] ?? '') === 'http')) {
                return false;
            }
            $origins[$key] = [strtolower($parts['host']), $parts['scheme'], $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80)];
        }
        // The credentialed token and UserInfo exchanges must go to the pinned identity authority.
        return $origins['base_url'] === $origins['issuer'];
    }

    private function failure(string $reason = 'failed'): RedirectResponse
    {
        return redirect('/?auth='.$reason)->withHeaders(['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }
}
