<?php

declare(strict_types=1);

namespace League\Flysystem\Rackspace;

use Exception;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedCopyTrait;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OpenStack\Common\Error\BadResponseError;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use Throwable;

final class RackspaceAdapter extends AbstractAdapter
{
    use StreamedCopyTrait;
    use NotSupportingVisibilityTrait;

    private $container;

    private $prefix;

    public function __construct(Container $container, string $prefix = '')
    {
        $this->setPathPrefix($prefix);

        $this->container = $container;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    protected function getObject(string $path): StorageObject
    {
        $location = $this->applyPathPrefix($path);

        return $this->container->getObject($location);
    }

    protected function getPartialObject(string $path): StorageObject
    {
        $location = $this->applyPathPrefix($path);

        return $this->container->getObject($location);
    }

    public function write($path, $contents, Config $config)
    {
        $location = $this->applyPathPrefix($path);
        $headers = [];

        if ($config && $config->has('headers')) {
            $headers = $config->get('headers');
        }

        $response = $this->container->createObject([
            'name' => $location,
            'content' => $contents,
            'headers' => $headers,
        ]);

        return $this->normalizeObject($response);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        $object = $this->getObject($path);
        $object->setContent($contents);
        $object->setEtag(null);
        $response = $object->update();

        if (!$response->lastModified) {
            return false;
        }

        return $this->normalizeObject($response);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $object = $this->getObject($path);
        $newlocation = $this->applyPathPrefix($newpath);
        $destination = sprintf('/%s/%s', $this->container->name, ltrim($newlocation, '/'));
        try {
            $object->copy(['destination' => $destination]);
        } catch (Throwable $exception) {
            return false;
        }

        $object->delete();

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        try {
            $location = $this->applyPathPrefix($path);

            $this->container->getObject($location)->delete();
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        $location = $this->applyPathPrefix($dirname);
        $objects = $this->container->listObjects(['prefix' => $location]);

        try {
            foreach ($objects as $object) {
                /* @var $object StorageObject */
                $object->delete();
            }
        } catch (Throwable $exception) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config)
    {
        $headers = $config->get('headers', []);
        $headers['Content-Type'] = 'application/directory';
        $extendedConfig = (new Config())->setFallback($config);
        $extendedConfig->set('headers', $headers);

        return $this->write($dirname, '', $extendedConfig);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->update($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        try {
            $location = $this->applyPathPrefix($path);
            $exists = $this->container->objectExists($location);
        } catch (BadResponseError | Exception $exception) {
            return false;
        }

        return $exists;
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $object = $this->getObject($path);
        $data = $this->normalizeObject($object);

        $stream = $object->download();
        $data['contents'] = $stream->read($object->contentLength);
        $stream->close();

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $object = $this->getObject($path);
        $responseBody = $object->download();
        $responseBody->rewind();

        return ['stream' => $responseBody->detach()];
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $response = [];
        $marker = null;
        $location = $this->applyPathPrefix($directory);

        while (true) {
            $objectList = $this->container->listObjects(['prefix' => $location, 'marker' => $marker]);
            if (null === $objectList->current()) {
                break;
            }

            $response = array_merge($response, iterator_to_array($objectList));
            $marker = end($response)->name;
        }

        return Util::emulateDirectories(array_map([$this, 'normalizeObject'], $response));
    }

    /**
     * {@inheritdoc}
     */
    protected function normalizeObject(StorageObject $object)
    {
        $name = $object->name;
        $name = $this->removePathPrefix($name);
        $mimetype = explode('; ', $object->contentType);

        $lastModified = new \DateTime($object->lastModified);

        return [
            'type' => ((in_array('application/directory', $mimetype)) ? 'dir' : 'file'),
            'dirname' => Util::dirname($name),
            'path' => $name,
            'timestamp' => $lastModified->getTimestamp(),
            'mimetype' => reset($mimetype),
            'size' => $object->contentLength,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        $object = $this->getPartialObject($path);

        return $this->normalizeObject($object);
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public function applyPathPrefix($path): string
    {
        $encodedPath = join('/', array_map('rawurlencode', explode('/', $path)));

        return parent::applyPathPrefix($encodedPath);
    }
}