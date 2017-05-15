<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldValue;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\ElasticsearchException;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use LizardsAndPumpkins\Import\Product\ProductId;

class ElasticsearchResponse
{
    const BUCKETS_CONTENTS_KEY = 'buckets';
    /**
     * @var array[]
     */
    private $response;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    /**
     * @param array[] $response
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     */
    private function __construct(array $response, FacetFieldTransformationRegistry $facetFieldTransformationRegistry)
    {
        $this->response = $response;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    /**
     * @param array[] $rawResponse
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     * @return ElasticsearchResponse
     */
    public static function fromElasticsearchResponseArray(
        array $rawResponse,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) : ElasticsearchResponse {
        if (isset($rawResponse['error'])) {
            throw new ElasticsearchException(json_encode($rawResponse));
        }

        return new self($rawResponse, $facetFieldTransformationRegistry);
    }

    public function getTotalNumberOfResults() : int
    {
        return $this->response['hits']['total'] ?? 0;
    }

    /**
     * @return ProductId[]
     */
    public function getMatchingProductIds() : array
    {
        if (! isset($this->response['hits']) || ! isset($this->response['hits']['hits'])) {
            return [];
        }

        return $this->getProductIdsOfMatchingDocuments($this->response['hits']['hits']);
    }

    /**
     * @param string[] $selectedFilterAttributeCodes
     * @return FacetField[]
     */
    public function getNonSelectedFacetFields(array $selectedFilterAttributeCodes) : array
    {
        return $this->getNonSelectedFacetFieldsFromElasticsearchFacetFields($selectedFilterAttributeCodes);
    }

    /**
     * @param string[] $selectedFilterAttributeCodes
     * @return FacetField[]
     */
    private function getNonSelectedFacetFieldsFromElasticsearchFacetFields(array $selectedFilterAttributeCodes) : array
    {
        $facetFieldsArray = $this->getFacetFields();
        $unselectedAttributeCodes = array_diff(array_keys($facetFieldsArray), $selectedFilterAttributeCodes);

        return array_map(function ($attributeCodeString) use ($facetFieldsArray) {
            $facetFieldValues = $facetFieldsArray[$attributeCodeString][self::BUCKETS_CONTENTS_KEY];
            return $this->createFacetField($attributeCodeString, $facetFieldValues);
        }, $unselectedAttributeCodes);
    }
    /**
     * @return array[]
     */
    private function getFacetFields() : array
    {
        return $this->response['aggregations'] ?? [];
    }

    /**
     * @param mixed[] $responseDocuments
     * @return ProductId[]
     */
    private function getProductIdsOfMatchingDocuments(array $responseDocuments) : array
    {
        return array_map(function (array $document) {
            return new ProductId($document['_source'][ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME]);
        }, $responseDocuments);
    }

    /**
     * @param string $attributeCodeString
     * @param mixed[] $facetFieldsValues
     * @return FacetField
     */
    private function createFacetField(string $attributeCodeString, array $facetFieldsValues) : FacetField
    {
        $attributeCode = AttributeCode::fromString($attributeCodeString);
        $facetFieldValues = array_reduce($facetFieldsValues, function ($carry, $fieldData) {
            if ("" === $fieldData['key']) {
                return $carry;
            }
            return array_merge($carry, [new FacetFieldValue($fieldData['key'], $fieldData['doc_count'])]);
        }, []);

        return new FacetField($attributeCode, ...$facetFieldValues);
    }
}
