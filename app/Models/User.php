<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database\Model;

/**
 * User model — example showing all commonly used Model features.
 *
 * Table: users (auto-derived from class name "User" → "users")
 *
 * Quick reference:
 *   User::all()
 *   User::findById(1)
 *   User::findById([1, 2, 3])
 *   User::findOrFail(1)
 *   User::where('active', 1)->orderBy('name')->get()
 *   User::where('email', 'a@b.com')->first()
 *   User::where('active', 1)->paginate(20)
 *   User::count()
 *   User::create(['name' => 'Alice', 'email' => 'a@example.com', 'password' => '...'])
 *   User::firstOrCreate(['email' => 'a@example.com'], ['name' => 'Alice'])
 *   User::updateOrCreateRecord(['email' => 'a@example.com'], ['name' => 'Alice Updated'])
 *   User::bulkInsert([['name' => 'A', 'email' => 'a@x.com'], [...]])
 *   User::destroy(1)
 *   User::destroy([1, 2, 3])
 *   User::withTrashed()->where('email', 'x@x.com')->first()  // include soft-deleted
 *   User::onlyTrashed()->get()                                // only soft-deleted
 *
 *   $user = User::findById(1);
 *   $user->name = 'Bob';
 *   $user->save();
 *   $user->delete();      // sets deleted_at (soft delete)
 *   $user->restore();     // clears deleted_at
 *   $user->forceDelete(); // hard DELETE
 *   $user->refresh();     // re-fetch from DB
 *
 *   $user->toArray();     // attributes minus $hidden
 *   $user->toJson();
 *   $user->isDirty('name');
 *   $user->getOriginal('name');
 */
class User extends Model
{
    // Optional: explicit table name.
    // Omit to auto-derive "users" from "User".
    // protected string $table = 'users';

    /** Database connection to use. */
    protected string $connection = 'default';

    /** Columns that may be mass-assigned. */
    protected array $fillable = [
        'name',
        'email',
        'password',
        'bio',
        'avatar',
        'phone',
        'is_active',
    ];

    /** Columns blocked from mass-assignment regardless of $fillable. */
    protected array $guarded = [
        'role_id',
        'is_admin',
        'is_superadmin',
    ];

    /** Columns excluded from toArray() and toJson(). */
    protected array $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Type-casts applied when reading attributes via getAttribute() / __get().
     * Raw values in $attributes remain unchanged; casting happens on read.
     */
    protected array $casts = [
        'is_active'  => 'bool',
        'is_admin'   => 'bool',
        'meta'       => 'json',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /** Auto-manage created_at / updated_at on insert and update. */
    protected bool $timestamps = true;

    /**
     * Soft-delete: delete() sets deleted_at instead of removing the row.
     * Queries automatically exclude rows with deleted_at IS NOT NULL.
     */
    protected bool $softDeletes = true;

    /** Default per-page for paginate(). */
    protected int $perPage = 20;
}
