<?php

declare(strict_types=1);

namespace SimpleWire\Signal;

use ProcessWire\ProcessWire;

/**
 * Manages the compiled signal index: read, write, sync, invalidation, and GC.
 *
 * Index format (written as a PHP return file):
 * [
 *   'product' => [
 *     'source'   => '/abs/path/product.signal.php',
 *     'hash'     => 'a3f8c2d1',
 *     'compiled' => '/abs/path/cache/a3f8c2d1.compiled.php',
 *     'events'   => ['Pages::saved'],
 *     'active'   => true,
 *   ],
 * ]
 */
class SignalIndex
{
    protected string $cacheDir;
    protected string $signalDir;
    protected string $indexFile;
    protected array $index = [];
    protected ProcessWire $wire;

    public function __construct(ProcessWire $wire)
    {
        $this->wire      = $wire;
        $this->cacheDir  = $wire->config->paths->cache . 'SimpleWire/Signal/';
        $this->signalDir = $wire->config->paths->site . 'signals/';
        $this->indexFile = $this->cacheDir . '.index.php';
    }

    // ========================================
    // Load
    // ========================================

    public static function load(ProcessWire $wire): static
    {
        $instance = new static($wire);
        $instance->read();
        return $instance;
    }

    protected function read(): void
    {
        if (file_exists($this->indexFile)) {
            $data = include $this->indexFile;
            $this->index = is_array($data) ? $data : [];
        }
    }

    // ========================================
    // Query
    // ========================================

    /** Returns only active entries from the index. */
    public function active(): array
    {
        return array_filter($this->index, fn($e) => !empty($e['active']));
    }

    /**
     * True if the compiled file exists AND the source hash still matches.
     * A hash mismatch means the source changed → needs recompile.
     */
    public function isValid(string $key): bool
    {
        if (!isset($this->index[$key])) return false;

        $entry = $this->index[$key];

        if (empty($entry['compiled']) || !file_exists($entry['compiled'])) return false;

        if (!empty($entry['source']) && file_exists($entry['source'])) {
            $currentHash = substr(md5_file($entry['source']), 0, 8);
            if (($entry['hash'] ?? '') !== $currentHash) return false;
        }

        return true;
    }

    public function getEntry(string $key): ?array
    {
        return $this->index[$key] ?? null;
    }

    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }

    public function getSignalDir(): string
    {
        return $this->signalDir;
    }

    // ========================================
    // Mutate
    // ========================================

    public function update(string $key, string $sourcePath, array $compiled): void
    {
        $this->index[$key] = [
            'source'   => $sourcePath,
            'hash'     => $compiled['hash'] ?? '',
            'compiled' => $compiled['compiled'] ?? '',
            'events'   => $compiled['events'] ?? [],
            'active'   => true,
        ];
    }

    public function remove(string $key): void
    {
        unset($this->index[$key]);
    }

    // ========================================
    // Persist
    // ========================================

    public function save(): void
    {
        $export  = var_export($this->index, true);
        $content = "<?php\n// SimpleSignal index — auto-generated, do not edit\nreturn {$export};\n";
        file_put_contents($this->indexFile, $content);
    }

    // ========================================
    // Sync & GC
    // ========================================

    /**
     * Scan /site/signals/ and reconcile with the index.
     * - Adds entries for new .signal.php files (no compiled path yet)
     * - Removes entries for deleted files
     */
    public function sync(): void
    {
        $files = $this->scanSignalDir();

        // Remove stale entries
        foreach (array_keys($this->index) as $key) {
            if (!isset($files[$key])) {
                unset($this->index[$key]);
            }
        }

        // Add entries for new files
        foreach ($files as $key => $path) {
            if (!isset($this->index[$key])) {
                $this->index[$key] = [
                    'source'   => $path,
                    'hash'     => '',
                    'compiled' => '',
                    'events'   => [],
                    'active'   => true,
                ];
            }
        }
    }

    /**
     * Delete any .compiled.php files in the cache dir not referenced by the index.
     */
    public function gc(): void
    {
        if (!is_dir($this->cacheDir)) return;

        $referenced = array_filter(array_column($this->index, 'compiled'));

        foreach (glob($this->cacheDir . '*.compiled.php') ?: [] as $file) {
            if (!in_array($file, $referenced, true)) {
                @unlink($file);
            }
        }
    }

    // ========================================
    // Internal
    // ========================================

    protected function scanSignalDir(): array
    {
        if (!is_dir($this->signalDir)) return [];

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->signalDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.signal.php')) continue;
            $path        = $file->getPathname();
            $files[$this->pathToKey($path)] = $path;
        }

        return $files;
    }

    protected function pathToKey(string $path): string
    {
        $relative = str_replace($this->signalDir, '', $path);
        // "product.signal.php"       → "product"
        // "product/inventory.signal.php" → "product.inventory"
        $relative = str_replace('/', '.', $relative);
        return str_replace('.signal.php', '', $relative);
    }
}
