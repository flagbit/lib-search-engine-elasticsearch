<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\Exception\ElasticsearchConnectionException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\CurlElasticsearchHttpClient
 */
class CurlElasticsearchHttpClientTest extends TestCase
{
    /**
     * @var CurlElasticsearchHttpClient
     */
    private $client;

    /**
     * @var string
     */
    private static $requestUrl;

    /**
     * @var mixed[]
     */
    private static $curlOptionsSet = [];

    /**
     * @var string
     */
    private static $returnType = 'json';

    public static function trackRequestUrl(string $url)
    {
        self::$requestUrl = $url;
    }

    /**
     * @param int $option
     * @param mixed $value
     */
    public static function trackCurlOptionSet(int $option, $value)
    {
        self::$curlOptionsSet[$option] = $value;
    }

    public static function getReturnType() : string
    {
        return self::$returnType;
    }

    protected function setUp()
    {
        $testElasticsearchConnectionPath = 'http://localhost:9200/prod/prod';
        $this->client = new CurlElasticsearchHttpClient($testElasticsearchConnectionPath);
    }

    protected function tearDown()
    {
        self::$requestUrl = null;
        self::$curlOptionsSet = [];
        self::$returnType = 'json';
    }

    public function testInstanceOfElasticsearchHttpClientIsReturned()
    {
        $this->assertInstanceOf(ElasticsearchHttpClient::class, $this->client);
    }

    public function testUpdateRequestIsSentToElasticsearchViaPut()
    {
        $documentId = 'foo';
        $parameters = ['bar' => 'baz'];
        $this->client->update($documentId, $parameters);

        $this->assertArrayHasKey(CURLOPT_CUSTOMREQUEST, self::$curlOptionsSet);
        $this->assertSame('PUT', self::$curlOptionsSet[CURLOPT_CUSTOMREQUEST]);

        $this->assertArrayHasKey(CURLOPT_POSTFIELDS, self::$curlOptionsSet);
        $this->assertSame(json_encode($parameters), self::$curlOptionsSet[CURLOPT_POSTFIELDS]);
    }

    public function testExceptionIsThrownIfElasticsearchIsNotAccessibleWithUpdateCall()
    {
        self::$returnType = 'html';

        $this->expectException(ElasticsearchConnectionException::class);
        $this->expectExceptionMessage('Error 404 Not Found');

        $documentId = 'foo';
        $parameters = [];
        $this->client->update($documentId, $parameters);
    }

    public function testSuccessfulUpdateRequestReturnsAnArray()
    {
        $documentId = 'foo';
        $parameters = [];
        $result = $this->client->update($documentId, $parameters);

        $this->assertInternalType('array', $result);
    }

    public function testSelectRequestHasSelectServlet()
    {
        $parameters = [];
        $this->client->select($parameters);

        $path = parse_url(self::$requestUrl, PHP_URL_PATH);
        $lastToken = preg_replace('/.*\//', '', $path);

        $this->assertSame(ElasticsearchHttpClient::SEARCH_SERVLET, $lastToken);
    }

    public function testExceptionIsThrownIfElasticsearchDoesNotReturnValidJsonForSelectRequest()
    {
        self::$returnType = 'html';

        $this->expectException(ElasticsearchConnectionException::class);
        $this->expectExceptionMessage('Error 404 Not Found');

        $parameters = [];
        $this->client->select($parameters);
    }

    public function testSuccessfulSelectRequestReturnsAnArray()
    {
        $parameters = [];
        $result = $this->client->select($parameters);

        $this->assertInternalType('array', $result);
    }
}

/**
 * @param string $url
 * @return resource
 */
function curl_init(string $url)
{
    CurlElasticsearchHttpClientTest::trackRequestUrl($url);
    return \curl_init($url);
}

/**
 * @param resource $handle
 * @param int $option
 * @param mixed $value
 */
function curl_setopt($handle, int $option, $value)
{
    CurlElasticsearchHttpClientTest::trackCurlOptionSet($option, $value);
}

/**
 * @param resource $handle
 * @return string
 */
function curl_exec($handle) : string
{
    if (CurlElasticsearchHttpClientTest::getReturnType() === 'html') {
        return '<html><title>Error 404 Not Found</title><body></body></html>';
    }

    return json_encode([]);
}
