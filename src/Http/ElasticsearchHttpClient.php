<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Http;

interface ElasticsearchHttpClient
{
    const UPDATE_SERVLET = '';
    const SEARCH_SERVLET = '_search';
    const CLEAR_SERVERLET = '_delete_by_query?refresh';

    /**
     * @param string $id
     * @param mixed[] $parameters
     * @return mixed
     */
    public function update(string $id, array $parameters);

    /**
     * @param mixed[] $parameters
     * @return mixed
     */
    public function select(array $parameters);

    /**
     * @param mixed[] $parameters
     * @return mixed
     */
    public function clear(array $parameters);
}
