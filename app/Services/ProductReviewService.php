<?php

namespace App\Services;

use Exception;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\ProductReview;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Http\Requests\ChangeImageRequest;
use App\Http\Requests\ProductReviewRequest;
use App\Libraries\QueryExceptionLibrary;

class ProductReviewService
{
    public object $productReview;

    /**
     * @throws Exception
     */
    public function store(ProductReviewRequest $request)
    {
        try {
            // Reviews are only offered on a delivered order's lines, but the
            // endpoint never checked: anyone with a token (and guest checkout
            // hands those out) could post unlimited reviews on any product.
            $productId = (int) $request->validated('product_id');
            $userId    = (int) Auth::id();

            if (!$this->hasReceived($userId, $productId)) {
                throw new Exception(trans('all.message.review_requires_delivered_order'), 422);
            }

            if (ProductReview::where(['user_id' => $userId, 'product_id' => $productId])->exists()) {
                throw new Exception(trans('all.message.review_already_exists'), 422);
            }

            DB::transaction(function () use ($request) {
                $this->productReview = ProductReview::create(
                    $request->safe()->only(['product_id', 'star', 'review']) + ['user_id' => Auth::user()->id]
                );

                if ($request->images) {
                    foreach ($request->images as $image) {
                        $this->productReview->addMedia($image)->toMediaCollection('product-review');
                    }
                }
            });
            return $this->productReview;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function update(ProductReviewRequest $request, ProductReview $productReview)
    {
        try {
            // star and review only: product_id came along with validated(), so
            // a review could be moved onto a product never bought.
            DB::transaction(function () use ($request, $productReview) {
                $productReview->update($request->safe()->only(['star', 'review']));
            });
            return $productReview;
        } catch (Exception $exception) {
            DB::rollBack();
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function show(ProductReview $productReview)
    {
        try {
            if ($productReview->user_id === Auth::user()->id) {
                return $productReview;
            } else {
                return  [];
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function uploadImage(ChangeImageRequest $request, ProductReview $productReview): ProductReview
    {
        try {
            $productReview->addMedia($request->image)->toMediaCollection('product-review');
            return $productReview;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function deleteImage(ProductReview $productReview, $index): ProductReview
    {
        try {
            $images = $productReview->getMedia('product-review');
            if (isset($images[$index])) {
                $images[$index]->delete();
            }
            return ProductReview::find($productReview->id);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    // The same rule the storefront applies when it decides to show the review
    // button: the product was on one of this customer's delivered orders.
    private function hasReceived(int $userId, int $productId): bool
    {
        return Stock::where('model_type', Order::class)
            ->where('product_id', $productId)
            ->whereIn('model_id', Order::where('user_id', $userId)->where('status', OrderStatus::DELIVERED)->select('id'))
            ->exists();
    }
}
