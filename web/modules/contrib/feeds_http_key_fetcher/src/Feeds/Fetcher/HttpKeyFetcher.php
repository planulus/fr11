<?php

namespace Drupal\feeds_http_key_fetcher\Feeds\Fetcher;


use Drupal\feeds\Exception\EmptyFeedException;
use Drupal\feeds\Feeds\Fetcher\HttpFetcher;
use Drupal\feeds\Result\HttpFetcherResult;
use Drupal\feeds\StateInterface;
use Drupal\feeds\Utility\Feed;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\RequestOptions;
use Symfony\Component\HttpFoundation\Response;
use Drupal\feeds\FeedInterface;

/**
 * Defines an HTTP fetcher.
 *
 * @FeedsFetcher(
 *   id = "httpkey",
 *   title = @Translation("Download from URL with X API Key"),
 *   description = @Translation("Downloads data from a URL using Drupal's HTTP request handler with specified X API key header."),
 *   form = {
 *     "configuration" = "Drupal\feeds\Feeds\Fetcher\Form\HttpFetcherForm",
 *     "feed" = "Drupal\feeds_http_key_fetcher\Feeds\Fetcher\Form\HttpKeyFetcherFeedForm",
 *   }
 * )
 */
class HttpKeyFetcher extends HTTPFetcher {

    public function defaultFeedConfiguration() {
        $default_configuration = parent::defaultConfiguration();
        $default_configuration['key'] = '';
        return $default_configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(FeedInterface $feed, StateInterface $state) {

        $sink = $this->fileSystem->tempnam('temporary://', 'feeds_http_fetcher');
        $sink = $this->fileSystem->realpath($sink);

        // Get cache key if caching is enabled.
        $cache_key = $this->useCache() ? $this->getCacheKey($feed) : FALSE;
        $feed_configuration = $feed->getConfigurationFor($this);
        $response = $this->get($feed->getSource(), $sink, $cache_key, [], $feed_configuration);
        // @todo Handle redirects.
        // @codingStandardsIgnoreStart
        // $feed->setSource($response->getEffectiveUrl());
        // @codingStandardsIgnoreEnd

        // 304, nothing to see here.
        if ($response->getStatusCode() == Response::HTTP_NOT_MODIFIED) {
            $state->setMessage($this->t('The feed has not been updated.'));
            throw new EmptyFeedException();
        }

        return new HttpFetcherResult($sink, $response->getHeaders());
    }
  /**
   * Performs a GET request.
   *
   * @param string $url
   *   The URL to GET.
   * @param string $sink
   *   The location where the downloaded content will be saved. This can be a
   *   resource, path or a StreamInterface object.
   * @param string $cache_key
   *   (optional) The cache key to find cached headers. Defaults to false.
   * @param string $key
   *   (optional) The AUthorization X API key. Defaults to null.
   *
   * @return \Guzzle\Http\Message\Response
   *   A Guzzle response.
   *
   * @throws \RuntimeException
   *   Thrown if the GET request failed.
   *
   * @see \GuzzleHttp\RequestOptions
   */
  protected function get($url, $sink, $cache_key = FALSE, array $options = [], array $feed_configuration = []) {
    $url = Feed::translateSchemes($url);

    $options = [RequestOptions::SINK => $sink];

    // This is the magic add the headers here so allows the request
    if (!empty($feed_configuration['key'])) {
      $options[RequestOptions::HEADERS]['x-api-key'] = $feed_configuration['key'];
    }
      // Add cached headers if requested.
    if ($cache_key && ($cache = $this->cache->get($cache_key))) {
      if (isset($cache->data['etag'])) {
        $options[RequestOptions::HEADERS]['If-None-Match'] = $cache->data['etag'];
      }
      if (isset($cache->data['last-modified'])) {
        $options[RequestOptions::HEADERS]['If-Modified-Since'] = $cache->data['last-modified'];
      }
    }

    try {
      $response = $this->client->get($url, $options);
    }
    catch (RequestException $e) {
      $args = ['%site' => $url, '%error' => $e->getMessage()];
      throw new \RuntimeException($this->t('The feed from %site seems to be broken because of error "%error".', $args));
    }

    if ($cache_key) {
      $this->cache->set($cache_key, array_change_key_case($response->getHeaders()));
    }

    return $response;
  }
}
