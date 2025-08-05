<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subtotal',
        'shipping',
        'coupon_code',
        'discount',
        'grand_total',
        'first_name',
        'last_name',
        'email',
        'phone',
        'country_id',
        'address',
        'house',
        'city',
        'state',
        'zip',
        'notes',
        'payment_status',       // Added for payment tracking
        'payment_method',       // Added for payment method (cod/razorpay/etc)
        'payment_id',          // Added for Razorpay payment ID
        'razorpay_order_id',   // Added for Razorpay order ID
        'razorpay_signature',  // Added for Razorpay signature
        'status',              // Added for order status (pending/processing/etc)
        'unique_order_id'      // Added for your custom order reference
    ];

    protected $dates = [
        'razorpay_payment_datetime' // Added for payment timestamp
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function order_items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function discount_coupon()
    {
        return $this->belongsTo(DiscountCoupon::class);
    }

    // In app/Models/Order.php
public function getStatusLabelAttribute()
{
    $statuses = [
        'pending' => 'Pending',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled'
    ];
    
    return $statuses[$this->status] ?? ucfirst($this->status);
}
}