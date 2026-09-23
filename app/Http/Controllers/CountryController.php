<?php

namespace App\Http\Controllers;

use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CountryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Country::query();

        if (! $request->boolean('all')) {
            $query->where('is_active', true);
        }

        $countries = $query->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Country $country): array => $this->present($country));

        return response()->json([
            'countries' => $countries,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:countries,name'],
            'code' => ['nullable', 'string', 'max:10'],
            'phoneCode' => ['nullable', 'string', 'max:10'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'isActive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $country = Country::create([
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'phone_code' => $data['phoneCode'] ?? $data['phone_code'] ?? null,
            'is_active' => $data['isActive'] ?? $data['is_active'] ?? true,
            'sort_order' => $data['sortOrder'] ?? $data['sort_order'] ?? 0,
        ]);

        return response()->json([
            'country' => $this->present($country),
        ], 201);
    }

    public function update(Request $request, Country $country): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'unique:countries,name,' . $country->id],
            'code' => ['nullable', 'string', 'max:10'],
            'phoneCode' => ['nullable', 'string', 'max:10'],
            'phone_code' => ['nullable', 'string', 'max:10'],
            'isActive' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'sortOrder' => ['nullable', 'integer'],
            'sort_order' => ['nullable', 'integer'],
        ]);

        $attributes = [];
        if (array_key_exists('name', $data)) {
            $attributes['name'] = $data['name'];
        }
        if (array_key_exists('code', $data)) {
            $attributes['code'] = $data['code'];
        }
        if (array_key_exists('phoneCode', $data) || array_key_exists('phone_code', $data)) {
            $attributes['phone_code'] = $data['phoneCode'] ?? $data['phone_code'] ?? null;
        }
        if (array_key_exists('isActive', $data) || array_key_exists('is_active', $data)) {
            $attributes['is_active'] = $data['isActive'] ?? $data['is_active'] ?? true;
        }
        if (array_key_exists('sortOrder', $data) || array_key_exists('sort_order', $data)) {
            $attributes['sort_order'] = $data['sortOrder'] ?? $data['sort_order'] ?? 0;
        }

        $country->update($attributes);

        return response()->json([
            'country' => $this->present($country->fresh()),
        ]);
    }

    public function destroy(Country $country): JsonResponse
    {
        $country->delete();

        return response()->json([
            'message' => "Country '{$country->name}' deleted successfully.",
        ]);
    }

    private function present(Country $country): array
    {
        return [
            'id' => $country->id,
            'name' => $country->name,
            'code' => $country->code,
            'phoneCode' => $country->phone_code,
            'phone_code' => $country->phone_code,
            'isActive' => (bool) $country->is_active,
            'is_active' => (bool) $country->is_active,
            'sortOrder' => (int) $country->sort_order,
            'sort_order' => (int) $country->sort_order,
            'createdAt' => $country->created_at?->toISOString(),
            'updatedAt' => $country->updated_at?->toISOString(),
        ];
    }
}
