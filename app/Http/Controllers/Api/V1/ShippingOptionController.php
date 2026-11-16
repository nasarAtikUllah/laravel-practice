<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreShippingOptionRequest;
use App\Http\Requests\Api\V1\UpdateShippingOptionRequest;
use App\Http\Resources\ShippingOptionResource;
use App\Models\ShippingOption;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShippingOptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'is_active' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $options = ShippingOption::query()
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->ordered()
            ->paginate(15);

        return $this->paginated($options, ShippingOptionResource::class, 'Shipping options retrieved successfully.');
    }

    public function store(StoreShippingOptionRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $option = DB::transaction(function () use ($data) {
                if ($data['is_default'] ?? false) {
                    $this->clearDefault();
                }

                return ShippingOption::create($data)->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error('A delivery option with this code already exists.', 422);
        }

        return $this->success(ShippingOptionResource::make($option), 'Shipping option created successfully.', 201);
    }

    public function show(ShippingOption $shippingOption): JsonResponse
    {
        return $this->success(ShippingOptionResource::make($shippingOption), 'Shipping option retrieved successfully.');
    }

    public function update(UpdateShippingOptionRequest $request, ShippingOption $shippingOption): JsonResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($request, $shippingOption, $data) {
            if ($request->has('is_default') && $request->boolean('is_default') && ! $shippingOption->is_default) {
                $this->clearDefault($shippingOption->id);
            }

            $shippingOption->update($data);
        });

        return $this->success(ShippingOptionResource::make($shippingOption->refresh()), 'Shipping option updated successfully.');
    }

    public function destroy(ShippingOption $shippingOption): JsonResponse
    {
        if ($shippingOption->is_default) {
            return $this->error('The default delivery option cannot be deleted. Make another option the default first.', 409);
        }

        if ($shippingOption->is_active && ! ShippingOption::query()->active()->whereKeyNot($shippingOption->id)->exists()) {
            return $this->error('At least one delivery option must stay active.', 409);
        }

        $shippingOption->delete();

        return $this->success(null, 'Shipping option deleted successfully.');
    }

    /** Only one option is the default. The rows are locked while it changes. */
    private function clearDefault(?int $exceptId = null): void
    {
        ShippingOption::query()
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->get()
            ->each(fn (ShippingOption $option) => $option->update(['is_default' => false]));
    }
}
