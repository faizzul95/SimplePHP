<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Database ForgeInterface Interface
 *
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 */

interface ForgeInterface
{
    public function create($schema);

    public function alter($schema);

    public function up(): void;

    public function down(): void;
}
