<?php

namespace App\Http\Controllers\Core\V1;

use App\Contracts\ServiceSearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Search\Request;
use App\Support\Coordinate;

class SearchController extends Controller
{
    /**
     * @param \App\Contracts\ServiceSearch $search
     * @param \App\Http\Requests\Search\Request $request
     * @return \Illuminate\Http\Resources\Json\AnonymousResourceCollection
     */
    public function __invoke(ServiceSearch $search, Request $request)
    {
        // Apply query.
        if ($request->has('query')) {
            $search->applyQuery($request->input('query'));
        }

        if ($request->has('category')) {
            // If category given then filter by category.
            $search->applyCategory($request->category);
        } elseif ($request->has('persona')) {
            // Otherwise, if persona given then filter by persona.
            $search->applyPersona($request->persona);
        }

        // Apply filter on `wait_time` field.
        if ($request->has('wait_time')) {
            $search->applyWaitTime($request->wait_time);
        }

        // Apply filter on `is_free` field.
        if ($request->has('is_free')) {
            $search->applyIsFree($request->is_free);
        }

        // If location was passed, then parse the location.
        if ($request->has('location')) {
            $location = new Coordinate(
                $request->input('location.lat'),
                $request->input('location.lon')
            );

            // Apply radius filtering.
            $search->applyRadius($location, $request->input('distance', config('ck.search_distance')));
        }

        if ($request->has('eligibilities')) {
            $search->applyEligibilities($request->input('eligibilities'));
        }

        // Apply order.
        $search->applyOrder($request->order ?? 'relevance', $location ?? null);

        // Perform the search.
        return $search->paginate($request->page, $request->per_page);
    }
}
