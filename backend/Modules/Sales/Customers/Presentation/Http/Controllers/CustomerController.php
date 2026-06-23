<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sales\Customers\Application\Actions\CreateCustomerAction;
use Modules\Sales\Customers\Application\Actions\DeleteCustomerAction;
use Modules\Sales\Customers\Application\Actions\GetCustomerAction;
use Modules\Sales\Customers\Application\Actions\ListCustomersAction;
use Modules\Sales\Customers\Application\Actions\UpdateCustomerAction;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Presentation\Http\Requests\StoreCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Requests\UpdateCustomerRequest;
use Modules\Sales\Customers\Presentation\Http\Resources\CustomerResource;

final class CustomerController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListCustomersAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'country' => $request->query('country'),
            'city' => $request->query('city'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => CustomerResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $customer, GetCustomerAction $action): JsonResponse
    {
        $model = $action->execute($customer)->data();

        return $this->success(new CustomerResource($model));
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): JsonResponse
    {
        $result = $action->execute(CustomerDTO::fromArray($request->validated()));

        return $this->created(new CustomerResource($result->data()), $result->message());
    }

    public function update(
        UpdateCustomerRequest $request,
        string $customer,
        UpdateCustomerAction $action,
    ): JsonResponse {
        $result = $action->execute($customer, CustomerDTO::fromArray($request->validated()));

        return $this->updated(new CustomerResource($result->data()), $result->message());
    }

    public function destroy(string $customer, DeleteCustomerAction $action): JsonResponse
    {
        $result = $action->execute($customer);

        return $this->deleted($result->message() ?? 'Customer deleted successfully.');
    }
}
