<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use stdClass;
use LizardsAndPumpkins\Util\Storage\Clearable;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetField;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngine;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngineResponse;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldCollection;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\ElasticsearchHttpClient;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;

class ElasticsearchSearchEngine implements SearchEngine, Clearable
{
    const SEARCH_FIELDS = [
        'name.text^5',
        'manufacturer.text^5',
        'model_name.text^8',
        'model.text^5',
        'ean.text^5',
        'children_ean^5'
    ];
    const NAME_FIELD = 'name.text';
    const DOCUMENT_ID_FIELD_NAME = 'id';
    const PRODUCT_ID_FIELD_NAME = 'product_id';

    const SORTING_SUFFIX = '';

    /**
     * @var ElasticsearchHttpClient
     */
    private $client;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    /**
     * ElasticsearchSearchEngine constructor.
     * @param ElasticsearchHttpClient $client
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     */
    public function __construct(
        ElasticsearchHttpClient $client,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) {
        $this->client = $client;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    /**
     * @param SearchDocument $document
     */
    public function addDocument(SearchDocument $document)
    {
        $elasticsearchDocument = ElasticsearchDocumentBuilder::fromSearchDocument($document);
        $this->client->update(
            $elasticsearchDocument[self::DOCUMENT_ID_FIELD_NAME],
            $elasticsearchDocument
        );
    }

    /**
     * @param SearchCriteria $criteria
     * @param QueryOptions $queryOptions
     * @return SearchEngineResponse
     */
    public function query(SearchCriteria $criteria, QueryOptions $queryOptions) : SearchEngineResponse
    {
        $filterSelection = $queryOptions->getFilterSelection();
        $query = new ElasticsearchQueryV2(
            $criteria,
            $queryOptions->getQueryFromString(),
            $queryOptions->getCriteriaFromString()
        );

        $facetFiltersToIncludeInResult = $queryOptions->getFacetFiltersToIncludeInResult();
        $aggregationsRequest = new ElasticsearchAggregationsRequest(
            $facetFiltersToIncludeInResult,
            $this->facetFieldTransformationRegistry
        );

        $response = $this->queryElasticsearch($query, $aggregationsRequest, $queryOptions);

        $totalNumberOfResults = $response->getTotalNumberOfResults();
        $matchingProductIds = $response->getMatchingProductIds();
        $facetFieldsCollection = $this->getFacetFieldCollectionFromElasticsearchResponse(
            $response,
            $criteria,
            $queryOptions,
            $filterSelection,
            $facetFiltersToIncludeInResult
        );

        return new SearchEngineResponse($facetFieldsCollection, $totalNumberOfResults, ...$matchingProductIds);
    }

    public function clear()
    {
        $request = ['query' => ['match_all' => new stdClass()]];
        $this->client->clear($request);
    }

    /**
     * @param ElasticsearchResponse $response
     * @param SearchCriteria $criteria
     * @param QueryOptions $queryOptions
     * @param array[] $filterSelection
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetFieldCollection
     */
    private function getFacetFieldCollectionFromElasticsearchResponse(
        ElasticsearchResponse $response,
        SearchCriteria $criteria,
        QueryOptions $queryOptions,
        array $filterSelection,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : FacetFieldCollection {
        $selectedFilterAttributeCodes = array_keys($filterSelection);
        $nonSelectedFacetFields = $response->getNonSelectedFacetFields($selectedFilterAttributeCodes);
        $selectedFacetFields = $this->getSelectedFacetFields(
            $filterSelection,
            $criteria,
            $queryOptions,
            $facetFiltersToIncludeInResult
        );

        return new FacetFieldCollection(...$nonSelectedFacetFields, ...$selectedFacetFields);
    }

    /**
     * @param array[] $filterSelection
     * @param SearchCriteria $criteria
     * @param QueryOptions $queryOptions
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetField[]
     */
    private function getSelectedFacetFields(
        array $filterSelection,
        SearchCriteria $criteria,
        QueryOptions $queryOptions,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : array {
        $selectedAttributeCodes = array_keys($filterSelection);
        $facetFields = [];

        foreach ($selectedAttributeCodes as $attributeCodeString) {
            $selectedFiltersExceptCurrentOne = array_diff_key($filterSelection, [$attributeCodeString => []]);
            $query = new ElasticsearchQueryV2(
                $criteria,
                $queryOptions->getQueryFromString(),
                $queryOptions->getCriteriaFromString()
            );

            $aggregationsRequest = new ElasticsearchAggregationsRequest(
                $facetFiltersToIncludeInResult,
                $this->facetFieldTransformationRegistry
            );
            $response = $this->queryElasticsearch($query, $aggregationsRequest, $queryOptions);
            $facetFieldsSiblings = $response->getNonSelectedFacetFields(array_keys($selectedFiltersExceptCurrentOne));
            $facetFields = array_merge($facetFields, $facetFieldsSiblings);
        }

        return $facetFields;
    }

    /**
     * @param ElasticsearchQueryV2 $query
     * @param ElasticsearchAggregationsRequest $aggregationsRequest
     * @param QueryOptions $queryOptions
     * @return ElasticsearchResponse
     */
    private function queryElasticsearch(
        ElasticsearchQueryV2 $query,
        ElasticsearchAggregationsRequest $aggregationsRequest,
        QueryOptions $queryOptions
    ) : ElasticsearchResponse {
        $request = [];

        $query = $query->toArray();
        if (\count($query) !== 0) {
            $request['query'] = $query;
        }

        $aggregations = $aggregationsRequest->toArray();
        if (\count($aggregations) !== 0) {
            $request['aggregations'] = $aggregations;
        }

        $rowsPerPage = $queryOptions->getRowsPerPage();
        $request['size'] = $rowsPerPage;

        $offset = $queryOptions->getPageNumber() * $rowsPerPage;
        $request['from'] = $offset;

        $request['track_scores'] = true;
        $sortOrderArray = $this->getSortOrderArray($queryOptions->getSortBy());
        $request['sort'] = $sortOrderArray;

        $response = $this->client->select($request);

        return ElasticsearchResponse::fromElasticsearchResponseArray(
            $response,
            $this->facetFieldTransformationRegistry
        );
    }

    /**
     * @param SortBy $sortOrderConfig
     * @return array
     */
    private function getSortOrderArray(SortBy $sortOrderConfig) : array
    {
        return [
            [
                '_score' => [
                    'order' => 'desc'
                ]
            ],
            [
                sprintf('%s%s', $sortOrderConfig->getAttributeCode(), self::SORTING_SUFFIX) => [
                    'order' => (string)$sortOrderConfig->getSelectedDirection()
                ]
            ]
        ];
    }

    /**
     * @param ElasticsearchHttpClient $elasticsearchCurlClient
     * @param $facetFieldTransformationRegistry
     * @return ElasticsearchSearchEngine
     */
    public static function create (
        ElasticsearchHttpClient $elasticsearchCurlClient,
        $facetFieldTransformationRegistry
    ): ElasticsearchSearchEngine {
        return new self($elasticsearchCurlClient, $facetFieldTransformationRegistry);
    }
}
