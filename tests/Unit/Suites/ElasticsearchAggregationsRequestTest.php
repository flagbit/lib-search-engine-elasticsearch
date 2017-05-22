<?php

declare(strict_types = 1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestRangedField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\Import\Product\AttributeCode;
use PHPUnit\Framework\TestCase;
use \PHPUnit_Framework_MockObject_MockObject as MockObject;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchAggregationsRequest
 */
class ElasticsearchAggregationsRequestTest extends TestCase
{
    /**
     * @var FacetFiltersToIncludeInResult|MockObject $stubFacetFiltersToIncludeInResult
     */
    private $stubFacetFiltersToIncludeInResult;

    /**
     * @var FacetFieldTransformationRegistry|MockObject $dummyFacetFieldsTransformationRegistry
     */
    private $dummyFacetFieldsTransformationRegistry;

    /**
     * @var ElasticsearchAggregationsRequest
     */
    private $aggregationsRequest;

    /**
     * @param string $attributeCodeString
     * @return FacetFilterRequestField|MockObject
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
     * @param AttributeCode $attributeCode
     * @param string|null $rangeFrom
     * @param string|null $rangeTo
     * @return FacetFilterRequestRangedField|MockObject
     */
    private function createStubFacetFilterRequestRangedField(
        AttributeCode $attributeCode,
        $rangeFrom,
        $rangeTo
    ) : FacetFilterRequestRangedField {
        $stubFacetFilterRange = $this->createMock(FacetFilterRange::class);
        $stubFacetFilterRange->method('from')->willReturn($rangeFrom);
        $stubFacetFilterRange->method('to')->willReturn($rangeTo);

        $stubFacetFilterRequestRangedField = $this->createMock(FacetFilterRequestRangedField::class);
        $stubFacetFilterRequestRangedField->method('getAttributeCode')->willReturn($attributeCode);
        $stubFacetFilterRequestRangedField->method('isRanged')->willReturn(true);
        $stubFacetFilterRequestRangedField->method('getRanges')->willReturn([$stubFacetFilterRange]);

        return $stubFacetFilterRequestRangedField;
    }

    final protected function setUp()
    {
        $this->stubFacetFiltersToIncludeInResult = $this->createMock(FacetFiltersToIncludeInResult::class);
        $this->dummyFacetFieldsTransformationRegistry = $this->createMock(FacetFieldTransformationRegistry::class);

        $this->aggregationsRequest = new ElasticsearchAggregationsRequest(
            $this->stubFacetFiltersToIncludeInResult,
            $this->dummyFacetFieldsTransformationRegistry
        );
    }

    public function testReturnsEmptyArrayIfNoFieldsAreRequested()
    {
        $this->stubFacetFiltersToIncludeInResult->method('getFields')->willReturn([]);
        $this->assertSame([], $this->aggregationsRequest->toArray());
    }


    public function testNonRangedFacetFieldsAreAddedToFacetFieldElementOfResultArray()
    {
        $testAttributeCode = 'foo';

        $stubField = $this->createStubFacetFilterRequestField($testAttributeCode);
        $this->stubFacetFiltersToIncludeInResult->method('getFields')->willReturn([$stubField]);

        $expectedArray = [$testAttributeCode => ['terms' => ['field' => $testAttributeCode]]];

        $this->assertSame($expectedArray, $this->aggregationsRequest->toArray());
    }

    public function testRangedFacetFieldsAreAddedToFacetQueryElementOfResultArray()
    {
        $testAttributeCodeSting = 'foo';
        $testAttributeCode = AttributeCode::fromString($testAttributeCodeSting);
        $rangeFrom = null;
        $rangeTo = 10;

        $stubRangedField = $this->createStubFacetFilterRequestRangedField($testAttributeCode, $rangeFrom, $rangeTo);
        $this->stubFacetFiltersToIncludeInResult->method('getFields')->willReturn([$stubRangedField]);

        $expectedArray = [
            $testAttributeCodeSting => [
                'range' => [
                    'field' => $testAttributeCode,
                    'ranges' => [
                        ['to' => $rangeTo]
                    ]
                ]
            ]
        ];

        $this->assertSame($expectedArray, $this->aggregationsRequest->toArray());
    }
}
