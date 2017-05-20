<?php

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool;

interface ElasticsearchQueryBool
{
    public function getFormattedArray(array $contents) : array;
}
