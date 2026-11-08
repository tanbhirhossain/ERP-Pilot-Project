<?php

/**
 * Deployment hygiene — §7 item 4's remaining half, recorded as item 27.
 *
 * Two files decide whether a deployment is safe, and neither was checked by anything:
 *
 *   - `docker-compose.yml` published `MYSQL_ROOT_PASSWORD: secret` — a credential in a public
 *     repository, which is the same exposure `PublishedCredentialGuard` exists to close for the
 *     seeded logins — and published MySQL on `3306:3306`, i.e. on every interface the host has, so
 *     anybody who could reach the host met the database with that password.
 *   - `.env.example` ships `APP_DEBUG=true`. That is correct for the `APP_ENV=local` file it is, and
 *     it is what Laravel's own template does. The exposure is that a server which was handed *this*
 *     file instead of `.env.production.example` runs in production with stack traces, environment
 *     variables and query dumps rendered to anybody who can provoke an error. The only defence was a
 *     hardened template nobody is forced to copy.
 *
 * So the fix is not "set APP_DEBUG=false in the dev template" — that would be wrong for a local file
 * and would not stop anybody. It is a runtime guard that makes the misconfiguration incapable of
 * taking effect, plus a compose file that fails closed instead of shipping an answer.
 *
 * **Limitation, stated rather than hidden:** there is no docker binary in this audit environment, so
 * nothing here ran `docker compose config` or started a container. The compose assertions parse the
 * file with `symfony/yaml` and judge its contents. They prove the file says what it should say, not
 * that the stack boots.
 *
 * Helpers are prefixed `dh` because every Feature file loads into one process.
 */

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\CORE\Support\ProductionDebugGuard;
use Symfony\Component\Yaml\Yaml;

/** The compose file, parsed into the structure compose itself would see. */
function dhCompose(): array
{
    expect(class_exists(Yaml::class))->toBeTrue(
        'symfony/yaml is gone — it arrives transitively via laravel/sail (require-dev). Add it to require-dev explicitly.'
    );

    $path = base_path('docker-compose.yml');
    expect(file_exists($path))->toBeTrue('docker-compose.yml is gone, so these checks have nothing to judge');

    return (array) Yaml::parseFile($path);
}

/** The compose file as text, for the checks that are about what the file *says*. */
function dhComposeText(): string
{
    return (string) file_get_contents(base_path('docker-compose.yml'));
}

function dhEnvText(string $file): string
{
    $path = base_path($file);
    expect(file_exists($path))->toBeTrue("$file is gone, so the deployment templates can no longer be judged");

    return (string) file_get_contents($path);
}

/**
 * Uncommented `KEY=value` pairs from an env template.
 *
 * Commented lines are deliberately excluded: a key that is commented out is documentation of an
 * option, not a value an operator gets by copying the file.
 *
 * @return array<string, string>
 */
function dhEnvValues(string $file): array
{
    $values = [];

    foreach (preg_split('/\R/', dhEnvText($file)) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value);
    }

    return $values;
}

/**
 * Simulate a deployment's environment.
 *
 * `config(['app.env' => ...])`, never `app()->instance('env', ...)`: rebinding the container's `env`
 * key also flips `runningUnitTests()`, after which every POST in the suite dies with a 419 and no
 * flashed errors. Production code reads `config('app.env')`, the same convention
 * `PublishedCredentialGuard::environment()` documents.
 */
function dhDeploy(string $env, bool $debug): void
{
    config(['app.env' => $env, 'app.debug' => $debug]);
}

// ─────────────────────────────────────────────────────────────────────────────
// docker-compose.yml
// ─────────────────────────────────────────────────────────────────────────────

test('the compose file takes its database passwords from the environment and fails closed without them', function () {
    $environment = (array) (dhCompose()['services']['db']['environment'] ?? []);

    // The published literal must be gone from the file, not merely overridden at runtime.
    expect(dhComposeText())->not->toContain('MYSQL_ROOT_PASSWORD: secret');

    foreach (['MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD'] as $key) {
        $this->assertArrayHasKey($key, $environment, "the db service no longer states $key at all");

        $value = (string) $environment[$key];

        // toBe and toStartWith do take a message; toHaveKey and toContain do not, which is why the
        // two assertions that need one are PHPUnit's rather than Pest's.
        expect($value)->toStartWith('${', "$key must be an interpolation rather than a literal; got '$value'");

        // `${VAR:-default}` is the literal by another name. `${VAR:?message}` refuses to start.
        $this->assertStringContainsString(':?', $value, "$key must fail closed when unset, not fall back to a default");
    }
});

test('the compose file creates a database user that is not root', function () {
    $environment = (array) (dhCompose()['services']['db']['environment'] ?? []);

    // `.env.production.example` already tells an operator "never root"; the compose file contradicted
    // it by provisioning nothing but a root password.
    expect($environment)->toHaveKey('MYSQL_USER')
        ->and((string) $environment['MYSQL_USER'])->not->toBe('')
        ->and((string) $environment['MYSQL_USER'])->not->toBe('root');
});

test('the database is not published beyond the loopback interface', function () {
    $ports = (array) (dhCompose()['services']['db']['ports'] ?? []);

    // `3306:3306` binds 0.0.0.0. The app reaches `db` by service name over the compose network and
    // needs no published port; a human who wants one can bind it to the loopback interface.
    foreach ($ports as $mapping) {
        expect((string) $mapping)->toStartWith(
            '127.0.0.1:',
            "the db service publishes '$mapping', which is reachable from every interface the host has"
        );
    }
});

test('the app container is pointed at the database the compose file starts', function () {
    $environment = (array) (dhCompose()['services']['app']['environment'] ?? []);

    // `env_file: .env` on its own hands the app the SQLite settings from `.env.example`, so
    // `docker compose up` started a MySQL container that nothing ever connected to.
    expect((string) ($environment['DB_CONNECTION'] ?? ''))->toBe('mysql')
        ->and((string) ($environment['DB_HOST'] ?? ''))->toBe('db')
        ->and((string) ($environment['DB_DATABASE'] ?? ''))->not->toBe('')
        ->and((string) ($environment['DB_USERNAME'] ?? ''))->not->toBe('root', 'the app container does not need the root account');
});

test('the app waits for the database to accept connections', function () {
    $services = (array) (dhCompose()['services'] ?? []);

    // Without this the app starts while MySQL is still initialising, and the first migrations fail
    // for a reason that has nothing to do with the code.
    expect($services['db']['healthcheck']['test'] ?? null)->not->toBeNull('the db service has no healthcheck for compose to wait on');

    $depends = $services['app']['depends_on'] ?? null;

    expect($depends)->toBeArray()
        ->and((string) ($depends['db']['condition'] ?? ''))->toBe('service_healthy');
});

/**
 * Every compose interpolation in the parsed document, however deeply nested.
 *
 * Walks the parsed structure rather than matching the file's text: the header comment documents the
 * `${VAR:?message}` syntax, and a text scan reports `VAR` as a variable the templates must define.
 * The same trap in a different shape caught the money-column scanner in item 26.
 *
 * @param  array<array-key, mixed>  $node
 * @return list<string>
 */
function dhComposeVariables(array $node): array
{
    $found = [];

    foreach ($node as $value) {
        if (is_array($value)) {
            $found = array_merge($found, dhComposeVariables($value));

            continue;
        }

        if (is_string($value)) {
            preg_match_all('/\$\{([A-Z0-9_]+)[:}]/', $value, $matches);
            $found = array_merge($found, $matches[1]);
        }
    }

    return array_values(array_unique($found));
}

test('every variable the compose file demands is documented in a template an operator can copy', function () {
    $variables = dhComposeVariables(dhCompose());

    // Guards the guard: a compose file that interpolates nothing would pass this vacuously.
    expect($variables)->not->toBeEmpty('the compose file interpolates no variables, so this check has gone vacuous');

    $documented = array_keys(dhEnvValues('.env.example') + dhEnvValues('.env.production.example'));

    foreach ($variables as $variable) {
        // assertContains rather than Pest's toContain, whose extra parameters are further needles and
        // not a message — passing a sentence there asserts that the array also contains the sentence.
        $this->assertContains(
            $variable,
            $documented,
            "$variable is required by docker-compose.yml but appears in neither env template, so a deployment that copies one gets a compose file that refuses to start"
        );
    }
});

test('the compose file builds an image whose Dockerfile is not in the repository — characterisation', function () {
    // REPORTED, NOT FIXED. `build: .` with no Dockerfile in the build context means
    // `docker compose up` fails before it starts anything. This asserts today's behaviour on purpose,
    // so that whoever adds the Dockerfile gets a failing test telling them to delete this one, rather
    // than a note in a report that has gone stale. Writing a Dockerfile that cannot be built or run
    // in this environment would be a guess dressed as a fix.
    expect((array) dhCompose()['services']['app'])->toHaveKey('build')
        ->and(file_exists(base_path('Dockerfile')))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// APP_DEBUG in production
// ─────────────────────────────────────────────────────────────────────────────

test('a production deployment cannot serve with APP_DEBUG on', function () {
    Log::spy();
    dhDeploy('production', true);

    ProductionDebugGuard::enforce();

    // `config('app.debug')` is the exact value the exception handler reads when it decides whether to
    // render a stack trace, so flipping it here is the whole fix — not a cosmetic copy of it.
    expect(config('app.debug'))->toBeFalse('the guard left debug enabled in production');

    Log::shouldHaveReceived('critical')->once()->withArgs(
        fn (string $message, array $context = []): bool => str_contains($message, 'APP_DEBUG')
    );
});

test('an error in a guarded production deployment renders without its stack trace', function () {
    // The effect, not just the config value: `config('app.debug')` is what the exception handler
    // reads, so a deployment the guard has corrected must serve the generic error page.
    dhDeploy('production', true);
    ProductionDebugGuard::enforce();

    Route::get('/__dh_probe', static fn () => throw new RuntimeException('DH_PROBE_SECRET'));

    $response = $this->get('/__dh_probe');

    $response->assertStatus(500);
    $this->assertStringNotContainsString(
        'DH_PROBE_SECRET',
        (string) $response->getContent(),
        'the exception message reached the response body, so debug rendering is still on'
    );
});

test('the same error in a development deployment still renders its stack trace', function () {
    // The other half: the guard must not make debug output unreachable where it is wanted, or the
    // assertion above could pass by breaking error pages altogether.
    dhDeploy('local', true);
    ProductionDebugGuard::enforce();

    Route::get('/__dh_probe_dev', static fn () => throw new RuntimeException('DH_PROBE_DEV_SECRET'));

    $response = $this->get('/__dh_probe_dev');

    $response->assertStatus(500);
    $this->assertStringContainsString('DH_PROBE_DEV_SECRET', (string) $response->getContent());
});

test('the debug guard leaves a development environment alone', function () {
    Log::spy();
    dhDeploy('local', true);

    ProductionDebugGuard::enforce();

    expect(config('app.debug'))->toBeTrue('debug was switched off outside production, which would break the dev experience the template promises');

    Log::shouldNotHaveReceived('critical');
});

test('a correctly configured production deployment hears nothing from the guard', function () {
    Log::spy();
    dhDeploy('production', false);

    ProductionDebugGuard::enforce();

    expect(config('app.debug'))->toBeFalse();

    // No message argument here: Mockery reads the second parameter of shouldNotHaveReceived as the
    // expected *arguments*, so passing a sentence would make the assertion pass vacuously.
    Log::shouldNotHaveReceived('critical');
});

test('the debug guard can be switched off, like the credential guard', function () {
    Log::spy();
    config(['core.security.force_debug_off_in_production' => false]);
    dhDeploy('production', true);

    expect(ProductionDebugGuard::enabled())->toBeFalse();

    ProductionDebugGuard::enforce();

    expect(config('app.debug'))->toBeTrue('the documented escape hatch did not take effect');
});

/**
 * PHP source with every comment removed.
 *
 * A source-contract check that matches raw text cannot tell a call from a comment about the call:
 * commenting out `ProductionDebugGuard::enforce();` still satisfies `str_contains`, and the first
 * sensitivity sweep of this file passed with the guard unwired. Duplicated from
 * `MoneyColumnCastTest::mccCodeOnly()` rather than called across files, because every Feature file
 * loads into one process and a helper that lives in another test file disappears when that file is
 * renamed.
 */
function dhCodeOnly(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

test('the debug guard is wired into the CORE provider boot path', function () {
    // A source contract rather than behaviour: this suite cannot boot a second application reliably,
    // so the wiring is asserted where it is written. The guard's own effect is tested behaviourally
    // above, and `enforce()` runs from `boot()` — before any request is handled, which is what makes
    // the exception handler see the corrected value.
    $source = dhCodeOnly((string) file_get_contents(base_path('modules/CORE/Providers/COREServiceProvider.php')));

    preg_match('/public function boot\(\).*?\n    \}/s', $source, $boot);

    $this->assertStringContainsString(
        'ProductionDebugGuard::enforce()',
        $boot[0] ?? '',
        'the guard is not called from COREServiceProvider::boot()'
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// The templates themselves
// ─────────────────────────────────────────────────────────────────────────────

test('the comment stripper still sees the code it is protecting', function () {
    // Guards the guard: a stripper that removed too much would let the wiring test pass forever.
    $sample = <<<'PHP'
    <?php
    // ProductionDebugGuard::enforce(); is only prose here and must be ignored.
    public function boot(): void
    {
        ProductionDebugGuard::enforce();
    }
    PHP;

    $stripped = dhCodeOnly($sample);

    expect($stripped)->not->toContain('only prose here')
        ->and(substr_count($stripped, 'ProductionDebugGuard::enforce();'))->toBe(1);
});

test('the development template ships APP_DEBUG=true only as a local setting, and says where the hardened one is', function () {
    $values = dhEnvValues('.env.example');
    $text = dhEnvText('.env.example');

    if (($values['APP_DEBUG'] ?? '') !== 'true') {
        $this->markTestSkipped('.env.example no longer ships APP_DEBUG=true, so there is nothing to qualify');
    }

    expect($values['APP_ENV'] ?? '')->toBe('local', 'APP_DEBUG=true in a template that does not declare APP_ENV=local');

    // The mistake this file can cause is being copied onto a server. It has to point at the template
    // that is safe to copy, and name the guard that catches the mistake anyway.
    expect($text)->toContain('.env.production.example')
        ->and($text)->toContain('ProductionDebugGuard');
});

test('the production template still ships debug off and names its environment', function () {
    $values = dhEnvValues('.env.production.example');

    expect($values['APP_ENV'] ?? '')->toBe('production')
        ->and($values['APP_DEBUG'] ?? '')->toBe('false')
        ->and($values['APP_KEY'] ?? 'missing')->toBe('', 'the production template must not carry an APP_KEY');
});
