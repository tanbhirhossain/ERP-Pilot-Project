<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Device\UserDeviceToken;

/**
 * Phase 4 — FCM push over HTTP v1, no vendor package.
 *
 * Why hand-rolled: the only thing FCM v1 needs is an OAuth2 bearer minted
 * from the service account (a signed JWT) plus one POST per token. A package
 * would buy connection pooling we do not need at this volume, at the cost of
 * a google/auth dependency tree. Everything HTTP is behind `Http::fake` in
 * tests, and everything here degrades to a no-op:
 *
 *   - FCM not configured (no FCM_PROJECT_ID/CLIENT_EMAIL/PRIVATE_KEY) → 0 sends.
 *   - user holds no tokens → 0 sends, no HTTP at all.
 *   - a token dies (NOT_FOUND/UNREGISTERED/INVALID_ARGUMENT) → pruned, rest send.
 *   - anything else fails → logged, never thrown. A push must never break the
 *     request that triggered it, same rule as the in-app bell.
 *
 * Full tokens never touch the logs — only the last 8 characters on prune.
 */
class PushService
{
    public const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    public const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public const CACHE_KEY = 'fcm.access_token';

    public function configured(): bool
    {
        $config = config('services.fcm', []);

        return ! empty($config['project_id'])
            && ! empty($config['client_email'])
            && ! empty($config['private_key']);
    }

    /**
     * @param  iterable<User>  $users
     * @return int tokens accepted by FCM
     */
    public function sendToUsers(iterable $users, string $title, string $body, array $data = []): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $ids = collect($users)->map(fn (User $user): int => (int) $user->id)->unique()->values()->all();

        if ($ids === []) {
            return 0;
        }

        /** @var \Illuminate\Support\Collection<int, string> $tokens */
        $tokens = UserDeviceToken::whereIn('user_id', $ids)->pluck('token', 'id');

        if ($tokens->isEmpty()) {
            return 0;
        }

        $access = $this->accessToken();

        if ($access === null) {
            return 0;
        }

        $sent = 0;
        $project = (string) config('services.fcm.project_id');

        foreach ($tokens as $id => $token) {
            if ($this->sendToToken($project, $access, (int) $id, (string) $token, $title, $body, $data)) {
                $sent++;
            }
        }

        return $sent;
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        return $this->sendToUsers([$user], $title, $body, $data);
    }

    private function accessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $jwt = $this->serviceJwt();

            if ($jwt === null) {
                return null;
            }

            $response = Http::timeout(10)->asForm()->post(self::OAUTH_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('FCM token exchange failed: HTTP '.$response->status().'.');

                return null;
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                Log::warning('FCM token exchange returned no access token.');

                return null;
            }

            // Google mints for an hour; 50 minutes leaves a comfortable margin.
            Cache::put(self::CACHE_KEY, $token, 3000);

            return $token;
        } catch (\Throwable $e) {
            Log::warning('FCM token exchange failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The service-account JWT grant: header + claims, RS256-signed with the
     * private key. Returns null (logged) when the key cannot sign, which in
     * practice means a mangled FCM_PRIVATE_KEY in the environment.
     */
    private function serviceJwt(): ?string
    {
        try {
            $now = time();
            $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
            $claims = $this->base64Url(json_encode([
                'iss' => config('services.fcm.client_email'),
                'scope' => self::FCM_SCOPE,
                'aud' => self::OAUTH_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ], JSON_THROW_ON_ERROR));

            $key = openssl_pkey_get_private($this->privateKey());

            if ($key === false) {
                Log::warning('FCM signing failed: FCM_PRIVATE_KEY is not a usable private key.');

                return null;
            }

            $signature = '';

            if (! openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
                Log::warning('FCM signing failed: openssl could not sign the grant.');

                return null;
            }

            return "{$header}.{$claims}.".$this->base64Url($signature);
        } catch (\Throwable $e) {
            Log::warning('FCM signing failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Env files carry the key with literal `\n` sequences; openssl needs real
     * newlines. A key pasted with real newlines passes through untouched.
     */
    private function privateKey(): string
    {
        return str_replace('\n', "\n", (string) config('services.fcm.private_key'));
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * FCM data values must be strings — ref_id and friends are cast here so no
     * caller has to remember.
     */
    private function sendToToken(
        string $project,
        string $access,
        int $rowId,
        string $token,
        string $title,
        string $body,
        array $data,
    ): bool {
        try {
            $stringData = [];

            foreach ($data as $key => $value) {
                $stringData[(string) $key] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
            }

            $response = Http::timeout(10)->withToken($access)->post(
                "https://fcm.googleapis.com/v1/projects/{$project}/messages:send",
                ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $stringData,
                    'android' => ['priority' => 'HIGH'],
                ]],
            );

            if ($response->successful()) {
                return true;
            }

            $status = (string) $response->json('error.status', '');

            if ($response->status() === 404 || in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'INVALID_ARGUMENT'], true)) {
                UserDeviceToken::whereKey($rowId)->delete();
                Log::info('FCM pruned a dead device token (…'.substr($token, -8).').');

                return false;
            }

            Log::warning('FCM send failed: HTTP '.$response->status().' '.(string) $response->json('error.message', ''));

            return false;
        } catch (\Throwable $e) {
            Log::warning('FCM send failed: '.$e->getMessage());

            return false;
        }
    }
}
