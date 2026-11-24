<?php

/**
 * Phase-4 guards: device registry + FCM fan-out.
 *
 * Two promises: the registry is per-user (mine only, 404 otherwise), and push
 * is a pure add-on — unconfigured it sends nothing and breaks nothing (which is
 * also what keeps every earlier suite green), while configured it carries the
 * same ref_type/ref_id the bell deep-links off.
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Device\UserDeviceToken;
use Modules\HRM\Notifications\ApprovalNotification;
use Modules\HRM\Notifications\DeskNotification;

beforeEach(function (): void {
    Cache::flush();
    Http::preventStrayRequests();

    $this->a = User::create([
        'name' => 'Push A', 'email' => 'pusha@example.com',
        'password' => Hash::make('secret123'), 'email_verified_at' => now(),
    ]);
    $this->b = User::create([
        'name' => 'Push B', 'email' => 'pushb@example.com',
        'password' => Hash::make('secret123'), 'email_verified_at' => now(),
    ]);
});

function pushUser(string $email): User
{
    return User::create([
        'name' => $email, 'email' => $email,
        'password' => Hash::make('secret123'), 'email_verified_at' => now(),
    ]);
}

/** A genuinely signable RSA key, so the JWT grant is exercised for real.
 * Test-only, generated for this file — never used outside the suite. Static rather
 * than openssl_pkey_new() because Windows PHP ships without OPENSSL_CONF, which
 * makes runtime key generation fail. */
function pushTestKey(): string
{
    return <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQDHjhtpVJuJHGkj
igtyv8K+BbKG0/snqbnHaRsyBJY3EtU2AS+OsXcToIM7CJjL/2Tp7BZqVTGCjIg5
r2LQu+VIF5PFDYnnOXjfskUtmDeoBNj1zTwFSqThI8QV3djxn9iiFGOzWqD7V4Ka
P2cuSnTKRkQxReFKCeWzbGWVqREX9H1FojLrmABkLYP3M6l2SoCzJCr4JSE7zn18
x9noUpl6cVt5wsxxImDmvzPO4DGY2zy5gO/70OOPc/avQCzK4z9Idz/qJ1nhRJJF
hBXufqwnknO7BpD3b3VdCQXOSotlXWEy8AkfhBeUy57Akec93VZe3mSGsNyX6Ua/
ZTcQWxD5AgMBAAECggEAAKjTpofg92Ad6Sq9X3If9LdO7/r23JpxvVA3sV+1AbZr
oyD4CoQZH+Tlp3YKOpBb7G77OFDoQpAa1M/40bZGlh6n08dNQkjya+YqVtx8K0S6
1Sh0uegVfpNQRFSQ8Tl6l+HdM3xJmxMJYN+emcH6u/66nna76B37bESf2vxYTLM2
D2840Y9KRynjbudqi/6SEn0WuR0Hb8ylOm3CFBf1y4awobhchYid7rSA8ekGpv7c
PM6tAF+5D8ZtV8a9qFdEHWHJSJon1TkSys5ikg3A8O/K1TjmFqIDinLmnq+ZEGLk
i3Ljsc774BkJmEBalyXekFMUU5h1ko3gWRqX6qlmRQKBgQD5rB2VjoXH40EGG56C
vy5NQaXWE5k+/gCrIUVl/BqnfPO6usXaZbQOoHGc/f6OWT6TYqMZ7qFzb2VVJx0N
e1wTxKR94wwgTiC1Hjm5V9fJ4iT7ZWbdPdOPuGo8l5lZVP9Mv5T9D2v6lCC4MHzh
ZWSkxfQQeehOY6N3axXpqDCYLQKBgQDMnNQy2SaBpRLKvFbb9hwGEEgBUsPFrq+M
uq0YMrLOpSeMwiXuU8NEmLld/CgwE8PJJ0uX8CiFThHXK6VRcuGL3o9Jf3ItmlfB
UmuzoscynmRijIJ5eXan7ZQqVHoia9N5Zx79cq2dcqLLCRmkbXM5Ga6Ovu0yF1/U
q/HmkUKvfQKBgQD0froR5RnZHxBkHNPjEbkT6r+tnk+pwi+BZfmB35lyfwhmydRL
BOGImVHo/Uo0t+xxhFxYXrk4Q9QlyUHIHA+VRDIZHSiY0ilxdAHvILiueIjirvBG
o+7zhRJz97kyI+xGii4qitmg70IDXWhNiW8/dKKHJ/p4GVbQ8Mrqn8vGmQKBgQCL
bsPb8saeylLH/RhjxI7MQwQOgswORkAy8nNKbiITV9Bfx135zvLeKAUJ2u+384AT
78c/vtSeItlde8yoeFTi78NNHqA8G1V51CS4wVBlWjKmRYIULYWCYg/52J9yHXB6
5p7jr+ZHyPrOyWDEACHo42tksw8Id41Ca/noWUzMMQKBgQCE8iRNVIq/btSmhP8N
rFNNqBasypcSSf6Effq+bFiz/DC/GGQ4g2V0MIDgsfgnIeNYhQdh4yc0csVb9Mle
q+v7TzEuflOXevcz9yaWkd7xflZu2uCdrKnrFEvHQ4LsCngyd/gVhNeJGfvp1OEW
jWrSV1fDxwMFQBX6YExqQx/UAQ==
-----END PRIVATE KEY-----
PEM;
}

function pushConfigureFcm(): void
{
    config()->set('services.fcm', [
        'project_id' => 'test-proj',
        'client_email' => 'push@test-proj.iam.gserviceaccount.com',
        'private_key' => pushTestKey(),
    ]);
}

function pushFakeGoogle(array $fcm = ['name' => 'projects/test-proj/messages/1'], int $fcmStatus = 200): void
{
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access', 'expires_in' => 3600], 200),
        'fcm.googleapis.com/*' => Http::response($fcm, $fcmStatus),
    ]);
}

test('device register validates and upserts', function (): void {
    $this->actingAs($this->a, 'sanctum')
        ->postJson('/api/v1/devices', ['platform' => 'android'])
        ->assertStatus(422);

    $this->actingAs($this->a, 'sanctum')
        ->postJson('/api/v1/devices', ['token' => str_repeat('t', 40), 'platform' => 'blackberry'])
        ->assertStatus(422);

    $first = $this->actingAs($this->a, 'sanctum')->postJson('/api/v1/devices', [
        'token' => str_repeat('t', 40),
        'platform' => 'ios',
        'device_name' => 'A iPhone',
    ]);
    $first->assertOk()->assertJsonPath('data.created', true);

    expect(UserDeviceToken::count())->toBe(1);

    // Same token again: refresh, not a second row.
    $second = $this->actingAs($this->a, 'sanctum')->postJson('/api/v1/devices', [
        'token' => str_repeat('t', 40),
        'device_name' => 'A iPhone renamed',
    ]);
    $second->assertOk()->assertJsonPath('data.created', false);

    expect(UserDeviceToken::count())->toBe(1)
        ->and(UserDeviceToken::first()->device_name)->toBe('A iPhone renamed');
});

test('registering a token transfers it to the new holder', function (): void {
    $token = str_repeat('x', 40);

    $this->actingAs($this->a, 'sanctum')
        ->postJson('/api/v1/devices', ['token' => $token])
        ->assertOk()->assertJsonPath('data.created', true);

    $this->actingAs($this->b, 'sanctum')
        ->postJson('/api/v1/devices', ['token' => $token])
        ->assertOk()->assertJsonPath('data.created', false);

    expect(UserDeviceToken::count())->toBe(1)
        ->and(UserDeviceToken::first()->user_id)->toBe($this->b->id);
});

test('devices list and delete are mine-only', function (): void {
    UserDeviceToken::create(['user_id' => $this->a->id, 'token' => str_repeat('a', 40), 'platform' => 'android']);
    UserDeviceToken::create(['user_id' => $this->a->id, 'token' => str_repeat('b', 40), 'platform' => 'web']);
    $theirs = UserDeviceToken::create(['user_id' => $this->b->id, 'token' => str_repeat('c', 40), 'platform' => 'ios']);

    $this->actingAs($this->a, 'sanctum')
        ->getJson('/api/v1/devices')
        ->assertOk()->assertJsonPath('meta.total', 2);

    // Another user's row answers 404, never 403.
    $this->actingAs($this->a, 'sanctum')
        ->deleteJson("/api/v1/devices/{$theirs->id}")
        ->assertStatus(404);

    $this->actingAs($this->a, 'sanctum')
        ->deleteJson('/api/v1/devices/999999')
        ->assertStatus(404);

    $mine = UserDeviceToken::where('user_id', $this->a->id)->first();
    $this->actingAs($this->a, 'sanctum')
        ->deleteJson("/api/v1/devices/{$mine->id}")
        ->assertOk();

    expect(UserDeviceToken::where('user_id', $this->a->id)->count())->toBe(1);
});

test('approval and desk notifications fan out to FCM when configured', function (): void {
    pushConfigureFcm();
    pushFakeGoogle();

    $token = str_repeat('f', 40);
    UserDeviceToken::create(['user_id' => $this->a->id, 'token' => $token, 'platform' => 'android']);

    Notification::send($this->a, new ApprovalNotification('Leave needs you', 'A1 applied for 2 days', 'leave', 5));
    Notification::send($this->a, new DeskNotification('Task done', 'A1 finished the count', 'task', 9));

    // The bell still wrote both rows.
    expect($this->a->notifications()->count())->toBe(2);

    // And FCM received both, addressed to the token, with the deep-link pair.
    Http::assertSentCount(3); // 1 oauth exchange + 2 sends (the token is cached)
    Http::assertSent(function ($request) use ($token): bool {
        return str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === $token
            && $request['message']['notification']['title'] === 'Leave needs you'
            && $request['message']['data']['ref_type'] === 'leave'
            && $request['message']['data']['ref_id'] === '5';
    });
    Http::assertSent(function ($request) use ($token): bool {
        return str_contains($request->url(), 'messages:send')
            && $request['message']['token'] === $token
            && $request['message']['data']['ref_type'] === 'task'
            && $request['message']['data']['ref_id'] === '9';
    });
});

test('tokens FCM reports dead are pruned', function (): void {
    pushConfigureFcm();
    pushFakeGoogle(['error' => ['code' => 404, 'message' => 'Requested entity was not found.', 'status' => 'NOT_FOUND']], 404);

    $dead = UserDeviceToken::create(['user_id' => $this->a->id, 'token' => str_repeat('d', 40), 'platform' => 'android']);

    Notification::send($this->a, new ApprovalNotification('Ping', 'Body', 'leave', 1));

    expect(UserDeviceToken::whereKey($dead->id)->exists())->toBeFalse()
        ->and($this->a->notifications()->count())->toBe(1);
});

test('unconfigured push sends nothing and breaks nothing', function (): void {
    // No services.fcm config: the dormant default on every environment without keys.
    UserDeviceToken::create(['user_id' => $this->a->id, 'token' => str_repeat('u', 40), 'platform' => 'android']);

    Notification::send($this->a, new DeskNotification('Quiet', 'No keys, no HTTP', 'notice', 3));

    Http::assertNothingSent();
    expect($this->a->notifications()->count())->toBe(1);
});

test('a user with no tokens triggers no HTTP even when configured', function (): void {
    pushConfigureFcm();
    pushFakeGoogle();

    Notification::send($this->b, new ApprovalNotification('Nobody home', 'No devices', 'leave', 2));

    Http::assertNothingSent();
    expect($this->b->notifications()->count())->toBe(1);
});
