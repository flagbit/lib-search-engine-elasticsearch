<?php

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool;

class ElasticsearchQueryBoolMustNot implements ElasticsearchQueryBool
{
    public function getFormattedArray(array $contents) : array
    {
        return [
            'bool' => [
                'must_not' => $contents
            ]
        ];
    }
}
