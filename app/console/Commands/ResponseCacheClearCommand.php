<?php

namespace App\Console\Commands;

use Core\Console\Command;
use Core\Console\Kernel;
use Core\Http\ResponseCache;

class ResponseCacheClearCommand extends Command
{
    public function name(): string
    {
        return 'cache:response:clear';
    }

    public function description(): string
    {
        return 'Clear full-page response cache entries globally, by path, or by tag';
    }

    public function handle(array $args, array $options, Kernel $console): int
    {
        $cache = new ResponseCache();
        $path = trim((string) ($options['path'] ?? ''));
        $tag = trim((string) ($options['tag'] ?? ''));

        if ($path !== '') {
            $deleted = $cache->forget($path);
            $console->success('Response cache cleared for path: ' . $path . ' (' . $deleted . ' entries)');
            return 0;
        }

        if ($tag !== '') {
            $deleted = $cache->forgetByTag($tag);
            $console->success('Response cache cleared for tag: ' . $tag . ' (' . $deleted . ' entries)');
            return 0;
        }

        $cache->flush();
        $console->success('Response cache namespace flushed.');
        return 0;
    }
}