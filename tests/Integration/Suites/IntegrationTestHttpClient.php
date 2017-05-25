<?php

declare(strict_types=1);

namespace LizardsAndPumpkins;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\CurlElasticsearchHttpClient;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\ElasticsearchHttpClient;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\Exception\ElasticsearchConnectionException;

class IntegrationTestHttpClient extends CurlElasticsearchHttpClient
{
    /**
     * @var string
     */
    private $elasticsearchConnectionPath;

    public function __construct(string $elasticsearchConnectionPath)
    {
        $this->elasticsearchConnectionPath = $elasticsearchConnectionPath;
        parent::__construct($elasticsearchConnectionPath);
    }

    /**
     * @param string $id
     * @param mixed[] $parameters
     * @return mixed
     */
    public function update(string $id, array $parameters)
    {
        $url = sprintf('%s/%s?refresh', $this->constructUrl(ElasticsearchHttpClient::UPDATE_SERVLET), urlencode($id));

        $curlHandle = $this->createCurlHandle($url);
        curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($curlHandle, CURLOPT_POSTFIELDS, json_encode($parameters));

        return $this->executeCurlRequest($curlHandle);
    }

    private function constructUrl(string $servlet) : string
    {
        if ("" === $servlet) {
            return $this->elasticsearchConnectionPath;
        }

        return sprintf('%s/%s', $this->elasticsearchConnectionPath, $servlet);
    }

    /**
     * @param string $url
     * @return resource
     */
    private function createCurlHandle(string $url)
    {
        $curlHandle = curl_init($url);

        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, ['Content-type: application/json']);

        return $curlHandle;
    }

    /**
     * @param resource $curlHandle
     * @return mixed
     */
    private function executeCurlRequest($curlHandle)
    {
        $responseJson = curl_exec($curlHandle);
        $response = json_decode($responseJson, true);
        $this->validateResponseType($responseJson);

        return $response;
    }

    private function validateResponseType(string $rawResponse)
    {
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMessage = preg_replace('/.*<title>|<\/title>.*/ism', '', $rawResponse);
            throw new ElasticsearchConnectionException($errorMessage);
        }
    }
}
