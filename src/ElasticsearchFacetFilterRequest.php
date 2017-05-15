<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
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

        return $this->getFacetFields(...$fields);
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
    private function getFacetFields(FacetFilterRequestField ...$fields) : array
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
     * @return array[]
     */
    private function getFormattedFacetQueryValues(string $filterCode, array $filterValues) : array
    {
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
