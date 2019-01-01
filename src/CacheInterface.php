<?php
/**
 * Athena
 * Copyright 2018-2019 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Athena/blob/master/LICENSE
*/

namespace CharlotteDunois\Athena;

/**
 * The asynchronous cache interface.
 */
interface CacheInterface {
    /**
     * Gets an item from the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @param mixed   $defVal
     * @param bool    $throwOnNotFound  Rejects the promise if the item is not found.
     * @return \React\Promise\PromiseInterface
     */
    function get(string $key, $defVal = null, bool $throwOnNotFound = false): \React\Promise\PromiseInterface;
    
    /**
     * Gets multiple items from the cache. The promise gets always rejected on errors.
     * @param string[]  $keys
     * @param mixed     $defVal
     * @param bool      $omitIfNotFound
     * @return \React\Promise\PromiseInterface
     */
    function getAll(array $keys, $defVal = null, bool $omitIfNotFound = false): \React\Promise\PromiseInterface;
    
    /**
     * Sets an item in the cache. The promise gets always rejected on errors.
     * @param string    $key
     * @param mixed     $value     Must be serializable.
     * @param int|null  $lifetime  Maximum lifetime in seconds.
     * @return \React\Promise\PromiseInterface
     */
    function set(string $key, $value, ?int $lifetime = null): \React\Promise\PromiseInterface;
    
    /**
     * Deletes an item in the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @return \React\Promise\PromiseInterface
     */
    function delete(string $key): \React\Promise\PromiseInterface;
}
