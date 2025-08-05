<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\CustomerAddress;
use App\Models\DiscountCoupon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ShippingCharge;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Gloudemans\Shoppingcart\Facades\Cart;
use Illuminate\Support\Facades\Auth;
use Validator;
use Razorpay\Api\Api;
use Illuminate\Support\Facades\Log;


class CheckoutController extends Controller
{
    public function checkout()
    {
        // If cart has 0 items, then the user cannot access checkout page
        if (Cart::count() == 0) {
            return redirect()->route("userend_cartpage");
        }

        // If user is not authenticated, then the user cannot access checkout page
        if (!Auth::check()) {
            if (!session()->has('url.intended')) {
                session(['url.intended' => url()->current()]); // Save the current url (checkout page's url) in session
            }

            return redirect()->route("userend_login_page");
        }

        $subtotal = Cart::subtotal(2, '.', '');
        $shipping_charge = 0;
        $grand_total = 0;
        $discount = 0;
        $cart_content = Cart::content();
        $countries = Country::get();
        $customer_address = CustomerAddress::where('user_id', Auth::user()->id)->first();

        // Calculate shipping charge
        if (!empty($customer_address)) {
            $shipping = ShippingCharge::where('country_id', $customer_address->country_id)->first();
            // dd($shipping->amount);
            $shipping_charge = $shipping->amount;
        }

        // Apply discount here
        if (session()->has('discount_coupon')) {
            $discount_coupon = session()->get('discount_coupon');
            if ($discount_coupon->type == 'percent') {
                $discount = $subtotal * ((double) $discount_coupon->discount_amount / 100.00);
            } else {
                $discount = (double) $discount_coupon->discount_amount;
            }
        }

        $grand_total = ($subtotal + $shipping_charge) - $discount;

        session()->forget('url.intended');

        return view("user_end.checkout", compact("cart_content", "countries", "customer_address", "shipping_charge", "grand_total", "discount"));
    }

    public function process_checkout(Request $request)
    {
        // 1: Apply validation
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|min:2',
            'last_name' => 'required',
            'email' => 'required|email',
            'country_code' => 'required',
            'phone' => 'required|digits:10|numeric',
            'country' => 'required|integer',
            'address' => 'required|min:5',
            'city' => 'required',
            'state' => 'required',
            'zip' => 'required|digits:6|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'msg' => 'Unable to save the order.',
                'errors' => $validator->errors(),
            ]);
        }

        // 2: Save customer address
        $user = Auth::user();

        CustomerAddress::updateOrCreate(
            ['user_id' => $user->id], // If this user's address exists in the table then update with below array's attributes, or else create a new record
            [
                'user_id' => $user->id,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'email' => $request->email,
                'country_code' => $request->country_code,
                'phone' => $request->phone,
                'country_id' => $request->country,
                'address' => $request->address,
                'house' => $request->house,
                'city' => $request->city,
                'state' => $request->state,
                'zip' => $request->zip,
            ],
        );

        // 3: Save order
        $subtotal = Cart::subtotal(2, '.', '');
        $shipping = 0;
        $discount = 0;
        $discount_coupon_id = null;
        $discount_coupon_code = '';

        $shipping_info = ShippingCharge::where('country_id', $request->country)->first();

        if (!empty($shipping_info)) {
            $shipping = $shipping_info->amount;
        } else {
            $shipping_info = ShippingCharge::join('countries', 'shipping_charges.country_id', '=', 'countries.id')
                ->where('countries.name', 'Rest of the world')
                ->first();

            $shipping = $shipping_info->amount;
        }

        // Apply discount here
        if (session()->has('discount_coupon')) {
            $discount_coupon = session()->get('discount_coupon');
            if ($discount_coupon->type == 'percent') {
                $discount = $subtotal * ((double) $discount_coupon->discount_amount / 100.00);
            } else {
                $discount = (double) $discount_coupon->discount_amount;
            }
            $discount_coupon_id = $discount_coupon->id;
            $discount_coupon_code = $discount_coupon->code;
        }

        $grand_total = ceil(($subtotal + $shipping) - $discount);

        $order = new Order();
        $order->unique_order_id = "LE-XXXX";
        $order->user_id = $user->id;
        $order->subtotal = $subtotal;
        $order->shipping = $shipping;
        $order->discount_coupon_id = $discount_coupon_id;
        $order->coupon_code = $discount_coupon_code;
        $order->discount = $discount;
        $order->grand_total = $grand_total;
        $order->payment_status = 'not_paid';

        $order->first_name = $request->first_name;
        $order->last_name = $request->last_name;
        $order->email = $request->email;
        $order->country_code = $request->country_code;
        $order->phone = $request->phone;
        $order->country_id = $request->country;
        $order->address = $request->address;
        $order->house = $request->house;
        $order->city = $request->city;
        $order->state = $request->state;
        $order->zip = $request->zip;
        $order->notes = $request->order_notes;
        $order->payment_method = $request->payment_method;
        $order->status = 'pending';
        $order->save();

        // Save unique order id
        $unique_order_id = 'LE-' . rand(10000, 99999) . '-' . $order->id;
        $order->unique_order_id = $unique_order_id;
        $order->save();

        // 4. Save order items
        foreach (Cart::content() as $item) {
            $order_item = new OrderItem();
            $order_item->order_id = $order->id;
            $order_item->product_id = $item->id;
            $order_item->name = $item->name;
            $order_item->qty = $item->qty;
            $order_item->price = $item->price;
            $order_item->total = $item->price * $item->qty;
            $order_item->save();

            // Update product stock if track qty is set at backend
            $product = Product::find($item->id);
            if ($product->track_qty == 1) {
                $current_qty = $product->qty;
                $items_buyed = $item->qty;
                $product->qty = $current_qty - $items_buyed;
                $product->save();
            }
        }

        // If payment method is 'razorpay'
        if ($request->payment_method == 'razorpay') {
            $api = new \Razorpay\Api\Api(config('services.razorpay.key'), config('services.razorpay.secret'));
        
            $razorpay_order_data = [
                'receipt' => 'order_' . rand(1000, 9999),
                'amount' => $grand_total * 100,
                'currency' => 'INR',
                'payment_capture' => 1,
            ];
        
            $razorpay_order = $api->order->create($razorpay_order_data);
            $razorpay_order_id = $razorpay_order['id'];
            $amount = $grand_total * 100;
            $customer = Auth::user();

            return response()->json([
                'status' => true,
                'payment_method' => 'razorpay',
                'razorpay_order_id' => $razorpay_order_id,
                'uniqueOrderID' => $unique_order_id,
                'amount' => $amount, // 👈 pass amount here
            ]);
        }

        // Send order success mail
       // send_order_success_mail($unique_order_id, $order->email, 'customer');

        session()->flash('success', 'Order placed successfully.');

        Cart::destroy(); // As order is saved already, clear the cart (from session)
        session()->forget('discount_coupon');

        return response()->json([
            'status' => true,
            'msg' => 'Order placed successfully.',
            'uniqueOrderID' => $order->unique_order_id,
        ]);
    }

    public function razorpayCheckoutPage(Request $request)
{
    return view('user_end.razorpay-payment', [
        'razorpay_order_id' => $request->get('order_id'),
        'amount' => $request->get('amount'), // ✅ NOW IT WORKS
        'unique_order_id' => $request->get('unique_order_id'),
        'customer' => Auth::user(),
    ]);
}



    // For verify payment
    public function razorpay_callback(Request $request)
    {
        DB::beginTransaction();
    
        try {
            // Validate required parameters first
            $request->validate([
                'payment_id' => 'required|string',
                'order_id' => 'required|string',
                'signature' => 'required|string', // Changed from 'sign' to 'signature'
                'unique_order_id' => 'required|string'
            ]);
    
            $payment_id = $request->input('payment_id');
            $order_id = $request->input('order_id');
            $signature = $request->input('signature'); // Changed from 'sign'
            $unique_order_id = $request->input('unique_order_id');
    
            $api = new Api(config('services.razorpay.key'), config('services.razorpay.secret'));
            $order = Order::where('unique_order_id', $unique_order_id)->first();
    
            if (!$order) {
                throw new \Exception('Order not found with ID: ' . $unique_order_id);
            }
    
            // Verify payment signature
            $attributes = [
                'razorpay_payment_id' => $payment_id, // Note the correct parameter name
                'razorpay_order_id' => $order_id,
                'razorpay_signature' => $signature
            ];
    
            $api->utility->verifyPaymentSignature($attributes);
    
            // Update order status
            $order->update([
                'payment_status' => 'paid',
                'payment_id' => $payment_id,
               // 'razorpay_order_id' => $order_id,
              //  'razorpay_signature' => $signature,
                'razorpay_payment_datetime' => now(),
                'status' => 'processing'
            ]);
    
            // Clear cart and session
            Cart::destroy();
            session()->forget('discount_coupon');
    
            DB::commit();
    
            // Redirect to thank you page - use the original unique_order_id, no encoding needed
            return redirect()->route('userend_order_successful_page', ['uniqueOrderID' => $unique_order_id])
                ->with('success', 'Payment successful! Your order has been placed.');
    
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            Log::error('Missing payment parameters: ' . $e->getMessage());
            return redirect()->route('userend_checkout_page')
                ->with('error', 'Invalid payment response. Please contact support.');
    
        } catch (\Razorpay\Api\Errors\SignatureVerificationError $e) {
            DB::rollBack();
            Log::error('Razorpay signature verification failed: ' . $e->getMessage());
            return redirect()->route('userend_checkout_page')
                ->with('error', 'Payment verification failed. Please try again.');
    
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Razorpay callback error: ' . $e->getMessage());
            return redirect()->route('userend_checkout_page')
                ->with('error', 'An error occurred. Please contact support with order ID: ' . ($unique_order_id ?? 'N/A'));
        }
    }

    public function thank_you($unique_order_id)
{
    $order = DB::table('orders')->where('unique_order_id', $unique_order_id)->first();
    
    if (!$order) {
        return redirect()->route('userend_home')
               ->with('error', 'Order not found. Please check your order ID.');
    }

    $order_items = DB::table('order_items')
                   ->where('order_id', $order->id)
                   ->get();

    return view("user_end.thank_you", [
        "order" => $order,          // Changed from ty_order_data to order
        "order_items" => $order_items // Changed from ty_order_items to order_items
    ]);
}

    public function get_order_summary(Request $request)
    {
        $subtotal = Cart::subtotal(2, '.', '');
        $discount = 0;
        $discount_coupon_code = '';
        // dd($request->all());

        // Apply discount here
        if (session()->has('discount_coupon')) {
            $discount_coupon = session()->get('discount_coupon');
            if ($discount_coupon->type == 'percent') {
                $discount = $subtotal * ((double) $discount_coupon->discount_amount / 100.00);
            } else {
                $discount = (double) $discount_coupon->discount_amount;
            }
        }

        // Check if request has 'discount_coupon_code' key or not
        if (array_key_exists('discount_coupon_code', $request->all()) == true) {
            $discount_coupon_code = $request->discount_coupon_code;
        }

        if ($request->input('country_id') != '') {
            $shipping = ShippingCharge::where('country_id', $request->input('country_id'))->first();

            if (!empty($shipping)) {
                return response()->json([
                    'status' => true,
                    'shippingAmount' => number_format($shipping->amount, 2),
                    'discount' => number_format($discount, 2),
                    'discount_coupon_code' => $discount_coupon_code,
                    'grandTotal' => number_format(($subtotal + $shipping->amount) - $discount, 2),
                ]);
            } else {
                $shipping = ShippingCharge::join('countries', 'shipping_charges.country_id', '=', 'countries.id')
                    ->where('countries.name', 'Rest of the world')
                    ->first();

                return response()->json([
                    'status' => true,
                    'shippingAmount' => number_format($shipping->amount, 2),
                    'discount' => number_format($discount, 2),
                    'discount_coupon_code' => $discount_coupon_code,
                    'grandTotal' => number_format(($subtotal + $shipping->amount) - $discount, 2),
                ]);
            }
        } else {
            return response()->json([
                'status' => true,
                'shippingAmount' => number_format(0, 2),
                'discount' => number_format($discount, 2),
                'discount_coupon_code' => $discount_coupon_code,
                'grandTotal' => number_format($subtotal - $discount, 2),
            ]);
        }
    }

    public function apply_discount(Request $request)
    {
        $discount_coupon = DiscountCoupon::where('code', $request->discount_coupon_code)->first();

        if (empty($discount_coupon)) {
            return response()->json([
                'status' => false,
                'msg' => 'Invalid discount coupon.',
            ]);
        }

        $now = Carbon::now();
        // echo $now->format('Y-m-d H:i:s');

        // Check if the coupon's starting date is valid or not
        if ($discount_coupon->starts_at != '') {
            $starts_at = Carbon::createFromFormat('Y-m-d H:i:s', $discount_coupon->starts_at);
            if ($now->lt($starts_at)) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Invalid discount coupon (Invalid starting date).',
                ]);
            }
        }

        // Check if the coupon's expiring date is valid or not
        if ($discount_coupon->expires_at != '') {
            $expires_at = Carbon::createFromFormat('Y-m-d H:i:s', $discount_coupon->expires_at);
            if ($now->gt($expires_at)) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Invalid discount coupon (Coupon expired).',
                ]);
            }
        }

        // Check how many times this coupon is used
        if ($discount_coupon->max_uses != null) {
            $coupon_used = Order::where('discount_coupon_id', $discount_coupon->id)->count();
            if ($coupon_used >= $discount_coupon->max_uses) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Invalid discount coupon (Maximum limit reached).',
                ]);
            }
        }

        // Check how many times the user used the coupon
        if ($discount_coupon->max_uses_user != null) {
            $user_used_coupon = Order::where([
                'discount_coupon_id' => $discount_coupon->id,
                'user_id' => Auth::user()->id,
            ])->count();
            if ($user_used_coupon >= $discount_coupon->max_uses_user) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Sorry! You cannot use this coupon code anymore.',
                ]);
            }
        }

        // Minimum amount condition
        $subtotal = Cart::subtotal(2, '.', '');
        if ($discount_coupon->min_amount != null) {
            if ($subtotal < $discount_coupon->min_amount) {
                return response()->json([
                    'status' => false,
                    'msg' => 'Sorry! This coupon can be applied only when subtotal is above ' . $discount_coupon->min_amount . 'Rs.',
                ]);
            }
        }

        session()->put('discount_coupon', $discount_coupon);

        return $this->get_order_summary($request);
    }

    public function remove_discount()
    {
        session()->forget('discount_coupon');

        return response()->json([
            'status' => true,
            'msg' => 'Discount coupon removed successfully from cart items.',
        ]);
    }
}
