<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolFilter;
use stdClass;

class ElasticsearchQueryOperatorAnything implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName = '', string $fieldValue = '') : array
    {
        return (new ElasticsearchQueryBoolFilter())->getFormattedArray([
            'match' => new stdClass()
        ]);
    }
}
