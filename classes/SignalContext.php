<?php

declare(strict_types=1);

namespace SimpleWire\Signal;

use ProcessWire\HookEvent;
use ProcessWire\Page;

/**
 * Context object passed to every signal callback as $signal.
 *
 * Provides clean access to the page, event, change data, and common
 * mutation helpers — without exposing the raw HookEvent unless needed.
 */
class SignalContext
{
    public readonly ?Page $page;
    public readonly array $changes;
    public readonly mixed $old;
    public readonly HookEvent $event;

    protected string $name;

    /**
     * @param HookEvent  $event   Raw PW hook event
     * @param Page|null  $page    Page involved (null for non-page events)
     * @param string     $name    Signal name (for logging)
     * @param array      $changes Explicit changes array (e.g. from Pages::saved arg 1)
     * @param mixed      $old     Previous state snapshot (only when available via PW args)
     */
    public function __construct(
        HookEvent $event,
        ?Page $page,
        string $name,
        array $changes = [],
        mixed $old = null
    ) {
        $this->event   = $event;
        $this->page    = $page;
        $this->name    = $name;
        $this->old     = $old;
        $this->changes = !empty($changes)
            ? $changes
            : ($page !== null ? $page->getChanges() : []);
    }

    // ========================================
    // Convenience helpers
    // ========================================

    /** True if the named field changed since the last save. */
    public function changed(string $field): bool
    {
        return $this->page !== null && $this->page->isChanged($field);
    }

    /**
     * Mutate a field without triggering change tracking.
     * Only meaningful inside before() callbacks (pre-save).
     */
    public function setQuietly(string $field, mixed $value): void
    {
        if ($this->page !== null) {
            $this->page->setQuietly($field, $value);
        }
    }

    /**
     * Reset the page's change tracking.
     * Useful after bulk mutations inside before() callbacks.
     */
    public function resetTracking(): void
    {
        if ($this->page !== null) {
            $this->page->resetTrackChanges(true);
        }
    }

    /**
     * Abort the hooked PW action (sets $event->replace = true).
     * Only meaningful inside before() callbacks.
     */
    public function cancel(): void
    {
        $this->event->replace = true;
    }
}
