<?php

namespace App\Docs\Paths\Search;

use App\Docs\Operations\Search\StorePagesSearchOperation;
use GoldSpecDigital\ObjectOrientedOAS\Objects\BaseObject;
use GoldSpecDigital\ObjectOrientedOAS\Objects\PathItem;

class SearchPagesPath extends PathItem
{
    /**
     * @param string|null $objectId
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     * @return static
     */
    public static function create(string $objectId = null): BaseObject
    {
        return parent::create($objectId)
            ->route('/search/pages')
            ->operations(
                StorePagesSearchOperation::create()
            );
    }
}
