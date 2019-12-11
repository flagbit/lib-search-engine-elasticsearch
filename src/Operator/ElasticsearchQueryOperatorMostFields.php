<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchSearchEngine;

class ElasticsearchQueryOperatorMostFields implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue) : array
    {
        return (new ElasticsearchQueryBoolShould())->getFormattedArray(
            [
                'multi_match' => [
                    'fields' => ElasticsearchSearchEngine::SEARCH_FIELDS,
                    'query' => $fieldValue,
                    'type' => 'most_fields',
                ]
            ]
        );
    }
}
