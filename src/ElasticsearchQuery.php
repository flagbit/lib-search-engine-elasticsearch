<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\ProductSearch\QueryOptions;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFilterRange;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolFilter;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperator;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorEqual;
use LizardsAndPumpkins\DataPool\SearchEngine\FacetFieldTransformation\FacetFieldTransformationRegistry;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorLessOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorGreaterOrEqualThan;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;

class ElasticsearchQuery
{
    /**
     * @var SearchCriteria
     */
    private $criteria;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var mixed[]
     */
    private $filters;

    /**
     * @var mixed[]
     */
    private $memoizedElasticsearchQueryArrayRepresentation;
    /**
     * @var FacetFieldTransformationRegistry
     */
    private $facetFieldTransformationRegistry;

    public function __construct(
        SearchCriteria $criteria,
        Context $context,
        FacetFieldTransformationRegistry $facetFieldTransformationRegistry,
        array $filters
    ) {
        $this->criteria = $criteria;
        $this->context = $context;
        $this->facetFieldTransformationRegistry = $facetFieldTransformationRegistry;
        $this->filters = $filters;
    }

    /**
     * @return mixed[]
     */
    public function toArray() : array
    {
        if (null === $this->memoizedElasticsearchQueryArrayRepresentation) {
            $this->memoizedElasticsearchQueryArrayRepresentation = $this->getElasticsearchQueryArrayRepresentation();
        }

        return $this->memoizedElasticsearchQueryArrayRepresentation;
    }

    /**
     * @return mixed[]
     */
    private function getElasticsearchQueryArrayRepresentation() : array
    {
        $criteriaBool = $this->convertCriteriaIntoElasticsearchBool($this->criteria);
        $contextBool = $this->convertContextIntoElasticsearchBool($this->context);
        $filtersBool = $this->convertFiltersIntoElasticsearchBools($this->filters);

        return $this->getBoolFilterArrayRepresentation(
            array_merge([$criteriaBool], [$contextBool], [$filtersBool])
        );
    }

    private function convertCriteriaIntoElasticsearchBool(SearchCriteria $criteria) : array
    {
        $criteriaJson = json_encode($criteria);
        $criteriaArray = json_decode($criteriaJson, true);

        return $this->createElasticsearchQueryBoolArrayFromCriteriaArray($criteriaArray);
    }

    /**
     * @param mixed[] $criteria
     * @return array[]
     */
    private function createElasticsearchQueryBoolArrayFromCriteriaArray(array $criteria) : array
    {
        if (isset($criteria['condition'])) {
            $subBools = array_map([$this, 'createElasticsearchQueryBoolArrayFromCriteriaArray'], $criteria['criteria']);
            return $this->packElasticsearchSubBools($criteria['condition'], $subBools);
        }

        return $this->createPrimitiveOperator($criteria);
    }

    /**
     * @param string $condition
     * @param mixed[] $subBools
     * @return mixed[]
     */
    private function packElasticsearchSubBools(string $condition, array $subBools)
    {
        if ('and' === $condition) {
            return $this->getBoolFilterArrayRepresentation(
                array_values($subBools)
            );
        } elseif ('or' === $condition) {
            return $this->getBoolShouldArrayRepresentation(
                array_values($subBools)
            );
        }

        return [];
    }

    /**
     * @param string[] $criteria
     * @return array[]
     */
    private function createPrimitiveOperator(array $criteria) : array
    {
        $operator = $this->getElasticsearchOperator($criteria['operation']);
        return $operator->getFormattedArray((string)$criteria['fieldName'], (string)$criteria['fieldValue']);
    }

    private function getElasticsearchOperator(string $operation) : ElasticsearchQueryOperator
    {
        $className = __NAMESPACE__ . '\\Operator\\ElasticsearchQueryOperator' . $operation;

        if (!class_exists($className)) {
            throw new UnsupportedSearchCriteriaOperationException(
                sprintf('Unsupported criterion operation "%s".', $operation)
            );
        }

        return new $className;
    }

    /**
     * @param Context $context
     * @return mixed[]
     */
    private function convertContextIntoElasticsearchBool(Context $context) : array
    {
        return $this->getBoolFilterArrayRepresentation(
            array_map(function ($contextCode) use ($context) {
                $fieldName = (string)$contextCode;
                $fieldValue = (string)$context->getValue($contextCode);
                $operator = $this->getElasticsearchOperator('Equal');

                return $operator->getFormattedArray($fieldName, $fieldValue);
            }, $context->getSupportedCodes())
        );
    }

    /**
     * @param mixed[] $filters
     * @return mixed[]
     */
    private function convertFiltersIntoElasticsearchBools($filters): array
    {
        if (count($filters) === 0) {
            return [];
        }

        return $this->getBoolFilterArrayRepresentation(
            array_reduce(array_keys($filters), function (array $carry, $filterCode) use ($filters) {
                if (count($filters[$filterCode]) > 0) {
                    $carry[] = $this->getBoolShouldArrayRepresentation(
                        $this->getElasticsearchBoolsFromFilterValues($filterCode, $filters[$filterCode])
                    );
                }
                return $carry;
            }, [])
        );
    }

    /**
     * @param string $filterCode
     * @param string[] $filterValues
     * @return mixed[]
     */
    private function getElasticsearchBoolsFromFilterValues(string $filterCode, array $filterValues) : array
    {
        if ($this->facetFieldTransformationRegistry->hasTransformationForCode($filterCode)) {
            $transformation = $this->facetFieldTransformationRegistry->getTransformationByCode($filterCode);

            return array_map(function (string $filterValue) use ($transformation, $filterCode) {
                $transformedValue = $transformation->decode($filterValue);

                if ($transformedValue instanceof FacetFilterRange) {
                    return $this->getElasticsearchQueryRangesFromFacetFilterRange(
                        $filterCode,
                        $transformedValue
                    );
                }

                return (new ElasticsearchQueryOperatorEqual())->getFormattedArray($filterCode, $transformedValue);
            }, $filterValues);
        }

        return array_map(function ($filterValue) use ($filterCode) {
            return (new ElasticsearchQueryOperatorEqual())->getFormattedArray($filterCode, $filterValue);
        }, $filterValues);
    }

    /**
     * @param string $rangeField
     * @param FacetFilterRange $facetFilterRange
     * @return mixed[]
     */
    private function getElasticsearchQueryRangesFromFacetFilterRange(
        string $rangeField,
        FacetFilterRange $facetFilterRange
    ) : array {
        $from = $facetFilterRange->from();
        $to = $facetFilterRange->to();

        if ($from !== null && null === $to) {
            return (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray($rangeField, (string)$from);
        } elseif (null === $from && $to !== null) {
            return (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray($rangeField, (string)$to);
        } elseif ($from !== null && $to !== null) {
            return $this->getBoolFilterArrayRepresentation([
                (new ElasticsearchQueryOperatorGreaterOrEqualThan())->getFormattedArray($rangeField, (string)$from),
                (new ElasticsearchQueryOperatorLessOrEqualThan())->getFormattedArray($rangeField, (string)$to)
            ]);
        }
    }

    private function getBoolFilterArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolFilter())->getFormattedArray($contents);
    }

    private function getBoolShouldArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolShould())->getFormattedArray($contents);
    }
}
