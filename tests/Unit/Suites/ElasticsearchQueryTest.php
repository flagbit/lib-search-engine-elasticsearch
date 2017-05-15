<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\Query\SortBy;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\CompositeSearchCriterion;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchQuery
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorEqual
 * @uses   \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorGreaterOrEqualThan
 */
class ElasticsearchQueryTest extends TestCase
{
    /**
     * @var SearchCriteria|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubCriteria;

    /**
     * @var QueryOptions|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubQueryOptions;

    /**
     * @var ElasticsearchQuery
     */
    private $elasticsearchQuery;

    protected function setUp()
    {
        $this->stubCriteria = $this->createMock(CompositeSearchCriterion::class);
        $this->stubQueryOptions = $this->createMock(QueryOptions::class);
        $this->elasticsearchQuery = new ElasticsearchQuery($this->stubCriteria, $this->stubQueryOptions);
    }

    public function testExceptionIsThrownIfElasticsearchOperationIsUnknown()
    {
        $this->expectException(UnsupportedSearchCriteriaOperationException::class);

        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'fieldName' => 'foo',
            'fieldValue' => 'bar',
            'operation' => 'non-existing-operation'
        ]);

        $this->elasticsearchQuery->toArray();
    }

    public function testArrayRepresentationOfQueryContainsFormattedQueryString()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([
            'condition' => CompositeSearchCriterion::OR_CONDITION,
            'criteria' => [
                [
                    'operation' => 'Equal',
                    'fieldName' => 'foo',
                    'fieldValue' => 'bar',
                ],
                [
                    'operation' => 'GreaterOrEqualThan',
                    'fieldName' => 'baz',
                    'fieldValue' => 1,
                ],
            ]
        ]);

        $stubContext = $this->createMock(Context::class);
        $stubContext->method('getSupportedCodes')->willReturn(['qux']);
        $stubContext->method('getValue')->willReturnMap([['qux', 2]]);
        $this->stubQueryOptions->method('getContext')->willReturn($stubContext);
        
        $result = $this->elasticsearchQuery->toArray();
        $expectedQueryArray = [
            'bool' => [
                'filter' => [
                    [
                        'bool' => [
                            'should' => [
                                [
                                    'bool' => [
                                        'filter' => [
                                            'term' => [
                                                'foo' => 'bar'
                                            ]
                                        ]
                                    ]
                                ],
                                [
                                    'bool' => [
                                        'filter' => [
                                            'range' => [
                                                'baz' => [
                                                    'gte' => '1'
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        'bool' => [
                            'filter' => [
                                [
                                    'bool' => [
                                        'filter' => [
                                            'term' => [
                                                'qux' => '2'
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $this->assertArrayHasKey('bool', $result);
        $this->assertSame($expectedQueryArray, $result);
    }

    public function testArrayRepresentationOfElasticsearchQueryIsMemoized()
    {
        $this->stubCriteria->expects($this->once())->method('jsonSerialize')->willReturn([
            'fieldName' => 'foo',
            'fieldValue' => 'bar',
            'operation' => 'Equal'
        ]);

        $stubContext = $this->createMock(Context::class);
        $stubContext->method('getSupportedCodes')->willReturn([]);
        $this->stubQueryOptions->method('getContext')->willReturn($stubContext);

        $stubSortOrderConfig = $this->createMock(SortBy::class);
        $this->stubQueryOptions->method('getSortBy')->willReturn($stubSortOrderConfig);

        $resultA = $this->elasticsearchQuery->toArray();
        $resultB = $this->elasticsearchQuery->toArray();

        $this->assertSame($resultA, $resultB);
    }
}
