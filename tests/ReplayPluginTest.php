<?php

namespace Http\Client\Common\Plugin\Tests;

use Http\Client\Common\Plugin\ReplayPlugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use \PHPUnit_Framework_MockObject_MockObject as MockObject;
//use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Class ReplayPluginTest.
 */
class ReplayPluginTest extends TestCase
{
    /**
     * @var CacheItemPoolInterface|MockObject
     */
    private $pool;

    /**
     * @var StreamFactory|MockObject
     */
    private $streamFactory;

    /**
     * @var StreamInterface|MockObject
     */
    private $stream;

    /**
     * @var RequestInterface|MockObject
     */
    private $request;

    /**
     * @var ResponseInterface|MockObject
     */
    private $response;

    /**
     * @var CacheItemInterface|MockObject
     */
    private $item;

    /**
     * @var Promise|MockObject
     */
    private $promise;

    public function setUp(): void
    {
        $this->pool = $this->createMock(CacheItemPoolInterface::class);
        $this->streamFactory = $this->createMock(StreamFactory::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->request = $this->createMock(RequestInterface::class);
        $this->item = $this->createMock(CacheItemInterface::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->promise = $this->createMock(Promise::class);
    }

    /**
     * @expectedException \LogicException
     *
     * @expectedExceptionMessage You need to specify a replay bucket
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function testShouldNotReturnRecordRequestOrRecordBecauseBucketItIsNotSet()
    {
        $body = '[{"slug":"john-doe","username":"john.doe","roles":["ROLE_SUPER_ADMIN","ROLE_TEST"],"dateCreated":"2019-02-14T17:22:06+01:00","dateModified":"2019-02-14T17:22:06+01:00"}]';

        $this->request->expects($this->never())->method('getHeaders');
        $this->request->expects($this->never())->method('getMethod')->willReturn('GET');
        $this->request->expects($this->never())->method('getUri')->willReturn('/users');

        $this->stream->expects($this->never())->method('isSeekable');
        $this->stream->expects($this->never())->method('__toString');
        $this->stream->expects($this->never())->method('rewind');

        $this->response->expects($this->never())->method('withBody');
        $this->response->expects($this->never())->method('withoutHeader');
        $this->response->expects($this->never())->method('getBody');

        $this->item->expects($this->never())->method('isHit');
        $this->item->expects($this->never())->method('set');
        $this->item->expects($this->never())->method('get');

        $this->pool->expects($this->never())->method('getItem');
        $this->pool->expects($this->never())->method('save')->with($this->item);

        $this->streamFactory->expects($this->never())->method('createStream')->with($body);

        $recorder = new ReplayPlugin($this->pool, $this->streamFactory);

        $recorder->handleRequest($this->request, function () {return new FulfilledPromise($this->response);}, function () {});
    }

    /**
     * @expectedException \RuntimeException
     *
     * @expectedExceptionMessage Cannot replay request [GET] "/users" because record mode is disable
     *
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function testShouldNotReturnRecordRequestOrRecordBecauseRecorderItIsDisable()
    {
        $body = '[{"slug":"john-doe","username":"john.doe","roles":["ROLE_SUPER_ADMIN","ROLE_TEST"],"dateCreated":"2019-02-14T17:22:06+01:00","dateModified":"2019-02-14T17:22:06+01:00"}]';

        $this->request->expects($this->exactly(2))->method('getHeaders')->willReturn([
            'Host' => ['api.example.org'],
            'Content-Type' => ['application/hal+json    '],
            'Accept' => ['application/json'],
        ]);
        $this->request->expects($this->exactly(2))->method('getMethod')->willReturn('GET');
        $this->request->expects($this->exactly(2))->method('getUri')->willReturn('/users');

        $this->stream->expects($this->never())->method('isSeekable');
        $this->stream->expects($this->never())->method('__toString');
        $this->stream->expects($this->never())->method('rewind');

        $this->response->expects($this->never())->method('withBody');
        $this->response->expects($this->never())->method('withoutHeader');
        $this->response->expects($this->never())->method('getBody');

        $this->item->expects($this->once())->method('isHit')->willReturn(false);
        $this->item->expects($this->never())->method('set');
        $this->item->expects($this->never())->method('get');

        $this->pool->expects($this->once())->method('getItem')->with('test_8a04ea89bdfb224f401e14c8f9d825f4d854951a')->willReturn($this->item);
        $this->pool->expects($this->never())->method('save')->with($this->item);

        $this->streamFactory->expects($this->never())->method('createStream')->with($body);

        $recorder = new ReplayPlugin($this->pool, $this->streamFactory);
        $recorder->setBucket('test');

        $recorder->handleRequest($this->request, function () {return new FulfilledPromise($this->response);}, function () {});
    }

    public function testShouldReturnRecordRequest()
    {
        $body = '[{"slug":"john-doe","username":"john.doe","roles":["ROLE_SUPER_ADMIN","ROLE_TEST"],"dateCreated":"2019-02-14T17:22:06+01:00","dateModified":"2019-02-14T17:22:06+01:00"}]';

        $this->request->expects($this->exactly(2))->method('getHeaders')->willReturn([
            'Host' => ['api.example.org'],
            'Content-Type' => ['application/hal+json'],
            'Accept' => ['application/json'],
        ]);
        $this->request->expects($this->exactly(1))->method('getMethod')->willReturn('GET');
        $this->request->expects($this->exactly(1))->method('getUri')->willReturn('/users');

        $this->stream->expects($this->never())->method('isSeekable');
        $this->stream->expects($this->never())->method('__toString');
        $this->stream->expects($this->never())->method('rewind');

        $this->response->expects($this->once())->method('withBody')->willReturn($this->response);
        $this->response->expects($this->never())->method('withoutHeader');
        $this->response->expects($this->never())->method('getBody');

        $this->item->expects($this->once())->method('isHit')->willReturn(true);
        $this->item->expects($this->never())->method('set');
        $this->item->expects($this->once())->method('get')->willReturn(['response' => $this->response, 'body' => $body]);

        $this->pool->expects($this->once())->method('getItem')->with('test_8a04ea89bdfb224f401e14c8f9d825f4d854951a')->willReturn($this->item);
        $this->pool->expects($this->never())->method('save')->with($this->item);

        $this->streamFactory->expects($this->once())->method('createStream')->with($body)->willReturn($this->stream);

        $recorder = new ReplayPlugin($this->pool, $this->streamFactory);
        $recorder->setBucket('test');
        $recorder->setRecorderEnabled(true);

        $this->assertInstanceOf(Promise::class, $recorder->handleRequest($this->request, function () {return new FulfilledPromise($this->response);}, function () {}));
    }

    public function testShouldRecordRequest()
    {
        $body = '[{"slug":"john-doe","username":"john.doe","roles":["ROLE_SUPER_ADMIN","ROLE_TEST"],"dateCreated":"2019-02-14T17:22:06+01:00","dateModified":"2019-02-14T17:22:06+01:00"}]';

        $this->request->expects($this->exactly(2))->method('getHeaders')->willReturn([
            'Host' => ['api.example.org'],
            'Content-Type' => ['application/hal+json'],
            'Accept' => ['application/json'],
        ]);
        $this->request->expects($this->exactly(1))->method('getMethod')->willReturn('GET');
        $this->request->expects($this->exactly(1))->method('getUri')->willReturn('/users');

        $this->stream->expects($this->once())->method('isSeekable')->willReturn(true);
        $this->stream->expects($this->once())->method('__toString')->willReturn($body);
        $this->stream->expects($this->once())->method('rewind');

        $this->response->expects($this->never())->method('withBody');
        $this->response->expects($this->exactly(8))->method('withoutHeader')->willReturn($this->response);
        $this->response->expects($this->exactly(1))->method('getBody')->willReturn($this->stream);

        $this->item->expects($this->once())->method('isHit')->willReturn(false);
        $this->item->expects($this->once())->method('set')->willReturnCallback(function ($value) use ($body) {
            $this->assertSame(['response' => $this->response, 'body' => $body], $value);
        });
        $this->item->expects($this->never())->method('get');

        $this->pool->expects($this->once())->method('getItem')->with('test_8a04ea89bdfb224f401e14c8f9d825f4d854951a')->willReturn($this->item);
        $this->pool->expects($this->once())->method('save')->with($this->item);

        $this->streamFactory->expects($this->never())->method('createStream')->with($body);

        $recorder = new ReplayPlugin($this->pool, $this->streamFactory);
        $recorder->setBucket('test');
        $recorder->setRecorderEnabled(true);

        $this->assertInstanceOf(Promise::class, $recorder->handleRequest($this->request, function () {return new FulfilledPromise($this->response);}, function () {}));
    }

    public function testShouldRecordRequestWithManifest()
    {
        if (is_file('manifest.json')) {
            unlink('manifest.json');
        }

        $body = '[{"slug":"john-doe","username":"john.doe","roles":["ROLE_SUPER_ADMIN","ROLE_TEST"],"dateCreated":"2019-02-14T17:22:06+01:00","dateModified":"2019-02-14T17:22:06+01:00"}]';

        $this->request->expects($this->exactly(2))->method('getHeaders')->willReturn([
            'Host' => ['api.example.org'],
            'Content-Type' => ['application/hal+json'],
            'Accept' => ['application/json'],
        ]);
        $this->request->expects($this->exactly(1))->method('getMethod')->willReturn('GET');
        $this->request->expects($this->exactly(1))->method('getUri')->willReturn('/users');

        $this->stream->expects($this->once())->method('isSeekable')->willReturn(true);
        $this->stream->expects($this->once())->method('__toString')->willReturn($body);
        $this->stream->expects($this->once())->method('rewind');

        $this->response->expects($this->never())->method('withBody');
        $this->response->expects($this->exactly(8))->method('withoutHeader')->willReturn($this->response);
        $this->response->expects($this->exactly(1))->method('getBody')->willReturn($this->stream);

        $this->item->expects($this->once())->method('isHit')->willReturn(false);
        $this->item->expects($this->once())->method('set')->willReturnCallback(function ($value) use ($body) {
            $this->assertSame(['response' => $this->response, 'body' => $body], $value);
        });
        $this->item->expects($this->never())->method('get');

        $this->pool->expects($this->once())->method('getItem')->with('test_8a04ea89bdfb224f401e14c8f9d825f4d854951a')->willReturn($this->item);
        $this->pool->expects($this->once())->method('save')->with($this->item);

        $this->streamFactory->expects($this->never())->method('createStream')->with($body);

        $recorder = new ReplayPlugin($this->pool, $this->streamFactory, 'manifest.json');
        $recorder->setBucket('test');
        $recorder->setRecorderEnabled(true);

        $this->assertInstanceOf(Promise::class, $recorder->handleRequest($this->request, function () {return new FulfilledPromise($this->response);}, function () {}));
        $this->assertTrue(is_file('manifest.json'));
    }
}
