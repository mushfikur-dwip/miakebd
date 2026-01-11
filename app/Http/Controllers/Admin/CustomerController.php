<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\User;
use App\Models\Transaction;
use App\Libraries\AppLibrary;
use App\Services\OrderService;
use App\Exports\CustomerExport;
use App\Services\CustomerService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Resources\OrderResource;
use App\Http\Requests\CustomerRequest;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\CustomerResource;
use App\Http\Resources\TransactionResource;
use App\Http\Requests\ChangeImageRequest;
use Illuminate\Routing\Controllers\Middleware;
use App\Http\Requests\UserChangePasswordRequest;
use Illuminate\Routing\Controllers\HasMiddleware;

class CustomerController extends AdminController implements HasMiddleware
{
    private CustomerService $customerService;
    private OrderService $orderService;

    public function __construct(CustomerService $customerService, OrderService $orderService)
    {
        parent::__construct();
        $this->customerService = $customerService;
        $this->orderService    = $orderService;
    }

    public static function middleware(): array
    {
        return [
            new Middleware('permission:customers', only: ['index']),
            new Middleware('permission:customers', only: ['export']),
            new Middleware('permission:customers', only: ['changePassword']),
            new Middleware('permission:customers', only: ['changeImage']),
            new Middleware('permission:customers', only: ['myOrder']),
            new Middleware('permission:customers_create', only: ['store']),
            new Middleware('permission:customers_edit', only: ['update']),
            new Middleware('permission:customers_delete', only: ['destroy']),
            new Middleware('permission:customers_show', only: ['show']),
        ];
    }

    public function index(PaginateRequest $request
    ) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return CustomerResource::collection($this->customerService->list($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function store(CustomerRequest $request
    ) : \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->store($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function update(
        CustomerRequest $request,
        User $customer
    ) : \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->update($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function destroy(User $customer
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->customerService->destroy($customer);
            return response('', 202);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function show(User $customer
    ) : \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->show($customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }


    public function export(PaginateRequest $request
    ) : \Illuminate\Http\Response | \Symfony\Component\HttpFoundation\BinaryFileResponse | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return Excel::download(new CustomerExport($this->customerService, $request), 'Customer.xlsx');
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changePassword(
        UserChangePasswordRequest $request,
        User $customer
    ) : \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->changePassword($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function changeImage(
        ChangeImageRequest $request,
        User $customer
    ) : \Illuminate\Http\Response | CustomerResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new CustomerResource($this->customerService->changeImage($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function myOrder(
        PaginateRequest $request,
        User $customer
    ) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return OrderResource::collection($this->orderService->userOrder($request, $customer));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function walletBalance(User $customer)
    {
        try {
            return response([
                'data' => [
                    'balance' => AppLibrary::flatAmountFormat($customer->balance),
                    'currency_balance' => AppLibrary::currencyAmountFormat($customer->balance),
                ]
            ]);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function walletTransactions(PaginateRequest $request, User $customer)
    {
        try {
            $transactions = Transaction::where('user_id', $customer->id)
                ->with(['user:id,name,email', 'admin:id,name'])
                ->orderBy('id', 'desc');

            if ($request->per_page) {
                return TransactionResource::collection($transactions->paginate($request->per_page));
            } else {
                return TransactionResource::collection($transactions->get());
            }
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function addCredit(\Illuminate\Http\Request $request, User $customer)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'required|string|max:500',
        ]);

        DB::beginTransaction();
        try {
            $balanceBefore = $customer->balance;
            $customer->balance += $request->amount;
            $customer->save();

            Transaction::create([
                'user_id' => $customer->id,
                'admin_id' => auth()->id(),
                'transaction_no' => 'WC-' . time() . '-' . $customer->id,
                'amount' => $request->amount,
                'payment_method' => 'manual_credit',
                'type' => 'manual_credit',
                'sign' => '+',
                'note' => $request->note,
                'balance_before' => $balanceBefore,
                'balance_after' => $customer->balance,
            ]);

            DB::commit();
            return response(['status' => true, 'message' => 'Credit added successfully']);
        } catch (Exception $exception) {
            DB::rollBack();
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function deductCredit(\Illuminate\Http\Request $request, User $customer)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'required|string|max:500',
        ]);

        if ($customer->balance < $request->amount) {
            return response(['status' => false, 'message' => 'Insufficient balance'], 422);
        }

        DB::beginTransaction();
        try {
            $balanceBefore = $customer->balance;
            $customer->balance -= $request->amount;
            $customer->save();

            Transaction::create([
                'user_id' => $customer->id,
                'admin_id' => auth()->id(),
                'transaction_no' => 'WD-' . time() . '-' . $customer->id,
                'amount' => $request->amount,
                'payment_method' => 'manual_debit',
                'type' => 'manual_debit',
                'sign' => '-',
                'note' => $request->note,
                'balance_before' => $balanceBefore,
                'balance_after' => $customer->balance,
            ]);

            DB::commit();
            return response(['status' => true, 'message' => 'Credit deducted successfully']);
        } catch (Exception $exception) {
            DB::rollBack();
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
