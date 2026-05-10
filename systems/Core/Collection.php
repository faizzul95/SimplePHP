<?php

namespace Core;

/**
 * Collection — Fluent wrapper for arrays (Laravel-style).
 *
 * Works with in-memory data. For large datasets with lazy
 * chunked loading, use LazyCollection instead.
 *
 * Usage:
 *   $c = collect([1, 2, 3]);
 *   $c = new Collection([1, 2, 3]);
 *
 * Fluent:
 *   collect($users)->where('active', true)->pluck('email')->toArray();
 *
 * @template TKey of array-key
 * @template TValue
 * @phpstan-consistent-constructor
 */
class Collection implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    /** @var array<TKey, TValue> */
    protected array $items;

    /**
     * @param array<TKey, TValue>|self $items
     */
    public function __construct(array|self $items = [])
    {
        $this->items = $items instanceof self ? $items->all() : $items;
    }

    // ─── Core Access ─────────────────────────────────────────

    /**
     * Get all items as a plain array.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Alias of all().
     *
     * @return array<TKey, TValue>
     */
    public function toArray(): array
    {
        return array_map(function ($value) {
            return $value instanceof self ? $value->toArray() : $value;
        }, $this->items);
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options | JSON_UNESCAPED_UNICODE);
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Get the number of items.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if the collection is NOT empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    // ─── Retrieval ───────────────────────────────────────────

    /**
     * Get the first item, optionally matching a callback.
     *
     * @param callable|null $callback fn($value, $key): bool
     * @param mixed $default
     * @return TValue|mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : reset($this->items);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the last item, optionally matching a callback.
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? $default : end($this->items);
        }

        return $this->reverse()->first($callback, $default);
    }

    /**
     * Get an item by key.
     *
     * @param TKey $key
     * @param mixed $default
     * @return TValue|mixed
     */
    public function get(mixed $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->items)) {
            return $this->items[$key];
        }

        return $default instanceof \Closure ? $default() : $default;
    }

    /**
     * Retrieve a value using dot notation.
     */
    public function dot(string $key, mixed $default = null): mixed
    {
        $target = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default instanceof \Closure ? $default() : $default;
            }
        }

        return $target;
    }

    /**
     * Get values for a given key from all items.
     *
     * @param string|int $valueKey
     * @param string|int|null $indexKey Optional key to use as the array index
     * @return static
     */
    public function pluck(string|int $valueKey, string|int|null $indexKey = null): static
    {
        $results = [];

        foreach ($this->items as $item) {
            $value = self::dataGet($item, $valueKey);

            if ($indexKey !== null) {
                $results[self::dataGet($item, $indexKey)] = $value;
            } else {
                $results[] = $value;
            }
        }

        return new static($results);
    }

    /**
     * Get only the specified keys.
     */
    public function only(array $keys): static
    {
        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Get all items except the specified keys.
     */
    public function except(array $keys): static
    {
        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    /**
     * Check if a key exists.
     */
    public function has(string|int $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Check if any item satisfies the callback.
     */
    public function contains(mixed $key, mixed $value = null): bool
    {
        if ($key instanceof \Closure) {
            foreach ($this->items as $k => $v) {
                if ($key($v, $k)) {
                    return true;
                }
            }
            return false;
        }

        if ($value !== null) {
            foreach ($this->items as $item) {
                if (self::dataGet($item, $key) == $value) {
                    return true;
                }
            }
            return false;
        }

        return in_array($key, $this->items, false);
    }

    // ─── Transformation ──────────────────────────────────────

    /**
     * Apply a callback to each item and return a new collection.
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    /**
     * Map and flatten one level.
     */
    public function flatMap(callable $callback): static
    {
        return $this->map($callback)->flatten(1);
    }

    /**
     * Flatten a multi-dimensional collection.
     */
    public function flatten(int|float $depth = INF): static
    {
        $result = [];

        $flatten = function (array $items, int $currentDepth) use (&$result, &$flatten, $depth) {
            foreach ($items as $item) {
                if (is_array($item) && $currentDepth < $depth) {
                    $flatten($item, $currentDepth + 1);
                } elseif ($item instanceof self && $currentDepth < $depth) {
                    $flatten($item->all(), $currentDepth + 1);
                } else {
                    $result[] = $item;
                }
            }
        };

        $flatten($this->items, 0);

        return new static($result);
    }

    /**
     * Filter items by a callback.
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback === null) {
            return new static(array_filter($this->items));
        }

        return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
    }

    /**
     * Reject items that match the callback (inverse of filter).
     */
    public function reject(callable $callback): static
    {
        return $this->filter(fn($value, $key) => !$callback($value, $key));
    }

    /**
     * Filter items where a field equals a value.
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        // Support ->where('name', 'John') and ->where('name', null) shorthand
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        // Default operator when none specified
        $operator = $operator ?? '=';

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $actual = self::dataGet($item, $key);

            return match ($operator) {
                '=', '=='   => $actual == $value,
                '==='       => $actual === $value,
                '!=', '<>'  => $actual != $value,
                '!=='       => $actual !== $value,
                '<'         => $actual < $value,
                '<='        => $actual <= $value,
                '>'         => $actual > $value,
                '>='        => $actual >= $value,
                default     => $actual == $value,
            };
        });
    }

    /**
     * Filter items where a field is in the given array.
     */
    public function whereIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => in_array(self::dataGet($item, $key), $values, false));
    }

    /**
     * Filter items where a field is NOT in the given array.
     */
    public function whereNotIn(string $key, array $values): static
    {
        return $this->filter(fn($item) => !in_array(self::dataGet($item, $key), $values, false));
    }

    /**
     * Filter items where a field is null.
     */
    public function whereNull(string $key): static
    {
        return $this->filter(fn($item) => self::dataGet($item, $key) === null);
    }

    /**
     * Filter items where a field is NOT null.
     */
    public function whereNotNull(string $key): static
    {
        return $this->filter(fn($item) => self::dataGet($item, $key) !== null);
    }

    /**
     * Filter items where a field is between two values.
     */
    public function whereBetween(string $key, array $values): static
    {
        return $this->filter(function ($item) use ($key, $values) {
            $actual = self::dataGet($item, $key);
            return $actual >= $values[0] && $actual <= $values[1];
        });
    }

    /**
     * Get unique items (optionally by key).
     */
    public function unique(?string $key = null): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $exists = [];
        return $this->filter(function ($item) use ($key, &$exists) {
            $val = self::dataGet($item, $key);
            if (in_array($val, $exists, true)) {
                return false;
            }
            $exists[] = $val;
            return true;
        });
    }

    /**
     * Sort items by callback or key.
     */
    public function sortBy(string|callable $callback, int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;

        if (is_string($callback)) {
            $key = $callback;
            $callback = fn($item) => self::dataGet($item, $key);
        }

        // Build sort array
        $sortKeys = [];
        foreach ($items as $k => $v) {
            $sortKeys[$k] = $callback($v, $k);
        }

        $descending ? arsort($sortKeys, $options) : asort($sortKeys, $options);

        $sorted = [];
        foreach (array_keys($sortKeys) as $k) {
            $sorted[$k] = $items[$k];
        }

        return new static($sorted);
    }

    /**
     * Sort items descending.
     */
    public function sortByDesc(string|callable $callback, int $options = SORT_REGULAR): static
    {
        return $this->sortBy($callback, $options, true);
    }

    /**
     * Sort by keys.
     */
    public function sortKeys(int $options = SORT_REGULAR, bool $descending = false): static
    {
        $items = $this->items;
        $descending ? krsort($items, $options) : ksort($items, $options);
        return new static($items);
    }

    /**
     * Reverse the collection order.
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Reset the keys to consecutive integers.
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the keys.
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Flip keys and values.
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * Merge items into the collection.
     */
    public function merge(array|self $items): static
    {
        return new static(array_merge($this->items, $items instanceof self ? $items->all() : $items));
    }

    /**
     * Combine the collection values as keys with the given values.
     */
    public function combine(array|self $values): static
    {
        $values = $values instanceof self ? $values->all() : $values;

        if (count($this->items) !== count($values)) {
            throw new \InvalidArgumentException(
                sprintf('combine() requires both arrays to have equal length. Keys: %d, Values: %d', count($this->items), count($values))
            );
        }

        return new static(array_combine($this->items, $values));
    }

    /**
     * Take the first or last N items.
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return new static(array_slice($this->items, $limit, abs($limit)));
        }

        return new static(array_slice($this->items, 0, $limit));
    }

    /**
     * Skip the first N items.
     */
    public function skip(int $count): static
    {
        return new static(array_slice($this->items, $count));
    }

    /**
     * Slice the collection.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Chunk the collection into groups of the given size.
     *
     * @return static<int, static>
     */
    public function chunk(int $size): static
    {
        if ($size <= 0) {
            return new static();
        }

        return new static(array_map(
            fn($chunk) => new static($chunk),
            array_chunk($this->items, $size, true)
        ));
    }

    /**
     * Group items by a given key or callback.
     *
     * @return static<string|int, static>
     */
    public function groupBy(string|callable $groupBy): static
    {
        $groups = [];

        foreach ($this->items as $key => $item) {
            $groupKey = ($groupBy instanceof \Closure)
                ? $groupBy($item, $key)
                : self::dataGet($item, $groupBy);

            $groups[$groupKey][] = $item;
        }

        return new static(array_map(fn($group) => new static($group), $groups));
    }

    /**
     * Key the collection by a given key or callback.
     */
    public function keyBy(string|callable $keyBy): static
    {
        $result = [];

        foreach ($this->items as $key => $item) {
            $newKey = ($keyBy instanceof \Closure)
                ? $keyBy($item, $key)
                : self::dataGet($item, $keyBy);

            $result[$newKey] = $item;
        }

        return new static($result);
    }

    /**
     * Collapse a collection of arrays into a single flat collection.
     */
    public function collapse(): static
    {
        $result = [];

        foreach ($this->items as $item) {
            if ($item instanceof self) {
                $item = $item->all();
            }
            if (!is_array($item)) {
                continue;
            }
            $result = array_merge($result, $item);
        }

        return new static($result);
    }

    /**
     * Zip the collection together with one or more arrays.
     */
    public function zip(array ...$arrays): static
    {
        $params = array_merge([$this->items], $arrays);
        return new static(array_map(fn() => new static(func_get_args()), ...$params));
    }

    /**
     * Pad the collection to the specified length.
     */
    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    // ─── Aggregation ─────────────────────────────────────────

    /**
     * Reduce the collection to a single value.
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        return array_reduce($this->items, $callback, $initial);
    }

    /**
     * Sum values (optionally by key or callback).
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        $callback = is_string($callback)
            ? fn($item) => self::dataGet($item, $callback)
            : $callback;

        return $this->reduce(fn($carry, $item) => $carry + $callback($item), 0);
    }

    /**
     * Get the average value.
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        $count = $this->count();
        return $count > 0 ? $this->sum($callback) / $count : null;
    }

    /**
     * Alias of avg().
     */
    public function average(string|callable|null $callback = null): int|float|null
    {
        return $this->avg($callback);
    }

    /**
     * Get the minimum value.
     */
    public function min(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? null : min($this->items);
        }

        return $this->map(is_string($callback) ? fn($item) => self::dataGet($item, $callback) : $callback)->min();
    }

    /**
     * Get the maximum value.
     */
    public function max(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return empty($this->items) ? null : max($this->items);
        }

        return $this->map(is_string($callback) ? fn($item) => self::dataGet($item, $callback) : $callback)->max();
    }

    /**
     * Get the median value.
     */
    public function median(string|callable|null $callback = null): int|float|null
    {
        $source = $this;
        if ($callback !== null) {
            $source = $callback instanceof \Closure || !is_string($callback)
                ? $this->map($callback)
                : $this->pluck($callback);
        }
        $values = $source->values()->sortBy(fn($v) => $v)->values()->all();
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $mid = (int) floor(($count - 1) / 2);

        if ($count % 2 === 1) {
            return $values[$mid];
        }

        return ($values[$mid] + $values[$mid + 1]) / 2;
    }

    /**
     * Join items into a string.
     */
    public function implode(string|callable $value, ?string $glue = null): string
    {
        if ($value instanceof \Closure) {
            return implode($glue ?? '', $this->map($value)->all());
        }

        if ($glue !== null) {
            return implode($glue, $this->pluck($value)->all());
        }

        return implode('', $this->items);
    }

    /**
     * Alias of implode().
     */
    public function join(string $glue, string $finalGlue = ''): string
    {
        if ($finalGlue === '') {
            return implode($glue, $this->items);
        }

        $count = $this->count();
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return (string) $this->first();
        }

        $items = $this->values()->all();
        $last = array_pop($items);

        return implode($glue, $items) . $finalGlue . $last;
    }

    // ─── Iteration ───────────────────────────────────────────

    /**
     * Execute a callback over each item.
     *
     * Return false from callback to stop iteration.
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Pass the collection to a callback and return the result.
     */
    public function pipe(callable $callback): mixed
    {
        return $callback($this);
    }

    /**
     * Pass the collection to a callback and return the collection.
     */
    public function tap(callable $callback): static
    {
        $callback($this);
        return $this;
    }

    /**
     * Apply a callback when a condition is true.
     */
    public function when(bool $condition, callable $callback, ?callable $default = null): static
    {
        if ($condition) {
            return $callback($this) ?? $this;
        }

        if ($default) {
            return $default($this) ?? $this;
        }

        return $this;
    }

    /**
     * Apply a callback when a condition is false.
     */
    public function unless(bool $condition, callable $callback, ?callable $default = null): static
    {
        return $this->when(!$condition, $callback, $default);
    }

    // ─── Set Operations ──────────────────────────────────────

    /**
     * Intersect the collection with the given items.
     */
    public function intersect(array|self $items): static
    {
        return new static(array_intersect($this->items, $items instanceof self ? $items->all() : $items));
    }

    /**
     * Diff the collection with the given items.
     */
    public function diff(array|self $items): static
    {
        return new static(array_diff($this->items, $items instanceof self ? $items->all() : $items));
    }

    /**
     * Diff by keys.
     */
    public function diffKeys(array|self $items): static
    {
        return new static(array_diff_key($this->items, $items instanceof self ? $items->all() : $items));
    }

    // ─── Mutation (return new collection) ────────────────────

    /**
     * Push an item onto the end.
     */
    public function push(mixed ...$values): static
    {
        $items = $this->items;
        foreach ($values as $value) {
            $items[] = $value;
        }
        return new static($items);
    }

    /**
     * Put a key/value pair.
     */
    public function put(mixed $key, mixed $value): static
    {
        $items = $this->items;
        $items[$key] = $value;
        return new static($items);
    }

    /**
     * Remove and return an item by key.
     *
     * @return TValue|mixed
     */
    public function pull(mixed $key, mixed $default = null): mixed
    {
        $items = $this->items;
        $value = $items[$key] ?? $default;
        unset($items[$key]);
        $this->items = $items;
        return $value;
    }

    /**
     * Remove items by key.
     */
    public function forget(string|int|array $keys): static
    {
        $items = $this->items;
        foreach ((array) $keys as $key) {
            unset($items[$key]);
        }
        return new static($items);
    }

    /**
     * Randomly pick N items.
     */
    public function random(int $number = 1): static
    {
        $count = $this->count();

        if ($count === 0) {
            return new static();
        }

        $number = max(1, min($number, $count));

        $keys = (array) array_rand($this->items, $number);
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->items[$key];
        }

        return new static($result);
    }

    /**
     * Shuffle the items.
     */
    public function shuffle(): static
    {
        $items = $this->items;
        shuffle($items);
        return new static($items);
    }

    // ─── Search ──────────────────────────────────────────────

    /**
     * Search for a value and return its key.
     *
     * @return TKey|false
     */
    public function search(mixed $value, bool $strict = false): mixed
    {
        if ($value instanceof \Closure) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key)) {
                    return $key;
                }
            }
            return false;
        }

        return array_search($value, $this->items, $strict);
    }

    // ─── Conversion ──────────────────────────────────────────

    /**
     * Convert the collection to a query string.
     */
    public function toQueryString(): string
    {
        return http_build_query($this->items);
    }

    /**
     * Dump the collection for debugging and continue.
     */
    public function dump(): static
    {
        if (function_exists('debug')) {
            debug()->dump($this->items);
        } else {
            var_dump($this->items);
        }
        return $this;
    }

    /**
     * Dump and die.
     */
    public function dd(): never
    {
        $this->dump();
        exit(1);
    }

    // ─── ArrayAccess Implementation ──────────────────────────

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    // ─── Magic ───────────────────────────────────────────────

    public function __toString(): string
    {
        return $this->toJson();
    }

    // ─── Internal Helpers ────────────────────────────────────

    /**
     * Get a value from an item using dot notation.
     *
     * @param mixed $target  Array or object
     * @param string|int $key
     * @param mixed $default
     * @return mixed
     */
    public static function dataGet(mixed $target, string|int $key, mixed $default = null): mixed
    {
        if (is_int($key) || !str_contains((string) $key, '.')) {
            if (is_array($target)) {
                return $target[$key] ?? $default;
            }
            if (is_object($target)) {
                return $target->{$key} ?? $default;
            }
            return $default;
        }

        foreach (explode('.', (string) $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }
}
