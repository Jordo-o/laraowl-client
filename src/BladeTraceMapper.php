<?php

namespace Laraowl\Client;

use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/**
 * Resolve compiled Blade stack frames back to their original view.
 *
 * Line numbers are only returned when an exact, unambiguous compiled-line
 * match can be established. An incorrect Blade line is worse than no line.
 *
 * @internal
 */
final class BladeTraceMapper
{
    /** @var array<string, array{file: string, lines: array<int, int> }|null> */
    private array $cache = [];

    public function __construct(private BladeCompiler $compiler)
    {
    }

    /** @return array{string, int|null}|null */
    public function map(string $file, ?int $line): ?array
    {
        if (! str_ends_with($file, '.php') || ! is_file($file)) {
            return null;
        }

        $compiledPath = str_replace('\\', '/', $file);
        if (! str_contains($compiledPath, '/storage/framework/views/')) {
            return null;
        }

        if (! array_key_exists($file, $this->cache)) {
            $this->cache[$file] = $this->buildMap($file);
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
            $compiled = file_get_contents($compiledFile);
            if ($compiled === false || ! preg_match('~/\*\*PATH (.*?) ENDPATH\*\*/~s', $compiled, $matches)) {
                return null;
            }

            $sourceFile = $matches[1];
            if (! is_file($sourceFile) || ! str_ends_with($sourceFile, '.blade.php')) {
                return null;
            }

            $source = file_get_contents($sourceFile);
            if ($source === false) {
                return null;
            }

            $compiledLines = preg_split('/\r\n|\n|\r/', $compiled);
            $sourceLines = preg_split('/\r\n|\n|\r/', $source);
            if ($compiledLines === false || $sourceLines === false) {
                return null;
            }

            // A unique, identical generated line is a safe source anchor.
            // This handles plain Blade markup and single-line expressions.
            $candidates = [];
            foreach ($sourceLines as $index => $sourceLine) {
                if (trim($sourceLine) === '') {
                    continue;
                }

                $generated = $this->compiler->compileString($sourceLine);
                if (str_contains($generated, "\n") || trim($generated) === '') {
                    continue;
                }

                $candidates[$generated][] = $index + 1;
            }

            $compiledOccurrences = array_count_values($compiledLines);
            $lines = [];
            foreach ($compiledLines as $index => $compiledLine) {
                if (($compiledOccurrences[$compiledLine] ?? 0) !== 1) {
                    continue;
                }

                $sourceMatches = $candidates[$compiledLine] ?? [];
                if (count($sourceMatches) === 1) {
                    $lines[$index + 1] = $sourceMatches[0];
                }
            }

            return ['file' => $sourceFile, 'lines' => $lines];
        } catch (Throwable) {
            return null;
        }
    }
}
