<?php

/**
 * The CI workflow is the only place most of this project's guarantees are enforced, and nothing
 * checked it. Report §7 item 5 / item 28.
 *
 * Every other test in the suite proves something about the code. This one proves something about the
 * thing that runs those tests: a check that is present but advisory, a linter invoked with `--fix`, a
 * build step ordered after the tests that need its output, or an aggregate job that reports success
 * because it never looks at the results it waited for, each turn a green suite into a decorative one.
 *
 * The step that promoted formatting from advisory to blocking is the reason this file exists; the rest
 * of it pins the properties that were already load-bearing and unprotected.
 *
 * Helpers are prefixed `ciw` because every Feature file loads into one process.
 */

use Symfony\Component\Yaml\Yaml;

/** The workflow, parsed. `symfony/yaml` arrives via laravel/sail (require-dev). */
function ciwWorkflow(): array
{
    expect(class_exists(Yaml::class))->toBeTrue(
        'symfony/yaml is gone — it arrives transitively via laravel/sail. Add it to require-dev explicitly.'
    );

    $path = base_path('.github/workflows/ci.yml');
    expect(file_exists($path))->toBeTrue('.github/workflows/ci.yml is gone, so nothing enforces any check in this suite');

    return (array) Yaml::parseFile($path);
}

/**
 * Every step of every job, each tagged with the job it belongs to.
 *
 * @return list<array<string, mixed>>
 */
function ciwSteps(): array
{
    $steps = [];

    foreach ((array) (ciwWorkflow()['jobs'] ?? []) as $job => $config) {
        foreach ((array) ($config['steps'] ?? []) as $step) {
            $step['__job'] = (string) $job;
            $steps[] = (array) $step;
        }
    }

    // Guards the guard: an empty step list would let every "no step does X" assertion pass vacuously.
    expect($steps)->not->toBeEmpty('the workflow parsed to no steps at all');

    return $steps;
}

/** The first step whose `run` block mentions $needle, or null. @return array<string, mixed>|null */
function ciwStepRunning(string $needle): ?array
{
    foreach (ciwSteps() as $step) {
        if (str_contains((string) ($step['run'] ?? ''), $needle)) {
            return $step;
        }
    }

    return null;
}

test('code style is enforced, not advised', function () {
    $step = ciwStepRunning('pint');

    $this->assertNotNull($step, 'the workflow no longer runs pint at all');

    // `--test` is what makes it a check: without it pint rewrites the working tree on the runner and
    // exits 0, so the job passes and the diff is thrown away.
    $this->assertStringContainsString('--test', (string) $step['run'], 'pint runs without --test, so it rewrites files instead of failing on them');

    // And `continue-on-error` is what made it advisory: the step could fail and the job still passed.
    // assertArrayNotHasKey rather than Pest's not->toHaveKey(), whose second parameter is the expected
    // value — with a message there, a step carrying `continue-on-error: true` satisfied it.
    $this->assertArrayNotHasKey('continue-on-error', $step, 'the pint step is still advisory');
});

test('no step anywhere in the workflow is allowed to fail', function () {
    $advisory = [];

    foreach (ciwSteps() as $step) {
        // `continue-on-error: false` is a no-op; only a truthy value weakens the job.
        if (! empty($step['continue-on-error'])) {
            $advisory[] = ($step['__job'] ?? '?').': '.($step['name'] ?? $step['uses'] ?? 'unnamed step');
        }
    }

    expect($advisory)->toBe([], 'these steps can fail without failing the build: '.implode(', ', $advisory));
});

test('the enforced style is the one this repository chose', function () {
    // Without pint.json the preset is whatever the installed pint version defaults to, so a composer
    // update could change what "formatted" means and fail the build for reasons nobody wrote.
    $path = base_path('pint.json');

    expect(file_exists($path))->toBeTrue('pint.json is absent, so CI enforces a default preset this repository never chose');

    $config = json_decode((string) file_get_contents($path), true);

    $this->assertIsArray($config, 'pint.json is not valid JSON');
    $this->assertArrayHasKey('preset', $config, 'pint.json does not name a preset');
});

test('the front-end linter fails on a violation instead of silently rewriting it', function () {
    $step = ciwStepRunning('eslint');

    $this->assertNotNull($step, 'the workflow no longer runs eslint');
    $this->assertStringNotContainsString('--fix', (string) $step['run'], 'eslint --fix on a runner rewrites the tree and exits 0, so the check passes and the fix is discarded');
});

test('the front-end assets are built before the tests that render them', function () {
    // Pest renders the real Blade shell, and `@vite` throws "Vite manifest not found" when
    // public/build is absent. If the build step moves after the test step, CI fails on a missing
    // artefact and the failure looks like a broken test.
    $steps = ciwSteps();

    $build = null;
    $pest = null;

    foreach ($steps as $index => $step) {
        $run = (string) ($step['run'] ?? '');

        if (($step['__job'] ?? '') !== 'php') {
            continue;
        }

        if (str_contains($run, 'npm run build') && $build === null) {
            $build = $index;
        }

        if (str_contains($run, 'pest') && $pest === null) {
            $pest = $index;
        }
    }

    $this->assertNotNull($build, 'the php job no longer builds front-end assets');
    $this->assertNotNull($pest, 'the php job no longer runs pest');
    $this->assertLessThan($pest, $build, 'the php job builds the front end after it runs the tests that render it');
});

test('dependency advisories are checked on both sides of the stack', function () {
    $this->assertNotNull(ciwStepRunning('composer audit'), 'composer audit is gone from the workflow');
    $this->assertNotNull(ciwStepRunning('npm audit'), 'npm audit is gone from the workflow');
});

test('seeding is verified, because a seeder that throws still exits 0', function () {
    // `migrate:fresh --seed` exits 0 even when a seeder throws mid-way, leaving the ledger
    // half-populated. The step only means anything if it counts rows afterwards.
    $step = ciwStepRunning('migrate:fresh --seed');

    $this->assertNotNull($step, 'the workflow no longer checks that a fresh seed succeeds');
    $this->assertStringContainsString('acc_accounts', (string) $step['run'], 'the seed step no longer counts the chart of accounts');
    $this->assertStringContainsString('permissions', (string) $step['run'], 'the seed step no longer counts permissions');
});

test('the aggregate job actually reads the results it waits for', function () {
    $jobs = (array) (ciwWorkflow()['jobs'] ?? []);

    $this->assertArrayHasKey('ci', $jobs, 'the workflow has no aggregate job for branch protection to require');

    $aggregate = (array) $jobs['ci'];

    // `if: always()` makes the job run even when a dependency failed — which is only useful if the
    // job then inspects those results. Without the inspection it reports success on a red build.
    expect((string) ($aggregate['if'] ?? ''))->toBe('always()')
        ->and((array) ($aggregate['needs'] ?? []))->toContain('php')
        ->and((array) ($aggregate['needs'] ?? []))->toContain('frontend');

    $run = (string) ($aggregate['steps'][0]['run'] ?? '');

    $this->assertStringContainsString('failure', $run, 'the aggregate job does not look for a failed dependency');
    $this->assertStringContainsString('exit 1', $run, 'the aggregate job notices a failure but does not fail');
});
