# Flysystem Adapter for Rackspace.

[![Author](http://img.shields.io/badge/author-@frankdejonge-blue.svg?style=flat-square)](https://twitter.com/frankdejonge)
[![Build Status](https://img.shields.io/travis/thephpleague/flysystem-rackspace/master.svg?style=flat-square)](https://travis-ci.org/thephpleague/flysystem-rackspace)
[![Coverage Status](https://img.shields.io/scrutinizer/coverage/g/thephpleague/flysystem-rackspace.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-rackspace/code-structure)
[![Quality Score](https://img.shields.io/scrutinizer/g/thephpleague/flysystem-rackspace.svg?style=flat-square)](https://scrutinizer-ci.com/g/thephpleague/flysystem-rackspace)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/league/flysystem-rackspace.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-rackspace)
[![Total Downloads](https://img.shields.io/packagist/dt/league/flysystem-rackspace.svg?style=flat-square)](https://packagist.org/packages/league/flysystem-rackspace)


## Installation

```bash
composer require league/flysystem-rackspace
```

## Usage

```php
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use League\Flysystem\Filesystem;
use League\Flysystem\Rackspace\RackspaceAdapter;
use OpenStack\Common\Transport\Utils as TransportUtils;
use OpenStack\Identity\v2\Service;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\OpenStack;

$httpClient = new Client([
    'base_uri' => TransportUtils::normalizeUrl(':endpoint'),
    'handler'  => HandlerStack::create(),
]);

$options = [
    'authUrl'         => ':endpoint',
    'region'          => ':region',
    'username'        => ':username',
    'password'        => ':password',
    'tenantId'        => ':tenantId',
    'identityService' => Service::factory($httpClient),
];

$client = new OpenStack($options);

$objectStoreOptions = ['catalogName' => 'cloudFiles'];

$store = $client->objectStoreV1($objectStoreOptions);

$container = $store->getContainer(':container']);

$filesystem = new Filesystem(new Adapter($container));
```
