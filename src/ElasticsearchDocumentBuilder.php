<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocument;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocumentField;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchDocument\SearchDocumentFieldCollection;

class ElasticsearchDocumentBuilder
{
    /**
     * @param SearchDocument $document
     * @return string[]
     */
    public static function fromSearchDocument(SearchDocument $document) : array
    {
        $context = $document->getContext();

        return array_merge(
            [
                ElasticsearchSearchEngine::DOCUMENT_ID_FIELD_NAME => $document->getProductId() . '_' . $context,
                ElasticsearchSearchEngine::PRODUCT_ID_FIELD_NAME => (string) $document->getProductId()
            ],
            self::getSearchDocumentFields($document->getFieldsCollection()),
            self::getContextFields($context)
        );
    }

    /**
     * @param SearchDocumentFieldCollection $fieldCollection
     * @return array[]
     */
    private static function getSearchDocumentFields(SearchDocumentFieldCollection $fieldCollection) : array
    {
        return array_reduce($fieldCollection->getFields(), function ($carry, SearchDocumentField $field) {
            $fieldValues = self::collapseFieldValues($field->getValues());
            return array_merge([$field->getKey() => $fieldValues], $carry);
        }, []);
    }

    /**
     * @param Context $context
     * @return string[]
     */
    private static function getContextFields(Context $context) : array
    {
        return array_reduce($context->getSupportedCodes(), function ($carry, $contextCode) use ($context) {
            return array_merge([$contextCode => $context->getValue($contextCode)], $carry);
        }, []);
    }

    private static function collapseFieldValues($fieldValues)
    {
        if (0 == count($fieldValues)) {
            return "";
        }
        
        if (1 == count($fieldValues) && isset($fieldValues[0]) && !is_array($fieldValues[0])) {
            return (string)$fieldValues[0];
        }
        
        return $fieldValues;
    }
}
