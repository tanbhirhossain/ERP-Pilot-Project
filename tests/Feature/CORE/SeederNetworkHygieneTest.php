<?php

/*
 * Seeder network hygiene, and the demo-device bypass that came with it.
 *
 * The seeders wrote biometric devices at 192.168.10.91/.93/.94, and the attendance sync service
 * answered "is this a demo device?" by comparing the device's IP address against a hardcoded copy
 * of that same list. An IP address says where a device lives, not what a device is, so on a
 * production host any customer whose LAN happened to use that extremely common private range got
 * fabricated punch rows written into the table payroll reads.
 *
 * Two contracts, both cheap: no seeder may publish an address that could belong to somebody's real
 * network, and no production code path may decide simulation eligibility from an address.
 */

use Modules\HRM\Services\Attendance\AttendanceSyncService;

/**
 * Is this IPv4 literal one a real network could hand out?
 *
 * RFC 1918 private space, loopback, link-local and the shared CGNAT block. Deliberately does NOT
 * include RFC 5737's documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24), which
 * are reserved for exactly this use and are guaranteed never to be routed to a customer device.
 */
function snh_is_real_network_address(string $ip): bool
{
    $parts = explode('.', $ip);
    if (count($parts) !== 4) {
        return false;
    }
    foreach ($parts as $part) {
        if ($part === '' || ! ctype_digit($part) || (int) $part > 255) {
            return false;
        }
    }
    [$a, $b] = [(int) $parts[0], (int) $parts[1]];

    return ($a === 10)
        || ($a === 172 && $b >= 16 && $b <= 31)
        || ($a === 192 && $b === 168)
        || ($a === 127)
        || ($a === 169 && $b === 254)
        || ($a === 100 && $b >= 64 && $b <= 127);
}

/**
 * Every PHP file a seeder could live in.
 */
function snh_seeder_files(): array
{
    $base = base_path();
    $patterns = [
        $base.'/database/seeders/*.php',
        $base.'/database/seeders/**/*.php',
        $base.'/modules/*/Database/Seeders/*.php',
        $base.'/modules/*/Database/Seeders/**/*.php',
    ];
    $files = [];
    foreach ($patterns as $pattern) {
        foreach (glob($pattern) as $file) {
            $files[$file] = true;
        }
    }
    ksort($files);

    return array_keys($files);
}

/**
 * The IPv4 literals in a source file, with comments stripped so prose about the old addresses
 * does not get read as an address.
 */
function snh_addresses_in(string $file): array
{
    $source = (string) file_get_contents($file);
    $tokens = token_get_all($source);
    $code = '';
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    preg_match_all('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $code, $matches);

    return array_values(array_unique($matches[0]));
}

it('classifies real-network addresses correctly, in both directions', function () {
    foreach (['10.0.0.5', '192.168.10.93', '172.16.4.9', '127.0.0.1', '169.254.7.7', '100.64.1.1'] as $ip) {
        $this->assertTrue(snh_is_real_network_address($ip), $ip.' should read as a real-network address');
    }
    foreach (['192.0.2.93', '198.51.100.12', '203.0.113.40', '8.8.8.8', '93.184.216.34'] as $ip) {
        $this->assertFalse(snh_is_real_network_address($ip), $ip.' must not read as a real-network address');
    }
    // Prose in a docblock is not an address, and a near-miss is not one either.
    $this->assertFalse(snh_is_real_network_address('192.168.10.9x'), 'a redacted address must not match');
    $this->assertFalse(snh_is_real_network_address('1.2.3'), 'a partial literal must not match');
});

it('publishes no seeder address that could belong to a real network', function () {
    $files = snh_seeder_files();
    $this->assertNotEmpty($files, 'the seeder scan found no files to scan');

    $offenders = [];
    $seen = [];
    foreach ($files as $file) {
        foreach (snh_addresses_in($file) as $ip) {
            $seen[$ip] = true;
            if (snh_is_real_network_address($ip)) {
                $offenders[] = str_replace(base_path().'/', '', $file).' → '.$ip;
            }
        }
    }

    // The scan must actually have seen the addresses this item is about, or it proves nothing.
    $this->assertArrayHasKey('192.0.2.93', $seen, 'the scan did not reach the biometric demo device');

    $this->assertSame([], $offenders, 'seeders publishing real-network addresses: '.implode('; ', $offenders));
});

it('keeps the demo biometric devices on the reserved documentation range', function () {
    $seeder = base_path('modules/HRM/Database/Seeders/BiometricDeviceSeeder.php');
    $this->assertFileExists($seeder);

    $addresses = snh_addresses_in($seeder);
    $this->assertNotEmpty($addresses);
    foreach ($addresses as $ip) {
        $this->assertStringStartsWith('192.0.2.', $ip, $ip.' is not on RFC 5737 TEST-NET-1');
    }
});

it('never decides attendance simulation eligibility from an IP address', function () {
    $service = base_path('modules/HRM/Services/Attendance/AttendanceSyncService.php');
    $this->assertFileExists($service);

    $this->assertSame([], snh_addresses_in($service),
        'the sync service still carries an IPv4 literal; demo-ness must not be an address');

    $source = (string) file_get_contents($service);
    foreach (['ip_address, [', 'in_array($host,', '$device->ip_address ==='] as $needle) {
        $this->assertStringNotContainsString($needle, $source,
            'found a comparison of a device address against a list: '.$needle);
    }
    $this->assertStringContainsString('private function simulationAllowed()', $source);
});

it('leaves simulation off in production and on only by environment or explicit opt-in', function () {
    // simulationAllowed() reads no state, so the three repository dependencies the constructor
    // asks for are irrelevant here and resolving them would only couple this test to bindings.
    $service = (new ReflectionClass(AttendanceSyncService::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($service, 'simulationAllowed');
    $method->setAccessible(true);

    $original = app()->environment();

    try {
        // Under the test environment simulation is permitted, which is what keeps the seeded demo
        // devices usable without weakening a deployed host.
        app()->detectEnvironment(fn () => 'testing');
        config(['hrm.sync.allow_simulation' => false]);
        $this->assertTrue($method->invoke($service), 'testing must permit simulation');

        // The whole point of the fix: a production host with a device at the old demo address must
        // not invent attendance.
        app()->detectEnvironment(fn () => 'production');
        config(['hrm.sync.allow_simulation' => false]);
        $this->assertFalse($method->invoke($service), 'production must not simulate by default');

        config(['hrm.sync.allow_simulation' => true]);
        $this->assertTrue($method->invoke($service), 'an explicit opt-in must still work');
    } finally {
        config(['hrm.sync.allow_simulation' => false]);
        app()->detectEnvironment(fn () => $original);
    }

    $this->assertSame($original, app()->environment());

    // Re-read the config file rather than the runtime value: this test has been overriding
    // `hrm.sync.allow_simulation` all the way through, so asking the container would only ever
    // report the override and would stay green even if the shipped default were flipped to true.
    $shipped = require base_path('modules/HRM/Config/hrm.php');
    $this->assertArrayHasKey('sync', $shipped, 'hrm.php lost its sync block');
    $this->assertFalse((bool) $shipped['sync']['allow_simulation'], 'the shipped default must be false');
    $this->assertStringContainsString(
        'HRM_ALLOW_ATTENDANCE_SIMULATION',
        (string) file_get_contents(base_path('modules/HRM/Config/hrm.php')),
        'the opt-in must stay reachable through an environment variable'
    );
});
