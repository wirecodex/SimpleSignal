<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * SimpleSignal — Declarative, compiled signal layer over ProcessWire hooks.
 *
 * Place signal declarations in /site/signals/*.signal.php.
 * This module compiles them to native PW hook code and loads the compiled
 * files at startup. In development mode it auto-recompiles on source change.
 *
 * Config (set in /site/config.php):
 *   $config->simpleSignalDevelopmentMode = true;          // default — auto-recompile on hash change
 *   $config->simpleSignalDevelopmentMode = false;         // trust index as gospel, zero stat calls
 *   $config->simpleSignalDevelopmentMode = $config->debug; // mirror PW's own debug flag
 */
class SimpleSignal extends WireData implements Module
{
    public static function getModuleInfo(): array
    {
        return [
            'title'    => 'SimpleSignal',
            'version'  => '0.1.0',
            'summary'  => 'Declarative, chainable, compiled signal layer over ProcessWire hooks.',
            'icon'     => 'bolt',
            'author'   => 'WireCodex',
            'href'     => 'https://simplewire.org',
            'autoload' => true,
            'singular' => true,
            'requires' => 'ProcessWire>=3.0.200,PHP>=8.1',
        ];
    }

    // ========================================
    // Lifecycle
    // ========================================

    public function init(): void
    {
        // Register PSR-style autoloader for SimpleWire\Signal\* classes
        spl_autoload_register(function (string $class): void {
            $prefix = 'SimpleWire\\Signal\\';
            if (!str_starts_with($class, $prefix)) return;
            $file = __DIR__ . '/classes/' . substr($class, strlen($prefix)) . '.php';
            if (file_exists($file)) require_once $file;
        });

        // Make signal() available globally in the ProcessWire namespace
        require_once __DIR__ . '/functions.php';
    }

    public function ready(): void
    {
        $devMode  = $this->wire->config->simpleSignalDevelopmentMode ?? true;
        $cacheDir = $this->wire->config->paths->cache . 'SimpleWire/Signal/';

        if (!is_dir($cacheDir)) {
            wireMkdir($cacheDir, true);
        }

        $index         = \SimpleWire\Signal\SignalIndex::load($this->wire);
        $indexModified = false;

        if ($devMode) {
            // Reconcile index with the filesystem on every request
            $index->sync();

            foreach ($index->active() as $key => $entry) {
                if (!$index->isValid($key)) {
                    try {
                        $compiled = \SimpleWire\Signal\SignalCompiler::compile(
                            $entry['source'],
                            $cacheDir
                        );
                        if (!empty($compiled['compiled'])) {
                            $index->update($key, $entry['source'], $compiled);
                            $indexModified = true;
                        }
                    } catch (\Throwable $e) {
                        $this->wire->log->save(
                            'simple-signal',
                            "[compile error: {$key}] " . $e->getMessage()
                        );
                    }
                }
            }

            if ($indexModified) {
                $index->save();
            }
        }

        // Include all compiled hook files
        foreach ($index->active() as $entry) {
            if (!empty($entry['compiled']) && file_exists($entry['compiled'])) {
                include $entry['compiled'];
            }
        }

        // GC and full index rebuild when PW clears its module cache
        $this->addHookAfter('Modules::refresh', function (): void {
            $idx = \SimpleWire\Signal\SignalIndex::load($this->wire);
            $idx->gc();
            // Wipe the index so the next request does a clean sync + recompile
            $indexFile = $this->wire->config->paths->cache . 'SimpleWire/Signal/.index.php';
            if (file_exists($indexFile)) {
                @unlink($indexFile);
            }
        });
    }

    // ========================================
    // Install / Uninstall
    // ========================================

    public function ___install(): void
    {
        $cacheDir  = $this->wire->config->paths->cache . 'SimpleWire/Signal/';
        $signalDir = $this->wire->config->paths->site . 'signals/';

        if (!is_dir($cacheDir)) {
            wireMkdir($cacheDir, true);
        }

        if (!is_dir($signalDir)) {
            wireMkdir($signalDir, true);
        }
    }

    public function ___uninstall(): void
    {
        $cacheDir = $this->wire->config->paths->cache . 'SimpleWire/Signal/';
        if (is_dir($cacheDir)) {
            wireRmdir($cacheDir, true);
        }
    }
}
