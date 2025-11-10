<?php

namespace Core;

/**
 * LazyCollection class for handling large datasets with minimal memory usage
 */
class LazyCollection implements \Iterator, \Countable
{
    private $source;
    private $position = 0;
    private $chunkSize = 100;
    private $chunkPosition = 0;
    private $totalCount = null;
    private $exhausted = false;
    private $operations = [];
    private $currentItems = [];
    private $currentItem = null;
    private $itemLoaded = false;

    /**
     * Create a new LazyCollection instance
     * 
     * @param callable $source The source data generator
     */
    public function __construct(callable $source)
    {
        $this->source = $source;
    }

    /**
     * Get the current item
     * 
     * @return mixed
     */
    public function current(): mixed
    {
        if (!$this->itemLoaded) {
            $this->loadCurrentItem();
        }

        return $this->currentItem;
    }

    /**
     * Get the current position
     * 
     * @return int
     */
    public function key(): mixed
    {
        return $this->position;
    }

    /**
     * Move to the next item
     */
    public function next(): void
    {
        $this->position++;
        $this->itemLoaded = false;
        $this->currentItem = null;
    }

    /**
     * Rewind the collection to the beginning
     */
    public function rewind(): void
    {
        $this->position = 0;
        $this->chunkPosition = 0;
        $this->currentItems = [];
        $this->exhausted = false;
        $this->itemLoaded = false;
        $this->currentItem = null;
    }

    /**
     * Check if the current position is valid
     * 
     * @return bool
     */
    public function valid(): bool
    {
        if ($this->exhausted) {
            return false;
        }

        if (!$this->itemLoaded) {
            $this->loadCurrentItem();
        }

        return $this->currentItem !== null;
    }

    /**
     * Load the current item and apply all operations
     */
    private function loadCurrentItem()
    {
        while (!$this->exhausted) {
            $this->loadChunkIfNeeded();

            if ($this->exhausted) {
                $this->currentItem = null;
                break;
            }

            $chunkIndex = $this->position % $this->chunkSize;

            if (!isset($this->currentItems[$chunkIndex])) {
                $this->exhausted = true;
                $this->currentItem = null;
                break;
            }

            $item = $this->currentItems[$chunkIndex];
            $passesFilter = true;

            // Apply operations to the item
            foreach ($this->operations as $operation) {
                if ($operation['type'] === 'map') {
                    $item = call_user_func($operation['callback'], $item);
                } elseif ($operation['type'] === 'filter') {
                    if (!call_user_func($operation['callback'], $item)) {
                        $passesFilter = false;
                        break;
                    }
                }
            }

            if ($passesFilter) {
                $this->currentItem = $item;
                break;
            } else {
                // Skip this item and try the next one
                $this->position++;
            }
        }

        $this->itemLoaded = true;
    }

    /**
     * Count elements of the collection
     * 
     * @return int
     */
    public function count(): int
    {
        if ($this->totalCount === null) {
            // We need to iterate through all items to get an accurate count for lazy collections
            $count = 0;
            $originalPosition = $this->position;
            $originalChunkPosition = $this->chunkPosition;
            $originalCurrentItems = $this->currentItems;
            $originalExhausted = $this->exhausted;
            $originalItemLoaded = $this->itemLoaded;
            $originalCurrentItem = $this->currentItem;

            $this->rewind();

            foreach ($this as $item) {
                $count++;
            }

            $this->totalCount = $count;

            // Restore original state
            $this->position = $originalPosition;
            $this->chunkPosition = $originalChunkPosition;
            $this->currentItems = $originalCurrentItems;
            $this->exhausted = $originalExhausted;
            $this->itemLoaded = $originalItemLoaded;
            $this->currentItem = $originalCurrentItem;
        }

        return $this->totalCount;
    }

    /**
     * Load the next chunk of data if needed
     */
    private function loadChunkIfNeeded()
    {
        $currentChunkIndex = floor($this->position / $this->chunkSize);

        if ($currentChunkIndex !== $this->chunkPosition || empty($this->currentItems)) {
            try {
                $source = $this->source;
                $chunk = $source($this->chunkSize, $this->position - ($this->position % $this->chunkSize));

                if (empty($chunk)) {
                    $this->exhausted = true;
                    $this->currentItems = [];
                    return;
                }

                $this->currentItems = $chunk;
                $this->chunkPosition = $currentChunkIndex;
            } catch (\Exception $e) {
                $this->exhausted = true;
                $this->currentItems = [];
                throw new \Exception("Error loading data chunk: " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Execute a callback over each item while maintaining lazy evaluation
     * 
     * @param callable $callback
     * @return LazyCollection
     */
    public function map(callable $callback)
    {
        $this->operations[] = [
            'type' => 'map',
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Filter items by a given callback while maintaining lazy evaluation
     * 
     * @param callable $callback
     * @return LazyCollection
     */
    public function filter(callable $callback)
    {
        $this->operations[] = [
            'type' => 'filter',
            'callback' => $callback
        ];

        return $this;
    }

    /**
     * Execute a callback over each item
     * 
     * @param callable $callback
     * @return LazyCollection
     */
    public function each(callable $callback)
    {
        foreach ($this as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Get all items as an array (caution: loads all data into memory)
     * 
     * @return array
     */
    public function all()
    {
        $results = [];

        foreach ($this as $item) {
            $results[] = $item;
        }

        return $results;
    }

    /**
     * Get the first item in the collection
     * 
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        $this->rewind();

        if ($callback === null) {
            if ($this->valid()) {
                return $this->current();
            }
            return $default;
        }

        foreach ($this as $item) {
            if ($callback($item)) {
                return $item;
            }
        }

        return $default;
    }

    /**
     * Take the first n items from the collection
     * 
     * @param int $limit
     * @return LazyCollection
     */
    public function take($limit)
    {
        $self = $this;
        return new LazyCollection(function ($size, $offset) use ($self, $limit) {
            if ($offset >= $limit) {
                return [];
            }

            $source = $this->source;
            $items = $source($size, $offset);

            return array_slice($items, 0, min(count($items), $limit - $offset));
        });
    }

    /**
     * Get a value from all items by key
     * Supports dot notation for nested values with eager loading (e.g., 'user.profile.name')
     * Automatically handles array relationships by taking the first item
     * 
     * @param string $key
     * @param string|null $valueKey Optional key to use as array keys
     * @return LazyCollection|array
     */
    public function pluck($key, $valueKey = null)
    {
        if ($valueKey !== null) {
            // Return an associative array keyed by $valueKey
            $result = [];
            
            foreach ($this as $item) {
                // Get the key value with dot notation support
                $keyValue = $item;
                if (strpos($valueKey, '.') !== false) {
                    $segments = explode('.', $valueKey);
                    foreach ($segments as $segment) {
                        if ($keyValue === null) {
                            break;
                        }
                        
                        if (is_array($keyValue)) {
                            // Check if this is a numeric array (list of items from with())
                            if (isset($keyValue[0]) && array_keys($keyValue) === range(0, count($keyValue) - 1)) {
                                // This is a numeric array, get the first item
                                $keyValue = $keyValue[0];
                                
                                // Now access the segment from the first item
                                if (is_array($keyValue)) {
                                    $keyValue = $keyValue[$segment] ?? null;
                                } elseif (is_object($keyValue)) {
                                    $keyValue = $keyValue->$segment ?? null;
                                } else {
                                    $keyValue = null;
                                }
                            } else {
                                // This is an associative array, access normally
                                $keyValue = $keyValue[$segment] ?? null;
                            }
                        } elseif (is_object($keyValue)) {
                            $keyValue = $keyValue->$segment ?? null;
                        } else {
                            $keyValue = null;
                        }
                    }
                } else {
                    $keyValue = is_array($item) ? ($item[$valueKey] ?? null) : (is_object($item) ? ($item->$valueKey ?? null) : null);
                }
                
                // Get the value with dot notation support
                $value = $item;
                if (strpos($key, '.') !== false) {
                    $segments = explode('.', $key);
                    foreach ($segments as $segment) {
                        if ($value === null) {
                            break;
                        }
                        
                        if (is_array($value)) {
                            // Check if this is a numeric array (list of items from with())
                            if (isset($value[0]) && array_keys($value) === range(0, count($value) - 1)) {
                                // This is a numeric array, get the first item
                                $value = $value[0];
                                
                                // Now access the segment from the first item
                                if (is_array($value)) {
                                    $value = $value[$segment] ?? null;
                                } elseif (is_object($value)) {
                                    $value = $value->$segment ?? null;
                                } else {
                                    $value = null;
                                }
                            } else {
                                // This is an associative array, access normally
                                $value = $value[$segment] ?? null;
                            }
                        } elseif (is_object($value)) {
                            $value = $value->$segment ?? null;
                        } else {
                            $value = null;
                        }
                    }
                } else {
                    $value = is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
                }
                
                if ($keyValue !== null) {
                    $result[$keyValue] = $value;
                }
            }
            
            return $result;
        }
        
        // Return a LazyCollection with mapped values
        return $this->map(function ($item) use ($key) {
            if (strpos($key, '.') === false) {
                // Simple key access
                return is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
            }

            // Handle dot notation
            $segments = explode('.', $key);
            $value = $item;
            
            foreach ($segments as $segment) {
                if ($value === null) {
                    return null;
                }

                // Handle array access
                if (is_array($value)) {
                    // Check if this is a numeric array (list of items from with())
                    if (isset($value[0]) && array_keys($value) === range(0, count($value) - 1)) {
                        // This is a numeric array, get the first item
                        $value = $value[0];
                        
                        // Now access the segment from the first item
                        if (is_array($value)) {
                            $value = $value[$segment] ?? null;
                        } elseif (is_object($value)) {
                            $value = $value->$segment ?? null;
                        } else {
                            $value = null;
                        }
                    } else {
                        // This is an associative array, access normally
                        $value = $value[$segment] ?? null;
                    }
                } elseif (is_object($value)) {
                    $value = $value->$segment ?? null;
                } else {
                    return null;
                }
            }
            
            return $value;
        });
    }

    /**
     * Get a specific chunk of items from the collection
     * 
     * @param int $size
     * @return LazyCollection
     */
    public function chunk($size)
    {
        $chunks = [];
        $chunk = [];
        $i = 0;

        foreach ($this as $item) {
            $chunk[] = $item;
            $i++;

            if ($i % $size === 0) {
                $chunks[] = $chunk;
                $chunk = [];
            }
        }

        if (!empty($chunk)) {
            $chunks[] = $chunk;
        }

        return new LazyCollection(function () use ($chunks) {
            return $chunks;
        });
    }

    /**
     * Create a collection of all elements that pass the given truth test
     * 
     * @param callable $callback
     * @return LazyCollection
     */
    public function reject(callable $callback)
    {
        return $this->filter(function ($item) use ($callback) {
            return !$callback($item);
        });
    }

    /**
     * Concatenate values of a given key as a string
     * 
     * @param string $key
     * @param string $glue
     * @return string
     */
    public function implode($key, $glue = '')
    {
        $result = '';
        $first = true;

        foreach ($this->pluck($key) as $item) {
            if (!$first) {
                $result .= $glue;
            } else {
                $first = false;
            }

            $result .= $item;
        }

        return $result;
    }

    /**
     * Pass the collection to the given callback and then return it
     * 
     * @param callable $callback
     * @return LazyCollection
     */
    public function tap(callable $callback)
    {
        $callback($this);
        return $this;
    }

    /**
     * Skip the given number of items
     * 
     * @param int $count
     * @return LazyCollection
     */
    public function skip($count)
    {
        return new LazyCollection(function ($size, $offset) use ($count) {
            $source = $this->source;
            return $source($size, $offset + $count);
        });
    }

    /**
     * Set the chunk size for internal data loading
     *
     * @param int $size
     * @return LazyCollection
     */
    public function setChunkSize($size)
    {
        $this->chunkSize = max(1, (int)$size);
        return $this;
    }
}
