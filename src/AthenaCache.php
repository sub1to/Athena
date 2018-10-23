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
class AthenaCache implements \CharlotteDunois\Events\EventEmitterInterface, CacheInterface {
    use \CharlotteDunois\Events\EventEmitterTrait;
    
    /** @var \React\EventLoop\LoopInterface */
    protected $loop;
    
    /** @var \Clue\React\Redis\Client */
    protected $redis;
    
    /** @var string */
    protected $prefix = '';
    
    protected $options;
    
    /**
     * Maximum default lifetime in seconds.
     * @var int
     */
    public $lifetime = 3600;
    
    /**
     * Constructor. Optional options are as following:
     *
     * ```
     * array(
     *     'address' => string, (the address to connect to (an URI string), defaults to tcp://127.0.0.1:6379)
     *     'connector' => \React\Socket\ConnectorInterface, (a connector used to connect to the redis server)
     *     'prefix' => string, (the prefix to prepend to keys to create an user-land namespace, useful for multiple "databases" inside a logical database)
     * )
     * ```
     *
     * The client has two events: error and debug. Debug contains debug information. And error gets emitted when redis emits an error.
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
        
        $this->loop = $loop;
        $this->options = $options;
        
        if(!empty($options['options']) && \is_array($options['options'])) {
            $options = \array_merge($options['options'], $options);
        }
    }
    
    /**
     * Starts connecting to redis.
     * @return \React\Promise\PromiseInterface
     */
    function connect() {
        $factory = new \Clue\React\Redis\Factory($this->loop, ($this->options['connector'] ?? null));
        return $factory->createClient((!empty($this->options['address']) ? $this->options['address'] : 'tcp://127.0.0.1:6379'))->then(function (\Clue\React\Redis\Client $client) {
            $this->redis = $client;
            
            $this->redis->on('error', function ($error) {
                $this->emit('error', $error);
            });
            
            $this->redis->on('close', function () {
                $this->emit('close');
            });
            
            $this->emit('debug', 'Connected to Redis');
        });
    }
    
    /**
     * Returns the options.
     * @return array
     */
    function getOptions() {
        return $this->options;
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
     * @return \Clue\React\Redis\Client
     */
    function getRedis() {
        return $this->redis;
    }
    
    /**
     * Disconnects from redis.
     * @return void
     */
    function destroy() {
        $this->redis->close();
    }
    
    /**
     * Gets an item from the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @param mixed   $defVal
     * @param bool    $throwOnNotFound  Rejects the promise if the item is not found.
     * @return \React\Promise\PromiseInterface
     */
    function get(string $key, $defVal = null, bool $throwOnNotFound = false): \React\Promise\ExtendedPromiseInterface {
        $key = $this->normalizeKey($key);
        
        return $this->redis->get($this->prefix.$key)->then(function ($value) use ($key, $defVal, $throwOnNotFound) {
            if($value === null) {
                if($throwOnNotFound) {
                    throw new \UnderflowException('Item "'.$key.'" not found');
                }
                
                return $defVal;
            }
            
            return \unserialize($value);
        });
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
     * @return \React\Promise\PromiseInterface
     */
    function set(string $key, $value, ?int $lifetime = null): \React\Promise\ExtendedPromiseInterface {
        $key = $this->normalizeKey($key);
        
        return $this->redis->set($this->prefix.$key, \serialize($value))->then(function () use ($key, $lifetime) {
            return $this->redis->expire($this->prefix.$key, ($lifetime ?: $this->lifetime));
        });
    }
    
    /**
     * Deletes an item in the cache. The promise gets always rejected on errors.
     * @param string  $key
     * @return \React\Promise\PromiseInterface
     */
    function delete(string $key): \React\Promise\ExtendedPromiseInterface {
        $key = $this->normalizeKey($key);
        
        return $this->redis->del($this->prefix.$key);
    }
    
    /**
     * @return string
     */
    protected function normalizeKey($key): string {
        return \preg_replace('/[^A-Z0-9\-_][\s]/iu', '-', $key);
    }
}
