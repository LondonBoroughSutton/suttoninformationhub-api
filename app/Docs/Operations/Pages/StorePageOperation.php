<?php

namespace App\Docs\Operations\Pages;

use App\Docs\Schemas\Page\PageSchema;
use App\Docs\Schemas\Page\StorePageSchema;
use App\Docs\Schemas\ResourceSchema;
use App\Docs\Tags\PagesTag;
use GoldSpecDigital\ObjectOrientedOAS\Objects\BaseObject;
use GoldSpecDigital\ObjectOrientedOAS\Objects\MediaType;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Operation;
use GoldSpecDigital\ObjectOrientedOAS\Objects\RequestBody;
use GoldSpecDigital\ObjectOrientedOAS\Objects\Response;

class StorePageOperation extends Operation
{
    /**
     * @param string|null $objectId
     * @throws \GoldSpecDigital\ObjectOrientedOAS\Exceptions\InvalidArgumentException
     * @return static
     */
    public static function create(string $objectId = null): BaseObject
    {
        return parent::create($objectId)
            ->action(static::ACTION_POST)
            ->tags(PagesTag::create())
            ->summary('Create an information page')
            ->description('**Permission:** `Global Admin`')
            ->requestBody(
                RequestBody::create()
                    ->required()
                    ->content(
                        MediaType::json()->schema(
                            StorePageSchema::create()
                        )
                    )
            )
            ->responses(
                Response::created()->content(
                    MediaType::json()->schema(
                        ResourceSchema::create(null, PageSchema::create())
                    )
                )
            );
    }
}
