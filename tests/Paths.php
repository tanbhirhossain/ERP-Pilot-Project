<?php

namespace Tests;

/**
 * Path helpers for tests that walk the repository.
 *
 * Every scanner in this suite used to build a repo-relative path with
 * `str_replace(base_path().'/', '', $file->getPathname())`. That is correct on
 * Linux, where `base_path()` returns `/srv/app` and `getPathname()` returns
 * `/srv/app/modules/...`, but it silently produces nothing on Windows:
 * `base_path()` is `C:\sites\app` while `getPathname()` is
 * `C:\sites\app\modules\...`, so the trailing `/` never matches, the prefix is
 * left in place, and every caller is handed an absolute Windows path where it
 * expected `modules/CORE/Resources/js/Components/DataTable.vue`.
 *
 * The damage was not noisy — it was silent and total. `file_get_contents()` on
 * a doubled path throws, so "the scanner sees the front-end tree" read as an
 * empty array, `toContain('modules/.../AdminLayout.vue')` failed, and the
 * inventory compared backslash keys against a forward-slash allowlist.
 *
 * `relativePath()` normalises both sides to forward slashes before making them
 * relative, so the value is identical on Linux, macOS and Windows and the
 * assertions that spell paths with `/` keep working everywhere.
 */
final class Paths
{
    /** Repository root, with platform separators normalised. */
    public static function root(): string
    {
        return self::normalise(base_path());
    }

    /**
     * The repository-relative form of an absolute path, always forward-slashed.
     *
     * `C:\sites\app\modules\CORE\x.vue` -> `modules/CORE/x.vue`
     * `/srv/app/modules/CORE/x.vue`      -> `modules/CORE/x.vue`
     */
    public static function relativePath(string $absolute): string
    {
        $path = self::normalise($absolute);
        $root = self::root();

        if ($path === $root) {
            return '';
        }

        if (str_starts_with($path, $root.'/')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /** An absolute path with `/` separators, safe to compare and to assert on. */
    public static function normalise(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, "/\\"));
    }
}
