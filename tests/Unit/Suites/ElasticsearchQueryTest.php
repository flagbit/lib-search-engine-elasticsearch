<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use PHPUnit\Framework\TestCase;
use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\CompositeSearchCriterion;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformation;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;

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
     * @var Context|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubContext;

    /**
     * @var FacetFieldTransformationRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubFacetFieldTransformationRegistry;

    protected function setUp()
    {
        $this->stubCriteria = $this->createMock(CompositeSearchCriterion::class);
        $this->stubContext = $this->createMock(Context::class);
        $this->stubFacetFieldTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);
    }

    public function testExceptionIsThrownIfElasticsearchOperationIsUnknown()
    {
        $this->expectException(UnsupportedSearchCriteriaOperationException::class);

        $this->stubCriteria->method('jsonSerialize')->willReturn([
            'fieldName' => 'foo',
            'fieldValue' => 'bar',
            'operation' => 'non-existing-operation'
        ]);

        $filters = [];

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $elasticsearchQuery->toArray();
    }

    public function testArrayRepresentationOfQueryContainsJoinedSourceMatchingBools()
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

        $this->stubContext->method('getSupportedCodes')->willReturn(['qux']);
        $this->stubContext->method('getValue')->willReturnMap([['qux', 2]]);

        $rangedAttributeOption1 = '-100';
        $rangedAttributeOption2 = '200-300';
        $rangedAttributeOption3 = '400-';

        $filters = [
            'non_ranged_attribute' => ['option1', 'option2'],
            'ranged_attribute' => [$rangedAttributeOption1, $rangedAttributeOption2,$rangedAttributeOption3]
        ];

        $stubFacetFilterRange1 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange1->method('from')->willReturn(null);
        $stubFacetFilterRange1->method('to')->willReturn('123');

        $stubFacetFilterRange2 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange2->method('from')->willReturn('246');
        $stubFacetFilterRange2->method('to')->willReturn('369');

        $stubFacetFilterRange3 = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange3->method('from')->willReturn('492');
        $stubFacetFilterRange3->method('to')->willReturn(null);

        $stubFacetFieldTransformation = $this->createMock(FacetFieldTransformation::class);
        $stubFacetFieldTransformation->method('decode')->willReturnMap([
            [$rangedAttributeOption1, $stubFacetFilterRange1],
            [$rangedAttributeOption2, $stubFacetFilterRange2],
            [$rangedAttributeOption3, $stubFacetFilterRange3],
        ]);

        $this->stubFacetFieldTransformationRegistry->method('hasTransformationForCode')->willReturnMap([
            ['non_ranged_attribute', false],
            ['ranged_attribute', true]
        ]);

        $this->stubFacetFieldTransformationRegistry->method('getTransformationByCode')
            ->with('ranged_attribute')
            ->willReturn($stubFacetFieldTransformation);

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $result = $elasticsearchQuery->toArray();
        $expectedQueryArray = [
            'bool' => [
                'filter' => [
                    $expectedCriteriaBool = [
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
                    $expectedContextBool = [
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
                    ],
                    $expectedFiltersBool = [
                        'bool' => [
                            'filter' => [
                                $expectedNonRangedAttributeFilterBool = [
                                    'bool' => [
                                        'should' => [
                                            [
                                                'bool' => [
                                                    'filter' => [
                                                        'term' => [
                                                            'non_ranged_attribute' => 'option1'
                                                        ]
                                                    ]
                                                ]
                                            ],
                                            [
                                                'bool' => [
                                                    'filter' => [
                                                        'term' => [
                                                            'non_ranged_attribute' => 'option2'
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ],
                                $expectedRangedAttributeFilterBool = [
                                    'bool' => [
                                        'should' => [
                                            [
                                                'bool' => [
                                                    'filter' => [
                                                        'range' => [
                                                            'ranged_attribute' => [
                                                                'lte' => '123'
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
                                                                    'range' => [
                                                                        'ranged_attribute' => [
                                                                            'gte' => '246'
                                                                        ]
                                                                    ]
                                                                ]
                                                            ]
                                                        ],
                                                        [
                                                            'bool' => [
                                                                'filter' => [
                                                                    'range' => [
                                                                        'ranged_attribute' => [
                                                                            'lte' => '369'
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
                                                        'range' => [
                                                            'ranged_attribute' => [
                                                                'gte' => '492'
                                                            ]
                                                        ]
                                                    ]
                                                ]
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

        $filters = [];

        $elasticsearchQuery = new ElasticsearchQuery(
            $this->stubCriteria,
            $this->stubContext,
            $this->stubFacetFieldTransformationRegistry,
            $filters
        );

        $resultA = $elasticsearchQuery->toArray();
        $resultB = $elasticsearchQuery->toArray();

        $this->assertSame($resultA, $resultB);
    }
}
