<?php

namespace App\Support\Modules;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class ModuleGenerator
{
    public function __construct(
        private readonly Filesystem $files
    ) {}

    public function normalizeModule(string $module): string
    {
        return Str::studly($module);
    }

    public function normalizeClass(string $name, string $suffix = ''): string
    {
        $class = Str::studly(str_replace(['/', '\\'], ' ', $name));

        if ($suffix !== '' && ! Str::endsWith($class, $suffix)) {
            return $class.$suffix;
        }

        return $class;
    }

    public function modulePath(string $module, string $path = ''): string
    {
        $path = trim($path, '/\\');

        return base_path('modules/'.$this->normalizeModule($module).($path !== '' ? '/'.$path : ''));
    }

    public function namespace(string $module, string $namespace = ''): string
    {
        $namespace = trim($namespace, '\\');

        return 'Modules\\'.$this->normalizeModule($module).($namespace !== '' ? '\\'.$namespace : '');
    }

    public function moduleExists(string $module): bool
    {
        return $this->files->isDirectory($this->modulePath($module));
    }

    public function missingModuleMessage(string $module): string
    {
        return "Module [{$this->normalizeModule($module)}] does not exist. Run php artisan make:module {$this->normalizeModule($module)} first.";
    }

    /**
     * @param  array<int, string>  $directories
     */
    public function createDirectories(string $module, array $directories): void
    {
        foreach ($directories as $directory) {
            $path = $this->modulePath($module, $directory);

            if (! $this->files->isDirectory($path)) {
                $this->files->makeDirectory($path, 0755, true);
            }
        }
    }

    public function writeFile(string $path, string $contents, bool $force = false): bool
    {
        if ($this->files->exists($path) && ! $force) {
            return false;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, $contents);

        return true;
    }

    public function providerStub(string $module): string
    {
        $module = $this->normalizeModule($module);
        $viewNamespace = Str::lower($module);

        return <<<PHP
        <?php

        namespace Modules\\{$module}\\Providers;

        use Illuminate\Support\ServiceProvider;

        class {$module}ServiceProvider extends ServiceProvider
        {
            public function register(): void
            {
                //
            }

            public function boot(): void
            {
                \$this->loadViewsFrom(__DIR__.'/../Resources/views', '{$viewNamespace}');
                \$this->loadRoutesFrom(__DIR__.'/../Routes/web.php');

                if (file_exists(__DIR__.'/../Routes/api.php')) {
                    \$this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
                }

                \$this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
            }
        }
        PHP;
    }

    public function webRoutesStub(string $module): string
    {
        return <<<PHP
        <?php

        use Illuminate\Support\Facades\Route;

        Route::middleware(['web', 'auth'])->group(function (): void {
            //
        });
        PHP;
    }

    public function apiRoutesStub(): string
    {
        return <<<PHP
        <?php

        use Illuminate\Support\Facades\Route;

        Route::middleware(['api'])->prefix('api')->group(function (): void {
            //
        });
        PHP;
    }

    public function controllerStub(string $module, string $class, bool $resource = false): string
    {
        $namespace = $this->namespace($module, 'Http\\Controllers');

        if (! $resource) {
            return <<<PHP
            <?php

            namespace {$namespace};

            use App\Http\Controllers\Controller;

            class {$class} extends Controller
            {
                //
            }
            PHP;
        }

        return <<<PHP
        <?php

        namespace {$namespace};

        use App\Http\Controllers\Controller;
        use Illuminate\Http\RedirectResponse;
        use Illuminate\Http\Request;
        use Inertia\Response;

        class {$class} extends Controller
        {
            public function index(Request \$request): Response
            {
                //
            }

            public function store(Request \$request): RedirectResponse
            {
                //
            }

            public function update(Request \$request, int \$id): RedirectResponse
            {
                //
            }

            public function destroy(Request \$request, int \$id): RedirectResponse
            {
                //
            }
        }
        PHP;
    }

    public function modelStub(string $module, string $class): string
    {
        $namespace = $this->namespace($module, 'Models');

        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Illuminate\Database\Eloquent\Model;

        class {$class} extends Model
        {
            use HasFactory;

            protected \$guarded = [];
        }
        PHP;
    }

    public function requestStub(string $module, string $class): string
    {
        $namespace = $this->namespace($module, 'Requests');

        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Foundation\Http\FormRequest;

        class {$class} extends FormRequest
        {
            public function authorize(): bool
            {
                return true;
            }

            /**
             * @return array<string, mixed>
             */
            public function rules(): array
            {
                return [
                    //
                ];
            }
        }
        PHP;
    }

    public function serviceStub(string $module, string $class): string
    {
        $namespace = $this->namespace($module, 'Services');

        return <<<PHP
        <?php

        namespace {$namespace};

        class {$class}
        {
            //
        }
        PHP;
    }

    public function repositoryInterfaceStub(string $module, string $class): string
    {
        $namespace = $this->namespace($module, 'Interfaces');

        return <<<PHP
        <?php

        namespace {$namespace};

        interface {$class}
        {
            //
        }
        PHP;
    }

    public function repositoryStub(string $module, string $class, string $interface): string
    {
        $namespace = $this->namespace($module, 'Repositories');
        $interfaceNamespace = $this->namespace($module, 'Interfaces\\'.$interface);

        return <<<PHP
        <?php

        namespace {$namespace};

        use {$interfaceNamespace};

        class {$class} implements {$interface}
        {
            //
        }
        PHP;
    }

    public function pageStub(string $title): string
    {
        return <<<VUE
        <script setup lang="ts">
        import AppLayout from '@/layouts/AppLayout.vue';
        import { type BreadcrumbItem } from '@/types';
        import { Head } from '@inertiajs/vue3';

        const breadcrumbs: BreadcrumbItem[] = [
            {
                title: '{$title}',
                href: '#',
            },
        ];
        </script>

        <template>
            <AppLayout :breadcrumbs="breadcrumbs">
                <Head title="{$title}" />

                <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
                    <h1 class="text-2xl font-semibold tracking-normal text-foreground">{$title}</h1>
                </div>
            </AppLayout>
        </template>
        VUE;
    }

    public function migrationStub(string $table): string
    {
        return <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$table): void {
                    \$table->id();
                    \$table->timestamps();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;
    }

    public function factoryStub(string $module, string $model): string
    {
        $namespace = $this->namespace($module, 'Database\\Factories');
        $modelNamespace = $this->namespace($module, 'Models\\'.$model);

        return <<<PHP
        <?php

        namespace {$namespace};

        use {$modelNamespace};
        use Illuminate\Database\Eloquent\Factories\Factory;

        /**
         * @extends Factory<{$model}>
         */
        class {$model}Factory extends Factory
        {
            protected \$model = {$model}::class;

            public function definition(): array
            {
                return [
                    //
                ];
            }
        }
        PHP;
    }

    public function seederStub(string $module, string $class): string
    {
        $namespace = $this->namespace($module, 'Database\\Seeders');

        return <<<PHP
        <?php

        namespace {$namespace};

        use Illuminate\Database\Seeder;

        class {$class} extends Seeder
        {
            public function run(): void
            {
                //
            }
        }
        PHP;
    }
}
