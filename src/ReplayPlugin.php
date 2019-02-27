<?php

namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ReplayPlugin
 *
 * @package Http\Client\Common\Plugin
 */
class ReplayPlugin implements Plugin
{
    /**
     * @var CacheItemPoolInterface
     */
    private $pool;

    /**
     * @var StreamFactory
     */
    private $streamFactory;

    /**
     * Specify a replay bucket
     *
     * @var string
     */
    private $bucket;

    /**
     * Record mode is disabled by default, so we can prevent dumb mistake
     *
     * @var bool
     */
    private $recorderEnabled = false;

    /**
     * ReplayPlugin constructor.
     *
     * @param CacheItemPoolInterface $pool
     * @param StreamFactory          $streamFactory
     */
    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory)
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;
    }

    /**
     * @param string $name
     */
    public function setBucket($name)
    {
        $this->bucket = $name;
    }

    /**
     * @param bool $recorderEnabled
     */
    public function setRecorderEnabled($recorderEnabled)
    {
        $this->recorderEnabled = $recorderEnabled;
    }

    /**
     * @param RequestInterface $request
     * @param callable         $next
     * @param callable         $first
     *
     * @throws \Psr\Cache\InvalidArgumentException
     *
     * @return FulfilledPromise|\Http\Promise\Promise
     */
    public function handleRequest(RequestInterface $request, callable $next, callable $first)
    {
        $cacheKey = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
        }

        if ($this->recorderEnabled === false) {
            throw new \RuntimeException(sprintf(
                'Cannot replay request [%s] "%s" because record mode is disable',
                $request->getMethod(),
                $request->getUri()
            ));
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            $bodyStream = $response->withoutHeader('Date')->withoutHeader('X-Debug-Token')->withoutHeader('X-Debug-Token-Link')->getBody();
            $body = $bodyStream->__toString();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }

            $cacheItem->set([
                'response' => $response->withoutHeader('Date')->withoutHeader('X-Debug-Token')->withoutHeader('X-Debug-Token-Link'),
                'body' => $body,
            ]);
            $this->pool->save($cacheItem);

            return $response;
        });
    }

    /**
     * @param RequestInterface $request
     *
     * @return string
     */
    private function createCacheKey(RequestInterface $request)
    {
        if ($this->bucket === null) {
            throw new \LogicException('You need to specify a replay bucket');
        }

        $parts = [
            $request->getMethod(),
            $request->getUri(),
            trim(implode(
                ' ',
                array_map(
                    function ($key, array $values) {
                        return in_array($key, ['Host', 'Content-Type']) ? $key.':'.implode(',', $values) : '';
                    },
                    array_keys($request->getHeaders()),
                    $request->getHeaders()
                )
            )),
            $request->getBody(),
        ];

        $key = $this->bucket.'_'.hash('sha1', trim(implode(' ', $parts)));

        return $key;
    }

    /**
     * @param CacheItemInterface $cacheItem
     *
     * @return ResponseInterface
     */
    private function createResponseFromCacheItem(CacheItemInterface $cacheItem)
    {
        $data = $cacheItem->get();

        return $data['response']->withBody(
            $this->streamFactory->createStream($data['body'])
        );
    }

}