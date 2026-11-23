<?php

namespace Laraowl\Client\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ViewException;
use Illuminate\View\ViewServiceProvider;
use Laraowl\Client\BladeTraceMapper;
use Laraowl\Client\Clock;
use Laraowl\Client\Location;
use Laraowl\Client\SensorManager;
use Laraowl\Client\State\RequestState;
use Laraowl\Client\UserProvider;
use Livewire\Component;
use Livewire\Mechanisms\ExtendBlade\ExtendBlade;
use Livewire\Mechanisms\ExtendBlade\ExtendedCompilerEngine;
use Livewire\Volt\MountedDirectories;
use Livewire\Volt\Precompilers\ExtractTemplate;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_column;
use function array_filter;
use function array_keys;
use function array_values;
use function bin2hex;
use function class_exists;
use function dirname;
use function file;
use function json_decode;
use function random_bytes;
use function realpath;
use function str_contains;
use function strlen;
use function substr;
use function sys_get_temp_dir;

final class BladeTraceMapperTest extends TestCase
{
    private string $root;

    private Filesystem $files;

    private Application $app;

    private BladeCompiler $compiler;

    private BladeTraceMapper $mapper;

    private Container $previousContainer;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laraowl-blade-'.bin2hex(random_bytes(6));
        $this->files = new Filesystem;
        $this->files->ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'components');
        $this->root = realpath($this->root);
        $this->files->ensureDirectoryExists($this->root.DIRECTORY_SEPARATOR.'app');
        $this->files->put($this->root.DIRECTORY_SEPARATOR.'composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');
        $this->app = new Application($this->root);
        $this->app->instance('files', $this->files);
        $this->app->instance('config', new Repository([
            'view' => ['paths' => [$this->root], 'compiled' => $this->root.DIRECTORY_SEPARATOR.'custom-cache'],
        ]));
        $this->app->register(ViewServiceProvider::class);
        Facade::setFacadeApplication($this->app);
        $this->compiler = $this->app->make('blade.compiler');
        $this->mapper = new BladeTraceMapper($this->compiler);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance($this->previousContainer);
        $this->files->deleteDirectory($this->root);
    }

    public function test_it_maps_repeated_blade_expressions_and_retains_the_unknown_line_fallback(): void
    {
        $view = $this->view('repeat', "\n{{-- {{ 'ignored' }} --}}\n<div>é</div>\n{{ 'hello' }}\n{{ 'hello' }}");
        $this->compiler->compile($view);
        $compiled = $this->compiler->getCompiledPath($view);
        $lines = [];
        foreach (file($compiled) as $index => $code) {
            if (str_contains($code, "e('hello')")) {
                $lines[] = $index + 1;
            }
        }
        $this->assertCount(2, $lines);
        $this->assertSame([$view, 4], $this->mapper->map($compiled, $lines[0]));
        $this->assertSame([$view, 5], $this->mapper->map($compiled, $lines[1]));
        $this->assertSame([$view, null], $this->mapper->map($compiled, 999));
        $this->assertNull($this->mapper->map($view, 4));
    }

    public function test_it_maps_an_exception_inside_a_multiline_directive(): void
    {
        $view = $this->view('multiline', <<<'BLADE'
            <p>Before</p>
            @if(
                true &&
                (throw new \RuntimeException('multiline'))
            )
                <p>Inside</p>
            @endif
            BLADE);
        $exception = $this->renderException('multiline');
        $cause = $exception->getPrevious();

        $this->assertSame([$view, 4], $this->mapper->map($cause->getFile(), $cause->getLine()));
    }

    public function test_it_maps_nested_anonymous_component_exceptions_through_the_sensor_manager(): void
    {
        $this->view('parent', "<main>\n<x-card>\ncontent\n</x-card>\n</main>");
        $child = $this->view('components'.DIRECTORY_SEPARATOR.'card', <<<'BLADE'
            <section>
            {{ throw new \RuntimeException('component') }}
            </section>
            BLADE);
        $exception = $this->renderException('parent');
        $sensor = new SensorManager(
            new RequestState(1.0, 'trace', 'request', 'deploy', 'server', 1.0, new UserProvider(fn ($callback) => '', fn () => null, fn () => null)),
            new Clock,
            new Location($this->root, $this->root),
            true,
            false,
            [],
            [],
            $this->app['config'],
        );
        [$record, $serialize] = $sensor->exception($exception, false);
        $payload = $serialize();
        $trace = json_decode($payload['trace'], true, flags: JSON_THROW_ON_ERROR);
        $relativeChild = substr($child, strlen($this->root) + 1);

        $this->assertSame(RuntimeException::class, $record->class);
        $this->assertSame($relativeChild, $record->file);
        $this->assertSame(2, $record->line);
        $this->assertSame(3, $payload['v']);
        $this->assertSame($relativeChild, $payload['file']);
        $this->assertSame(2, $payload['line']);
        $this->assertSame([$relativeChild.':2', 'parent.blade.php:4'], array_values(array_filter(array_column($trace, 'file'), fn ($file) => str_contains($file, '.blade.php:'))));
        $this->assertSame(['file', 'source', 'code'], array_keys($trace[0]));
        $this->assertStringContainsString('throw new', $trace[0]['code'][2]);
    }

    public function test_it_maps_a_line_inside_a_php_block(): void
    {
        $view = $this->view('php-block', <<<'BLADE'
            @verbatim
            @php {{ left alone }} @endphp
            @endverbatim
            @php
                throw new \RuntimeException('php block');
            @endphp
            BLADE);
        $cause = $this->renderException('php-block')->getPrevious();

        $this->assertSame([$view, 5], $this->mapper->map($cause->getFile(), $cause->getLine()));
    }

    public function test_it_maps_a_real_livewire_engine_frame_when_livewire_is_installed(): void
    {
        if (! class_exists(ExtendedCompilerEngine::class)) {
            $this->markTestSkipped('Install Livewire 3 to exercise its Blade engine.');
        }

        $view = $this->view('livewire', <<<'BLADE'
            <div wire:click="save">
                {{ throw new \RuntimeException('livewire') }}
            </div>
            BLADE);
        $engine = new ExtendedCompilerEngine($this->compiler, $this->files);
        $extendBlade = new ExtendBlade;
        $extendBlade->startLivewireRendering(new class extends Component {});

        try {
            $engine->get($view);
            $this->fail('Expected the Livewire Blade engine to throw.');
        } catch (ViewException $exception) {
            $cause = $exception->getPrevious();
            $this->assertSame([$view, 2], $this->mapper->map($cause->getFile(), $cause->getLine()));
        } finally {
            $extendBlade->endLivewireRendering();
        }
    }

    public function test_it_maps_a_volt_template_through_the_real_volt_precompiler(): void
    {
        if (! class_exists(ExtractTemplate::class)) {
            $this->markTestSkipped('Install Volt to exercise its Blade template precompiler.');
        }

        $mounts = new MountedDirectories;
        $mounts->mount($this->root);
        $this->compiler->prepareStringsForCompilationUsing(new ExtractTemplate($mounts));
        $view = $this->view('volt', <<<'BLADE'
            <?php
            use function Livewire\Volt\{state};

            state(['count' => 0]);
            ?>

            <div>
                {{ throw new \RuntimeException('volt') }}
            </div>
            BLADE);
        $exception = $this->renderException('volt');
        $cause = $exception->getPrevious();
        $this->compiler->setPath(null);

        $this->assertSame([$view, 8], $this->mapper->map($cause->getFile(), $cause->getLine()));
        $this->assertNull($this->compiler->getPath());
    }

    public function test_it_keeps_the_view_path_without_a_line_for_stale_compiled_php(): void
    {
        $view = $this->view('stale', "{{ 'old' }}");
        $this->compiler->compile($view);
        $compiled = $this->compiler->getCompiledPath($view);
        $this->assertSame([$view, 1], $this->mapper->map($compiled, 1));

        $this->view('stale', "\n{{ 'new' }}");
        $this->mapper->reset();
        $this->assertSame([$view, null], $this->mapper->map($compiled, 1));

        $otherFile = dirname($compiled).DIRECTORY_SEPARATOR.'ab12.php';
        $this->files->copy($compiled, $otherFile);
        $this->assertNull($this->mapper->map($otherFile, 1));
    }

    public function test_it_keeps_the_view_path_when_a_compiler_extension_throws(): void
    {
        $view = $this->view('extension', "{{ 'hello' }}");
        $this->compiler->compile($view);
        $this->compiler->setPath(null);
        $this->compiler->precompiler(static fn () => throw new RuntimeException('compiler extension'));

        $this->assertSame([$view, null], $this->mapper->map($this->compiler->getCompiledPath($view), 1));
        $this->assertNull($this->compiler->getPath());
    }

    private function view(string $name, string $source): string
    {
        $file = $this->root.DIRECTORY_SEPARATOR.$name.'.blade.php';
        $this->files->put($file, $source);

        return $file;
    }

    private function renderException(string $name): ViewException
    {
        try {
            $this->app['view']->make($name)->render();
        } catch (ViewException $exception) {
            return $exception;
        }

        $this->fail('Expected the Blade view to throw.');
    }
}
