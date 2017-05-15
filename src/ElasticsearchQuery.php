<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\Context\Context;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperator;
use LizardsAndPumpkins\ProductSearch\QueryOptions;

class ElasticsearchQuery
{
    /**
     * @var SearchCriteria
     */
    private $criteria;

    /**
     * @var QueryOptions
     */
    private $queryOptions;

    /**
     * @var mixed[]
     */
    private $memoizedElasticsearchQueryArrayRepresentation;

    public function __construct(SearchCriteria $criteria, QueryOptions $queryOptions)
    {
        $this->criteria = $criteria;
        $this->queryOptions = $queryOptions;
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
        $fieldsQueryArray = $this->convertCriteriaIntoElasticsearchQueryArray($this->criteria);
        $contextQueryArray = $this->convertContextIntoElasticsearchQueryArray($this->queryOptions->getContext());

        return [
            'bool' => [
                'filter' => array_merge([$fieldsQueryArray], [$contextQueryArray])
            ]
        ];
    }

    private function convertCriteriaIntoElasticsearchQueryArray(SearchCriteria $criteria) : array
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

        return $this->createPrimitiveBool($criteria);
    }
    
    private function packElasticsearchSubBools(string $condition, array $subBools)
    {
        if ('and' === $condition) {
            return [
                'bool' => [
                    'filter' => array_values($subBools)
                ]
            ];
        } elseif ('or' === $condition) {
            return [
                'bool' => [
                    'should' => array_values($subBools)
                ]
            ];
        }
        
        return [];
    }

    /**
     * @param string[] $criteria
     * @return array[]
     */
    private function createPrimitiveBool(array $criteria) : array
    {
        $fieldName = $this->escapeQueryChars($criteria['fieldName']);
        $fieldValue = (string)$criteria['fieldValue'];
        $operator = $this->getElasticsearchOperator($criteria['operation']);

        return $operator->getFormattedArray($fieldName, $fieldValue);
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

    private function convertContextIntoElasticsearchQueryArray(Context $context) : array
    {
        return [
            'bool' => [
                'filter' => array_map(function ($contextCode) use ($context) {
                    $fieldName = $this->escapeQueryChars($contextCode);
                    $fieldValue = (string)$context->getValue($contextCode);
                    $operator = $this->getElasticsearchOperator('Equal');

                    return $operator->getFormattedArray($fieldName, $fieldValue);
                }, $context->getSupportedCodes())
            ]
        ];
    }

    /**
     * @param mixed $queryString
     * @return string
     */
    private function escapeQueryChars($queryString) : string
    {
        $src = ['\\', '+', '-', '&&', '||', '!', '(', ')', '{', '}', '[', ']', '^', '~', '*', '?', ':', '"', ';', '/'];
        
        $replace = array_map(function (string $string) {
            return '\\' . $string;
        }, $src);

        return str_replace($src, $replace, $queryString);
    }
}
