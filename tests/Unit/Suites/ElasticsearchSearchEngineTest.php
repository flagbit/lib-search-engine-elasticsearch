<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\Context\SelfContainedContext;
use LizardsAndPumpkins\Context\SelfContainedContextBuilder;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortDirection;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriterionEqual;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocumentFieldCollection;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchEngineResponse;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http\ElasticsearchHttpClient;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use LizardsAndPumpkins\Import\Product\ProductId;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchSearchEngine
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchDocumentBuilder
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchFacetFilterRequest
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchQuery
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchResponse
 */
class ElasticsearchSearchEngineTest extends TestCase
{
    /**
     * @var ElasticsearchSearchEngine
     */
    private $searchEngine;

    /**
     * @var ElasticsearchHttpClient|\PHPUnit_Framework_MockObject_MockObject
     */
    private $mockHttpClient;

    private function createTestContext() : Context
    {
        return SelfContainedContextBuilder::rehydrateContext([
            'website' => 'website',
            'version' => '-1'
        ]);
    }

    /**
     * @param string $sortByFieldCode
     * @param string $sortDirection
     * @return SortBy|\PHPUnit_Framework_MockObject_MockObject
     */
    private function createStubSortOrderConfig(string $sortByFieldCode, string $sortDirection) : SortBy
    {
        $stubAttributeCode = $this->createMock(AttributeCode::class);
        $stubAttributeCode->method('__toString')->willReturn($sortByFieldCode);

        $sortOrderConfig = $this->createMock(SortBy::class);
        $sortOrderConfig->method('getAttributeCode')->willReturn($stubAttributeCode);
        $sortOrderConfig->method('getSelectedDirection')->willReturn(SortDirection::create($sortDirection));

        return $sortOrderConfig;
    }

    /**
     * @param array[] $filterSelection
     * @return QueryOptions|\PHPUnit_Framework_MockObject_MockObject
     */
    private function createStubQueryOptions(array $filterSelection) : QueryOptions
    {
        $facetFiltersToIncludeInResult = new FacetFiltersToIncludeInResult();
        $stubSortOrderConfig = $this->createStubSortOrderConfig('foo', SortDirection::ASC);

        $stubQueryOptions = $this->createMock(QueryOptions::class);
        $stubQueryOptions->method('getFilterSelection')->willReturn($filterSelection);
        $stubQueryOptions->method('getContext')->willReturn($this->createTestContext());
        $stubQueryOptions->method('getFacetFiltersToIncludeInResult')->willReturn($facetFiltersToIncludeInResult);
        $stubQueryOptions->method('getRowsPerPage')->willReturn(100);
        $stubQueryOptions->method('getPageNumber')->willReturn(0);
        $stubQueryOptions->method('getSortBy')->willReturn($stubSortOrderConfig);

        return $stubQueryOptions;
    }

    protected function setUp()
    {
        $this->mockHttpClient = $this->createMock(ElasticsearchHttpClient::class);

        $stubTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);

        $this->searchEngine = new ElasticsearchSearchEngine($this->mockHttpClient, $stubTransformationRegistry);
    }

    public function testUpdateRequestContainingElasticsearchDocumentsIsSentToHttpClient()
    {
        $searchDocumentFieldCollection = SearchDocumentFieldCollection::fromArray(['foo' => 'bar']);
        $context = new SelfContainedContext(['baz' => 'qux']);
        $productId = new ProductId(uniqid());
        $documentId = $productId . '_' . $context;

        $searchDocument = new SearchDocument($searchDocumentFieldCollection, $context, $productId);
        $expectedElasticsearchDocument = ElasticsearchDocumentBuilder::fromSearchDocument($searchDocument);

        $this->mockHttpClient->expects($this->once())->method('update')->with(
            $documentId,
            $expectedElasticsearchDocument
        );
        $this->searchEngine->addDocument($searchDocument);
    }

    public function testUpdateRequestFlushingElasticsearchIndexIsSentToHttpClient()
    {
        $this->mockHttpClient->expects($this->once())->method('clear')->with(['query' => ['match_all' => new stdClass()]]);
        $this->searchEngine->clear();
    }

    public function testSearchEngineResponseIsReturned()
    {
        $this->mockHttpClient->method('select')->willReturn([]);

        $searchCriteria = new SearchCriterionEqual('foo', 'bar');
        $filterSelection = [];
        
        $result = $this->searchEngine->query($searchCriteria, $this->createStubQueryOptions($filterSelection));

        $this->assertInstanceOf(SearchEngineResponse::class, $result);
    }
}
