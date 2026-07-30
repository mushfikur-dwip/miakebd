<?php

namespace App\Services;



use Exception;
use App\Enums\Ask;
use Carbon\Carbon;
use App\Models\Coupon;
use App\Models\OrderCoupon;
use App\Libraries\AppLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\CouponRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\CouponCheckRequest;
use App\Libraries\QueryExceptionLibrary;

class CouponService
{
    public object $coupon;
    protected array $couponFilter = [
        'name',
        'code',
        'discount',
        'discount_type',
        'start_date',
        'end_date',
        'minimum_order',
        'maximum_discount',
        'limit_per_user',
    ];

    protected array $exceptFilter = [
        'excepts'
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

            return Coupon::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->couponFilter)) {
                        if ($key == "start_date") {
                            $start_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '>=', $start_date);
                        } else if ($key == "end_date") {
                            $end_date  = Date('Y-m-d', strtotime($request));
                            $query->whereDate($key, '<=', $end_date);
                        } else {
                            $query->where($key, 'like', '%' . $request . '%');
                        }
                    }

                    if (in_array($key, $this->exceptFilter)) {
                        $explodes = explode('|', $request);
                        if (is_array($explodes)) {
                            foreach ($explodes as $explode) {
                                $query->where('id', '!=', $explode);
                            }
                        }
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(CouponRequest $request)
    {
        try {
            $this->coupon = Coupon::create([
                'name'             => $request->name,
                'description'      => $request->description,
                'code'             => $request->code,
                'discount'         => $request->discount,
                'discount_type'    => $request->discount_type,
                'start_date'       => !blank($request->start_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->start_date)
                ) : "",
                'end_date'         => !blank($request->end_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->end_date)
                ) : "",
                'minimum_order'    => $request->minimum_order,
                'maximum_discount' => $request->maximum_discount,
                'limit_per_user'   => $request->limit_per_user,
            ]);
            if ($request->image) {
                $this->coupon->addMedia($request->image)->toMediaCollection('coupon');
            }
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(CouponRequest $request, Coupon $coupon)
    {
        try {
            DB::transaction(function () use ($request, $coupon) {
                $this->coupon             = $coupon;
                $coupon->name             = $request->name;
                $coupon->description      = $request->description;
                $coupon->code             = $request->code;
                $coupon->discount         = $request->discount;
                $coupon->discount_type    = $request->discount_type;
                $coupon->start_date       = !blank($request->start_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->start_date)
                ) : null;
                $coupon->end_date         = !blank($request->end_date) ? date(
                    'Y-m-d H:i:s',
                    strtotime($request->end_date)
                ) : null;
                $coupon->minimum_order    = $request->minimum_order;
                $coupon->maximum_discount = $request->maximum_discount;
                $coupon->limit_per_user   = $request->limit_per_user;
                $coupon->save();
                if ($request->image) {
                    $coupon->media()->delete();
                    $coupon->addMedia($request->image)->toMediaCollection('coupon');
                }
            });
            return $this->coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Coupon $coupon): void
    {
        try {
            $coupon->delete();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Coupon $coupon): Coupon
    {
        try {
            return $coupon;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponDateWise(): \Illuminate\Database\Eloquent\Collection
    {
        try {
            return Coupon::all()->filter(function ($item) {
                if (Carbon::now()->isBetween($item->start_date, $item->end_date)) {
                    return $item;
                }
            });
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function couponChecking(CouponCheckRequest $request)
    {
        try {
            // Coupons require a real account.
            //
            // limit_per_user is counted against order_coupons.user_id, and guest
            // checkout deliberately mints a NEW user row per checkout — so a
            // once-per-customer coupon could be redeemed without limit simply by
            // ordering as a guest each time. The customer is asked to claim
            // their account (one-click, OTP-verified) before a code applies.
            // auth('sanctum'), not auth(): this route carries no auth:sanctum
            // middleware, so the default guard resolves to null even when a
            // valid Bearer token is present — which would reject every real
            // customer too. Resolving the token guard directly keeps the
            // friendly message instead of a bare 401.
            $user = auth('sanctum')->user();

            if (blank($user) || (int) $user->is_guest === Ask::YES) {
                throw new Exception(trans('all.message.coupon_requires_account'), 422);
            }

            $coupon = Coupon::where(['code' => $request->code])->first();
            if ($coupon) {
                if ($coupon->minimum_order > $request->total) {
                    throw new Exception(trans('all.message.minimum_order_amount') . AppLibrary::convertAmountFormat($coupon->minimum_order), 422);
                } else {
                    if (strtotime($coupon->end_date) >= strtotime(Carbon::now())) {
                        $ordered_coupon_count = OrderCoupon::where(['user_id' => auth()->user()->id, 'coupon_id' => $coupon->id])->count();
                        if ($coupon->limit_per_user <= $ordered_coupon_count) {
                            throw new Exception(trans('all.message.coupon_limit_exceeded'), 422);
                        }
                        return $coupon;
                    } else {
                        throw new Exception(trans('all.message.coupon_date_expired'), 422);
                    }
                }
            } else {
                throw new Exception(trans('all.message.coupon_not_exist'), 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
