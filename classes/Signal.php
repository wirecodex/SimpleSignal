<?php

declare(strict_types=1);

namespace SimpleWire\Signal;

class Signal
{
    protected string $name;
    protected array $events  = [];
    protected array $wheres  = [];
    protected array $whens   = [];
    protected int $priority  = 100;
    protected bool $strict   = false;
    protected ?\Closure $beforeCallback = null;
    protected ?\Closure $afterCallback  = null;

    protected static array $eventMap = [
        // Page lifecycle
        'page.saveReady'         => 'Pages::saveReady',
        'page.saved'             => 'Pages::saved',
        'page.saveFieldReady'    => 'Pages::saveFieldReady',
        'page.savedField'        => 'Pages::savedField',
        'page.deleteReady'       => 'Pages::deleteReady',
        'page.deleted'           => 'Pages::deleted',
        'page.added'             => 'Pages::added',
        'page.cloned'            => 'Pages::cloned',
        // Page status transitions
        'page.published'         => 'Pages::published',
        'page.unpublished'       => 'Pages::unpublished',
        'page.statusChanged'     => 'Pages::statusChanged',
        'page.statusChangeReady' => 'Pages::statusChangeReady',
        'page.trashed'           => 'Pages::trashed',
        'page.restored'          => 'Pages::restored',
        'page.moved'             => 'Pages::moved',
        'page.renamed'           => 'Pages::renamed',
        // User / Session
        'user.login'             => 'Session::login',
        'user.logout'            => 'Session::logout',
        'user.loginFailure'      => 'Session::loginFailure',
        'user.saved'             => 'Users::saved',
        // System
        'system.init'            => 'ProcessWire::init',
        'system.ready'           => 'ProcessWire::ready',
        'system.finished'        => 'ProcessWire::finished',
    ];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    // ========================================
    // Chainable API
    // ========================================

    public function on(string|array $event): static
    {
        $this->events = is_array($event) ? $event : [$event];
        return $this;
    }

    public function where(string $field, mixed $value): static
    {
        $this->wheres[$field] = $value;
        return $this;
    }

    public function when(string|callable $field, mixed $cond = null): static
    {
        if (is_callable($field) && !is_string($field)) {
            $this->whens[] = ['type' => 'callable', 'fn' => \Closure::fromCallable($field)];
        } else {
            $this->whens[] = ['type' => 'string', 'field' => (string) $field, 'cond' => $cond];
        }
        return $this;
    }

    public function priority(int $n): static
    {
        $this->priority = $n;
        return $this;
    }

    public function strict(): static
    {
        $this->strict = true;
        return $this;
    }

    public function before(callable $fn): static
    {
        $this->beforeCallback = \Closure::fromCallable($fn);
        return $this;
    }

    public function after(callable $fn): static
    {
        $this->afterCallback = \Closure::fromCallable($fn);
        return $this;
    }

    // ========================================
    // Getters (for compiler)
    // ========================================

    public function getName(): string     { return $this->name; }
    public function getWheres(): array    { return $this->wheres; }
    public function getWhens(): array     { return $this->whens; }
    public function getPriority(): int    { return $this->priority; }
    public function isStrict(): bool      { return $this->strict; }
    public function getBeforeCallback(): ?\Closure { return $this->beforeCallback; }
    public function getAfterCallback(): ?\Closure  { return $this->afterCallback; }

    /**
     * Returns ['alias' => 'PW::Hook'] — aliases resolve via event map, raw strings pass through.
     */
    public function getPwEvents(): array
    {
        $resolved = [];
        foreach ($this->events as $alias) {
            $resolved[$alias] = self::$eventMap[$alias] ?? $alias;
        }
        return $resolved;
    }

    /**
     * Builds the PW conditional hook target string.
     * Pages::saved + where('template','product') → Pages(template=product)::saved
     */
    public function resolveHookTarget(string $pwEvent): string
    {
        if (empty($this->wheres)) {
            return $pwEvent;
        }

        $conditions = [];
        foreach ($this->wheres as $field => $value) {
            $conditions[] = "{$field}={$value}";
        }
        $selector = implode(', ', $conditions);

        $sep = strpos($pwEvent, '::');
        if ($sep === false) {
            return $pwEvent;
        }

        $class  = substr($pwEvent, 0, $sep);
        $method = substr($pwEvent, $sep + 2);

        return "{$class}({$selector})::{$method}";
    }
}
