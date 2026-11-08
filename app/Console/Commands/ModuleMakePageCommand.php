<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ModuleMakePageCommand extends Command
{
    protected $signature = 'module:make-page {module : The module name} {name : The page path/name} {--force : Overwrite existing file}';

    protected $description = 'Create an Inertia Vue page inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $name = str_replace('\\', '/', (string) $this->argument('name'));
        $page = Str::of($name)->explode('/')->map(fn (string $part): string => Str::studly($part))->implode('/');
        $title = Str::of($page)->afterLast('/')->headline()->toString();

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $path = $generator->modulePath($module, "Resources/js/Pages/{$page}.vue");
        $created = $generator->writeFile($path, $generator->pageStub($title), (bool) $this->option('force'));

        $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);

        return self::SUCCESS;
    }
}
