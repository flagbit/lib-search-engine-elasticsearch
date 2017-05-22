<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\SelfContainedContext;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocumentFieldCollection;
use LizardsAndPumpkins\Import\Product\ProductId;
use PHPUnit\Framework\TestCase;

/**
 * @covers \LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchDocumentBuilder
 */
class ElasticsearchDocumentBuilderTest extends TestCase
{
    public function testSearchDocumentWithoutFieldValuesIsConvertedIntoElasticsearchFormat()
    {
        $documentFieldName = 'foo';

        $searchDocumentFieldCollection = SearchDocumentFieldCollection::fromArray([$documentFieldName => []]);

        $contextPartName = 'baz';
        $contextPartValue = 'qux';
        $context = new SelfContainedContext([$contextPartName => $contextPartValue]);

        $productId = new ProductId(uniqid());

        $searchDocument = new SearchDocument($searchDocumentFieldCollection, $context, $productId);

        $documentUniqueId = sprintf('%s_%s:%s', (string) $productId, $contextPartName, $contextPartValue);
        $expectedElasticsearchDocument = [
            ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME => $documentUniqueId,
            ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME  => (string) $productId,
            $documentFieldName => '',
            $contextPartName => $contextPartValue,
        ];

        $result = ElasticsearchDocumentBuilder::fromSearchDocument($searchDocument);

        $this->assertSame($expectedElasticsearchDocument, $result);
    }

    /**
     * @dataProvider searchDocumentFieldsProvider
     * @param mixed[] $searchDocumentFields
     */
    public function testSearchDocumentIsConvertedIntoElasticsearchFormat(array $searchDocumentFields)
    {
        $searchDocumentFieldCollection = SearchDocumentFieldCollection::fromArray($searchDocumentFields);

        $contextPartName = 'baz';
        $contextPartValue = 'qux';
        $context = new SelfContainedContext([$contextPartName => $contextPartValue]);

        $productId = new ProductId(uniqid());

        $searchDocument = new SearchDocument($searchDocumentFieldCollection, $context, $productId);

        $documentUniqueId = sprintf('%s_%s:%s', (string) $productId, $contextPartName, $contextPartValue);
        $expectedElasticsearchDocument = array_merge(
            [
                ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME => $documentUniqueId,
                ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME => (string) $productId,
            ],
            $searchDocumentFields,
            [$contextPartName => $contextPartValue]
        );

        $result = ElasticsearchDocumentBuilder::fromSearchDocument($searchDocument);

        $this->assertSame($expectedElasticsearchDocument, $result);
    }

    public function searchDocumentFieldsProvider(): array
    {
        return [
            [[$documentFieldName = 'foo' => $documentFieldValue = 'bar']],
            [[$documentFieldName = 'foo' => $documentFieldValue = ['bar', 'some other bar']]],
        ];
    }
}
