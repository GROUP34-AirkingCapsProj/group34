<?php
// Find in-stock appliance serials (status_id=26 = In Stock)
$serials = \App\Models\ItemSerial::where('status_id', 26)->with('product')->take(3)->get();
foreach ($serials as $s) {
    echo "SERIAL: {$s->serial_number} | PRODUCT: {$s->product->product_name}\n";
}
echo "---\n";
// Find consumable products
$consumables = \App\Models\ConsumableDetail::with('product')->take(5)->get();
foreach ($consumables as $c) {
    $pcode = $c->product->product_code ?? 'N/A';
    $pname = $c->product->product_name;
    $price = $c->product->unit_price;
    $ppp   = $c->pieces_per_package;
    echo "BARCODE: {$pcode} | PRODUCT: {$pname} | PRICE: {$price} | PPP: {$ppp}\n";
}
echo "---\n";
// Also get a customer ID
$cust = \App\Models\Customer::first();
echo "CUSTOMER_ID: {$cust->customer_id} | NAME: {$cust->customer_name}\n";
