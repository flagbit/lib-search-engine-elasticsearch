<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

class ElasticsearchQueryOperatorEqual implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue) : array
    {
        return [
            'bool' => [
                'filter' => [
                    'term' => [
                        $fieldName => $fieldValue
                    ]
                ]
            ]
        ];
    }
}
