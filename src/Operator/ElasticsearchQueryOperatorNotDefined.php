<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolMustNot;

class ElasticsearchQueryOperatorNotDefined implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue): array
    {
        return (new ElasticsearchQueryBoolMustNot())->getFormattedArray([
            'exists' => [
                'field' => $fieldName
            ],
        ]);
    }
}
