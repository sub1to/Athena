<?php
/**
 * Athena
 * Copyright 2018 Charlotte Dunois, All Rights Reserved
 *
 * Website: https://charuru.moe
 * License: https://github.com/CharlotteDunois/Athena/blob/master/LICENSE
*/

namespace CharlotteDunois\Athena;

/**
 * The Athena Cache client. Uses Redis as cache asynchronously.
 */
class AthenaCache extends \CharlotteDunois\Events\EventEmitter implements CacheInterface {
    /** @var \React\EventLoop\LoopInterface */
    protected $loop;
    
    /** @var \Predis\Async\Client */
    protected $redis;
    
    /** @var string */
    protected $prefix = '';
    
    /**
     * Maximum default lifetime in seconds.
     * @var int
     */
    public $lifetime = 3600;
    
    /**
     * Constructor. Optional options are as following:
     *
     * <pre>
     * array(
     *     'address' => string, (the address to connect to (an URI string), defaults to tcp://127.0.0.1:6379)
     *     'prefix' => string, (the prefix to prepend to keys to create an user-land namespace, useful for multiple "databases" inside a logical database)
     *     'options' => array (additional options to pass to the redis client)
     * )
     * </pre>
     *
     * The client has two events: error and debug. Debug contains debug information. And error gets emitted when predis emits an error.
     *
     * @param \React\EventLoop\LoopInterface|null  $loop
     * @param array                                $options
     */
    function __construct(?\React\EventLoop\LoopInterface $loop = null, array $options = array()) {
        if($loop === null) {
            $loop = \React\EventLoop\Factory::create();
        }
        
        if(isset($options['prefix'])) {
            $this->prefix = (string) $options['prefix'];
        }
        
        $options = array('eventloop' => $loop, 'exceptions' => false, 'on_error' => function ($client, $error) {
            if($error === 'on_error') {
                return;
            }
            
            $this->emit('error', $error);
        });
        
        if(!empty($options['options']) && \is_array($options['options'])) {
            $options = \array_merge($options['options'], $options);
        }
        
        $this->loop = $loop;
        $this->redis = new \Predis\Async\Client((!empty($options['address']) ? $options['address'] : 'tcp://127.0.0.1:6379'), $options);
        
        $this->redis->connect(function () {
            $this->emit('debug', 'Connected to Redis');
        });
    }
    
    /**
     * Returns the loop.
     * @return \React\EventLoop\LoopInterface
     */
    function getLoop() {
        return $this->loop;
    }
    
    /**
     * Returns the redis client.
     * @return \Predis\Async\Client
     */
    function getRedis() {
        return $this->redis;
    }
    
    /**
     * Disconnects from redis.
     */
    function destroy() {
        $this->redis->disconnect();
    }
    
    /**
     * Gets an item from the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @param mixed   $defVal
     * @param bool    $throwOnNotFound  Rejects the promise if the item is not found.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function get(string $key, $defVal = null, bool $throwOnNotFound = false): \React\Promise\ExtendedPromiseInterface {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($key, $defVal, $throwOnNotFound) {
            $key = $this->normalizeKey($key);
            
            $this->redis->get($this->prefix.$key, function ($value) use ($key, $defVal, $throwOnNotFound, $resolve, $reject) {
                try {
                    if($value instanceof \Predis\Response\ErrorInterface) {
                        return $reject($value);
                    }
                    
                    if($value === null) {
                        if($throwOnNotFound) {
                            return $reject(new \RuntimeException('Item "'.$key.'" not found'));
                        }
                        
                        return $resolve($defVal);
                    }
                    
                    $resolve(\unserialize($value));
                } catch(\Throwable | \Exception | \ErrorException $e) {
                    $reject($e);
                }
            });
        }));
    }
    
    /**
     * Gets multiple items from the cache. The promise gets always rejected on errors.
     * @param string[]  $keys
     * @param mixed     $defVal
     * @param bool      $omitIfNotFound
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function getAll(array $keys, $defVal = null, bool $omitIfNotFound = false): \React\Promise\ExtendedPromiseInterface {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($keys, $defVal, $omitIfNotFound) {
            $values = array();
            
            foreach($keys as $key) {
                $values[] = $this->get($key, null, true)->then(function ($value) use ($key, $defVal) {
                    return array($key, $value);
                }, function () use ($key, $defVal, $omitIfNotFound) {
                    if($omitIfNotFound) {
                        return null;
                    }
                    
                    return array($key, $defVal);
                });
            }
            
            \React\Promise\all($values)->done(function ($values) use ($resolve) {
                $vals = array();
                foreach($values as $val) {
                    if($val === null) {
                        continue;
                    }
                    
                    $vals[$val[0]] = $val[1];
                }
                
                $resolve($vals);
            });
        }));
    }
    
    /**
     * Sets an item in the cache. The promise gets always rejected on errors.
     * @param string    $key
     * @param mixed     $value     Must be serializable.
     * @param int|null  $lifetime  Maximum lifetime in seconds.
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function set(string $key, $value, ?int $lifetime = null): \React\Promise\ExtendedPromiseInterface {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($key, $value, $lifetime) {
            $key = $this->normalizeKey($key);
            
            $this->redis->set($this->prefix.$key, \serialize($value), function ($status) use ($key, $lifetime, $resolve, $reject) {
                try {
                    if($status instanceof \Predis\Response\ErrorInterface) {
                        return $reject($status);
                    }
                    
                    if($status->getPayload() === 'OK' || $status->getPayload() === 'QUEUED') {
                        $this->redis->expire($this->prefix.$key, ($lifetime ?: $this->lifetime), function ($status) use ($key, $resolve, $reject) {
                            if($status === 1) {
                                $resolve();
                            } else {
                                $this->delete($key)->always(function () use ($reject) {
                                    $reject(new \Exception('Unable to set expire on item in redis'));
                                });
                            }
                        });
                    } else {
                        $reject(new \Exception('Unable to set item in redis'));
                    }
                } catch(\Throwable | \Exception | \ErrorException $e) {
                   $reject($e);
               }
           });
        }));
    }
    
    /**
     * Deletes an item in the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @return \React\Promise\ExtendedPromiseInterface
     */
    function delete(string $key): \React\Promise\ExtendedPromiseInterface {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) use ($key) {
            $key = $this->normalizeKey($key);
            
            $this->redis->del($this->prefix.$key, function ($status) use ($resolve, $reject) {
                try {
                    if($status instanceof \Predis\Response\ErrorInterface) {
                        return $reject($status);
                    }
                    
                    $resolve();
                } catch(\Throwable | \Exception | \ErrorException $e) {
                    $reject($e);
                }
            });
        }));
    }
    
    /**
     * @return string
     */
    protected function normalizeKey($key): string {
        return \preg_replace('/[^A-Z0-9\-_][\s]/iu', '-', $key);
    }
    
    /**
     * @return \React\Promise\ExtendedPromiseInterface
     */
    protected function getMap(): \React\Promise\ExtendedPromiseInterface {
        return (new \React\Promise\Promise(function (callable $resolve, callable $reject) {
            $this->redis->get($this->prefix.'cachemap', function ($map) use ($resolve, $reject) {
                try {
                    if($map instanceof \Predis\Response\ErrorInterface) {
                        return $reject($map);
                    }
                    
                    $resolve(($map !== null ? \json_decode($map, true) : array()));
                } catch(\Throwable | \Exception | \ErrorException $e) {
                    $reject($e);
                }
            });
        }));
    }
}
