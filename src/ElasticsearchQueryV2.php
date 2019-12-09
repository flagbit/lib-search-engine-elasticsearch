<?php
/**
 * ElasticsearchQueryV2
 *
 * @copyright Copyright © 2019 Staempfli AG. All rights reserved.
 * @author    juan.alonso@staempfli.com
 */

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolFilter;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolMust;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\InvalidSearchCriteriaOperationFormatException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaConditionException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Exception\UnsupportedSearchCriteriaOperationException;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperator;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator\ElasticsearchQueryOperatorAnything;
use LizardsAndPumpkins\DataPool\SearchEngine\SearchCriteria\SearchCriteria;

class ElasticsearchQueryV2
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
        SearchCriteria $criteria
    ) {
        $this->criteria = $criteria;
    }

    /**
     * @return array[]
     */
    public function toArray() : array
    {
        if (null === $this->memoizedElasticsearchQueryArrayRepresentation) {
            $this->memoizedElasticsearchQueryArrayRepresentation = $this->getElasticsearchQueryArrayRepresentation();
        }

        return $this->memoizedElasticsearchQueryArrayRepresentation;
    }

    /**
     * @return array[]
     */
    private function getElasticsearchQueryArrayRepresentation(): array
    {
        $criteriaMustBool = $this->convertCriteriaIntoElasticsearchBool($this->criteria);
        $dateBoostBool = $this->getDateBoostCriteriaIntoElasticsearchBool();

        foreach ($criteriaMustBool as $boolKey => $mustBoolContent) {
            if ($boolKey === 'bool') {
                $criteriaMustBool['bool']['should'] = $dateBoostBool; // optional should date bool
            }
        }

        return $criteriaMustBool;
    }

    private function convertCriteriaIntoElasticsearchBool(SearchCriteria $criteria) : array
    {
        $criteriaJson = json_encode($criteria);
        $criteriaArray = json_decode($criteriaJson, true);

        if (0 === \count($criteriaArray)) {
            return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
        }

        if (isset($criteriaArray['criteria'])) {
            $reformatCriteriaArray = [
                'condition' => 'and',
                'criteria' => []
            ];

            foreach ($criteriaArray['criteria'] as $singleCriteria) {
                $reformatCriteriaArray['criteria'][] = $singleCriteria;
            }
            $criteriaArray = $reformatCriteriaArray;
        }

        return $this->createElasticsearchQueryBoolArrayFromCriteriaArray($criteriaArray);
    }

    /**
     * @param mixed[] $criteria
     * @return array[]
     */
    private function createElasticsearchQueryBoolArrayFromCriteriaArray(array $criteria) : array
    {
        if (isset($criteria['condition'])) {
            if (!isset($criteria['criteria'])
                || !\is_array($criteria['criteria'])
                || 0 === \count($criteria['criteria'])
            ) {
                return (new ElasticsearchQueryOperatorAnything())->getFormattedArray();
            }

            $subBools = array_map(
                [$this, 'createElasticsearchQueryBoolArrayFromCriteriaArray'],
                $criteria['criteria']
            );

            return $this->packSubCriteriaIntoElasticsearchSubBools($criteria['condition'], $subBools);
        }

        return $this->createPrimitiveOperator($criteria);
    }

    /**
     * @param string $condition
     * @param mixed[] $subBools
     * @return mixed[]
     */
    private function packSubCriteriaIntoElasticsearchSubBools(string $condition, array $subBools)
    {
        if ('and' === $condition) {
            return $this->getBoolMustArrayRepresentation($subBools);
        }

        if ('or' === $condition) {
            return $this->getBoolShouldArrayRepresentation($subBools);
        }

        throw new UnsupportedSearchCriteriaConditionException(
            sprintf('Unsupported criteria condition "%s".', $condition)
        );
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolMustArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolMust())->getFormattedArray($contents);
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolShouldArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolShould())->getFormattedArray($contents);
    }

    /**
     * @param string[] $criteria
     * @return array[]
     */
    private function createPrimitiveOperator(array $criteria) : array
    {
        if (!isset($criteria['fieldName'], $criteria['fieldValue'], $criteria['operation'])) {
            throw new InvalidSearchCriteriaOperationFormatException(
                sprintf('Invalid search criteria operation format')
            );
        }

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
     * @return array
     */
    private function getDateBoostCriteriaIntoElasticsearchBool(): array
    {
        try {
            $currentDate = new \DateTime('now', new \DateTimeZone('Europe/Berlin'));
        } catch (\Exception $exception) {
            return [];
        }

        return [
            [
                'range' => [
                    'created_at' => [
                        'boost' => 5,
                        'gte' => $currentDate->modify('-1 months')->format('Y-m-d').'T00:00:00'
                    ]
                ]
            ],
            [
                'range' => [
                    'created_at' => [
                        'boost' => 4,
                        'gte' => $currentDate->modify('-1 months')->format('Y-m-d').'T00:00:00'
                    ]
                ]
            ],
            [
                'range' => [
                    'created_at' => [
                        'boost' => 3,
                        'gte' => $currentDate->modify('-1 months')->format('Y-m-d').'T00:00:00'
                    ]
                ]
            ],
            [
                'range' => [
                    'created_at' => [
                        'boost' => 2,
                        'gte' => $currentDate->modify('-9 months')->format('Y-m-d').'T00:00:00'
                    ]
                ]
            ],
            [
                'range' => [
                    'created_at' => [
                        'boost' => 1,
                        'gte' => $currentDate->modify('-12 months')->format('Y-m-d').'T00:00:00'
                    ]
                ]
            ]
        ];
    }

    /**
     * @param array[] $contents
     * @return array[]
     */
    private function getBoolFilterArrayRepresentation(array $contents) : array
    {
        return (new ElasticsearchQueryBoolFilter())->getFormattedArray($contents);
    }
}
