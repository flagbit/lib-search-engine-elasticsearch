<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestRangedField;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchFacetFilterRequest
 */
class ElasticsearchFacetFilterRequestTest extends TestCase
{
    /**
     * @var FacetFiltersToIncludeInResult|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubFacetFiltersToIncludeInResult;

    /**
     * @var FacetFieldTransformationRegistry|\PHPUnit_Framework_MockObject_MockObject
     */
    private $stubFacetFieldTransformationRegistry;

    /**
     * @param string $attributeCodeString
     * @return FacetFilterRequestField|\PHPUnit_Framework_MockObject_MockObject
     */
    private function createStubFacetFilterRequestField(string $attributeCodeString) : FacetFilterRequestField
    {
        $stubAttribute = $this->createMock(AttributeCode::class);
        $stubAttribute->method('__toString')->willReturn($attributeCodeString);

        $stubFacetFilterRequestField = $this->createMock(FacetFilterRequestField::class);
        $stubFacetFilterRequestField->method('getAttributeCode')->willReturn($stubAttribute);

        return $stubFacetFilterRequestField;
    }

    /**
     * @param string $attributeCodeString
     * @param string|null $rangeFrom
     * @param string|null $rangeTo
     * @return FacetFilterRequestRangedField|\PHPUnit_Framework_MockObject_MockObject
     */
    private function createStubFacetFilterRequestRangedField(
        string $attributeCodeString,
        $rangeFrom,
        $rangeTo
    ) : FacetFilterRequestRangedField {
        $stubAttribute = $this->createMock(AttributeCode::class);
        $stubAttribute->method('__toString')->willReturn($attributeCodeString);

        $stubFacetFilterRange = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange->method('from')->willReturn($rangeFrom);
        $stubFacetFilterRange->method('to')->willReturn($rangeTo);

        $stubFacetFilterRequestRangedField = $this->createMock(FacetFilterRequestRangedField::class);
        $stubFacetFilterRequestRangedField->method('getAttributeCode')->willReturn($stubAttribute);
        $stubFacetFilterRequestRangedField->method('isRanged')->willReturn(true);
        $stubFacetFilterRequestRangedField->method('getRanges')->willReturn([$stubFacetFilterRange]);

        return $stubFacetFilterRequestRangedField;
    }

    protected function setUp()
    {
        $this->stubFacetFiltersToIncludeInResult = $this->createMock(FacetFiltersToIncludeInResult::class);
        $this->stubFacetFieldTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);
    }

    public function testEmptyArrayIsReturnedIfNoFacetFieldsAreRequested()
    {
        $this->stubFacetFiltersToIncludeInResult->method('getFields')->willReturn([]);
        $testFilterSelection = [];

        $elasticsearchFacetFilterRequest = new ElasticsearchFacetFilterRequest(
            $this->stubFacetFiltersToIncludeInResult,
            $testFilterSelection,
            $this->stubFacetFieldTransformationRegistry
        );
        $expectedArray = [
            'facetRequestParameters' => [],
            'facetQueriesRequestParameters' => []
        ];

        $this->assertSame($expectedArray, $elasticsearchFacetFilterRequest->toArray());
    }

    public function testNonRangedFacetFieldsAreAddedToFacetFieldElementOfResultArray()
    {
        $testAttributeCode = 'foo';

        $stubField = $this->createStubFacetFilterRequestField($testAttributeCode);
        $this->stubFacetFiltersToIncludeInResult->method('getFields')->willReturn([$stubField]);

        $testFilterSelection = [];

        $elasticsearchFacetFilterRequest = new ElasticsearchFacetFilterRequest(
            $this->stubFacetFiltersToIncludeInResult,
            $testFilterSelection,
            $this->stubFacetFieldTransformationRegistry
        );

        $expectedArray = [
            'facetRequestParameters' => [
                $testAttributeCode => [
                    'terms' => [
                        'field' => $testAttributeCode
                    ]
                ]
            ],
            'facetQueriesRequestParameters' => []
        ];

        $this->assertSame($expectedArray, $elasticsearchFacetFilterRequest->toArray());
    }
    
    public function testSelectedFieldsAreAddedToFqElementOfResultArray()
    {
        $testFilterSelection = ['foo' => ['bar', 'baz']];
        
        $elasticsearchFacetFilterRequest = new ElasticsearchFacetFilterRequest(
            $this->stubFacetFiltersToIncludeInResult,
            $testFilterSelection,
            $this->stubFacetFieldTransformationRegistry
        );

        $expectedArray = [
            'facetRequestParameters' => [],
            'facetQueriesRequestParameters' => [
                [
                    'bool' => [
                        'filter' => [
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
                                                'term' => [
                                                    'foo' => 'baz'
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

        $this->assertSame($expectedArray, $elasticsearchFacetFilterRequest->toArray());
    }

    public function testSpecialCharactersInSelectedFieldsInFacetQueriesElementOfResultArrayAreEscaped()
    {
        $testFilterSelection = ['fo+o' => ['ba\r', 'ba"z']];

        $elasticsearchFacetFilterRequest = new ElasticsearchFacetFilterRequest(
            $this->stubFacetFiltersToIncludeInResult,
            $testFilterSelection,
            $this->stubFacetFieldTransformationRegistry
        );
        
        $expectedFacetFilterRequestArray = [
            'facetRequestParameters' => [],
            'facetQueriesRequestParameters' => [
                [
                    'bool' => [
                        'filter' => [
                            'bool' => [
                                'should' => [
                                    [
                                        'bool' => [
                                            'filter' => [
                                                'term' => [
                                                    'fo\+o' => 'ba\r'
                                                ]
                                            ]
                                        ]
                                    ],
                                    [
                                        'bool' => [
                                            'filter' => [
                                                'term' => [
                                                    'fo\+o' => 'ba"z'
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

        $this->assertSame($expectedFacetFilterRequestArray, $elasticsearchFacetFilterRequest->toArray());
    }
}
