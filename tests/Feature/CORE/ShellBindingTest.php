<?php

use Tests\Paths;

/**
 * The shell's template bindings must exist in its script.
 *
 * Two regressions this round broke the shell in a way that no gate caught:
 *
 *  - finding 53: `UiTheme::share()` was hinted against the module's `User` subclass, so every Inertia
 *    response 500'd for the starter kit's own `App\Models\User` and surfaced as a confusing
 *    `assertGuest()` failure two modules away;
 *  - finding 56: the theme engine's block replaced `const user = computed(() => page.props.auth.user)`
 *    in `AdminLayout.vue`, and the template kept referring to `user`. `v-if="user"` then read as
 *    undefined, so the account pill and the printed "Printed by" line rendered nothing — while pint,
 *    ESLint, Vitest, the production build and all 589 Pest tests stayed green. Only `vue-tsc`, which
 *    nothing runs in CI, reported it.
 *
 * A Vue SFC template resolves a bare name against the setup scope, and an unresolved name is not an
 * error: it is `undefined`, which is falsy and silently renders nothing. That makes a dropped binding
 * the quietest kind of breakage in this codebase, so it gets a static contract that runs with the
 * suite rather than depending on a type gate nobody has wired up.
 *
 * Scope, deliberately narrow: only names a template uses *bare* are checked — a `v-if`/`v-show` whose
 * whole value is one identifier, and the first identifier of a mustache. `props.title`, `$slots.x`,
 * `a && b` and `open ? x : y` are left alone, because reasoning about those needs a real parser and a
 * scanner that over-reports teaches people to ignore it.
 */
const SB_LAYOUTS = 'modules/CORE/Resources/js/Layouts';

/** Names a Vue template resolves without any declaration in the component. */
const SB_GLOBALS = [
    'true', 'false', 'null', 'undefined', 'NaN', 'Infinity',
    'Math', 'Date', 'JSON', 'Number', 'String', 'Boolean', 'Array', 'Object', 'Intl',
    'new', 'typeof', 'instanceof', 'in', 'of', 'return',
];

/** @return list<string> every .vue file under a directory, sorted */
function sbFiles(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'vue') {
            $files[] = Paths::relativePath($file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/** The `<script setup>` body, or '' when a file has none. */
function sbScript(string $source): string
{
    if (! preg_match('/<script\b[^>]*\bsetup\b[^>]*>(.*?)<\/script>/s', $source, $m)) {
        return '';
    }

    return $m[1];
}

/** The outer `<template>` body, or '' when a file has none. */
function sbTemplate(string $source): string
{
    $start = strpos($source, '<template>');

    if ($start === false) {
        return '';
    }

    $end = strrpos($source, '</template>');

    return $end === false ? '' : substr($source, $start + 10, $end - $start - 10);
}

/**
 * Every name the script puts in scope: declarations, import specifiers, destructured bindings and the
 * members of a `Props` interface, which `defineProps<Props>()` exposes to the template as bare names.
 *
 * @return list<string>
 */
function sbDeclared(string $script): array
{
    $names = [];

    // const/let/var/function/class declarations, including `const user = computed(...)`.
    preg_match_all('/\b(?:const|let|var|function|class)\s+([A-Za-z_$][\w$]*)/', $script, $m);
    $names = array_merge($names, $m[1]);

    // Destructuring: `const { mode, scheme, setScheme } = useUiTheme();` and array forms.
    preg_match_all('/\b(?:const|let|var)\s*(?:\{([^}]*)\}|\[([^\]]*)\])\s*=/s', $script, $d);

    foreach (array_merge($d[1], $d[2]) as $group) {
        foreach (explode(',', $group) as $part) {
            // `local: imported` declares the local name; a default value ends at `=`.
            $part = explode('=', $part)[0];
            $part = trim(explode(':', $part)[0]);

            if (preg_match('/^[A-Za-z_$][\w$]*$/', $part)) {
                $names[] = $part;
            }
        }
    }

    // Imports: default, named (with and without an alias) and namespace forms.
    preg_match_all('/import\s+([^;\n]+)\s+from/s', $script, $i);

    foreach ($i[1] as $clause) {
        $clause = trim($clause);
        $braced = '';

        if (preg_match('/\{(.*)\}/s', $clause, $b)) {
            $braced = $b[1];
            $clause = trim(preg_replace('/\{.*\}/s', '', $clause), " ,\n\t");
        }

        foreach (explode(',', $braced) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // `type Foo` and `Foo as Bar` both put the last name in scope.
            $part = (string) preg_replace('/^\s*type\s+/', '', $part);
            $bits = preg_split('/\s+as\s+/i', $part);
            $name = trim((string) end($bits));

            if (preg_match('/^[A-Za-z_$][\w$]*$/', $name)) {
                $names[] = $name;
            }
        }

        foreach (explode(',', $clause) as $part) {
            $part = trim((string) preg_replace('/^\s*type\s+/', '', $part));

            if (preg_match('/^[A-Za-z_$][\w$]*$/', $part)) {
                $names[] = $part;
            }
        }
    }

    // `interface Props { title?: string }` — defineProps exposes each member as a bare template name.
    // The closing brace is matched with ^\s*} rather than \n} because the interface is indented.
    if (preg_match('/interface\s+Props\s*\{(.*?)^\s*\}/ms', $script, $p)) {
        preg_match_all('/^\s*([A-Za-z_$][\w$]*)\s*[?:]/m', $p[1], $pm);
        $names = array_merge($names, $pm[1]);
    }

    return array_values(array_unique($names));
}

/** Names introduced by a `v-for` in the template, which are in scope for everything it wraps. */
function sbLoopAliases(string $template): array
{
    $names = [];

    preg_match_all('/v-for="(?:\(([^)]*)\)|([A-Za-z_$][\w$]*))\s+in\s/s', $template, $m);

    foreach (array_merge($m[1], $m[2]) as $group) {
        foreach (explode(',', $group) as $part) {
            $part = trim($part);

            if (preg_match('/^[A-Za-z_$][\w$]*$/', $part)) {
                $names[] = $part;
            }
        }
    }

    return array_values(array_unique($names));
}

/**
 * The bare names a template uses: a `v-if`/`v-else-if`/`v-show` whose whole value is one identifier,
 * and the first identifier of each mustache.
 *
 * @return list<string>
 */
function sbUsedNames(string $template): array
{
    $names = [];

    // The first character excludes `$` on purpose: `$slots`, `$page` and `$attrs` are Vue's own.
    preg_match_all('/\bv-(?:if|else-if|show)="([A-Za-z_][\w$]*)"/', $template, $m);
    $names = array_merge($names, $m[1]);

    preg_match_all('/\{\{\s*([A-Za-z_][\w$]*)/', $template, $mu);
    $names = array_merge($names, $mu[1]);

    return array_values(array_unique($names));
}

/**
 * The first line on which the template uses this name bare, so the failure points somewhere a person
 * can go and look. Falls back to 0 rather than guessing.
 */
function sbLineOf(string $source, string $name): int
{
    $quoted = preg_quote($name, '/');

    if (! preg_match('/^(.*?(?:\bv-(?:if|else-if|show)="'.$quoted.'"|\{\{\s*'.$quoted.'\b))/s', $source, $m)) {
        return 0;
    }

    return substr_count($m[1], "\n") + 1;
}

/** @return list<string> template names with nothing behind them, as `file:line name` */
function sbUnresolved(string $path): array
{
    $source = (string) file_get_contents(base_path($path));
    $script = sbScript($source);
    $template = sbTemplate($source);

    if ($script === '' || $template === '') {
        return []; // not a `<script setup>` SFC; this contract says nothing about it
    }

    $inScope = array_merge(sbDeclared($script), sbLoopAliases($template), SB_GLOBALS);

    $unresolved = [];

    foreach (sbUsedNames($template) as $name) {
        if (in_array($name, $inScope, true)) {
            continue;
        }

        $unresolved[] = $path.':'.sbLineOf($source, $name).' '.$name;
    }

    return $unresolved;
}

test('every bare name a CORE layout template uses is declared in its script', function () {
    $layouts = sbFiles(SB_LAYOUTS);

    $this->assertNotEmpty($layouts, 'no layouts found, so the scan has nothing to look at and would pass vacuously');

    $unresolved = [];

    foreach ($layouts as $path) {
        $unresolved = array_merge($unresolved, sbUnresolved($path));
    }

    $this->assertSame(
        [],
        $unresolved,
        'these template names have no binding behind them, so each one reads as undefined and renders '.
        'nothing: a falsy v-if drops the element and an empty mustache prints nothing. Either declare '.
        'the name in the script or stop referring to it. (A dropped `const user` in AdminLayout did '.
        'exactly this to the account menu — finding 56.)'
    );
});

test('the bindings the shell cannot work without are declared and still used', function () {
    $source = (string) file_get_contents(base_path('modules/CORE/Resources/js/Layouts/AdminLayout.vue'));
    $script = sbScript($source);
    $template = sbTemplate($source);

    $declared = sbDeclared($script);

    // Two directions on purpose: a name that is declared but no longer used means this list has rotted,
    // and a name that is used but not declared is finding 56 again.
    foreach (['user', 'appName', 'sheetTitle', 'printMeta', 'mode', 'scheme', 'schemes'] as $name) {
        $this->assertContains($name, $declared, "$name is used by the shell's template but is not declared in its script");
        $this->assertStringContainsString($name, $template, "$name is declared but the template no longer uses it, so this list has rotted");
    }

    // The regression itself, pinned as a fact about the file rather than as a scan result.
    $this->assertMatchesRegularExpression(
        '/const\s+user\s*=\s*computed\(/',
        $script,
        'the account pill and the printed colophon both read `user`, so it has to be a computed binding'
    );
});

test('the scanner catches an undeclared binding and passes a legitimate one', function () {
    $directory = 'tests/Fixtures/ShellBindingScan';

    @mkdir(base_path($directory), 0o777, true);

    // Broken: `ghost` is in neither the script nor a loop, in both scanned positions.
    file_put_contents(base_path($directory.'/Broken.vue'), <<<'VUE'
    <script setup lang="ts">
    import { computed } from 'vue';
    const real = computed(() => 1);
    </script>

    <template>
        <div v-if="ghost">never renders</div>
        <p>{{ real }} and {{ ghost.name }}</p>
    </template>
    VUE);

    // Legitimate: a loop alias, a destructured composable, an aliased import, a prop and a global.
    file_put_contents(base_path($directory.'/Fine.vue'), <<<'VUE'
    <script setup lang="ts">
    import { Link as InertiaLink } from '@inertiajs/vue3';
    import { useUiTheme } from '../Composables/useUiTheme';

    interface Props {
        title?: string;
    }

    defineProps<Props>();

    const { mode, scheme } = useUiTheme();
    const rows = [{ id: 1 }];
    </script>

    <template>
        <InertiaLink :href="route('core.dashboard')">
            <span v-for="(row, index) in rows" :key="row.id">{{ index }} {{ mode }} {{ scheme }}</span>
            <span v-if="row">alias from the loop</span>
            <span>{{ title }}</span>
            <span>{{ Math.round(1.5) }}</span>
        </InertiaLink>
    </template>
    VUE);

    $broken = sbUnresolved($directory.'/Broken.vue');
    $fine = sbUnresolved($directory.'/Fine.vue');

    unlink(base_path($directory.'/Broken.vue'));
    unlink(base_path($directory.'/Fine.vue'));
    rmdir(base_path($directory));

    $ghosts = array_values(array_filter($broken, static fn (string $hit): bool => str_ends_with($hit, ' ghost')));

    // One hit, not two: the scanner reports distinct names, and `ghost` is used in both scanned
    // positions but is the same missing binding.
    $this->assertCount(
        1,
        $ghosts,
        'the scanner no longer sees an undeclared binding, so the layout check above passes vacuously. '.
        'Saw: '.implode(', ', $broken)
    );

    $this->assertStringContainsString(
        ':7 ghost',
        $ghosts[0] ?? '',
        'the reported line should be the v-if that uses the missing name, so the failure is actionable'
    );

    $this->assertSame(
        [],
        $fine,
        'the scanner is over-reporting: a loop alias, a destructured composable, an aliased import, a '.
        'prop and a global are all legitimate. '.implode(', ', $fine)
    );
});
