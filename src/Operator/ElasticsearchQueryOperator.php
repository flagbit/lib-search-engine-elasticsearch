<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

interface ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue) : array;
}
