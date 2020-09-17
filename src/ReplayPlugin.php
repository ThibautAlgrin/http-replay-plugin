<?php

declare(strict_types=1);

namespace Http\Client\Common\Plugin;

use Http\Client\Common\Plugin;
use Http\Message\StreamFactory;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Class ReplayPlugin.
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
     * @var string
     */
    private $manifest;

    /**
     * @var string[]
     */
    private $keepHeaders;

    /**
     * ReplayPlugin constructor.
     *
     * @param CacheItemPoolInterface $pool
     * @param StreamFactory          $streamFactory
     * @param string|null            $manifest
     */
    public function __construct(CacheItemPoolInterface $pool, StreamFactory $streamFactory, ?string $manifest = null)
    {
        $this->pool = $pool;
        $this->streamFactory = $streamFactory;
        $this->manifest = $manifest;
        $this->keepHeaders = ['Host', 'Content-Type'];
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

    public function addKeepHeaders(string $keepHeader)
    {
        $this->keepHeaders[] = $keepHeader;
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
    public function handleRequest(RequestInterface $request, callable $next, callable $first): Promise
    {
        $cacheKey = $this->createCacheKey($request);
        $cacheItem = $this->pool->getItem($cacheKey);

        if ($cacheItem->isHit()) {
            return new FulfilledPromise($this->createResponseFromCacheItem($cacheItem));
        }

        if (false === $this->recorderEnabled) {
            throw new \RuntimeException(sprintf(
                'Cannot replay request [%s] "%s" because record mode is disable',
                $request->getMethod(),
                $request->getUri()
            ));
        }

        return $next($request)->then(function (ResponseInterface $response) use ($cacheItem) {
            $bodyStream = $response
                ->withoutHeader('Date')
                ->withoutHeader('ETag')
                ->withoutHeader('X-Debug-Token')
                ->withoutHeader('X-Debug-Token-Link')
                ->getBody()
            ;
            $body = $bodyStream->__toString();
            if ($bodyStream->isSeekable()) {
                $bodyStream->rewind();
            }

            $cacheItem->set([
                'response' => $response
                    ->withoutHeader('Date')
                    ->withoutHeader('ETag')
                    ->withoutHeader('X-Debug-Token')
                    ->withoutHeader('X-Debug-Token-Link'),
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
            (string) $request->getUri(),
            trim(implode(
                ' ',
                array_map(
                    function ($key, array $values) {
                        return in_array($key, \array_unique($this->keepHeaders)) ? $key.':'.implode(',', $values) : '';
                    },
                    array_keys($request->getHeaders()),
                    $request->getHeaders()
                )
            )),
            (string) $request->getBody(),
        ];

        $key = $this->bucket.'_'.hash('sha1', trim(implode(' ', $parts)));

        if (null !== $this->manifest) {
            $this->buildManifest($key, $parts);
        }

        return $key;
    }

    /**
     * @param string $key
     * @param array  $parts
     */
    private function buildManifest(string $key, array $parts)
    {
        $data = is_file($this->manifest) ? json_decode(file_get_contents($this->manifest), true) : [];
        $data[$key] = $parts;
        file_put_contents($this->manifest, json_encode($data));
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
