<?php

namespace League\Flysystem\Rackspace\Tests;

use ArrayIterator;
use Exception;
use GuzzleHttp\Psr7\Stream;
use League\Flysystem\Config;
use League\Flysystem\Rackspace\RackspaceAdapter as Rackspace;
use Mockery;
use Mockery\MockInterface;
use OpenStack\ObjectStore\v1\Models\Container;
use OpenStack\ObjectStore\v1\Models\StorageObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

class RackspaceTests extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function getContainerMock(): MockInterface
    {
        return Mockery::mock(Container::class);
    }

    public function getDataObjectMock($filename): MockInterface
    {
        $mock = Mockery::mock(StorageObject::class);
        $mock->name = $filename;
        $mock->contentType = '; plain/text';
        $mock->lastModified = new \DateTimeImmutable('2014-01-01');
        $mock->contentLength = 4;

        return $mock;
    }

    public function testRead(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $body = Mockery::mock(StreamInterface::class);
        $body->shouldReceive('read')->andReturn('file contents');
        $body->shouldReceive('close');
        $dataObject->shouldReceive('download')->andReturn($body);
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $this->assertIsArray($adapter->read('filename.ext'));
    }

    public function testReadStream(): void
    {
        $resource = tmpfile();
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('some%20directory/filename.ext');
        $body = new Stream($resource);
        $dataObject->shouldReceive('download')->andReturn($body);
        $container->shouldReceive('getObject')->with('some%20directory/filename.ext')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $response = $adapter->readStream('some directory/filename.ext');
        $this->assertIsArray($response);
        $this->assertEquals($resource, $response['stream']);
        fclose($resource);
    }

    public function testPrefixed(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('prefix/filename.ext');
        $body = Mockery::mock(StreamInterface::class);
        $body->shouldReceive('read')->andReturn('file contents');
        $body->shouldReceive('close');
        $dataObject->shouldReceive('download')->andReturn($body);
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container, 'prefix');
        $this->assertIsArray($adapter->read('filename.ext'));
    }

    public function testHas(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('objectExists')->andReturn(true);
        $adapter = new Rackspace($container);
        $this->assertTrue($adapter->has('filename.ext'));
    }

    public function testHasFail(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('objectExists')->andReturn(false);
        $adapter = new Rackspace($container);
        $this->assertFalse($adapter->has('filename.ext'));
    }

    public function testHasFailFromUnknownException(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('objectExists')->andThrow(new Exception());
        $adapter = new Rackspace($container);
        $this->assertFalse($adapter->has('filename.ext'));
    }

    public function testHasNotFound(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('objectExists')->andReturn(false);
        $adapter = new Rackspace($container);
        $this->assertFalse($adapter->has('filename.ext'));
    }

    public function testWrite(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $container
            ->shouldReceive('createObject')
            ->with([
                'name' => 'filename.ext',
                'content' => 'content',
                'headers' => [],
            ])
            ->andReturn($dataObject)
        ;
        $adapter = new Rackspace($container);
        $config = new Config();
        $this->assertIsArray($adapter->write('filename.ext', 'content', $config));
    }

    public function testWriteWithHeaders(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $headers = ['custom' => 'headers'];
        $container
            ->shouldReceive('createObject')
            ->with([
                'name' => 'filename.ext',
                'content' => 'content',
                'headers' => $headers,
            ])
            ->andReturn($dataObject)
        ;
        $adapter = new Rackspace($container);
        $config = new Config(['headers' => $headers]);
        $this->assertIsArray($adapter->write('filename.ext', 'content', $config));
    }

    public function testWriteStream(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $container->shouldReceive('createObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $config = new Config([]);
        $stream = tmpfile();
        fwrite($stream, 'something');
        $this->assertIsArray($adapter->writeStream('filename.ext', $stream, $config));
        fclose($stream);
    }

    public function testUpdateFail(): void
    {
        $container = $this->getContainerMock();
        $dataObject = Mockery::mock(StorageObject::class);
        $dataObject->lastModified = false;
        $dataObject->shouldReceive('setContent');
        $dataObject->shouldReceive('setEtag');
        $dataObject->shouldReceive('update')->andReturn(Mockery::self());
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $this->assertFalse($adapter->update('filename.ext', 'content', new Config()));
    }

    public function testUpdate(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $dataObject->shouldReceive('setContent');
        $dataObject->shouldReceive('setEtag');
        $dataObject->shouldReceive('update')->andReturn(Mockery::self());
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $this->assertIsArray($adapter->update('filename.ext', 'content', new Config()));
    }

    public function testUpdateStream(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $dataObject->shouldReceive('setContent');
        $dataObject->shouldReceive('setEtag');
        $dataObject->shouldReceive('update')->andReturn(Mockery::self());
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $resource = tmpfile();
        $this->assertIsArray($adapter->updateStream('filename.ext', $resource, new Config()));
        fclose($resource);
    }

    public function testCreateDir(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('dirname');
        $container->shouldReceive('createObject')->with([
            'name' => 'dirname',
            'content' => '',
            'headers' => ['Content-Type' => 'application/directory'],
        ])->andReturn($dataObject);

        $adapter = new Rackspace($container);
        $response = $adapter->createDir('dirname', new Config());
        $this->assertIsArray($response);
        $this->assertEquals('dirname', $response['path']);
    }

    public function getterProvider(): array
    {
        return [
            ['getTimestamp'],
            ['getSize'],
            ['getMimetype'],
        ];
    }

    /**
     * @dataProvider getterProvider
     * @param string $functionName
     */
    public function testGetters(string $functionName): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $this->assertIsArray($adapter->{$functionName}('filename.ext'));
    }

    public function testDelete(): void
    {
        $container = $this->getContainerMock();
        $dataObject = $this->getDataObjectMock('filename.ext');
        $dataObject->shouldReceive('delete');
        $container->shouldReceive('getObject')->andReturn($dataObject);
        $adapter = new Rackspace($container);
        $this->assertTrue($adapter->delete('filename.ext'));
    }

    public function testDeleteNotFound(): void
    {
        $container = $this->getContainerMock();
        $adapter = new Rackspace($container);
        $this->assertFalse($adapter->delete('filename.txt'));
    }

    public function renameProvider(): array
    {
        return [
            [201, true],
            [500, false],
        ];
    }

    /**
     * @dataProvider renameProvider
     * @param int $status
     * @param string $expected
     */
    public function testRename(int $status, bool $expected): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('getName')->andReturn('container_name');
        $dataObject = Mockery::mock(StorageObject::class);
        $dataObject->shouldReceive('getStatusCode')->andReturn($status);
        $container->shouldReceive('getObject')->andReturn($dataObject);

        if ($expected) {
            $dataObject->shouldReceive('copy')->andReturn(Mockery::self());
            $dataObject->shouldReceive('delete');
        } else {
            $dataObject->shouldReceive('copy')->andThrow(new Exception());
        }

        $adapter = new Rackspace($container);
        $this->assertEquals($expected, $adapter->rename('filename.ext', 'other.ext'));
    }

    public function deleteDirProvider(): array
    {
        return [
            [200, true],
            [500, false],
        ];
    }

    /**
     * @dataProvider deleteDirProvider
     * @param int $status
     * @param string $expected
     */
    public function testDeleteDir(int $status, bool $expected): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('getName')->andReturn('container_name');
        $dataObject = Mockery::mock(StorageObject::class);
        if ($expected) {
            $dataObject->shouldReceive('delete');
        } else {
            $dataObject->shouldReceive('delete')->andThrow(new Exception());
        }
        $container->shouldReceive('listObjects')->andReturn(
            (function() use ($dataObject) { yield $dataObject; })()
        );
        $adapter = new Rackspace($container);
        $this->assertEquals($expected, $adapter->deleteDir(''));
    }

    public function testListContents(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('getName')->andReturn('container_name');
        $dataObject = $this->getDataObjectMock('filename.ext');
        $container->shouldReceive('listObjects')->andReturn((function() use ($dataObject) { yield $dataObject; })());
        $adapter = new Rackspace($container);
        $this->assertIsArray($adapter->listContents('', true));
    }

    public function testListContentsEmpty(): void
    {
        $container = $this->getContainerMock();
        $container->shouldReceive('getName')->andReturn('container_name');
        $container->shouldReceive('listObjects')->andReturn((function() { yield; })());
        $adapter = new Rackspace($container);
        $this->assertIsArray($adapter->listContents('', true));
    }

    public function testGetContainer(): void
    {
        $container = $this->getContainerMock();
        $adapter = new Rackspace($container);

        $this->assertEquals($container, $adapter->getContainer());
    }
}
