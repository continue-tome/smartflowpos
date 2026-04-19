<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['category', 'modifierGroups.modifiers'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->category_id, fn($q) => $q->where('category_id', $request->category_id))
            ->when(isset($request->available), fn($q) => $q->where('available', $request->boolean('available')))
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->where('active', true)->orderBy('order')->orderBy('name')->get();

        return response()->json($products);
    }

    public function publicMenu(Request $request, string $restaurantSlug)
    {
        $restaurant = \App\Models\Restaurant::where('slug', $restaurantSlug)->firstOrFail();

        $categories = Category::with([
            'products' => fn($q) => $q->where('available', true)->where('active', true)->orderBy('order'),
            'products.modifierGroups.modifiers',
        ])
        ->where('restaurant_id', $restaurant->id)->where('active', true)
        ->whereNull('parent_id')->orderBy('order')->get();

        return response()->json([
            'restaurant' => $restaurant->only(['name', 'logo', 'address']),
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'category_id'  => 'required|exists:categories,id',
            'name'         => 'required|string|max:200',
            'description'  => 'nullable|string',
            'price'        => 'required|numeric|min:0',
            'cost_price'   => 'nullable|numeric|min:0',
            'vat_rate'     => 'nullable|numeric|min:0|max:100',
            'track_stock'  => 'nullable',
            'quantity'     => 'nullable|numeric|min:0',
            'min_quantity' => 'nullable|numeric|min:0',
            'available'    => 'nullable',
            'image'        => 'nullable|image|max:2048',
            'emoji'        => 'nullable|string|max:20',
            'modifier_groups' => 'nullable|array',
            'modifier_groups.*.name'     => 'required|string',
            'modifier_groups.*.required' => 'boolean',
            'modifier_groups.*.multiple' => 'boolean',
            'modifier_groups.*.modifiers' => 'array',
            'modifier_groups.*.modifiers.*.name'        => 'required|string',
            'modifier_groups.*.modifiers.*.extra_price' => 'numeric|min:0',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store("restaurants/{$request->user()->restaurant_id}/products", 'public');
        }

        $product = Product::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'category_id'   => $request->category_id,
            'name'          => $request->name,
            'description'   => $request->description,
            'price'         => $request->price,
            'cost_price'    => $request->cost_price,
            'vat_rate'      => $request->vat_rate ?? 18,
            'track_stock'   => $request->boolean('track_stock'),
            'quantity'      => $request->quantity ?? 0,
            'min_quantity'  => $request->min_quantity ?? 0,
            'available'     => $request->boolean('available', true),
            'image'         => $imagePath,
            'emoji'         => $request->emoji ?? '🍽️',
        ]);

        if ($request->modifier_groups) {
            foreach ($request->modifier_groups as $i => $groupData) {
                $group = $product->modifierGroups()->create([
                    'name'     => $groupData['name'],
                    'required' => $groupData['required'] ?? false,
                    'multiple' => $groupData['multiple'] ?? false,
                    'order'    => $i,
                ]);

                foreach ($groupData['modifiers'] ?? [] as $j => $modData) {
                    $group->modifiers()->create([
                        'name'        => $modData['name'],
                        'extra_price' => $modData['extra_price'] ?? 0,
                        'order'       => $j,
                    ]);
                }
            }
        }

        return response()->json($product->load('modifierGroups.modifiers', 'category'), 201);
    }

    public function update(Request $request, Product $product)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');

        $request->validate([
            'name'        => 'sometimes|string|max:200',
            'price'       => 'sometimes|numeric|min:0',
            'available'   => 'nullable',
            'track_stock' => 'nullable',
            'quantity'    => 'sometimes|numeric|min:0',
            'min_quantity'=> 'sometimes|numeric|min:0',
            'category_id' => 'sometimes|exists:categories,id',
            'image'       => 'nullable|sometimes|image|max:2048',
            'emoji'       => 'nullable|string|max:20',
        ]);

        $data = $request->only([
            'name', 'description', 'price', 'cost_price', 'vat_rate',
            'category_id', 'order', 'emoji'
        ]);

        if ($request->has('available'))   $data['available'] = $request->boolean('available');
        if ($request->has('track_stock')) $data['track_stock'] = $request->boolean('track_stock');

        if ($request->hasFile('image')) {
            if ($product->image) Storage::disk('public')->delete($product->image);
            $data['image'] = $request->file('image')->store(
                "restaurants/{$product->restaurant_id}/products", 'public'
            );
        }

        $product->update($data);

        return response()->json($product->load('category', 'modifierGroups.modifiers'));
    }

    public function toggleAvailable(Product $product, Request $request)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $product->update(['available' => !$product->available]);
        return response()->json($product);
    }

    public function destroy(Product $product, Request $request)
    {
        abort_if($product->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $product->delete();
        return response()->json(['message' => 'Produit supprimé.']);
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'products'       => 'required|array',
            'products.*.id'  => 'required|exists:products,id',
            'products.*.order' => 'required|integer',
        ]);

        foreach ($request->products as $item) {
            Product::where('id', $item['id'])
                ->where('restaurant_id', $request->user()->restaurant_id)
                ->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }
}
