<?php

namespace App\Docs\Operations\Pages;

use App\Docs\Parameters\FilterIdParameter;
use App\Docs\Parameters\FilterParameter;
use App\Docs\Parameters\SortParameter;
use App\Docs\Schemas\AllSchema;
use App\Docs\Schemas\Page\PageListItemSchema;
use App\Docs\Tags\PagesTag;
use App\Models\Page;
use GoldSpecDigital\ObjectOrientedOAS\Objects\BaseObject;
use GoldSpecDigital\ObjectOrientedOAS\Objects\MediaType;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Operation;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Response;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Schema;

class IndexPageOperation extends Operation
{
    /**
     * @param string|null $objectId
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     * @return static
     */
    public static function create(string $objectId = null): BaseObject
    {
        return parent::create($objectId)
            ->action(static::ACTION_GET)
            ->tags(PagesTag::create())
            ->summary('List all the information pages')
            ->description('**Permission:** `Open`')
            ->noSecurity()
            ->parameters(
                FilterIdParameter::create(),
                FilterParameter::create(null, 'parent_id')
                    ->description('Filter by Parent ID to return descendant nodes')
                    ->schema(Schema::string()),
                FilterParameter::create(null, 'title')
                    ->description('Filter by title')
                    ->schema(Schema::string()),
                FilterParameter::create(null, 'page_type')
                    ->description('Filter by page type')
                    ->schema(Schema::string('page_type')
                    ->enum(
                        Page::PAGE_TYPE_INFORMATION,
                        Page::PAGE_TYPE_LANDING
                    )),
                SortParameter::create(null, ['title'], 'title')
            )
            ->responses(
                Response::ok()->content(
                    MediaType::json()->schema(
                        AllSchema::create(null, PageListItemSchema::create())
                    )
                )
            );
    }
}
