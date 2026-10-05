<?php

namespace Tests\Feature\Permissions;

use ReflectionClass;
use Tests\TestCase;

class ListScopingSmokeTest extends TestCase
{
    public function test_scoped_controllers_apply_location_scope(): void
    {
        $controllers = [
            \App\Http\Controllers\Api\InventoryController::class,
            \App\Http\Controllers\Api\TransferController::class,
            \App\Http\Controllers\Api\IssuanceController::class,
            \App\Http\Controllers\Api\ReceivingController::class,
            \App\Http\Controllers\Api\AdjustmentController::class,
            \App\Http\Controllers\Api\SaleController::class,
        ];

        foreach ($controllers as $class) {
            $src = file_get_contents((new ReflectionClass($class))->getFileName());
            $this->assertTrue(
                str_contains($src, 'forUser(') || str_contains($src, 'scopedLocationId('),
                "$class must apply location scoping (forUser or scopedLocationId)"
            );
        }
    }
}
