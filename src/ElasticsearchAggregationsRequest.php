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
     * @return array[]
     */
    public function toArray(): array
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
     * @return array[]
     */
    private function getNonRangedFacetFieldsElasticsearchAggregations(FacetFilterRequestField ...$fields): array
    {
        return array_reduce($fields, function (array $carry, FacetFilterRequestField $field) {
            if ($field->isRanged()) {
                return $carry;
            }
            return array_merge($carry, [
                (string) $field->getAttributeCode() => [
                    'terms' => [
                        'field' => (string) $field->getAttributeCode()
                    ]
                ]
            ]);
        }, []);
    }

    /**
     * @param FacetFilterRequestField[] $fields
     * @return array[]
     */
    private function getRangedFacetFieldsElasticsearchAggregations(FacetFilterRequestField ...$fields): array
    {
        return array_reduce($fields, function (array $carry, FacetFilterRequestField $field) {
            if (! $field->isRanged()) {
                return $carry;
            }
            return array_merge($carry, [
                (string) $field->getAttributeCode() => [
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
     * @return mixed[]
     */
    private function getRangedFieldElasticsearchAggregationRanges(FacetFilterRequestRangedField $field): array
    {
        $facetFilterRanges = $field->getRanges();

        if ([] === $facetFilterRanges) {
            return [json_decode('{}')];
        }

        return array_map(function (FacetFilterRange $range) use ($field) {
            if ($range->from() !== null && null === $range->to()) {
                return ['from' => $range->from()];
            }

            if (null === $range->from() && $range->to() !== null) {
                return ['to' => $range->to()];
            }

            if ($range->from() !== null && $range->to() !== null) {
                return ['from' => $range->from(), 'to' => $range->to()];
            }

            return json_decode('{}');
        }, $facetFilterRanges);
    }
}
