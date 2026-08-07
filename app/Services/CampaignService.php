<?php

namespace App\Services;

use App\Http\Requests\CampaignRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use App\Models\Campaign;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CampaignService
{
    protected array $campaignFilter = [
        'name',
        'type',
        'status',
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return Campaign::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->campaignFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method($methodValue);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * Campaigns the storefront may show right now.
     *
     * Used by the nav, so it runs on every full page load — kept to the two
     * columns the header needs plus the window filter, which the
     * (status, starts_at, ends_at) index covers.
     *
     * @throws Exception
     */
    public function running(PaginateRequest $request)
    {
        try {
            return Campaign::running()
                ->when($request->filled('type'), fn($query) => $query->where('type', $request->get('type')))
                ->orderBy('ends_at', 'asc')
                ->get();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(CampaignRequest $request): Campaign
    {
        try {
            return Campaign::create(
                $request->validated() + ['slug' => $this->uniqueSlug($request->name)]
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CampaignRequest $request, Campaign $campaign): Campaign
    {
        try {
            $campaign->update(
                $request->validated() + ['slug' => $this->uniqueSlug($request->name, $campaign->id)]
            );

            return $campaign;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Campaign $campaign): void
    {
        try {
            // campaign_products cascades in the schema; deleting here too keeps
            // the behaviour the same on a connection with FKs disabled.
            $campaign->campaignProducts()->delete();
            $campaign->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Campaign $campaign): Campaign
    {
        return $campaign;
    }

    /**
     * campaigns.slug is unique and it is the public URL, so a second
     * "Clearance Sale" cannot silently take the first one's address — it gets
     * a suffix instead of failing the save.
     */
    private function uniqueSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i    = 2;

        while (
            Campaign::where('slug', $slug)
                ->when($ignoreId, fn($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
