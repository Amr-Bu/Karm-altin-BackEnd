<?php

namespace Tests\Support;

use App\Models\Department;
use App\Models\InventoryStock;
use App\Models\Printer;
use App\Models\Product;
use App\Models\RestaurantTable;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class OrderFixtures
{
    public static function create(): array
    {
        $suffix = bin2hex(random_bytes(6));
        $admin = User::create(['name' => 'مدير الاختبار', 'username' => 'test_admin_'.$suffix, 'password_hash' => Hash::make('test-password'), 'role' => 'ADMIN', 'is_active' => true]);
        $employee = User::create(['name' => 'موظف الاختبار', 'username' => 'test_employee_'.$suffix, 'password_hash' => Hash::make('test-password'), 'role' => 'EMPLOYEE', 'is_active' => true]);
        $table = RestaurantTable::create(['table_number' => ((int) RestaurantTable::max('table_number')) + 1, 'default_chairs' => 4, 'status' => 'AVAILABLE', 'is_active' => true]);
        $printer = Printer::create(['name' => 'مطبخ الاختبار', 'printer_type' => 'DEPARTMENT', 'connection_type' => 'LOCAL_AGENT', 'is_active' => true]);
        $department = Department::create(['name' => 'مطبخ الاختبار', 'printer_id' => $printer->id, 'is_active' => true]);
        $silent = Department::create(['name' => 'قسم بلا طابعة', 'is_active' => true]);
        // Callers isolate this fixture with a rollback or a disposable database.
        Printer::where('printer_type', 'RECEIPT')->update(['is_active' => false]);
        $receipt = Printer::create(['name' => 'إيصال الاختبار', 'printer_type' => 'RECEIPT', 'connection_type' => 'NETWORK', 'connection_config' => '{"ip":"127.0.0.1","port":1}', 'is_active' => true]);
        Setting::updateOrCreate(['key' => 'chair_price'], ['value' => '15']);
        $raw = Product::create(['name' => 'مادة خام', 'item_type' => 'RAW_MATERIAL', 'unit' => 'KG', 'is_stock_tracked' => true, 'is_active' => true]);
        $meal = Product::create(['name' => 'وجبة', 'item_type' => 'PRODUCED_PRODUCT', 'unit' => 'PIECE', 'price' => '10.25', 'department_id' => $department->id, 'is_stock_tracked' => true, 'is_active' => true]);
        $meal->ingredients()->create(['raw_material_id' => $raw->id, 'quantity_required' => '0.250']);
        $drink = Product::create(['name' => 'مشروب', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => '3.10', 'department_id' => $department->id, 'is_stock_tracked' => true, 'is_active' => true]);
        $snack = Product::create(['name' => 'وجبة خفيفة', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => '2.00', 'department_id' => $silent->id, 'is_stock_tracked' => true, 'is_active' => true]);
        $extra = Product::create(['name' => 'إضافة', 'item_type' => 'PURCHASED_PRODUCT', 'unit' => 'PIECE', 'price' => '1.50', 'department_id' => $department->id, 'is_stock_tracked' => true, 'is_active' => true]);
        foreach ([$raw, $drink, $snack, $extra] as $product) {
            InventoryStock::create(['product_id' => $product->id, 'current_quantity' => '100.000']);
        }

        return compact('admin', 'employee', 'table', 'printer', 'department', 'silent', 'receipt', 'raw', 'meal', 'drink', 'snack', 'extra');
    }
}
