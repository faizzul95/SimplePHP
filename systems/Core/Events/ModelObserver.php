<?php

declare(strict_types=1);

namespace Core\Events;

/**
 * Model Observer base class.
 *
 * Override any lifecycle hook you want to react to. Unimplemented hooks are
 * skipped automatically — no abstract methods to fill in.
 *
 * Usage:
 *   class UserObserver extends ModelObserver
 *   {
 *       public function creating(array $data): void { ... }
 *       public function created(array $data): void { ... }
 *       public function updating(array $data): void { ... }
 *       public function updated(array $data): void { ... }
 *       public function deleting(array $data): void { ... }
 *       public function deleted(array $data): void { ... }
 *       public function restored(array $data): void { ... }
 *   }
 *
 *   // Register (in EventServiceProvider or AppServiceProvider):
 *   ModelObserverRegistry::observe(\App\Models\User::class, new UserObserver());
 *
 * The Model base class fires these events automatically when you call
 * Model::create(), $model->save(), $model->delete(), $model->restore().
 */
abstract class ModelObserver
{
    /** Before a new record is inserted. Return false to cancel. */
    public function creating(array $data): bool { return true; }

    /** After a new record is inserted successfully. */
    public function created(array $data): void {}

    /** Before an existing record is updated. Return false to cancel. */
    public function updating(array $data): bool { return true; }

    /** After an existing record is updated successfully. */
    public function updated(array $data): void {}

    /** Before a record is deleted. Return false to cancel. */
    public function deleting(array $data): bool { return true; }

    /** After a record is deleted successfully. */
    public function deleted(array $data): void {}

    /** After a soft-deleted record is restored. */
    public function restored(array $data): void {}

    /** Before a record is force-deleted (hard delete). Return false to cancel. */
    public function forceDeleting(array $data): bool { return true; }

    /** After a record is force-deleted (hard delete). */
    public function forceDeleted(array $data): void {}

    /** After a record is retrieved from the database. */
    public function retrieved(array $data): void {}
}
