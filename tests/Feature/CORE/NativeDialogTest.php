<?php

/**
 * Native browser dialogs — report P2.1 / §7 item 6, recorded as item 29.
 *
 * `confirm()`, `alert()` and `prompt()` block the main thread, cannot be styled, ignore the theme,
 * cannot be dismissed by the app, and in several browsers can be suppressed entirely by a "don't let
 * this page create more dialogs" checkbox — after which `confirm()` returns false and the guarded
 * action silently never happens again. This project has a `ConfirmDialog` component and a
 * `useToast` composable, and 35 pages already use the dialog; the native calls are what is left.
 *
 * They are not decorative: they guard movement approval, attendance rebuild-all, leave
 * approval/rejection, overtime rejection, payroll loan allocation and journal voiding. Fifteen of
 * them are `prompt()`, used to collect a rejection reason for the audit trail — a value the browser
 * dialog returns as `null` on cancel and as an untrimmed string otherwise, with no way to mark it
 * required or to explain what it is for.
 *
 * The count asserted below is a **ratchet**: it may only fall. Migrating a page lowers it; adding a
 * native dialog anywhere raises it and fails this test.
 *
 * Helpers are prefixed `nd` because every Feature file loads into one process.
 */

/** Directories scanned. `resources/js/components/ui/` is vendored shadcn output and is eslint-ignored. */
const ND_ROOTS = ['modules', 'resources/js'];

/**
 * Baseline. Before any migration this was **77 live calls across 42 files**; item 29 migrated 30 of
 * them across 8 files, which leaves 47.
 *
 * A naive grep reports 80. Two of those are prose inside `PAYROLL/Pages/Reopens/Index.vue`, a page
 * that has already been migrated and whose comments describe the native dialogs it used to have —
 * that file is the reason ndCodeOnly() exists — and two more are `function confirm(` declarations,
 * which are counted by their own test because they shadow rather than call.
 *
 * The 77 were 18 `alert()`, 44 `confirm()` and 15 `prompt()`. Two of the 44 are not native calls at
 * all but template handler references (`@click="confirm(p.id)"`) to a page's own shadowing
 * `confirm()`, which is what makes them unreachable-as-a-guard rather than merely ugly.
 *
 * This number may only fall: migrate the page and lower it in the same edit.
 */
// 46 since item 39, which rewrote `Pos/Show.vue` onto confirmDialog() — the ratchet only falls.
const ND_BASELINE_CALLS = 46;

/** Files that have been migrated and must never grow a native dialog again. */
const ND_MIGRATED = [
    'modules/HRM/Resources/js/Pages/Overtimes/Index.vue',
    'modules/HRM/Resources/js/Pages/Probations/Index.vue',
    'modules/HRM/Resources/js/Pages/ExtraDuty/Index.vue',
    'modules/HRM/Resources/js/Pages/Attendance/Fix/Index.vue',
    'modules/PAYROLL/Resources/js/Pages/Loans/Index.vue',
    'modules/PAYROLL/Resources/js/Pages/Finance/SheetFinance.vue',
    'modules/PAYROLL/Resources/js/Pages/ProvidentFund/Index.vue',
];

/**
 * A native dialog call: `confirm(`, `alert(` or `prompt(`, optionally qualified with `window.`.
 *
 * The lookbehind rejects member calls (`dialog.confirm(`, `toast.alert(`) and identifiers that merely
 * end in the word (`confirmLabel`, `pendingConfirm`, `onConfirm`), which is what lets the composable
 * and the component live in the scanned tree without tripping their own test.
 */
const ND_PATTERN = '/(?<![.\w$])(?:window\s*\.\s*)?(confirm|alert|prompt)\s*\(/';

/**
 * A *declaration* of one of those names, which shadows the global for the whole file.
 *
 * This is not a style problem. `function confirm(id) { if (confirm('…')) … }` calls itself: the name
 * resolves to the local declaration, not to `window.confirm`, so the guard question is never asked
 * and the handler dies with "Maximum call stack size exceeded". That was live on the HRM probation
 * page, where the ✓ Confirm button was wired to exactly that function.
 */
const ND_DECLARATION = '/(?:^|[^\w.$])(?:async\s+)?(?:function|const|let|var)\s+(confirm|alert|prompt)\s*[=(]/';

/**
 * JavaScript source with line and block comments removed.
 *
 * Without this, a comment explaining why a native dialog is banned matches the ban — the same trap
 * that caught `MoneyColumnCastTest` in item 26 and the wiring check in item 28. A `//` inside a URL
 * is kept, because stripping it would eat the rest of every line that mentions an endpoint.
 */
function ndCodeOnly(string $source): string
{
    $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);

    $lines = [];

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        $lines[] = (string) preg_replace('#(?<!:)//.*$#', '', $line);
    }

    return implode("\n", $lines);
}

/** @return list<string> every scanned file, relative to the repository root */
function ndFiles(): array
{
    $root = base_path();
    $files = [];

    foreach (ND_ROOTS as $relative) {
        $directory = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$relative, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($directory as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['vue', 'ts', 'js'], true)) {
                continue;
            }

            $path = str_replace($root.'/', '', $file->getPathname());

            if (str_contains($path, '/components/ui/') || str_contains($path, '/node_modules/')) {
                continue;
            }

            $files[] = $path;
        }
    }

    sort($files);

    return $files;
}

/**
 * Every native dialog call in the tree, as `path:line:function`.
 *
 * @return list<string>
 */
function ndCalls(): array
{
    $calls = [];

    foreach (ndFiles() as $path) {
        $lines = explode("\n", ndCodeOnly((string) file_get_contents(base_path($path))));

        foreach ($lines as $index => $line) {
            if (! preg_match_all(ND_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            // `function confirm(` declares, it does not call — and the declaration can start the
            // line, where there is no preceding text to test. So collect the declared spans first
            // and drop any call that falls inside one. Counted separately by ndDeclarations().
            $declared = [];

            if (preg_match_all(ND_DECLARATION, $line, $declarations, PREG_OFFSET_CAPTURE)) {
                foreach ($declarations[0] as $declaration) {
                    $declared[] = [$declaration[1], $declaration[1] + strlen($declaration[0])];
                }
            }

            foreach ($matches[0] as $position => $match) {
                $offset = $match[1];

                foreach ($declared as [$start, $end]) {
                    if ($offset >= $start && $offset < $end) {
                        continue 2;
                    }
                }

                $calls[] = $path.':'.($index + 1).':'.$matches[1][$position][0];
            }
        }
    }

    return $calls;
}

/**
 * Every place a page declares its own `confirm`, `alert` or `prompt`, as `path:line:name`.
 *
 * @return list<string>
 */
function ndDeclarations(): array
{
    $declarations = [];

    foreach (ndFiles() as $path) {
        $lines = explode("\n", ndCodeOnly((string) file_get_contents(base_path($path))));

        foreach ($lines as $index => $line) {
            if (preg_match_all(ND_DECLARATION, $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $declarations[] = $path.':'.($index + 1).':'.$name;
                }
            }
        }
    }

    return $declarations;
}

test('the scanner actually looks at the front-end tree', function () {
    $files = ndFiles();

    // Guards the guard: an empty file list would make every assertion below pass vacuously.
    expect($files)->not->toBeEmpty()
        ->and(count($files))->toBeGreaterThan(200, 'the scan found far fewer files than the front end has, so the globs are wrong');

    // And it must see the components that prove the pattern is not the only way.
    expect($files)->toContain('modules/CORE/Resources/js/Components/ConfirmDialog.vue');
});

test('the comment stripper still sees the code it is protecting', function () {
    $sample = <<<'JS'
    // window.confirm('only prose here') must be ignored.
    export const endpoint = 'https://example.test/api'; // trailing prose
    if (window.confirm('real')) { run(); }
    JS;

    $stripped = ndCodeOnly($sample);

    expect($stripped)->not->toContain('only prose here');

    // toContain() takes variadic *needles*, not a message, so a message goes through assert*.
    $this->assertStringContainsString(
        'https://example.test/api',
        $stripped,
        'the stripper ate a URL and with it the rest of the line'
    );

    expect(preg_match_all(ND_PATTERN, $stripped))->toBe(1);
});

test('native dialogs are on the way out and the count only falls', function () {
    $calls = ndCalls();

    expect($calls)->toHaveCount(
        ND_BASELINE_CALLS,
        'the native dialog count moved. It may only fall: migrate the page to confirmDialog()/promptDialog()/useToast() and lower ND_BASELINE_CALLS in this file. Found: '.implode(', ', $calls)
    );
});

test('the pages already migrated stay migrated', function () {
    $offending = [];

    foreach (ndCalls() as $call) {
        $path = explode(':', $call)[0];

        if (in_array($path, ND_MIGRATED, true)) {
            $offending[] = $call;
        }
    }

    expect($offending)->toBe([], 'these migrated pages grew a native dialog again: '.implode(', ', $offending));
});

test('every migrated page uses the composables instead', function () {
    // The count above could fall by deleting a guard entirely, which would be worse than the dialog.
    foreach (ND_MIGRATED as $path) {
        $source = (string) file_get_contents(base_path($path));

        $this->assertMatchesRegularExpression(
            '/useConfirm|useToast/',
            $source,
            "$path no longer imports either composable, so its dialogs were removed rather than replaced"
        );
    }
});

test('no page declares its own confirm, alert or prompt', function () {
    $declarations = ndDeclarations();

    $this->assertSame(
        [],
        $declarations,
        'these files shadow a native dialog name, so any call to it inside the file recurses or silently does nothing: '.implode(', ', $declarations)
    );
});

test('the confirm composable and its host exist and are mounted once', function () {
    expect(file_exists(base_path('modules/CORE/Resources/js/Composables/useConfirm.ts')))
        ->toBeTrue('useConfirm.ts is missing, so there is nothing to migrate to');

    expect(file_exists(base_path('modules/CORE/Resources/js/Components/ConfirmDialogHost.vue')))
        ->toBeTrue('ConfirmDialogHost.vue is missing, so a promise from useConfirm() could never settle');

    // Mounted in the admin shell, the way ToastStack is: one host, every page.
    $layout = (string) file_get_contents(base_path('modules/CORE/Resources/js/Layouts/AdminLayout.vue'));

    $this->assertStringContainsString('ConfirmDialogHost', $layout, 'the host is not mounted in AdminLayout, so confirmDialog() would hang forever');
});
