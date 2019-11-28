<?php

declare(strict_types=1);

namespace LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Operator;

use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\Bool\ElasticsearchQueryBoolShould;
use LizardsAndPumpkins\DataPool\SearchEngine\Elasticsearch\ElasticsearchSearchEngine;

class ElasticsearchQueryOperatorFullText implements ElasticsearchQueryOperator
{
    public function getFormattedArray(string $fieldName, string $fieldValue) : array
    {
        $fuzziness = 1;
        if (true === $this->isQuotationMarksSet($fieldValue)) {
            $fieldValue = str_replace('"','', $fieldValue);
            $fuzziness = 0;
        }

        $return = (new ElasticsearchQueryBoolShould())->getFormattedArray([
            'multi_match' => [
                'fields' => ElasticsearchSearchEngine::SEARCH_FIELDS,
                'query' => $fieldValue,
                'fuzziness' => $fuzziness
            ]
        ]);

        return $return;
    }

    /**
     * @param string $query
     * @return bool
     */
    private function isQuotationMarksSet(string $query): bool
    {
        preg_match('/(%22(.*)%22|"(.*)")/', $query, $output);
        return false === empty($output);
    }
}
