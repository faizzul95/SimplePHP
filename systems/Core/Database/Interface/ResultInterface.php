<?php

declare(strict_types=1);

namespace Core\Database\Interface;

/**
 * Result Interface
 *
 * This interface defines methods for converting database query results
 * into different formats such as objects, arrays, or JSON.
 *
 * @category Database
 * @package Core\Database
 * @license http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version 0.0.1
 */

interface ResultInterface
{
    /**
     * Converts the result set to an object.
     *
     * @return object The result set as an object.
     */
    public function toObject();

    /**
     * Converts the result set to an array.
     *
     * @return array The result set as an array.
     */
    public function toArray();

    /**
     * Converts the result set to an json.
     *
     * @return array The result set as an json.
     */
    public function toJson();
}
