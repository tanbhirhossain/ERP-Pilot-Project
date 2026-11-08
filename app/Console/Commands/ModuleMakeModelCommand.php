<?php

namespace App\Console\Commands;

use App\Support\Modules\ModuleGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ModuleMakeModelCommand extends Command
{
    protected $signature = 'module:make-model {module : The module name} {name : The model name} {--migration : Create a migration} {--factory : Create a factory} {--seeder : Create a seeder} {--all : Create migration, factory, and seeder} {--force : Overwrite existing files}';

    protected $description = 'Create a model inside a module';

    public function handle(ModuleGenerator $generator): int
    {
        $module = (string) $this->argument('module');
        $class = $generator->normalizeClass((string) $this->argument('name'));
        $force = (bool) $this->option('force');

        if (! $generator->moduleExists($module)) {
            $this->error($generator->missingModuleMessage($module));

            return self::FAILURE;
        }

        $files = [
            $generator->modulePath($module, "Models/{$class}.php") => $generator->modelStub($module, $class),
        ];

        if ($this->option('migration') || $this->option('all')) {
            $table = Str::snake(Str::pluralStudly($class));
            $timestamp = now()->format('Y_m_d_His');
            $files[$generator->modulePath($module, "Database/Migrations/{$timestamp}_create_{$table}_table.php")] = $generator->migrationStub($table);
        }

        if ($this->option('factory') || $this->option('all')) {
            $files[$generator->modulePath($module, "Database/Factories/{$class}Factory.php")] = $generator->factoryStub($module, $class);
        }

        if ($this->option('seeder') || $this->option('all')) {
            $files[$generator->modulePath($module, "Database/Seeders/{$class}Seeder.php")] = $generator->seederStub($module, "{$class}Seeder");
        }

        foreach ($files as $path => $contents) {
            $created = $generator->writeFile($path, $contents, $force);
            $this->line(($created ? '<info>CREATED</info> ' : '<comment>EXISTS</comment>  ').$path);
        }

        return self::SUCCESS;
    }
}
