<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldCollection;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngine;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngineResponse;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\ElasticsearchHttpClient;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use LizardsAndPumpkins\Util\Storage\Clearable;
use stdClass;

class ElasticsearchSearchEngine implements SearchEngine, Clearable
{
    const DOCUMENT_ID_FIELD_NAME = 'id';
    const PRODUCT_ID_FIELD_NAME = 'product_id';
    const FULL_TEXT_SEARCH_FIELD_NAME = 'full_text_search_field_name';

    const SORTING_SUFFIX = '';

    /**
     * @var ElasticsearchHttpClient
     */
    private $client;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    public function __construct(
        ElasticsearchHttpClient $client,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) {
        $this->client = $client;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    public function addDocument(SearchDocument $document)
    {
        $elasticsearchDocument = ElasticsearchDocumentBuilder::fromSearchDocument($document);
        $this->client->update(
            $elasticsearchDocument[ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME],
            $elasticsearchDocument
        );
    }

    public function query(SearchCriteria $criteria, QueryOptions $queryOptions) : SearchEngineResponse
    {
        $query =  new ElasticsearchQuery($criteria, $queryOptions);

        $facetFiltersToIncludeInResult = $queryOptions->getFacetFiltersToIncludeInResult();
        $filterSelection = $queryOptions->getFilterSelection();

        $facetFilterRequest = new ElasticsearchFacetFilterRequest(
            $facetFiltersToIncludeInResult,
            $filterSelection,
            $this->facetFieldTransformationRegistry
        );
        $response = $this->queryElasticsearch($query, $queryOptions, $facetFilterRequest);

        $totalNumberOfResults = $response->getTotalNumberOfResults();
        $matchingProductIds = $response->getMatchingProductIds();
        $facetFieldsCollection = $this->getFacetFieldCollectionFromElasticsearchResponse(
            $response,
            $query,
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
     * @param ElasticsearchQuery $query
     * @param array[] $filterSelection
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetFieldCollection
     */
    private function getFacetFieldCollectionFromElasticsearchResponse(
        ElasticsearchResponse $response,
        ElasticsearchQuery $query,
        QueryOptions $queryOptions,
        array $filterSelection,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : FacetFieldCollection {
        $selectedFilterAttributeCodes = array_keys($filterSelection);
        $nonSelectedFacetFields = $response->getNonSelectedFacetFields($selectedFilterAttributeCodes);
        $selectedFacetFields = $this->getSelectedFacetFields(
            $filterSelection,
            $query,
            $queryOptions,
            $facetFiltersToIncludeInResult
        );

        return new FacetFieldCollection(...$nonSelectedFacetFields, ...$selectedFacetFields);
    }

    /**
     * @param array[] $filterSelection
     * @param ElasticsearchQuery $query
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @return FacetField[]
     */
    private function getSelectedFacetFields(
        array $filterSelection,
        ElasticsearchQuery $query,
        QueryOptions $queryOptions,
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
    ) : array {
        $selectedAttributeCodes = array_keys($filterSelection);
        $facetFields = [];

        foreach ($selectedAttributeCodes as $attributeCodeString) {
            $selectedFiltersExceptCurrentOne = array_diff_key($filterSelection, [$attributeCodeString => []]);
            $facetFilterRequest = new ElasticsearchFacetFilterRequest(
                $facetFiltersToIncludeInResult,
                $selectedFiltersExceptCurrentOne,
                $this->facetFieldTransformationRegistry
            );
            $response = $this->queryElasticsearch($query, $queryOptions, $facetFilterRequest);
            $facetFieldsSiblings = $response->getNonSelectedFacetFields(array_keys($selectedFiltersExceptCurrentOne));
            $facetFields = array_merge($facetFields, $facetFieldsSiblings);
        }

        return $facetFields;
    }

    private function queryElasticsearch(
        ElasticsearchQuery $query,
        QueryOptions $queryOptions,
        ElasticsearchFacetFilterRequest $facetFilterRequest
    ) : ElasticsearchResponse {
        $queryParameters = $query->toArray();
        $facetQueryParameters = $facetFilterRequest->toArray();

        $rowsPerPage = $queryOptions->getRowsPerPage();
        $offset = $queryOptions->getPageNumber() * $rowsPerPage;
        $sortOrderArray = $this->getSortOrderArray($queryOptions->getSortBy());

        $finalQuery = [
            'query' => [
                'bool' => [
                    'filter' => array_merge(
                        [$queryParameters],
                        array_values($facetQueryParameters['facetQueriesRequestParameters'])
                    )
                ]
            ],
            'aggs' => $facetQueryParameters['facetRequestParameters'],
            'size' => $rowsPerPage,
            'from' => $offset,
            'sort' => $sortOrderArray
        ];

        $response = $this->client->select($finalQuery);

        return ElasticsearchResponse::fromElasticsearchResponseArray(
            $response,
            $this->facetFieldTransformationRegistry
        );
    }

    private function getSortOrderArray(SortBy $sortOrderConfig) : array
    {
        return [
            [
                sprintf('%s%s', $sortOrderConfig->getAttributeCode(), self::SORTING_SUFFIX) => [
                    'order' => (string)$sortOrderConfig->getSelectedDirection()
                ]
            ]
        ];
    }

    public static function create(ElasticsearchHttpClient $elasticsearchCurlClient, $facetFieldTransformationRegistry)
    {
        return new self($elasticsearchCurlClient, $facetFieldTransformationRegistry);
    }
}
