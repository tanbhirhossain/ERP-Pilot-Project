<?php

/**
 * Theme integrity of the shared components — report P2.2, corrected and pinned as item 30.
 *
 * P2.2 claimed roughly 75 hardcoded light-only classes with the "highest blast radius" in
 * `DataTable.vue` and `PageHeader.vue`. That was a regex counting *hits*, not defects. Read in
 * context, the shared components contained **eight** raw neutral palette classes and **no**
 * dark-mode defect at all. Item 42 tokenised four of them away, so **four** remain:
 *
 *   - DataTable's four grays are inside `hidden print:block`. Printed paper is white whichever
 *     theme the screen is in, so tokenising them would make the print header follow the screen
 *     theme and print near-white text on white paper. Still excused, still the only excuse left.
 *   - ~~PageHeader's three `text-white` on a `bg-[#0B0F1E]` glass hero~~ — at item 42 the hero
 *     paints the palette's ramp (`from-hero-from via-hero-via to-hero-to`) and every letter on it is
 *     `hero-ink`, so the allowlist entry is gone and the assertion below now demands the tokens.
 *   - ~~StatusBadge's `bg-zinc-500` neutral dot in a six-colour set~~ — the six tones are semantic
 *     tokens now (`success`, `warning`, `danger`, `info`, `neutral-ink`, `primary`), and with them
 *     the six `dark:text-*-400` counterparts: themes.css redefines a status colour per mode, so the
 *     badge no longer has to know which mode it is in.
 *
 * The scanner strips comments before it counts. Both of the components above now carry a history
 * note naming the class they used to use, and a regex over raw text would have scored the note as a
 * defect — the third time in this audit that an explanatory comment failed a scan written to catch
 * the thing it explains.
 *
 * The rule that separates a defect from a design choice: **a hardcoded surface is a defect only when
 * its ink comes from a token (or is absent), so the pair can invert.** `bg-slate-800 text-white` is
 * self-consistent in both themes; `bg-white` with inherited `text-foreground` goes white-on-white
 * the moment `.dark` is applied. Five such defects in `PAYROLL/SheetFinance.vue` were fixed with
 * tokens; the page-level ratchet below records what is left to adjudicate.
 *
 * Helpers are prefixed `sct` because every Feature file loads into one process.
 */

/** The components every module page renders through. */
const SCT_SHARED = 'modules/CORE/Resources/js/Components';

/**
 * A solid neutral palette utility — `bg-white`, `text-slate-700`, `border-gray-200`.
 *
 * Named neutrals only. Saturated light tints (`bg-emerald-50`, `bg-amber-100`) are a second class of
 * light-only surface that this scanner does not measure; they are noted in the report rather than
 * silently counted here.
 */
const SCT_SOLID = '/(?<![\w:\/-])((?:bg|text|border|divide|ring|from|via|to|fill|stroke|decoration|outline|accent|caret)-(?:white|black|slate|gray|zinc|neutral|stone)(?:-\d{2,3})?)(?![\w-])/';

/**
 * Raw palette classes that are legitimate, and why. Keyed by file, then by class, so the entry
 * survives reformatting but still fails if a *new* palette class appears in that file.
 */
const SCT_ALLOWLIST = [
    SCT_SHARED.'/DataTable.vue' => [
        'border-gray-900' => 'print-only sheet header (hidden print:block); paper is white in both themes',
        'text-gray-500' => 'print-only sheet header (hidden print:block); paper is white in both themes',
        'text-gray-600' => 'print-only sheet header (hidden print:block); paper is white in both themes',
        'text-gray-900' => 'print-only sheet header (hidden print:block); paper is white in both themes',
    ],
];

/**
 * Page-level candidate backlog. **Candidates, not defects** — each needs the ink rule above applied
 * by reading it. The number may only fall.
 */
// 144 → 143 at item 42, and the fall is not a fix: the scanner strips comments before it counts, so a
// colour that was only written about in a comment stopped being a candidate. Repointing a page at a
// token is what moves this number for a real reason, and none has been repointed yet.
const SCT_PAGE_CANDIDATES = 143;

/**
 * A semantic token utility — `bg-card`, `text-hero-ink/70`, `from-hero-from`, `bg-success/[0.06]`.
 *
 * Item 42 widened the vocabulary from the twelve shadcn tokens to the twenty the palettes define,
 * because a census that cannot see `hero-ink` would report the tokenised hero as untokenised.
 */
const SCT_TOKEN = '/(?<![\w:-])(?:bg|text|border|from|via|to|ring|divide|fill|stroke|shadow|caret|outline|decoration)-(?:background|foreground|card|popover|primary|secondary|muted|accent|destructive|input|ring|sidebar|success|warning|danger|info|neutral-ink|brand-tint|hero-from|hero-via|hero-to|hero-ink|hero-glow|chart-[1-5])(?:-[a-z0-9]+){0,2}(?:\/(?:\[[\d.]+\]|[\d.]+))?/';

/** @return list<string> every .vue file under a directory */
function sctFiles(string $directory): array
{
    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'vue') {
            $files[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    sort($files);

    return $files;
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
function sctStripComments(string $source): string
{
    $blank = static fn (array $match): string => str_repeat("\n", substr_count($match[0], "\n"));

    foreach (['#<!--.*?-->#s', '#(^|\n)[ \t]*//[^\n]*#', '#(^|\n)[ \t]*/\*.*?\*/#s'] as $pattern) {
        $source = (string) preg_replace_callback($pattern, $blank, $source);
    }

    return $source;
}/**
 * Raw neutral palette classes, after dropping the forms that are not defects.
 *
 * @return list<array{path: string, line: int, class: string}>
 */
function sctHits(string $directory): array
{
    $hits = [];

    foreach (sctFiles($directory) as $path) {
        $source = sctStripComments((string) file_get_contents(base_path($path)));

        foreach (explode("\n", $source) as $index => $raw) {
            // `dark:text-zinc-400` is the deliberate counterpart, never a defect.
            $line = (string) preg_replace('/dark:[\w.\/\[\]-]+/', '', $raw);

            if (! preg_match_all(SCT_SOLID, $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $position => $match) {
                $class = $matches[1][$position][0];
                $after = substr($line, $match[1] + strlen($match[0]), 8);

                // Opacity variants: bg-white/10 and bg-white/[0.07] are glass fills, by design.
                if (preg_match('/^\s*\/\s*[\[\d.]/', $after)) {
                    continue;
                }

                // A light class whose dark counterpart is on the same line is already themed.
                $property = explode('-', $class)[0];

                if (preg_match('/dark:'.$property.'-/', $raw)) {
                    continue;
                }

                $hits[] = ['path' => $path, 'line' => $index + 1, 'class' => $class];
            }
        }
    }

    return $hits;
}

/**
 * The body of one CSS rule, brace-matched.
 *
 * Reading "everything after `.dark {`" instead would silently include the rest of the stylesheet,
 * and a bare substring test for `--card` is satisfied by `--card-foreground` — a probe that deleted
 * `--card:` from the dark block left the assertion green until both were fixed.
 */
function sctCssBlock(string $css, string $selector): string
{
    $start = strpos($css, $selector.' {');

    if ($start === false) {
        $start = strpos($css, $selector.'{');
    }

    if ($start === false) {
        return '';
    }

    $open = strpos($css, '{', $start);
    $depth = 0;

    for ($i = (int) $open; $i < strlen($css); $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($css, (int) $open + 1, $i - (int) $open - 1);
            }
        }
    }

    return '';
}

test('the theme scanner actually sees the shared components', function () {
    $files = sctFiles(SCT_SHARED);

    expect($files)->not->toBeEmpty()
        ->and(count($files))->toBeGreaterThan(15, 'far fewer shared components than the ERP renders through, so the path is wrong')
        ->and($files)->toContain(SCT_SHARED.'/DataTable.vue');

    // Guards the guard: an empty hit list would make the allowlist tests pass vacuously.
    $classes = array_column(sctHits(SCT_SHARED), 'class');

    expect($classes)->not->toBeEmpty();

    // toContain() takes variadic needles, not a message, so a message goes through assert*.
    $this->assertContains(
        'text-gray-900',
        $classes,
        "the scanner stopped seeing DataTable's print header, so the allowlist tests below are vacuous"
    );
});

test('every raw palette class in a shared component has a documented reason', function () {
    $undocumented = [];

    foreach (sctHits(SCT_SHARED) as $hit) {
        $reason = SCT_ALLOWLIST[$hit['path']][$hit['class']] ?? null;

        if ($reason === null) {
            $undocumented[] = $hit['path'].':'.$hit['line'].' '.$hit['class'];
        }
    }

    $this->assertSame(
        [],
        $undocumented,
        'these shared components paint a raw neutral colour with no documented reason. Use a semantic '.
        'token (bg-card, bg-muted, text-foreground) — it is themed in both modes — or, if the colour '.
        'really must not follow the theme, add it to SCT_ALLOWLIST with the reason: '.implode(', ', $undocumented)
    );
});

test('no allowlist reason has gone stale', function () {
    $found = [];

    foreach (sctHits(SCT_SHARED) as $hit) {
        $found[$hit['path']][$hit['class']] = true;
    }

    $stale = [];

    foreach (SCT_ALLOWLIST as $path => $classes) {
        foreach (array_keys($classes) as $class) {
            if (! isset($found[$path][$class])) {
                $stale[] = $path.' '.$class;
            }
        }
    }

    $this->assertSame([], $stale, 'these allowlist entries no longer match anything and should be deleted: '.implode(', ', $stale));
});

test('the reasons in the allowlist are still true of the code', function () {
    $dataTable = (string) file_get_contents(base_path(SCT_SHARED.'/DataTable.vue'));
    $pageHeader = (string) file_get_contents(base_path(SCT_SHARED.'/PageHeader.vue'));
    $statusBadge = (string) file_get_contents(base_path(SCT_SHARED.'/StatusBadge.vue'));

    // The grays are excused *because* they are print-only; if that wrapper goes, so does the excuse.
    $this->assertStringContainsString('hidden print:block', $dataTable, 'DataTable no longer marks its sheet header print-only, so its gray palette is now a screen defect');

    // Item 42 inverted both of these. The hero was excused *because* it was a fixed navy; it now
    // paints the palette's ramp, so what has to hold is that its ink comes from the ramp too —
    // a hardcoded navy with tokenised ink is exactly the defect the ink rule describes.
    $header = sctStripComments($pageHeader);

    $this->assertStringContainsString('from-hero-from', $header, 'PageHeader no longer paints its hero from the palette ramp');
    $this->assertStringContainsString('text-hero-ink', $header, 'PageHeader no longer takes its hero ink from a token, so the ramp can invert underneath it');
    $this->assertStringNotContainsString('bg-[#', $header, 'PageHeader paints an arbitrary colour again, which no palette can reach');

    // The badge used to need six `dark:` counterparts to stay legible. It needs none, because a
    // status token is redefined per mode in themes.css — and if one comes back, the badge has
    // started making theme decisions that belong to the palette.
    $this->assertSame(0, preg_match('/dark:[\w-]+/', sctStripComments($statusBadge)), 'StatusBadge has a dark: counterpart again; the status tokens are per-mode, so it should not need one');

    foreach (['success', 'warning', 'danger', 'info', 'neutral-ink'] as $tone) {
        $this->assertSame(
            1,
            preg_match('/text-'.$tone.'(?![\w-])/', $statusBadge),
            "StatusBadge's $tone tone no longer paints from the semantic token of the same name"
        );
    }
});

test('the scanner ignores a colour that is only written about', function () {
    // The sensitivity twin for the comment stripping: without it the scanner is not merely lenient,
    // it is wrong in both directions, and a fixture is the only way to show which.
    $directory = 'storage/framework/testing/sct-scanner-fixture';

    @mkdir(base_path($directory), 0777, true);

    file_put_contents(base_path($directory.'/Commented.vue'), <<<'VUE'
<template>
  <!-- was: bg-[#0B0F1E] with text-white and dark:text-zinc-400 -->
  <div class="rounded-xl">
    <p>nothing to see</p>
  </div>
</template>
<style scoped>
/* text-slate-700 and bg-gray-100 used to live here */
</style>
VUE);

    file_put_contents(base_path($directory.'/Live.vue'), <<<'VUE'
<template>
  <div class="bg-white text-slate-700">
    <p class="border-gray-200">three live raw neutrals</p>
  </div>
</template>
VUE);

    $hits = sctHits($directory);

    // A string that contains `/*` must not open a comment: DataTable.vue sends an Accept header of
    // 'application/pdf, */*' and the pattern version of this helper swallowed the code after it.
    file_put_contents(base_path($directory.'/Strings.vue'), <<<'VUE'
<script setup lang="ts">
const headers = { Accept: 'application/octet-stream, application/pdf, */*' };
</script>
<template>
  <div class="bg-white">a live raw neutral after a string that looks like a comment</div>
</template>
VUE);

    // Written after the first scan, so it has to be scanned again — a fixture nobody reads is a
    // fixture that passes.
    $stringHits = sctHits($directory);

    $this->assertSame(
        1,
        count(array_filter($stringHits, static fn (array $hit): bool => str_ends_with($hit['path'], 'Strings.vue'))),
        'a string literal containing /* is being read as a comment, and real classes after it go uncounted'
    );

    unlink(base_path($directory.'/Strings.vue'));

    $this->assertSame(
        ['Commented.vue' => 0, 'Live.vue' => 3],
        [
            'Commented.vue' => count(array_filter($hits, static fn (array $hit): bool => str_ends_with($hit['path'], 'Commented.vue'))),
            'Live.vue' => count(array_filter($hits, static fn (array $hit): bool => str_ends_with($hit['path'], 'Live.vue'))),
        ],
        'the scanner is counting comments, or it has stopped seeing live classes'
    );

    unlink(base_path($directory.'/Commented.vue'));
    unlink(base_path($directory.'/Live.vue'));
    rmdir(base_path($directory));
});

test('the shared components are tokenised, which is why a raw colour stands out', function () {
    $tokens = 0;
    $filesUsingTokens = 0;

    foreach (sctFiles(SCT_SHARED) as $path) {
        $count = preg_match_all(SCT_TOKEN, sctStripComments((string) file_get_contents(base_path($path))));

        $tokens += (int) $count;

        if ($count > 0) {
            $filesUsingTokens++;
        }
    }

    // Measured: 264 token utilities across 21 of the 22 shared components. The count moved twice
    // without any component being repainted by hand: item 42 widened the vocabulary from the twelve
    // shadcn tokens to the twenty the palettes define, and wave 1 of the token pass rewrote four
    // components in terms of it. `SchemePicker` is the 22nd file and the one that uses no token
    // utility at all, because its swatches are inline styles carrying each palette's own hex.
    expect($tokens)->toBeGreaterThan(200, 'semantic token usage collapsed, so the components are being repainted with raw colours')
        ->and($filesUsingTokens)->toBeGreaterThan(15);
});

test('the dark theme still redefines the tokens the components rely on', function () {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // Class-based dark mode: tokenising a component only helps while this variant exists.
    $this->assertStringContainsString('@custom-variant dark', $css, 'the dark variant declaration is gone, so no dark: utility and no .dark token override applies');

    $dark = sctCssBlock($css, '.dark');

    $this->assertNotSame('', $dark, 'resources/css/app.css has no .dark rule at all, so every token stays at its light value in dark mode');

    foreach (['--background', '--foreground', '--card', '--muted', '--border', '--primary'] as $token) {
        // The colon matters: without it, --card-foreground passes for a deleted --card.
        $this->assertStringContainsString($token.':', $dark, "the .dark block no longer redefines $token, so every tokenised component breaks in dark mode");
    }
});

test('the page-level palette backlog only shrinks', function () {
    $candidates = [];

    foreach (['modules', 'resources/js'] as $root) {
        foreach (sctHits($root) as $hit) {
            if (str_contains($hit['path'], '/Components/') || str_contains($hit['path'], '/components/')) {
                continue; // shared components are governed by the allowlist above
            }

            $candidates[] = $hit['path'].':'.$hit['line'].' '.$hit['class'];
        }
    }

    expect(count($candidates))->toBeLessThanOrEqual(
        SCT_PAGE_CANDIDATES,
        'the page-level raw-palette backlog grew. These are candidates, not confirmed defects: apply the ink rule '.
        '(a hardcoded surface is a defect only when its ink comes from a token or is absent) before fixing one. '.
        'New entries: '.implode(', ', array_slice($candidates, 0, 40))
    );
});
