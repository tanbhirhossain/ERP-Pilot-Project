<?php

namespace Modules\CORE\Console\Commands;

use Illuminate\Console\Command;
use Modules\CORE\Support\Api\ApiSpecBuilder;

/**
 * F13 — write (or verify) `docs/openapi.json` from the routes that exist.
 *
 * `--check` is the CI guard: it re-derives the spec and compares bytes, so adding an
 * endpoint without `#[ApiEndpoint]`, or adding it with one and forgetting to commit the
 * regenerated file, both fail. A docs-only convention rots within a release; a
 * comparison does not.
 *
 * Output is deterministic — paths and properties sorted, no timestamps — because a
 * generated file that churns on unrelated edits stops being reviewable.
 */
class GenerateApiSpecCommand extends Command
{
    protected $signature = 'api:spec
                            {--out= : Where to write the document (default docs/openapi.json)}
                            {--check : Exit non-zero if the committed file differs from the generated spec}
                            {--json : Print the document instead of touching the filesystem}';

    protected $description = 'Generate or verify the OpenAPI 3.1 document for the v1 API';

    public function handle(ApiSpecBuilder $spec): int
    {
        $json = $spec->toJson();
        $path = $this->option('out') ?: base_path(ApiSpecBuilder::SPEC_PATH);

        if ($this->option('json')) {
            $this->output->write($json);

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            if (! is_file($path)) {
                $this->error('No committed spec at '.ApiSpecBuilder::SPEC_PATH.'. Run: php artisan api:spec');

                return self::FAILURE;
            }

            if (file_get_contents($path) !== $json) {
                $this->error('docs/openapi.json is out of date. Run: php artisan api:spec');

                return self::FAILURE;
            }

            $this->info('Spec matches the routes ('.count(json_decode($json, true)['paths']).' documented v1 paths).');

            return self::SUCCESS;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o755, true);
        }

        file_put_contents($path, $json);
        $this->line('Wrote <info>'.ApiSpecBuilder::SPEC_PATH.'</info> — '.count(json_decode($json, true)['paths']).' paths.');

        return self::SUCCESS;
    }
}
