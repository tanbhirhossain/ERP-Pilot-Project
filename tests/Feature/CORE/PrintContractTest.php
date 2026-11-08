<?php

/**
 * The print contract — item 43.
 *
 * Printing was broken in a way that no single file looks responsible for. The shell's stylesheet hid
 * `body *` under `@media print` and revealed only `[data-core-print]`, an attribute that exactly one
 * component in the application set. Every other Print button therefore produced a blank sheet: the
 * CSS was correct, the markup was correct, and the two had never been introduced. Two pages then
 * carried their own unscoped `<style>` print blocks to work around it, and because an unscoped style
 * in a Vue SFC is injected once and never removed, visiting either of those pages blanked printing
 * for the rest of the tab's life — including pages printed before them.
 *
 * What this file pins is the architecture that replaced it, because the failure mode is silent and
 * returns the moment somebody "just adds a little print CSS" to a page:
 *
 *   - one stylesheet owns paper (`resources/css/print.css`), and no component owns any;
 *   - region isolation is opt-in, keyed on a class the engine sets only when a region is marked;
 *   - the engine, not a template, calls `window.print()`;
 *   - paper is light-on-white whatever the screen tokens say;
 *   - a page that marks nothing still prints its content.
 *
 * Helpers are prefixed `pc` because every Feature file loads into one process.
 */
const PC_PRINT_CSS = 'resources/css/print.css';
const PC_LAYOUT = 'modules/CORE/Resources/js/Layouts/AdminLayout.vue';
const PC_APP_TS = 'resources/js/app.ts';
const PC_ENGINE = 'modules/CORE/Resources/js/Composables/usePrint.ts';

/** Pages whose colour *is* the content: a barcode or a receipt must not be dithered to grey. */
const PC_EXACT_COLOUR = [
    'modules/INVENTORY/Resources/js/Pages/Products/BarcodePrint.vue',
    'modules/SALES/Resources/js/Pages/Pos/Receipt.vue',
];

/** Documents that are read on a portrait sheet, whatever the screen layout needs. */
const PC_PORTRAIT = [
    'modules/PAYROLL/Resources/js/Pages/PaySlips/Show.vue',
    'modules/INVENTORY/Resources/js/Pages/Products/BarcodePrint.vue',
    'modules/HRM/Resources/js/Pages/LeaveRequests/Show.vue',
    'modules/SALES/Resources/js/Pages/Pos/Receipt.vue',
    'modules/CORE/Resources/js/Pages/UiKit.vue',
];

/** @return list<string> every .vue file in the application */
function pcVueFiles(): array
{
    $files = [];

    foreach (['modules', 'resources/js'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    sort($files);

    return $files;
}

function pcRead(string $path): string
{
    $contents = file_get_contents(base_path($path));

    expect($contents)->not->toBeFalse("cannot read $path");

    return (string) $contents;
}

/**
 * Comments out, code and line numbers kept.
 *
 * Several of the files this suite reads explain the defect they guard in prose, naming the very call
 * or class that is forbidden, so a scan over raw text reports the explanation as the offence. Two
 * attempts at being clever about it both measured wrongly, and both are worth recording because the
 * third rule is only obviously right once you have seen them fail:
 *
 *   - Three `preg_replace_callback` calls, block comments included: `DataTable.vue` sends an
 *     `Accept` header whose value ends in the star-slash-star wildcard, and the comment opener
 *     inside that string swallowed 1.4 kB of real code — including a call this suite counts.
 *   - A character walker that tracks strings and comments properly: the same file matches
 *     `/filename="?([^";]+)"?/i`, and a regex literal is indistinguishable from a string without a
 *     JavaScript parser, so the walker opened a phantom string at line 294 and stopped stripping
 *     anything after it.
 *
 * So: **whole-line comments only**. Markup comments anywhere, `//` and a block comment that each
 * start their own line. Every explanation in this codebase is written that way, the star-slash-star
 * header value is mid-line, and a regex literal never begins a line. Newlines are preserved rather
 * than collapsed so a reported line number still points at the markup it describes.
 */
function pcStripComments(string $source): string
{
    $blank = static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));

    foreach (['#<!--.*?-->#s', '#(^|\n)[ \t]*//[^\n]*#', '#(^|\n)[ \t]*/\*.*?\*/#s'] as $pattern) {
        $source = (string) preg_replace_callback($pattern, $blank, $source);
    }

    return $source;
}/** The template and style of a component, with its script removed. */
function pcWithoutScripts(string $source): string
{
    return (string) preg_replace('#<script\b[^>]*>.*?</script>#s', '', $source);
}

/**
 * The contents of every `<style>` block in a component.
 *
 * @return list<string>
 */
function pcStyleBlocks(string $source): array
{
    preg_match_all('#<style\b[^>]*>(.*?)</style>#s', $source, $matches);

    return $matches[1] ?? [];
}

/**
 * Every rule in a stylesheet as [selector, body], with at-rules flattened into the selector.
 *
 * Nested rules are the whole difficulty here: `@media print { html.is-printing-region body * {...} }`
 * parsed as one rule whose selector is `@media print` and whose body contains the nested text, which
 * reads as "an unconditional rule that hides everything" — the defect this test exists to catch,
 * reported against the wrapper that makes it conditional. Prefixing instead of nesting keeps the
 * condition attached to the selector it qualifies.
 *
 * @return list<array{selector: string, body: string}>
 */
function pcRules(string $css, string $prefix = ''): array
{
    $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

    $rules = [];
    $length = strlen($css);
    $cursor = 0;
    $selectorStart = 0;

    while ($cursor < $length) {
        if ($css[$cursor] === ';') {
            $cursor++;
            $selectorStart = $cursor;

            continue;
        }

        if ($css[$cursor] !== '{') {
            $cursor++;

            continue;
        }

        $selector = trim(substr($css, $selectorStart, $cursor - $selectorStart));
        $depth = 1;
        $bodyStart = $cursor + 1;
        $cursor = $bodyStart;

        while ($cursor < $length && $depth > 0) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }

            $cursor++;
        }

        $body = substr($css, $bodyStart, max(0, $cursor - $bodyStart - 1));
        $qualified = trim($prefix.' '.$selector);

        if (str_contains($body, '{')) {
            foreach (pcRules($body, $qualified) as $nested) {
                $rules[] = $nested;
            }
        } else {
            $rules[] = ['selector' => $qualified, 'body' => $body];
        }

        $selectorStart = $cursor;
    }

    return $rules;
}

test('the scanner is looking at the application it claims to describe', function () {
    $files = pcVueFiles();

    // Without this the rest of the file passes on an empty glob, which is the quietest way a
    // contract test can stop being one.
    expect($files)->toHaveCount(396, 'the .vue census moved; if pages were added or removed, re-measure rather than guess')
        ->and($files)->toContain(PC_LAYOUT);

    $withStyle = array_filter($files, static fn (string $path): bool => pcStyleBlocks(pcRead($path)) !== []);

    expect($withStyle)->not->toBeEmpty('no component has a <style> block, so the tests about style blocks prove nothing');

    // And the stripper must survive contact with a real file, in both directions: prose that names
    // a forbidden call is the false positive this suite has hit three times, and a string that
    // contains `/*` is the false negative it hit on the fourth.
    $prose = pcStripComments("<!-- window.print() -->\n<div>window.print()</div>");

    // toContain() is variadic — a trailing explanation becomes a second needle and the assertion
    // fails on its own message — so anything that needs to say why goes through PHPUnit.
    $this->assertStringContainsString('<div>window.print()</div>', $prose, 'live markup was stripped along with the comment');
    $this->assertStringNotContainsString('<!-- window', $prose, 'an HTML comment survived, and it names the forbidden call');

    $stringWithSlashStar = pcStripComments(
        "headers: { Accept: 'application/pdf, */*' },\nprintDocument();\n// window.print() was the old way\n"
    );

    $this->assertStringContainsString('printDocument();', $stringWithSlashStar, 'a string containing /* swallowed the code after it');
    $this->assertStringContainsString('*/*', $stringWithSlashStar, 'the string literal itself was stripped as a comment');
    $this->assertStringNotContainsString('was the old way', $stringWithSlashStar, 'a line comment on the second line survived, so $atLineStart is not being reset');
});

test('no component hides the page when printing', function () {
    foreach (pcVueFiles() as $path) {
        $styles = pcStripComments(implode("\n", pcStyleBlocks(pcRead($path))));

        // The original defect, verbatim: hide everything, reveal one attribute.
        $this->assertSame(
            0,
            preg_match('/body\s+\*[^}]*visibility:\s*hidden/s', $styles),
            "$path hides the whole body under @media print. Paper is owned by resources/css/print.css; a page-local rule like this outlives the page in an SPA and blanks every later print."
        );

        $this->assertSame(
            0,
            preg_match('/@media\s+print[^{]*\{[^}]*body\s*\{/s', $styles),
            "$path restyles <body> for print; the shell already owns the page box."
        );
    }
});

test('region isolation is opt-in, and the marked region is revealed', function () {
    $rules = pcRules(pcRead(PC_PRINT_CSS));

    $hiding = array_values(array_filter(
        $rules,
        static fn (array $rule): bool => str_contains($rule['body'], 'visibility: hidden')
    ));

    expect($hiding)->not->toBeEmpty('print.css no longer isolates anything, so a printed sheet carries the sidebar and the header');

    foreach ($hiding as $rule) {
        $this->assertStringContainsString(
            'is-printing-region',
            $rule['selector'],
            "the rule `{$rule['selector']}` hides content unconditionally. Isolation has to be keyed on the class the engine sets only when a [data-core-print] region exists, or pages that mark nothing print blank."
        );
    }

    $css = pcRead(PC_PRINT_CSS);

    $this->assertStringContainsString('html.is-printing-region [data-core-print]', $css, 'the marked region is never revealed, so isolation can only ever print nothing');
    $this->assertStringContainsString('.print-furniture', $css, 'the letterhead is hidden along with the shell');

    // Pages that mark nothing still have to lose the sidebar, structurally rather than by hiding
    // the body: the shell is known, so name it.
    $this->assertStringContainsString('.core-shell > *:not(main)', $css, 'there is no structural fallback, so an unmarked page prints its sidebar');
});

test('the print hook is installed once, at boot, and cleans up after itself', function () {
    $boot = pcRead(PC_APP_TS);
    $engine = pcRead(PC_ENGINE);

    expect(substr_count($boot, 'installPrintSupport();'))->toBe(1, 'the global hook is installed zero times (unmigrated window.print() calls print the chrome) or twice (handlers fire twice)');
    $this->assertStringContainsString("from '../../modules/CORE/Resources/js/Composables/usePrint'", $boot, 'the boot file does not import the engine it calls');

    $this->assertStringContainsString("window.addEventListener('beforeprint'", $engine, 'a bare window.print() gets no region handling at all');
    $this->assertStringContainsString("window.addEventListener('afterprint'", $engine, 'the shell never comes back after a print');
    $this->assertStringContainsString('fonts', $engine, 'printing before web fonts settle reflows the sheet mid-preview');
});

test('no template calls window.print(), because window is not in template scope', function () {
    foreach (pcVueFiles() as $path) {
        $template = pcStripComments(pcWithoutScripts(pcRead($path)));

        // This compiled to `_ctx.window.print()` in the POS receipt and threw on click: the button
        // looked right, and had never once printed anything.
        $this->assertStringNotContainsString(
            'window.print',
            $template,
            "$path calls window.print() from a template, where `window` resolves against the component instance and throws. Call printDocument() from the script instead."
        );
    }
});

test('every page prints through the engine, and nothing calls window.print() directly', function () {
    $callers = [];

    foreach (pcVueFiles() as $path) {
        $source = pcStripComments(pcRead($path));

        if (! str_contains($source, 'printDocument(')) {
            continue;
        }

        $callers[] = $path;

        $this->assertStringContainsString(
            'usePrint',
            $source,
            "$path calls printDocument() without importing the composable that exports it"
        );
    }

    // Measured: the eight accounting reports, the shared DataTable (so every list it renders prints
    // through the engine), the payslip, the leave application, the barcode sheet, the POS receipt,
    // the two day-end screens and the design-system demo.
    expect($callers)->toHaveCount(16, 'the set of pages that print through the engine changed; re-measure and say why');

    // With DataTable migrated, nothing in the component tree calls the browser directly any more.
    foreach (pcVueFiles() as $path) {
        $this->assertStringNotContainsString(
            'window.print',
            pcStripComments(pcRead($path)),
            "$path calls window.print() directly, bypassing the engine: no font wait, no stamped furniture, no portrait option"
        );
    }
});

test('the shell renders the letterhead and owns no print CSS of its own', function () {
    $layout = pcRead(PC_LAYOUT);

    $this->assertStringContainsString('print-furniture', $layout, 'the printed sheet has no letterhead, so a report leaves the building unbranded');
    $this->assertStringContainsString('print-sheet-header', $layout, 'the sheet header is gone');

    $styles = pcStripComments(implode("\n", pcStyleBlocks($layout)));

    $this->assertStringNotContainsString('@media print', $styles, 'AdminLayout owns print CSS again; that is where the body-hiding rule lived');
});

test('paper is light-on-white in both modes, and tables repeat their headings', function () {
    $css = pcRead(PC_PRINT_CSS);

    // Dark mode on screen must not become grey-on-grey on paper: the token set is reset, not
    // inverted, and the reset has to reach the dark scope or it loses on specificity.
    $this->assertStringContainsString('--background: 0 0% 100%', $css, 'print.css no longer resets the page to white');
    $this->assertMatchesRegularExpression('/[^{]*\.dark[^{]*\{[^}]*--background: 0 0% 100%/s', $css, 'the white reset does not cover the dark scope, so .dark wins and paper goes grey');

    $this->assertStringContainsString('display: table-header-group', $css, 'table headings no longer repeat on the second sheet, so page two of a ledger has no columns');
    $this->assertStringContainsString('break-inside: avoid', $css, 'rows can now split across sheets');
});

test('colour is preserved exactly where the colour is the content', function () {
    foreach (PC_EXACT_COLOUR as $path) {
        $this->assertStringContainsString(
            'print-keep-color',
            pcRead($path),
            "$path no longer asks for exact colour. Browsers drop background colour by default, which turns a barcode label into a rectangle of nothing."
        );
    }

    $this->assertStringContainsString('print-color-adjust: exact', pcRead(PC_PRINT_CSS), 'the class exists on the pages but does nothing in the stylesheet');
});

test('documents that are read on a portrait sheet ask for one', function () {
    $css = pcRead(PC_PRINT_CSS);

    // The default sheet is landscape, because that is what a ledger wants; portrait is a named page,
    // which Chrome and Edge honour and Firefox ignores rather than misprints.
    $this->assertStringContainsString('size: A4 landscape', $css, 'the default @page no longer asks for landscape');
    $this->assertStringContainsString('@page portrait-sheet', $css, 'the named portrait page is gone');
    $this->assertStringContainsString('size: A4 portrait', $css, 'the named portrait page no longer sets a portrait size');

    foreach (PC_PORTRAIT as $path) {
        $source = pcStripComments(pcRead($path));

        $this->assertTrue(
            str_contains($source, 'portrait: true') || str_contains($source, 'print-portrait'),
            "$path prints a document that is read on a portrait sheet but no longer asks for one"
        );
    }
});

test('one page marks at most one print region', function () {
    $marked = [];

    foreach (pcVueFiles() as $path) {
        $source = pcStripComments(pcRead($path));
        // Whitespace before the name, so only an attribute in a tag counts. The design-system page
        // documents the attribute inside a <code> element, and prose about a marker is not a marker.
        $count = preg_match_all('/\sdata-core-print(?![\w-])/', $source);

        if ($count > 0) {
            $marked[$path] = $count;
        }
    }

    $this->assertStringContainsString('data-core-print', pcRead('modules/CORE/Resources/js/Components/DataTable.vue'), 'the shared table no longer marks itself as the printable region');

    foreach ($marked as $path => $count) {
        $this->assertLessThanOrEqual(
            1,
            $count,
            "$path marks $count print regions. Isolation reveals one region; two means one of them prints blank, and which one depends on document order."
        );
    }

    // Measured: the shared table plus the four documents that print themselves rather than a list.
    expect($marked)->toHaveCount(5, 'the set of pages that mark a print region changed; re-measure and say why');
});
