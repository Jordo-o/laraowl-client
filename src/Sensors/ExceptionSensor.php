<?php

namespace Laraowl\Client\Sensors;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\View\ViewException;
use Laraowl\Client\BladeTraceMapper;
use Laraowl\Client\Clock;
use Laraowl\Client\Facades\LaraowlClient;
use Laraowl\Client\Location;
use Laraowl\Client\Records\Exception;
use Laraowl\Client\State\CommandState;
use Laraowl\Client\State\RequestState;
use Laraowl\Client\Types\Str;
use Spatie\LaravelIgnition\Exceptions\ViewException as IgnitionViewException;
use SplFileObject;
use stdClass;
use Throwable;

use function array_is_list;
use function array_keys;
use function array_map;
use function count;
use function debug_backtrace;
use function gettype;
use function hash;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function max;
use function rtrim;

/**
 * @internal
 */
final class ExceptionSensor
{
    /**
     * @var array<string, SplFileObject|null>
     */
    private array $fileObjects = [];

    private int $capturedCodeFrames = 0;

    public function __construct(
        private RequestState|CommandState $executionState,
        private Clock $clock,
        private Location $location,
        private bool $captureSourceCode,
        private ?BladeTraceMapper $bladeTraceMapper = null,
    ) {
        //
    }

    /**
     * @return array{0: Exception, 1: callable(): array<mixed>}
     */
    public function __invoke(Throwable $e, ?bool $handled): array
    {
        $nowMicrotime = $this->clock->microtime();
        $this->bladeTraceMapper?->reset();
        $normalizedException = $e;
        while (($previous = $normalizedException->getPrevious()) !== null
            && ($normalizedException instanceof ViewException || $normalizedException instanceof IgnitionViewException)) {
            $normalizedException = $previous;
        }

        [$file, $line] = $this->exceptionLocation($e, $normalizedException);

        $handled ??= $this->wasManuallyReported($normalizedException);

        if (! $handled) {
            $this->executionState->exceptionPreview = $normalizedException->getMessage();
        }

        return [
            $record = new Exception(
                class: $normalizedException::class,
                message: $normalizedException->getMessage(),
                code: $normalizedException->getCode(),
                file: $file,
                line: $line,
                handled: $handled,
            ),
            function () use ($nowMicrotime, $record, $normalizedException) {
                $this->executionState->exceptions++;

                return [
                    'v' => 3,
                    't' => 'exception',
                    'timestamp' => $nowMicrotime,
                    'deploy' => $this->executionState->deploy,
                    'server' => $this->executionState->server,
                    '_group' => hash('xxh128', $record->class.','.$record->code.','.$record->file.','.$record->line),
                    'trace_id' => $this->executionState->trace,
                    'execution_source' => $this->executionState->source,
                    'execution_id' => $this->executionState->id(),
                    'execution_preview' => $this->executionState->executionPreview(),
                    'execution_stage' => $this->executionState->stage,
                    'user' => $this->executionState->user->id(),
                    'class' => Str::text($record->class),
                    'file' => Str::text($record->file),
                    'line' => $record->line ?? 0,
                    'message' => Str::text($record->message),
                    'code' => Str::text((string) $record->code),
                    'trace' => Str::mediumText($this->serializeTrace($normalizedException)),
                    'handled' => $record->handled,
                    'php_version' => $this->executionState->phpVersion,
                    'laravel_version' => $this->executionState->laravelVersion,
                ];
            },
        ];
    }

    /** @return array{string, int|null} */
    private function exceptionLocation(Throwable $exception, Throwable $cause): array
    {
        $mapped = $this->bladeTraceMapper?->map($cause->getFile(), $cause->getLine());

        // When view code calls into PHP, the original exception location may be
        // outside Blade. For a view wrapper, look for its innermost view frame.
        if ($mapped === null && $cause !== $exception) {
            foreach ($cause->getTrace() as $frame) {
                if (isset($frame['file']) && ($mapped = $this->bladeTraceMapper?->map($frame['file'], $frame['line'] ?? null)) !== null) {
                    break;
                }
            }
        }

        if ($mapped === null) {
            return $this->location->forException($exception);
        }

        return [$this->location->normalizeFile($mapped[0]), $mapped[1]];
    }

    private function wasManuallyReported(Throwable $e): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, limit: 20) as $frame) {
            if ($frame['function'] === 'report' && ! isset($frame['type'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @see https://github.com/php/php-src/blob/f17c2203883ddf53adfcb33d85523d11429729ab/Zend/zend_exceptions.c
     */
    private function serializeTrace(Throwable $e): string
    {
        [$file, $line] = $this->mapBladeLocation($e->getFile(), $e->getLine());
        $trace = [
            // Insert the exception location as the first frame.
            // This matches the behavior of Symfony's exception renderer.
            [
                'file' => $this->formatLocation($file, $line),
                'source' => '',
                'code' => $this->fetchSourceCode($file, $line),
            ],
        ];

        foreach ($e->getTrace() as $i => $frame) {
            if ($i < 2 && ($frame['class'] ?? '') === HandleExceptions::class) {
                // Skip internal frames when a PHP error has been converted to an ErrorException
                // This matches the behavior of Laravel's exception renderer.
                continue;
            }

            $file = $frame['file'] ?? null;
            $line = $frame['line'] ?? null;
            if ($file !== null) {
                [$file, $line] = $this->mapBladeLocation($file, $line);
            }

            $source = '';

            if (isset($frame['class']) && is_string($frame['class'])) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
                $source .= $frame['class'];
            }

            if (isset($frame['type']) && is_string($frame['type'])) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
                $source .= $frame['type'];
            }

            if (isset($frame['function']) && is_string($frame['function'])) { // @phpstan-ignore booleanAnd.rightAlwaysTrue, isset.offset
                $source .= $frame['function'];
            }

            $source .= '(';

            if (isset($frame['args']) && is_array($frame['args']) && count($frame['args']) > 0) { // @phpstan-ignore booleanAnd.rightAlwaysTrue
                $args = array_map(static fn ($argument) => match (gettype($argument)) {
                    'NULL' => 'null',
                    'boolean' => 'bool',
                    'integer' => 'int',
                    'double' => 'float',
                    'array' => 'array',
                    'object' => $argument::class,
                    'resource' => 'resource',
                    'resource (closed)' => 'resource (closed)',
                    'string' => 'string',
                    'unknown type' => '[unknown]',
                }, $frame['args']);

                if (! array_is_list($args)) {
                    $args = array_map(static fn ($value, $key) => "{$key}: {$value}", $args, array_keys($args));
                }

                $source .= implode(', ', $args);
            }

            $source .= ')';

            $trace[] = [
                'file' => $this->formatLocation($file ?? '[internal function]', $line),
                'source' => $source,
                'code' => $this->fetchSourceCode($file, $line),
            ];
        }

        $this->fileObjects = [];
        $this->capturedCodeFrames = 0;

        return json_encode($trace, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{string, int|null} */
    private function mapBladeLocation(string $file, ?int $line): array
    {
        return $this->bladeTraceMapper?->map($file, $line) ?? [$file, $line];
    }

    private function formatLocation(string $file, ?int $line): string
    {
        return $this->location->normalizeFile($file).($line !== null ? ':'.$line : '');
    }

    private function fetchSourceCode(mixed $file, mixed $line, int $context = 5): ?stdClass
    {
        if (! $this->captureSourceCode || $this->capturedCodeFrames >= 10) {
            return null;
        }

        if (! is_string($file) || ! is_int($line)) {
            return null;
        }

        if (! $this->location->isApplicationFile($file)) {
            return null;
        }

        $fileObject = $this->fileObjects[$file] ??= $this->getFileObject($file);

        if ($fileObject === null) {
            return null;
        }

        try {
            $fileObject->seek(max(0, $line - 1 - $context));

            $code = new stdClass;

            while ($fileObject->key() <= $line - 1 + $context && ! $fileObject->eof()) {
                $code->{$fileObject->key() + 1} = rtrim($fileObject->fgets(), "\r\n");
            }

            $this->capturedCodeFrames++;

            return $code;
        } catch (Throwable $e) {
            LaraowlClient::unrecoverableExceptionOccurred($e);

            return null;
        }
    }

    /**
     * Get a file object for the given file path.
     */
    private function getFileObject(string $file): ?SplFileObject
    {
        try {
            return new SplFileObject($file);
        } catch (Throwable $e) {
            LaraowlClient::unrecoverableExceptionOccurred($e);

            return null;
        }
    }
}
