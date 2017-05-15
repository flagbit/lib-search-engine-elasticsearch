<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

class ElasticsearchQueryOperatorNotEqual implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue) : array
    {
        return [
            'bool' => [
                'must_not' => [
                    'term' => [
                        $fieldName => $fieldValue
                    ]
                ]
            ]
        ];
    }
}
