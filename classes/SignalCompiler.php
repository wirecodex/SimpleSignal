<?php

declare(strict_types=1);

namespace SimpleWire\Signal;

/**
 * Execute-and-collect compiler.
 *
 * Strategy:
 *   1. Reset SignalRegistry
 *   2. Include the .signal.php file — signal() calls push Signal objects into the registry
 *   3. Collect the Signal objects
 *   4. For each Signal, generate native ProcessWire addHook* calls
 *   5. Write the compiled file — named by the md5 hash of the source
 *
 * Closure source extraction uses ReflectionFunction to locate the exact
 * lines in the source file, then extracts the closure text via brace/depth
 * tracking. This avoids any AST dependency.
 */
class SignalCompiler
{
    // ========================================
    // Public entry point
    // ========================================

    /**
     * Compile a .signal.php source file into a native PW hook file.
     *
     * @return array{hash: string, compiled: string, events: string[]}
     */
    public static function compile(string $sourcePath, string $cacheDir): array
    {
        SignalRegistry::reset();

        // Execute-and-collect: run inside a static closure so no $this leaks
        (static function (string $file): void {
            include $file;
        })($sourcePath);

        $signals = SignalRegistry::collect();

        if (empty($signals)) {
            return ['hash' => '', 'compiled' => '', 'events' => []];
        }

        $hash         = substr(md5_file($sourcePath), 0, 8);
        $compiledPath = $cacheDir . $hash . '.compiled.php';
        $allEvents    = [];
        $blocks       = [];

        foreach ($signals as $signal) {
            [$code, $events] = self::generateSignalCode($signal, $sourcePath);
            if ($code !== '') $blocks[] = $code;
            $allEvents = array_merge($allEvents, $events);
        }

        $date   = date('Y-m-d H:i:s');
        $source = basename($sourcePath);

        $output  = "<?php\n";
        $output .= "// Compiled by SimpleSignal — do not edit\n";
        $output .= "// Source: {$source} | Hash: {$hash} | Compiled: {$date}\n\n";
        $output .= implode("\n\n", $blocks) . "\n";

        file_put_contents($compiledPath, $output);

        return [
            'hash'     => $hash,
            'compiled' => $compiledPath,
            'events'   => array_values(array_unique($allEvents)),
        ];
    }

    // ========================================
    // Code generation
    // ========================================

    /** @return array{0: string, 1: string[]} [$code, $events] */
    protected static function generateSignalCode(Signal $signal, string $sourcePath): array
    {
        $blocks = [];
        $events = [];

        foreach ($signal->getPwEvents() as $alias => $pwEvent) {
            $hookTarget = $signal->resolveHookTarget($pwEvent);
            $events[]   = $pwEvent;

            $options = $signal->getPriority() !== 100
                ? ", ['priority' => {$signal->getPriority()}]"
                : '';

            if ($signal->getBeforeCallback() !== null) {
                $blocks[] = self::generateHookBlock(
                    'addHookBefore', $hookTarget, $options,
                    $signal, $signal->getBeforeCallback(), $sourcePath
                );
            }

            if ($signal->getAfterCallback() !== null) {
                $blocks[] = self::generateHookBlock(
                    'addHookAfter', $hookTarget, $options,
                    $signal, $signal->getAfterCallback(), $sourcePath
                );
            }
        }

        return [implode("\n\n", $blocks), $events];
    }

    protected static function generateHookBlock(
        string $hookMethod,
        string $hookTarget,
        string $options,
        Signal $signal,
        \Closure $callback,
        string $sourcePath
    ): string {
        $name           = addslashes($signal->getName());
        $closureSource  = trim(self::extractClosureSource($callback));
        $whenGuards     = self::generateWhenGuards($signal->getWhens(), $sourcePath);

        $body  = '';
        $body .= "    \$_page    = \$event->arguments(0);\n";
        $body .= "    \$_page    = \$_page instanceof \\ProcessWire\\Page ? \$_page : null;\n";
        $body .= "    \$_changes = is_array(\$event->arguments(1)) ? \$event->arguments(1) : (\$_page ? \$_page->getChanges() : []);\n";
        $body .= "    \$signal   = new \\SimpleWire\\Signal\\SignalContext(\$event, \$_page, '{$name}', \$_changes);\n";

        if ($whenGuards !== '') {
            $body .= $whenGuards;
        }

        $call = "(({$closureSource}))(\$signal)";

        if ($signal->isStrict()) {
            $body .= "    {$call};\n";
        } else {
            $body .= "    try {\n";
            $body .= "        {$call};\n";
            $body .= "    } catch (\\Throwable \$_e) {\n";
            $body .= "        wire('log')->save('simple-signal', '[{$name}] ' . \$_e->getMessage());\n";
            $body .= "    }\n";
        }

        return "wire()->{$hookMethod}('{$hookTarget}', static function(\\ProcessWire\\HookEvent \$event) {\n{$body}}{$options});";
    }

    protected static function generateWhenGuards(array $whens, string $sourcePath): string
    {
        $guards = '';

        foreach ($whens as $when) {
            if ($when['type'] === 'string' && $when['cond'] === 'changed') {
                $field   = addslashes($when['field']);
                $guards .= "    if (\$_page === null || !\$_page->isChanged('{$field}')) return;\n";
            } elseif ($when['type'] === 'callable' && $when['fn'] instanceof \Closure) {
                $src     = trim(self::extractClosureSource($when['fn']));
                $guards .= "    if (!(({$src}))(\$signal)) return;\n";
            }
        }

        return $guards;
    }

    // ========================================
    // Closure source extraction
    // ========================================

    /**
     * Extract the PHP source text of a closure using ReflectionFunction.
     *
     * Handles:
     *   - Regular functions: function($s) { ... }
     *   - Arrow functions:   fn($s) => expr
     *   - Static variants of both
     *
     * The returned string is always prefixed with 'static'.
     */
    protected static function extractClosureSource(\Closure $closure): string
    {
        $rf        = new \ReflectionFunction($closure);
        $startLine = $rf->getStartLine() - 1; // 0-based
        $endLine   = $rf->getEndLine() - 1;   // 0-based

        $fileLines = file($rf->getFileName());
        $raw       = implode('', array_slice($fileLines, $startLine, $endLine - $startLine + 1));

        // Locate the opening keyword of the closure
        if (!preg_match('/\b(static\s+fn|static\s+function|fn|function)\b/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException(
                "SimpleSignal: cannot locate closure source in {$rf->getFileName()}:{$rf->getStartLine()}"
            );
        }

        $offset  = $m[0][1];
        $keyword = preg_replace('/\s+/', ' ', trim($m[1][0])); // normalise whitespace
        $code    = substr($raw, $offset);

        $isArrow = ($keyword === 'fn' || $keyword === 'static fn');

        $code = $isArrow
            ? self::extractArrowFunction($code)
            : self::extractToMatchingBrace($code);

        // Ensure 'static' prefix — compiled hooks must not capture $this
        $trimmed = ltrim($code);
        if (!str_starts_with($trimmed, 'static')) {
            $code = 'static ' . $trimmed;
        }

        return $code;
    }

    /**
     * Extract a regular function(...) { ... } by tracking brace depth.
     */
    protected static function extractToMatchingBrace(string $code): string
    {
        $depth    = 0;
        $inString = false;
        $strChar  = '';
        $len      = strlen($code);

        for ($i = 0; $i < $len; $i++) {
            $c = $code[$i];

            if ($inString) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $strChar) $inString = false;
                continue;
            }

            if ($c === '"' || $c === "'") {
                $inString = true;
                $strChar  = $c;
                continue;
            }

            if ($c === '{') { $depth++; continue; }

            if ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($code, 0, $i + 1);
                }
            }
        }

        return $code;
    }

    /**
     * Extract an arrow function fn($params) => expr by tracking bracket depth
     * after the '=>'. Stops at the first unmatched closing bracket or semicolon.
     */
    protected static function extractArrowFunction(string $code): string
    {
        $arrowPos = strpos($code, '=>');
        if ($arrowPos === false) {
            return rtrim($code, " \t\n\r;),");
        }

        $prefix    = substr($code, 0, $arrowPos + 2); // fn($p) =>
        $body      = substr($code, $arrowPos + 2);
        $depth     = 0;
        $inString  = false;
        $strChar   = '';
        $len       = strlen($body);
        $end       = $len;

        for ($i = 0; $i < $len; $i++) {
            $c = $body[$i];

            if ($inString) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $strChar) $inString = false;
                continue;
            }

            if ($c === '"' || $c === "'") {
                $inString = true;
                $strChar  = $c;
                continue;
            }

            if ($c === '(' || $c === '[' || $c === '{') { $depth++; continue; }

            if ($c === ')' || $c === ']' || $c === '}') {
                if ($depth === 0) { $end = $i; break; }
                $depth--;
                continue;
            }

            if ($depth === 0 && $c === ';') { $end = $i; break; }
        }

        return $prefix . rtrim(substr($body, 0, $end));
    }
}
