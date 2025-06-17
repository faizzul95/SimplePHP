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
    #[\ReturnTypeWillChange]
    public function current()
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
    #[\ReturnTypeWillChange]
    public function key()
    {
        return $this->position;
    }

    /**
     * Move to the next item
     */
    #[\ReturnTypeWillChange]
    public function next()
    {
        $this->position++;
        $this->itemLoaded = false;
        $this->currentItem = null;
    }

    /**
     * Rewind the collection to the beginning
     */
    #[\ReturnTypeWillChange]
    public function rewind()
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
    #[\ReturnTypeWillChange]
    public function valid()
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
    #[\ReturnTypeWillChange]
    public function count()
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
    public function first(callable $callback = null, $default = null)
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
     * 
     * @param string $key
     * @return LazyCollection
     */
    public function pluck($key)
    {
        return $this->map(function ($item) use ($key) {
            return is_array($item) ? ($item[$key] ?? null) : (is_object($item) ? ($item->$key ?? null) : null);
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
