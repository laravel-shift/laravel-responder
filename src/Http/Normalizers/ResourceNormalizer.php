<?php

namespace Flugg\Responder\Http\Normalizers;

use Flugg\Responder\Adapters\IlluminatePaginatorAdapter;
use Flugg\Responder\Contracts\Http\Normalizer;
use Flugg\Responder\Http\Resources\Collection;
use Flugg\Responder\Http\Resources\Item;
use Flugg\Responder\Http\SuccessResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Arr;

/**
 * Class for normalizing API resources to success responses.
 */
class ResourceNormalizer implements Normalizer
{
    /**
     * The data being normalized.
     *
     * @var \Illuminate\Http\Resources\Json\JsonResource
     */
    protected $data;

    /**
     * A request object.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * Create a new normalizer instance.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $data
     * @param \Illuminate\Http\Request $request
     */
    public function __construct(JsonResource $data, Request $request)
    {
        $this->data = $data;
        $this->request = $request;
    }

    /**
     * Normalize response data.
     *
     * @throws \Flugg\Responder\Exceptions\InvalidStatusCodeException
     * @return \Flugg\Responder\Http\SuccessResponse
     */
    public function normalize(): SuccessResponse
    {
        $response = (new SuccessResponse(
            $this->data instanceof ResourceCollection
                    ? $this->buildCollection($this->data)
                    : $this->buildResource($this->data)
        ))
            ->setStatus($this->calculateStatus($this->data))
            ->setMeta(array_merge_recursive($this->data->with($this->request), $this->data->additional));

        if ($this->data->resource instanceof LengthAwarePaginator) {
            $response->setPaginator(new IlluminatePaginatorAdapter($this->data->resource));
        }

        return $response;
    }

    /**
     * Build a resource object from an API resource.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $resource
     * @return \Flugg\Responder\Http\Resources\Item
     */
    protected function buildResource(JsonResource $resource): Item
    {
        $resourceKey = $this->resolveResourceKey($resource);
        $relations = $this->extractRelations($resource);

        return new Item(Arr::except($resource->resolve(), array_keys($relations)), $resourceKey, $relations);
    }

    /**
     * Build a collection of resources from an API resource collection.
     *
     * @param \Illuminate\Http\Resources\Json\ResourceCollection $collection
     * @return \Flugg\Responder\Http\Resources\Collection
     */
    protected function buildCollection(ResourceCollection $collection): Collection
    {
        $resources = $collection->collection;
        $resourceKey = ! $resources->isEmpty() ? $this->resolveResourceKey($resources->first()) : null;

        return new Collection(array_map([$this, 'buildResource'], $resources->all()), $resourceKey);
    }

    /**
     * Resolve a resource key from an API resource.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $resource
     * @return string
     */
    protected function resolveResourceKey(JsonResource $resource): string
    {
        if (method_exists($resource, 'getResourceKey')) {
            return $resource->getResourceKey();
        } elseif (method_exists($resource->resource, 'getResourceKey')) {
            return $resource->resource->getResourceKey();
        }

        return $resource->resource->getTable();
    }

    /**
     * Extract relations from an API resource.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $resource
     * @return array
     */
    protected function extractRelations(JsonResource $resource): array
    {
        $relations = array_filter($resource->toArray($this->request), function ($value) {
            return $value instanceof JsonResource && ! $value->resource instanceof MissingValue;
        });

        return array_map(function ($relation) {
            return $relation instanceof ResourceCollection ? $this->buildCollection($relation) : $this->buildResource($relation);
        }, $relations);
    }

    /**
     * Calculate the appropriate status code for the response.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $resource
     * @return int
     */
    protected function calculateStatus(JsonResource $resource): int
    {
        return $resource->resource instanceof Model && $resource->resource->wasRecentlyCreated ? 201 : 200;
    }
}
