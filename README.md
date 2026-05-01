# SimpleSignal

 **Alpha — v0.1.0.** This module is in early testing. The API may change before a stable release. Feedback and bug reports are welcome.
 
Declarative, chainable, compiled signal layer over ProcessWire hooks.

## Features

- **Compiled Output** — Signal declarations compile to native PW hook calls. Near-zero runtime overhead after first request.
- **Chainable API** — Expressive, readable syntax replaces verbose hook boilerplate
- **File-Based** — Signal files live in `/site/signals/`, are version-controlled and git-friendly
- **Hash-Invalidated Cache** — Change a signal file and the next request auto-recompiles. No manual cache clearing.
- **Safe by Default** — Errors are caught and logged. A broken signal never crashes a page save or user login.
- **25 Built-in Event Aliases** — Clean aliases for all common page, user, and system events
- **Escape Hatches Everywhere** — Raw PW hooks and the full `HookEvent` object are always accessible
- **Dev / Prod Awareness** — Development mode auto-recompiles on change. Production mode trusts the cache entirely.

## Installation

Install as a standalone ProcessWire module:

1. Copy the `SimpleSignal` folder to `/site/modules/`
2. Go to **Modules → Refresh** in the ProcessWire admin
3. Install **SimpleSignal**

The module creates `/site/signals/` and the compiled cache directory automatically on install.

## Quick Start

### 1. Create a signal file

Create `/site/signals/product.signal.php`:

```php
<?php namespace ProcessWire;

signal('product.published')
    ->on('page.published')
    ->where('template', 'product')
    ->after(function($signal) {
        $signal->page->wire('mail')
            ->to('admin@example.com')
            ->subject('Product published: ' . $signal->page->title)
            ->send();
    });
```

### 2. That's it

The module detects the file, compiles it to native ProcessWire hook code, and loads it on the next request. No registration, no `ready.php` edits.

---

## Signal Declaration API

```php
signal(string $name)           // Signal identity — used in log messages
    ->on(string|array $event)  // PW event alias or raw hook (e.g. 'page.saved')
    ->where(string $field, mixed $value)  // Filter: only pages matching this field=value
    ->when(string|callable $field, $cond) // Condition: field state or callable check
    ->priority(int $n)         // Hook execution priority (default: 100, lower runs first)
    ->strict()                 // Throw on error instead of logging (opt-in)
    ->before(callable $fn)     // Run BEFORE the PW action — can modify page fields
    ->after(callable $fn)      // Run AFTER the PW action — side effects only
```

### Multiple signals per file

Group related signals together:

```php
// /site/signals/product.signal.php

signal('product.published')
    ->on('page.published')
    ->where('template', 'product')
    ->after(function($signal) {
        // notify team
    });

signal('product.price-guard')
    ->on('page.saveReady')
    ->where('template', 'product')
    ->when('price', 'changed')
    ->before(function($signal) {
        if ($signal->page->price < 0)
            $signal->setQuietly('price', 0);
    });

signal('product.slug-normalize')
    ->on('page.saveReady')
    ->where('template', 'product')
    ->priority(90)   // run before other saveReady signals
    ->before(function($signal) {
        $signal->setQuietly('name', wire('sanitizer')->pageName($signal->page->title));
    });
```

Sub-folders are supported — the scanner is recursive:

```
/site/signals/
    product.signal.php
    user.signal.php
    product/
        inventory.signal.php
        pricing.signal.php
```

---

## The `$signal` Context Object

Every callback receives a `$signal` object with clean access to PW internals:

```php
->after(function($signal) {
    $signal->page;              // The page object (Page|null)
    $signal->changes;           // Array of changed field names
    $signal->changed('body');   // Boolean: did this field change?
    $signal->old;               // Previous state — see caveat below
    $signal->event;             // Raw HookEvent (escape hatch to full PW API)

    // Only meaningful in before() callbacks:
    $signal->setQuietly('field', $value);  // Mutate without triggering change tracking
    $signal->resetTracking();              // $page->resetTrackChanges()
    $signal->cancel();                     // Abort the hooked action ($event->replace = true)
})
```

> **`$signal->old` caveat** — ProcessWire does not snapshot page state before a save.
> `$signal->old` is only populated when the underlying PW hook natively provides previous
> values via `$event->arguments()` — for example, `Page::changed` passes `($what, $old, $new)`.
> For events like `Pages::saved`, `$signal->old` will be `null`. This is documented behaviour,
> not a bug.

---

## The `.where()` Method

`.where()` translates to ProcessWire's native **conditional hook selector** syntax. The filter runs inside PW itself — there is no runtime guard code generated.

```php
// Compiles to: wire()->addHookAfter('Pages(template=product)::saved', ...)
signal('product.saved')
    ->on('page.saved')
    ->where('template', 'product')
    ->after(...);

// Multiple conditions are AND-joined
signal('product.published-on-sale')
    ->on('page.published')
    ->where('template', 'product')
    ->where('sale', '1')
    ->after(...);
```

---

## The `.when()` Method

`.when()` generates a runtime guard **inside** the compiled hook body.

### String shortcut — common cases

```php
->when('status', 'changed')   // guard: if (!$page->isChanged('status')) return;
->when('price', 'changed')
```

### Callable — full power

```php
// Arrow function
->when(fn($signal) => $signal->changed('status') && $signal->page->price > 0)

// Regular closure
->when(function($signal) {
    return $signal->changed('status') && $signal->page->price > 0;
})

// With old-state access (where the hook provides it)
->when(fn($signal) => $signal->old?->status === 'draft')
```

---

## The `.priority()` Method

Maps directly to ProcessWire's hook priority. Lower numbers run first (default: 100):

```php
signal('product.validate')
    ->on('page.saveReady')
    ->priority(80)    // runs first
    ->before(...);

signal('product.normalize')
    ->on('page.saveReady')
    ->priority(90)    // runs second
    ->before(...);

signal('product.log')
    ->on('page.saveReady')
    ->priority(110)   // runs last
    ->before(...);
```

---

## Multi-Event Signals

A single signal can listen to multiple events:

```php
signal('page.touch-log')
    ->on(['page.saved', 'page.restored'])
    ->after(function($signal) {
        wire('log')->save('page-activity', $signal->page->title . ' was modified');
    });
```

Each event compiles to a separate `addHook*` call sharing the same callback.

---

## Strict Mode

By default, every signal callback is wrapped in a `try/catch`. A broken signal will never crash a page save or login — errors are logged to `simple-signal` in the PW logs.

For critical signals where silent failure is unacceptable:

```php
signal('order.sync')
    ->on('page.saved')
    ->where('template', 'order')
    ->strict()    // exception propagates normally — no try/catch generated
    ->after(function($signal) {
        // exceptions here will bubble up like normal PW exceptions
    });
```

---

## Event Vocabulary

### Page lifecycle

| Alias | ProcessWire Hook |
|---|---|
| `page.saveReady` | `Pages::saveReady` |
| `page.saved` | `Pages::saved` |
| `page.saveFieldReady` | `Pages::saveFieldReady` |
| `page.savedField` | `Pages::savedField` |
| `page.deleteReady` | `Pages::deleteReady` |
| `page.deleted` | `Pages::deleted` |
| `page.added` | `Pages::added` |
| `page.cloned` | `Pages::cloned` |

### Page status transitions

| Alias | ProcessWire Hook |
|---|---|
| `page.published` | `Pages::published` |
| `page.unpublished` | `Pages::unpublished` |
| `page.statusChanged` | `Pages::statusChanged` |
| `page.statusChangeReady` | `Pages::statusChangeReady` |
| `page.trashed` | `Pages::trashed` |
| `page.restored` | `Pages::restored` |
| `page.moved` | `Pages::moved` |
| `page.renamed` | `Pages::renamed` |

### User / Session

| Alias | ProcessWire Hook |
|---|---|
| `user.login` | `Session::login` |
| `user.logout` | `Session::logout` |
| `user.loginFailure` | `Session::loginFailure` |
| `user.saved` | `Users::saved` |

### System

| Alias | ProcessWire Hook |
|---|---|
| `system.init` | `ProcessWire::init` |
| `system.ready` | `ProcessWire::ready` |
| `system.finished` | `ProcessWire::finished` |

### Raw PW hooks (escape hatch)

Any hookable ProcessWire method is accepted directly — aliases are not required:

```php
signal('custom')
    ->on('Field::getInputfield')
    ->after(function($signal) { ... });
```

---

## Configuration

Set in `/site/config.php`:

```php
// Development mode (default) — auto-recompiles when a signal file changes
$config->simpleSignalDevelopmentMode = true;

// Production mode — trusts the compiled cache entirely, zero filesystem stat calls
$config->simpleSignalDevelopmentMode = false;

// Mirror ProcessWire's own debug flag (recommended pattern)
$config->simpleSignalDevelopmentMode = $config->debug;
```

---

## How Compilation Works

SimpleSignal uses an **execute-and-collect** strategy — not AST parsing.

1. The compiler executes your `.signal.php` file inside a sandbox
2. Each `signal()` call pushes a builder object into `SignalRegistry`
3. The compiler collects the builders and generates native PW `addHook*` calls
4. The compiled file is written to `/site/assets/cache/SimpleWire/Signal/` named by the MD5 hash of the source
5. On the next request, the compiled file is included directly — no SimpleSignal builder code runs at runtime

The compiled files use only the ProcessWire hook API. If this module were removed, the compiled hooks would continue to work standalone (they do reference `SignalContext` for the `$signal` object).

### Cache invalidation

The compiled filename **is** the content hash of the source:

- `product.signal.php` → `md5(file_contents)` → `a3f8c2d1.compiled.php`
- Any change to the source produces a different hash → new filename
- Stale detection is a single `md5_file()` comparison — no timestamp checks
- Old compiled files become orphaned and are removed automatically by the GC

GC runs automatically when **Modules → Refresh** is triggered in the PW admin.

### What the compiled output looks like

Input (`product.signal.php`):
```php
signal('product.published')
    ->on('page.published')
    ->where('template', 'product')
    ->after(function($signal) {
        wire('mail')->to('admin@example.com')
            ->subject('Product live: ' . $signal->page->title)
            ->send();
    });
```

Output (`a3f8c2d1.compiled.php`):
```php
// Compiled by SimpleSignal — do not edit
// Source: product.signal.php | Hash: a3f8c2d1 | Compiled: 2026-04-30 10:00:00

wire()->addHookAfter('Pages(template=product)::published', static function(\ProcessWire\HookEvent $event) {
    $_page    = $event->arguments(0);
    $_page    = $_page instanceof \ProcessWire\Page ? $_page : null;
    $_changes = is_array($event->arguments(1)) ? $event->arguments(1) : ($_page ? $_page->getChanges() : []);
    $signal   = new \SimpleWire\Signal\SignalContext($event, $_page, 'product.published', $_changes);
    try {
        ((static function($signal) {
            wire('mail')->to('admin@example.com')
                ->subject('Product live: ' . $signal->page->title)
                ->send();
        }))($signal);
    } catch (\Throwable $_e) {
        wire('log')->save('simple-signal', '[product.published] ' . $_e->getMessage());
    }
});
```

---

## Use Cases

- **Content Notifications** — Email or Slack alerts when pages are published, trashed, or updated
- **Data Validation** — Guard fields before save with `page.saveReady` and `.before()`
- **Slug / Field Normalisation** — Auto-correct values before they hit the database
- **Audit Logging** — Record who changed what and when on any page event
- **Cache Invalidation** — Bust caches when specific templates change
- **Status Workflows** — React to page status transitions (`page.published`, `page.trashed`)
- **Login Hooks** — Track failed logins, enforce rate limits, log user sessions
- **SimpleQueue Integration** — Dispatch background jobs from signal callbacks

---

## Example: Audit Log

```php
// /site/signals/audit.signal.php
<?php namespace ProcessWire;

signal('audit.page-saved')
    ->on('page.saved')
    ->after(function($signal) {
        if (empty($signal->changes)) return;

        wire('log')->save('audit', sprintf(
            '[%s] %s changed: %s',
            wire('user')->name,
            $signal->page->title,
            implode(', ', $signal->changes)
        ));
    });
```

## Example: SimpleQueue Integration

```php
// /site/signals/media.signal.php
<?php namespace ProcessWire;

signal('media.image-changed')
    ->on('page.saved')
    ->when('images', 'changed')
    ->after(function($signal) {
        queue()->push('OptimizeImagesJob', [
            'page_id' => $signal->page->id,
        ]);
    });
```

## Example: Before-Save Validation

```php
// /site/signals/shop.signal.php
<?php namespace ProcessWire;

signal('shop.price-floor')
    ->on('page.saveReady')
    ->where('template', 'product')
    ->when('price', 'changed')
    ->priority(80)
    ->before(function($signal) {
        if ($signal->page->price < 0) {
            $signal->setQuietly('price', 0);
        }
    });

signal('shop.slug-normalise')
    ->on('page.saveReady')
    ->where('template', 'product')
    ->priority(85)
    ->before(function($signal) {
        $clean = wire('sanitizer')->pageName($signal->page->title);
        $signal->setQuietly('name', $clean);
    });
```

---

## File Structure

```
/site/signals/                             ← Your signal files (commit to git)
    product.signal.php
    user.signal.php
    shop/
        inventory.signal.php
        pricing.signal.php

/site/assets/cache/SimpleWire/Signal/      ← Auto-generated (gitignore this)
    a3f8c2d1.compiled.php
    b7e4a9f2.compiled.php
    .index.php
```

---

## Requirements

- ProcessWire 3.0.200 or higher
- PHP 8.1 or higher

## License

MIT License
