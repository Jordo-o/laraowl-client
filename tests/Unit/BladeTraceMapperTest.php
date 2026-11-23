<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Laraowl\Client\BladeTraceMapper;

test('compiled Blade frames resolve to original view and unambiguous line', function () {
    $root = sys_get_temp_dir().'/laraowl-blade-'.bin2hex(random_bytes(6));
    $compiledDir = $root.'/storage/framework/views';
    $viewDir = $root.'/resources/views';
    mkdir($compiledDir, 0777, true);
    mkdir($viewDir, 0777, true);

    try {
        $view = $viewDir.'/example.blade.php';
        $source = "Before\n{{ \'hello\' }}\nAfter";
        file_put_contents($view, $source);
        $compiler = new BladeCompiler(new Filesystem, $compiledDir);
        $compiled = $compiler->compileString($source)."\n/**PATH {$view} ENDPATH**/";
        $compiledFile = $compiledDir.'/test.php';
        file_put_contents($compiledFile, $compiled);

        $mapper = new BladeTraceMapper($compiler);
        $lines = explode("\n", $compiled);
        $compiledLine = array_search($compiler->compileString("{{ 'hello' }}"), $lines, true);
        expect($compiledLine)->not->toBeFalse();

        expect($mapper->map($compiledFile, $compiledLine + 1))
            ->toBe([$view, 2]);
        expect($mapper->map($compiledFile, 999))
            ->toBe([$view, null]);
        expect($mapper->map($view, 2))->toBeNull();
    } finally {
        @unlink($compiledDir.'/test.php');
        @unlink($viewDir.'/example.blade.php');
        @rmdir($compiledDir);
        @rmdir($root.'/storage/framework');
        @rmdir($root.'/storage');
        @rmdir($viewDir);
        @rmdir($root.'/resources');
        @rmdir($root);
    }
});
