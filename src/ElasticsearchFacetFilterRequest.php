<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestRangedField;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFiltersToIncludeInResult;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRequestField;

class ElasticsearchFacetFilterRequest
{
    /**
     * @var FacetFiltersToIncludeInResult
     */
    private $facetFiltersToIncludeInResult;

    /**
     * @var array[]
     */
    private $filterSelection;

    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    /**
     * @param FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult
     * @param array[] $filterSelection
     * @param FacetFieldTransformationRegistry $facetFieldTransformationRegistry
     */
    public function __construct(
        FacetFiltersToIncludeInResult $facetFiltersToIncludeInResult,
        array $filterSelection,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry
    ) {
        $this->facetFiltersToIncludeInResult = $facetFiltersToIncludeInResult;
        $this->filterSelection = $filterSelection;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
    }

    /**
     * @return mixed[]
     */
    public function toArray() : array
    {
        return [
            'facetRequestParameters' => $this->getFacetsRequestParameters(),
            'facetQueriesRequestParameters' => $this->getFacetQueriesRequestParameter()
        ];
    }

    /**
     * @return mixed[]
     */
    private function getFacetsRequestParameters(): array
    {
        $fields = $this->facetFiltersToIncludeInResult->getFields();

        if (count($fields) === 0) {
            return [];
        }

        return array_merge(
            $this->getNonRangedFacetFields(...$fields),
            $this->getRangedFacetFields(...$fields)
        );
    }

    /**
     * @return array[]
     */
    private function getFacetQueriesRequestParameter(): array
    {
        if (count($this->filterSelection) === 0) {
            return [];
        }

        return $this->getSelectedFacetQueries($this->filterSelection);
    }

    /**
     * @param FacetFilterRequestField[] $fields
     * @return string[]
     */
    private function getNonRangedFacetFields(FacetFilterRequestField ...$fields) : array
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
    private function getRangedFacetFields(FacetFilterRequestField ...$fields) : array
    {
        return array_reduce($fields, function (array $carry, FacetFilterRequestField $field) {
            if (!$field->isRanged()) {
                return $carry;
            }

            return array_merge($carry, [
                (string)$field->getAttributeCode() => [
                    'range' => [
                        'field' => $field->getAttributeCode(),
                        'ranges' => $this->getRangedFieldRanges($field)
                    ]
                ]
            ]);
        }, []);
    }

    /**
     * @param FacetFilterRequestRangedField $field
     * @return string[]
     */
    private function getRangedFieldRanges(FacetFilterRequestRangedField $field) : array
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
    
    /**
     * @param array[] $filterSelection
     * @return string[]
     */
    private function getSelectedFacetQueries(array $filterSelection) : array
    {
        return array_reduce(array_keys($filterSelection), function (array $carry, $filterCode) use ($filterSelection) {
            if (count($filterSelection[$filterCode]) > 0) {
                $carry[] = [
                    'bool' => [
                        'filter' => $this->getFormattedFacetQueryValues($filterCode, $filterSelection[$filterCode])
                    ]
                ];
            }
            return $carry;
        }, []);
    }

    /**
     * @param string $filterCode
     * @param string[] $filterValues
     * @return mixed[]
     */
    private function getFormattedFacetQueryValues(string $filterCode, array $filterValues) : array
    {
        if ($this->facetFieldTransformationRegistry->hasTransformationForCode($filterCode)) {
            $transformation = $this->facetFieldTransformationRegistry->getTransformationByCode($filterCode);

            return [
                'bool' => [
                    'should' => array_map(function (string $filterValue) use ($transformation, $filterCode) {
                        $facetValue = $transformation->decode($filterValue);

                        if ($facetValue instanceof FacetFilterRange) {
                            $from = $facetValue->from();
                            $to = $facetValue->to();

                            $facetQueryRange = [];

                            if ($from !== null && null === $to) {
                                $facetQueryRange = ['gte' => $from];
                            } elseif (null === $from && $to !== null) {
                                $facetQueryRange = ['lte' => $to];
                            } elseif ($from !== null && $to !== null) {
                                $facetQueryRange = ['gte' => $from, 'lte' => $to];
                            }

                            return [
                                'bool' => [
                                    'filter' => [
                                        'range' => [
                                            $this->escapeQueryChars($filterCode) => $facetQueryRange
                                        ]
                                    ]
                                ]
                            ];
                        }

                        return [
                            'bool' => [
                                'filter' => [
                                    'term' => [
                                        $this->escapeQueryChars($filterCode) => $facetValue
                                    ]
                                ]
                            ]
                        ];
                    }, $filterValues)
                ]
            ];
        }

        return [
            'bool' => [
                'should' => array_map(function ($filterValue) use ($filterCode) {
                    return [
                        'bool' => [
                            'filter' => [
                                'term' => [
                                    $this->escapeQueryChars($filterCode) => $filterValue
                                ]
                            ]
                        ]
                    ];
                }, $filterValues)
            ]
        ];
    }

    /**
     * @param string $queryString
     * @return string
     */
    private function escapeQueryChars(string $queryString) : string
    {
        $src = ['\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '~', '*', '?', ':', '"', ';', '/'];

        $replace = array_map(function ($string) {
            return '\\' . $string;
        }, $src);

        return str_replace($src, $replace, $queryString);
    }
}
