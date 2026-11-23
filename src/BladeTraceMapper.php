<?php

namespace Laraowl\Client;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

use function array_key_exists;
use function file_get_contents;
use function is_file;
use function preg_match;
use function realpath;
use function str_ends_with;
use function str_replace;
use function substr;

/** @internal */
final class BladeTraceMapper
{
    /** @var array<string, array{file: string, lines: array<int, int>}|null> */
    private array $cache = [];

    private bool $building = false;

    private BladeSourceMapCompiler $sourceMapCompiler;

    public function __construct(private BladeCompiler $compiler)
    {
        $this->sourceMapCompiler = new BladeSourceMapCompiler($compiler);
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /** @return array{string, int|null}|null */
    public function map(string $file, ?int $line): ?array
    {
        // The default Blade compiler uses a hash, even with a custom cache path.
        if ($this->building || ! preg_match('~(?:^|[/\\\\])[0-9a-f]+\.[^/\\\\]+$~iD', $file)) {
            return null;
        }

        if (! array_key_exists($file, $this->cache)) {
            $this->building = true;
            try {
                $this->cache[$file] = $this->buildMap($file);
            } finally {
                $this->building = false;
            }
        }

        $map = $this->cache[$file];

        if ($map === null) {
            return null;
        }

        return [$map['file'], $line !== null ? ($map['lines'][$line] ?? null) : null];
    }

    /** @return array{file: string, lines: array<int, int>}|null */
    private function buildMap(string $compiledFile): ?array
    {
        try {
            $compiled = $this->readFile($compiledFile);
            if ($compiled === false || ! preg_match('~<\?php /\*\*PATH ([^\r\n]+) ENDPATH\*\*/ \?>\s*\z~', $compiled, $matches, PREG_OFFSET_CAPTURE)) {
                return null;
            }

            $sourceFile = $matches[1][0];
            $compiledPath = realpath($compiledFile);
            if ($compiledPath === false || realpath($this->compiler->getCompiledPath($sourceFile)) !== $compiledPath
                || ! str_ends_with($sourceFile, '.blade.php') || ! is_file($sourceFile)) {
                return null;
            }

            $lines = [];
            $source = $this->readFile($sourceFile);
            if ($source !== false) {
                try {
                    $lines = $this->sourceMapCompiler->compile($sourceFile, $source, substr($compiled, 0, $matches[0][1]));
                } catch (Throwable) {
                    // An exception in a Blade extension must not interrupt reporting.
                }
            }

            return ['file' => str_replace('/', DIRECTORY_SEPARATOR, $sourceFile), 'lines' => $lines];
        } catch (Throwable) {
            return null;
        }
    }

    private function readFile(string $file): string|false
    {
        return is_file($file) ? @file_get_contents($file) : false;
    }
}
