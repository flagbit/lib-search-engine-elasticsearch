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
    public function testSearchDocumentIsConvertedIntoElasticsearchFormat()
    {
        $documentFieldName = 'foo';
        $documentFieldValue = 'bar';
        $searchDocumentFieldCollection = SearchDocumentFieldCollection::fromArray(
            [$documentFieldName => $documentFieldValue]
        );

        $contextPartName = 'baz';
        $contextPartValue = 'qux';
        $context = new SelfContainedContext([$contextPartName => $contextPartValue]);

        $productId = new ProductId(uniqid());

        $searchDocument = new SearchDocument($searchDocumentFieldCollection, $context, $productId);

        $documentUniqueId = sprintf('%s_%s:%s', (string) $productId, $contextPartName, $contextPartValue);
        $expectedElasticsearchDocument = [
            ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME => $documentUniqueId,
            ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME  => (string) $productId,
            $documentFieldName => $documentFieldValue,
            $contextPartName => $contextPartValue,
        ];

        $this->assertSame($expectedElasticsearchDocument, ElasticsearchDocumentBuilder::fromSearchDocument($searchDocument));
    }
}
