<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Address\StoreAddressRequest;
use App\Http\Requests\Address\UpdateAddressRequest;
use App\Http\Resources\AddressResource;
use App\Models\Address;
use App\Models\User;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class AddressController extends Controller
{
    public function __construct(private AddressService $addresses) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $this->user($request)->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        );
    }

    public function store(StoreAddressRequest $request): JsonResponse
    {
        $address = $this->addresses->create($this->user($request), $request->validated());

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function show(Address $address): AddressResource
    {
        Gate::authorize('view', $address);

        return new AddressResource($address);
    }

    public function update(UpdateAddressRequest $request, Address $address): AddressResource
    {
        Gate::authorize('update', $address);

        return new AddressResource($this->addresses->update($address, $request->validated()));
    }

    public function destroy(Address $address): Response
    {
        Gate::authorize('delete', $address);

        $this->addresses->delete($address);

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
