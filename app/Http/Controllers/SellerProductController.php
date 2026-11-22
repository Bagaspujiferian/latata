<?php

namespace App\Http\Controllers;

use App\Models\GameCategory;
use App\Models\Product;
use App\Services\SellerProductCrudService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SellerProductController extends Controller
{
    public function __construct(
        private readonly SellerProductCrudService $crudService,
    ) {}

    public function categoryAttributes(GameCategory $category): JsonResponse
    {
        $category->load(['game', 'attributes.options']);

        return response()->json([
            'ok' => true,
            'category_id' => $category->id,
            'delivery_method' => $category->delivery_method,
            'platform_fee_percent' => (float) $category->platform_fee_percent,
            'attributes' => $category->attributes->map(fn ($attr): array => [
                'id' => $attr->id,
                'name' => $attr->name,
                'slug' => $attr->slug,
                'input_type' => $attr->input_type,
                'unit_label' => $attr->unit_label,
                'is_required' => (bool) $attr->is_required,
                'is_filterable' => (bool) $attr->is_filterable,
                'options' => $attr->options->map(fn ($opt): array => [
                    'id' => $opt->id,
                    'label' => $opt->label,
                    'value_slug' => $opt->value_slug,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user()->load('sellerProfile');
        $categories = GameCategory::with(['game', 'attributes.options'])
            ->orderBy('game_id')
            ->orderBy('id')
            ->get();

        return view('seller.products-create', compact('user', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'game_category_id' => ['required', 'integer', 'exists:game_categories,id'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'price' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'min_order' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_order' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sla_target_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'h2h_provider_sku' => ['nullable', 'string', 'max:60'],
            'primary_upload_index' => ['nullable', 'integer', 'min:0', 'max:5'],
            'attributes' => ['nullable', 'array'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ]);

        try {
            $uploadedImages = $request->file('images') ?? [];
            if (! is_array($uploadedImages)) {
                $uploadedImages = [$uploadedImages];
            }

            $product = $this->crudService->createProduct(
                sellerId: $request->user()->id,
                data: $validated,
                uploadedImages: array_values(array_filter($uploadedImages)),
            );

            return redirect()->route('seller.products')
                ->with('success', "Produk {$product->title} ({$product->sku_code}) berhasil dibuat! Stok terverifikasi: {$product->stock}.");
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function edit(Request $request, Product $product): View
    {
        if ((int) $product->seller_id !== (int) $request->user()->id) {
            abort(403, 'Anda tidak berwenang mengedit produk milik toko lain.');
        }

        $user = $request->user()->load('sellerProfile');
        $product->load(['gameCategory.game', 'gameCategory.attributes.options', 'images', 'attributeValues']);
        $categories = GameCategory::with(['game', 'attributes.options'])
            ->orderBy('game_id')
            ->orderBy('id')
            ->get();

        return view('seller.products-edit', compact('user', 'product', 'categories'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        if ((int) $product->seller_id !== (int) $request->user()->id) {
            abort(403, 'Anda tidak berwenang mengubah produk milik toko lain.');
        }

        $validated = $request->validate([
            'game_category_id' => ['nullable', 'integer', 'exists:game_categories,id'],
            'title' => ['required', 'string', 'min:5', 'max:200'],
            'description' => ['nullable', 'string', 'min:10', 'max:3000'],
            'price' => ['required', 'numeric', 'min:1000', 'max:50000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'min_order' => ['nullable', 'integer', 'min:1', 'max:50'],
            'max_order' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sla_target_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'h2h_provider_sku' => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
            'attributes' => ['nullable', 'array'],
            'delete_image_ids' => ['nullable', 'array'],
            'delete_image_ids.*' => ['integer'],
            'primary_image_id' => ['nullable', 'integer'],
            'images' => ['nullable', 'array', 'max:6'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'mimetypes:image/jpeg,image/png,image/webp', 'max:2048'],
        ]);

        try {
            $newImages = $request->file('images') ?? [];
            if (! is_array($newImages)) {
                $newImages = [$newImages];
            }

            $updated = $this->crudService->updateProduct(
                sellerId: $request->user()->id,
                productId: $product->id,
                data: $validated,
                newImages: array_values(array_filter($newImages)),
                deleteImageIds: array_map('intval', $validated['delete_image_ids'] ?? []),
                primaryImageId: isset($validated['primary_image_id']) ? (int) $validated['primary_image_id'] : null,
            );

            return redirect()->route('seller.products')
                ->with('success', "Produk {$updated->title} berhasil diperbarui (Stok terverifikasi: {$updated->stock}).");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function toggleActive(Request $request, Product $product): RedirectResponse
    {
        try {
            $updated = $this->crudService->toggleProductActive($request->user()->id, $product->id);
            $label = $updated->is_active ? 'AKTIF di etalase publik' : 'NONAKTIF (disembunyikan sementara)';

            return back()->with('success', "Status produk \"{$updated->title}\" diubah menjadi {$label}.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        try {
            $this->crudService->archiveProduct($request->user()->id, $product->id);

            return back()->with('success', 'Produk berhasil diarsipkan (soft-delete). Riwayat pesanan lama tetap utuh.');
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function restore(Request $request, int $id): RedirectResponse
    {
        try {
            $product = $this->crudService->restoreProduct($request->user()->id, $id);

            return back()->with('success', "Produk \"{$product->title}\" berhasil dipulihkan ke etalase aktif.");
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
