<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestRangedField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;

class ElasticsearchAggregationsRequest
{
    /**
     * @var FacetFiltersToIncludeInResult
     */
    private $facetFiltersToIncludeInResult;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    /**
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     */
    public function __construct(
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) {
        $this->facetFiltersToIncludeInResult = $facetFiltersToIncludeInResult;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    /**
     * @return mixed[]
     */
    public function toArray() : array
    {
        $fields = $this->facetFiltersToIncludeInResult->getFields();

        if (count($fields) === 0) {
            return [];
        }

        return array_merge(
            $this->getNonRangedFacetFieldsElasticsearchAggregations(...$fields),
            $this->getRangedFacetFieldsElasticsearchAggregations(...$fields)
        );
    }

    /**
     * @param FacetFilterRequestField[] $fields
     * @return string[]
     */
    private function getNonRangedFacetFieldsElasticsearchAggregations(FacetFilterRequestField ...$fields) : array
    {
        return array_reduce($fields, function (array $carry, FacetFilterRequestField $field) {
            if ($field->isRanged()) {
                return $carry;
            }
            return array_merge($carry, [
                (string)$field->getAttributeCode() => [
                    'terms' => [
                        'field' => (string) $field->getAttributeCode()
                    ]
                ]
            ]);
        }, []);
    }

    /**
     * @param FacetFilterRequestField[] $fields
     * @return string[]
     */
    private function getRangedFacetFieldsElasticsearchAggregations(FacetFilterRequestField ...$fields) : array
    {
        return array_reduce($fields, function (array $carry, FacetFilterRequestField $field) {
            if (!$field->isRanged()) {
                return $carry;
            }
            return array_merge($carry, [
                (string)$field->getAttributeCode() => [
                    'range' => [
                        'field' => $field->getAttributeCode(),
                        'ranges' => $this->getRangedFieldElasticsearchAggregationRanges($field)
                    ]
                ]
            ]);
        }, []);
    }

    /**
     * @param FacetFilterRequestRangedField $field
     * @return string[]
     */
    private function getRangedFieldElasticsearchAggregationRanges(FacetFilterRequestRangedField $field) : array
    {
        return array_reduce($field->getRanges(), function (array $carry, FacetFilterRange $range) use ($field) {
            $from = $range->from();
            $to = $range->to();

            $facetFieldRange = [];

            if ($from !== null && null === $to) {
                $facetFieldRange = [['from' => $from]];
            } elseif (null === $from && $to !== null) {
                $facetFieldRange = [['to' => $to]];
            } elseif ($from !== null && $to !== null) {
                $facetFieldRange = [['from' => $from, 'to' => $to]];
            }

            return array_merge($carry, $facetFieldRange);
        }, []);
    }
}
