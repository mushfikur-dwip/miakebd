<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Requests\PaginateRequest;
use Exception;
use App\Models\Address;
use App\Services\AddressService;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddressRequest;
use App\Http\Resources\AddressResource;


class AddressController extends Controller
{

    public AddressService $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    public function index(PaginateRequest $request) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            return AddressResource::collection($this->addressService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    // Route model binding resolves ANY address by id, so without this a
    // customer could walk the ids and read, rewrite or delete every other
    // customer's name, phone and home address. 404 rather than 403 so the
    // response does not confirm the id exists.
    private function assertOwned(Address $address): void
    {
        abort_unless((int) $address->user_id === (int) auth()->id(), 404);
    }

    public function show(Address $address)
    {
        $this->assertOwned($address);

        try {
            return new AddressResource($address);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(AddressRequest $request)
    {
        try {
            return new AddressResource($this->addressService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(AddressRequest $request, Address $address)
    {
        $this->assertOwned($address);

        try {
            return new AddressResource($this->addressService->update($request, $address));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(Address $address)
    {
        $this->assertOwned($address);

        try {
            $this->addressService->destroy($address);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
