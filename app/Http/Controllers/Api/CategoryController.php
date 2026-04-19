<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    public function index(Request $request)
    {
        $categories = Category::with(['children', 'products' => fn($q) => $q->where('active', true)])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->whereNull('parent_id')->where('active', true)->orderBy('order')->get();
        return response()->json($categories);
    }

    public function flat(Request $request)
    {
        $categories = Category::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)->orderBy('name')->get(['id', 'name', 'parent_id', 'destination', 'color']);
        return response()->json($categories);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'name'        => 'required|string|max:100',
            'parent_id'   => 'nullable|exists:categories,id',
            'image'       => 'nullable|image|max:2048',
            'order'       => 'integer|min:0',
            'destination' => 'nullable|in:kitchen,bar,pizza',
            'color'       => 'nullable|string|max:20'
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store("restaurants/{$request->user()->restaurant_id}/categories", 'public');
        }

        $category = Category::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name'          => $request->name,
            'parent_id'     => $request->parent_id,
            'image'         => $imagePath,
            'order'         => $request->order ?? 0,
            'destination'   => $request->destination ?? 'kitchen',
            'color'         => $request->color,
        ]);

        return response()->json($category, 201);
    }

    public function update(Request $request, Category $category)
    {
        abort_if($category->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'name'        => 'sometimes|string|max:100',
            'parent_id'   => 'nullable|exists:categories,id',
            'order'       => 'integer|min:0',
            'active'      => 'nullable',
            'destination' => 'nullable|in:kitchen,bar,pizza',
            'color'       => 'nullable|string|max:20',
        ]);

        if ($request->hasFile('image')) {
            if ($category->image) Storage::disk('public')->delete($category->image);
            $request->merge(['image' => $request->file('image')->store("restaurants/{$category->restaurant_id}/categories", 'public')]);
        }

        $category->update($request->only(['name', 'parent_id', 'order', 'active', 'image', 'destination', 'color']));
        return response()->json($category);
    }

    public function destroy(Request $request, Category $category)
    {
        abort_if($category->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $hasProducts = $category->products()->where('active', true)->exists();
        abort_if($hasProducts, 422, 'Impossible de supprimer : catégorie contient des produits actifs.');
        $category->update(['active' => false]);
        return response()->json(['message' => 'Catégorie désactivée.']);
    }

    public function reorder(Request $request)
    {
        $request->validate(['categories' => 'required|array', 'categories.*.id' => 'required|exists:categories,id', 'categories.*.order' => 'required|integer']);

        foreach ($request->categories as $item) {
            Category::where('id', $item['id'])->where('restaurant_id', $request->user()->restaurant_id)->update(['order' => $item['order']]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }
}
