<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Ticket;
use App\Support\AuditLogger;

class CustomerController extends Controller
{
    public function destroy(string $id)
    {
        /** @var Customer|null $customer */
        $customer = Customer::query()->find($id);
        if (!$customer) {
            return response()->json(['message' => 'Customer tidak ditemukan.'], 404);
        }

        $activeTicketCount = Ticket::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['new', 'open', 'pending', 'on_progress'])
            ->count();

        if ($activeTicketCount > 0) {
            return response()->json(['message' => 'Customer masih memiliki ticket aktif.'], 422);
        }

        $customer->delete();

        AuditLogger::log(
            action: 'admin.customer.deleted',
            subject: $customer,
            payload: ['deleted' => ['name' => $customer->name, 'phone_number' => $customer->phone_number]],
        );

        return response()->json(['message' => 'Customer berhasil dihapus.'], 200);
    }
}

