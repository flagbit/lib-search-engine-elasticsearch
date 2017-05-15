<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use PHPUnit\Framework\TestCase;

abstract class AbstractElasticsearchQueryOperatorTest extends TestCase
{
    /**
     * @var ElasticsearchQueryOperator
     */
    private $operator;

    protected function setUp()
    {
        $this->operator = $this->getOperatorInstance();
    }

    public function testElasticsearchQueryOperatorInterfaceIsImplemented()
    {
        $this->assertInstanceOf(ElasticsearchQueryOperator::class, $this->operator);
    }

    public function testFormattedQueryExpressionIsReturned()
    {
        $fieldName = 'foo';
        $fieldValue = 'bar';

        $expectedExpression = $this->getExpectedExpression($fieldName, $fieldValue);
        $result = $this->operator->getFormattedArray($fieldName, $fieldValue);

        $this->assertSame($expectedExpression, $result);
    }

    abstract protected function getOperatorInstance() : ElasticsearchQueryOperator;

    abstract protected function getExpectedExpression(string $fieldName, string $fieldValue) : array;
}
