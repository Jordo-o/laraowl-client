<?php

namespace Laraowl\Client;

use Illuminate\View\Compilers\BladeCompiler;

use function array_key_first;
use function count;
use function explode;
use function hash;
use function implode;
use function preg_match_all;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function str_contains;
use function str_starts_with;
use function substr_count;
use function trim;

/** @internal */
final class BladeSourceMapCompiler
{
    private const CONSTRUCTS = <<<'REGEX'
        ~
          \{\{-- .*? --\}\}
        | (?<!@) @verbatim \b .*? @endverbatim \b
        | (?<php> (?<!@) @php \b (?! [ \t]* \( ) .*? @endphp \b | <\?php \s .*? (?: \?> | \z ) )
        | (?<tag> < (?: /? x[-:] | livewire: ) [\w.:-]+ \b (?: "[^"]*" | '[^']*' | [^"'>] )* > )
        | (?<expression> @? (?: \{\{\{ .*? \}\}\} | \{\{ .*? \}\} | \{!! .*? !!\} )
            | \B (?<!@) @ \w+ (?: :: \w+ )? (?: [ \t]* (?<arguments> \( (?: [^()]++ | (?&arguments) )* \) ) )? )
        ~sx
        REGEX;

    public function __construct(private BladeCompiler $compiler) {}

    /** @return array<int, int> */
    public function compile(string $file, string $source, string $compiled): array
    {
        // Adapted from Spatie's BladeSourceMapCompiler and Laravel's BladeMapper.
        // See LICENSE-BLADE-SOURCEMAPS.md for the upstream sources and notices.
        $prefix = '|---LARAOWL:'.hash('sha256', $source).':';
        $marker = static fn (int $line, string $kind = 'L'): string => $prefix.$kind.$line.'---|';
        $pattern = '/'.preg_quote($prefix, '/').'([LSE])([0-9]+)---\|/';

        // Some directives trim their arguments. Retry with only opening markers
        // when markers inside an expression change the generated PHP.
        foreach ([true, false] as $markContinuations) {
            $marked = $this->mark($source, $marker, $markContinuations);
            if ($marked === null) {
                return [];
            }

            $generated = $this->compileString($file, $marked);
            $unmarked = preg_replace($pattern, '', $generated);

            // Laravel closes an open PHP block before appending its path footer.
            if ($unmarked !== null && ($compiled === $unmarked || $compiled === $unmarked.' ?>')) {
                return $this->lineMap($pattern, $generated);
            }
        }

        return [];
    }

    /** @param callable(int, string=): string $marker */
    private function mark(string $source, callable $marker, bool $markContinuations): ?string
    {
        $line = 1;
        $previousOffset = 0;

        return preg_replace_callback(self::CONSTRUCTS, static function (array $matches) use ($source, $marker, $markContinuations, &$line, &$previousOffset): string {
            /** @var array<array-key, array{string, int}|array{null, -1}> $matches */
            [$text, $offset] = $matches[0];
            if ($text === null) {
                return '';
            }

            $line += substr_count($source, "\n", $previousOffset, $offset - $previousOffset);
            $previousOffset = $offset;

            if (($matches['tag'][1] ?? -1) >= 0) {
                return $marker($line, 'S').$text.$marker($line, 'E');
            }

            $isPhp = ($matches['php'][1] ?? -1) >= 0;
            if (! $isPhp && (($matches['expression'][1] ?? -1) < 0 || str_starts_with($text, '@{'))) {
                return $text;
            }

            $start = $isPhp ? '' : $marker($line);
            if (! $markContinuations || ! str_contains($text, "\n")) {
                return $start.$text;
            }

            $lines = explode("\n", $text);
            foreach ($lines as $index => $part) {
                if ($index > 0 && trim($part) !== '') {
                    $lines[$index] = $marker($line + $index).$part;
                }
            }

            return $start.implode("\n", $lines);
        }, $source, flags: PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
    }

    private function compileString(string $file, string $source): string
    {
        // Keep mutable compilation state off the shared compiler. Volt also
        // reads the source path from the shared compiler through the facade.
        $compiler = clone $this->compiler;
        $previousPath = $this->compiler->getPath();

        try {
            $compiler->setPath($file);
            $this->compiler->setPath($file);

            return $compiler->compileString($source);
        } finally {
            $this->compiler->setPath($previousPath);
        }
    }

    /** @return array<int, int> */
    private function lineMap(string $pattern, string $generated): array
    {
        // Component tags can expand to several PHP lines. Only fill the span
        // when both boundaries survived compilation.
        $candidates = [];
        $componentStart = null;
        foreach (explode("\n", $generated) as $index => $code) {
            $compiledLine = $index + 1;
            preg_match_all($pattern, $code, $matches, PREG_SET_ORDER);
            foreach ($matches as [, $kind, $sourceLine]) {
                $line = (int) $sourceLine;
                $candidates[$compiledLine][$line] = true;
                if ($kind === 'S') {
                    $componentStart = [$line, $compiledLine];
                } elseif ($kind === 'E' && $componentStart !== null && $componentStart[0] === $line) {
                    for ($inside = $componentStart[1] + 1; $inside < $compiledLine; $inside++) {
                        $candidates[$inside][$line] = true;
                    }

                    $componentStart = null;
                }
            }
        }

        $map = [];
        foreach ($candidates as $compiledLine => $sourceLines) {
            if (count($sourceLines) === 1) {
                $map[$compiledLine] = array_key_first($sourceLines);
            }
        }

        return $map;
    }
}
