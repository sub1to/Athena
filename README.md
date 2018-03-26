# Athena [![Build Status](https://scrutinizer-ci.com/g/CharlotteDunois/Athena/badges/build.png?b=master)](https://scrutinizer-ci.com/g/CharlotteDunois/Athena/build-status/master)

Athena is an asynchronous cache library for PHP. It defines an asynchronous cache interface using Promises, which can also be used by others to implement alternatives. `AthenaCache` utilizes Redis with the help of `predis-async`.

# Getting Started
Getting started with Athena is pretty straight forward. All you need to do is to use [composer](https://packagist.org/packages/charlottedunois/athena) to install Athena and its dependencies.

```
composer require charlottedunois/athena
```

# Usage

## CacheInterface
The Cache Interface provides a way for consumers to typehint against it.

**get**:
```php
$cache->get('foobar')->then('var_dump');
```

This example fetches the key `foobar` and resolves the promise. The promise executes then the function `var_dump`. If the key does not exist, consumers can choose to either return a default value `$defVal` or set a boolean `$throwOnNotFound` to make the promise get rejected on that case. Promises get always rejected on errors.

**getAll**:
```php
$cache->getAll([ 'foo', 'bar' ])->then('var_dump');
```

This example fetches multiple keys `foo` and `bar` from the cache. The optional arguments `$defVal` will determine whether a missing key in the cache will be replaced with a default value. Or the optional argument `$omitIfNotFound` will omit missing keys. The promise gets always rejected on errors - otherwise resolved with an associative array `key => value`.

**set**:
```php
$cache->set('foobar', 0, 600);
```

This examples sets the key `foobar` to the value `0` and will expire in 600 seconds (this is the maximum lifetime, if needed the used cache can expire it sooner). The promise gets rejected on errors - or resolved with `null` on success.

**delete**:
```php
$cache->delete('foobar');
```

This example deletes the key `foobar` from the cache. The promise gets rejected on errors - or resolved with `null` on success.

## AthenaCache
AthenaCache is an asynchronous redis client. It connects to a redis server and handles the execution of commands.

Example:
```php
$loop = \React\EventLoop\Factory::create();
$cache = new \CharlotteDunois\Athena\AthenaCache($loop);

$cache->get('foo')->then('var_dump');
$cache->set('bar', 500)->then(null, 'printf');
$cache->delete('foobar')->then(null, 'printf');

$loop->run();
```

A common use-case to reject promises, if the key does not exist, is to fetch the value from the original source (e.g. a database).
```php
$cache->get('foobar', null, true)->otherwise(function () {
    return Database::fetchFoobar();
})->then('var_dump');
```
