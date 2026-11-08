import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Guard against silently-unresolvable components.
 *
 * If a template tag has no matching import, Vue renders it as an unknown HTML
 * element and only warns in *development* builds. In production that element
 * is inert — no events, no styles, no behaviour. That is exactly what made
 * `<Sidebar>` (collapsing) and `<DropdownMenuItem>` (Excel/PDF export) appear
 * on screen yet do nothing. This test fails the build instead.
 */
const ROOTS = ['resources/js', 'modules/CORE/Resources/js'];

const BUILTINS = new Set(['Transition', 'TransitionGroup', 'KeepAlive', 'Teleport', 'Suspense', 'Component']);

/** Registered globally by Inertia / the app shell, so no local import needed. */
const GLOBALS = new Set(['Head', 'Link']);

/**
 * Known upstream gaps that live outside the CORE module and are not used by
 * any CORE page. Listed so the suite stays green while documenting the debt.
 */
const ALLOWLIST = new Set(['resources/js/components/AppHeader.vue::<Breadcrumbs>']);

function collectVueFiles(dir: string, out: string[] = []): string[] {
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) collectVueFiles(full, out);
        else if (entry.name.endsWith('.vue')) out.push(full);
    }

    return out;
}

/** Identifiers available to the template from `<script setup>`. */
function scriptScope(script: string): Set<string> {
    const scope = new Set<string>();

    for (const m of script.matchAll(/import\s+(?:([\w$]+)\s*,\s*)?(?:\{([^}]*)\})?\s*(?:([\w$]+)\s*)?from/g)) {
        if (m[1]) scope.add(m[1]);
        if (m[3]) scope.add(m[3]);

        (m[2] ?? '')
            .split(',')
            .map((part) =>
                part
                    .trim()
                    .split(/\s+as\s+/)
                    .pop()
                    ?.trim(),
            )
            .filter((name): name is string => Boolean(name))
            .forEach((name) => scope.add(name));
    }

    for (const m of script.matchAll(/(?:const|let|var|function|class)\s+([A-Z][\w$]*)/g)) scope.add(m[1]);

    return scope;
}

function unresolvedTags(file: string): string[] {
    const src = readFileSync(file, 'utf8');
    const script = src.match(/<script[^>]*>([\s\S]*?)<\/script>/)?.[1] ?? '';
    const template = src.match(/<template>([\s\S]*)<\/template>/)?.[1] ?? '';
    if (!template) return [];

    const scope = scriptScope(script);
    const tags = new Set([...template.matchAll(/<([A-Za-z][\w.-]*)/g)].map((m) => m[1]));
    const problems: string[] = [];

    for (const tag of tags) {
        const pascal = tag.replace(/(^|-)(\w)/g, (_all, _sep, char: string) => char.toUpperCase());
        const isComponent = /^[A-Z]/.test(tag) || /^[a-z]+-[a-z]/.test(tag);

        if (!isComponent || BUILTINS.has(pascal) || GLOBALS.has(pascal)) continue;
        if (scope.has(tag) || scope.has(pascal)) continue;
        if (ALLOWLIST.has(`${file}::<${tag}>`)) continue;

        problems.push(tag);
    }

    return problems;
}

describe('component resolution', () => {
    const files = ROOTS.flatMap((root) => collectVueFiles(root));

    it('scans a meaningful number of components', () => {
        expect(files.length).toBeGreaterThan(20);
    });

    it.each(files)('%s resolves every component it renders', (file) => {
        expect(unresolvedTags(file)).toEqual([]);
    });
});
