<?php

namespace App\Services;

use App\Models\Order;
use App\Models\PrintJob;

class DepartmentPrintingService
{
    /** Each entry contains product, type, quantity, optional old/new and note. */
    public function enqueue(Order $order, array $changes): void
    {
        $groups = [];
        foreach ($changes as $change) {
            $department = $change['product']->department;
            if (! $department?->printer_id) {
                continue;
            }
            $key = $department->id.':'.$change['type'];
            $groups[$key]['department'] = $department;
            $groups[$key]['type'] = $change['type'];
            $line = $change['product']->name.' — الكمية: '.$change['quantity'];
            if ($change['type'] === 'MODIFICATION') {
                $line .= ' (من '.$change['old'].' إلى '.$change['new'].')';
            }
            if (! empty($change['note'])) {
                $line .= ' — ملاحظة: '.$change['note'];
            }
            $groups[$key]['lines'][] = $line;
        }
        $labels = ['NEW_ORDER' => 'طلب جديد', 'ADDITION' => 'إضافة', 'MODIFICATION' => 'تعديل', 'CANCELLATION' => 'إلغاء'];
        foreach ($groups as $group) {
            PrintJob::create([
                'order_id' => $order->id, 'department_id' => $group['department']->id,
                'printer_id' => $group['department']->printer_id, 'job_type' => $group['type'],
                'status' => 'PENDING', 'attempts' => 0,
                'payload' => implode("\n", [$labels[$group['type']], 'الطلب: '.$order->order_number,
                    'الطاولة: '.$order->table->table_number, ...$group['lines']]),
            ]);
        }
    }
}
