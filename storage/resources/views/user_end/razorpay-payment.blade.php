<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Processing Payment - Laravel ECOM</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="stylesheet" href="{{ asset('user-assets/css/bootstrap.min.css') }}">
</head>
<body>
    <div class="p-3 text-center">
        <span class="spinner-border spinner-border-sm" role="status"></span>
        <span>Please wait, redirecting to Razorpay...</span>
    </div>

    <script src="{{ asset('user-assets/js/bootstrap.bundle.5.1.3.min.js') }}"></script>
    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const base64Encode = (str) => btoa(encodeURIComponent(str));
            const uniqueOrderId = "{{ $unique_order_id }}";

            const rzp1 = new Razorpay({
                key: "{{ config('services.razorpay.key') }}",
                amount: {{ $amount }},
                currency: "INR",
                name: "Laravel ECOM",
                description: "Test Transaction",
                image: "{{ asset('user-assets/images/site_name.png') }}",
                order_id: "{{ $razorpay_order_id }}",
                prefill: {
                    name: "{{ $customer->name ?? 'Guest' }}",
                    email: "{{ $customer->email ?? '' }}",
                    contact: "{{ $customer->phone ?? '' }}"
                },
                notes: {
                    address: "Laravel ECOM Team"
                },
                theme: {
                    color: "#3399cc"
                },
                // handler: function (response) {
                //     window.location.href = "{{ route('userend_razorpay_callback') }}?payment_id=" +
                //         base64Encode(response.razorpay_payment_id) +
                //         "&order_id=" + base64Encode(response.razorpay_order_id) +
                //         "&sign=" + base64Encode(response.razorpay_signature) +
                //         "&unique_order_id=" + base64Encode(uniqueOrderId);
                // }

                handler: function(response) {
                    window.location.href = "{{ route('userend_razorpay_callback') }}?" + 
                        "payment_id=" + response.razorpay_payment_id + 
                        "&order_id=" + response.razorpay_order_id + 
                        "&signature=" + response.razorpay_signature + 
                        "&unique_order_id=" + uniqueOrderId;
                }
            });

            setTimeout(() => {
               
            }, 3000);

            try {
                rzp1.open();
            } catch (e) {
                alert("Unable to open Razorpay checkout. Please try again.");
                console.error(e);
            }
        });
    </script>

    <div class="text-center mt-3">
        <a href="#" onclick="rzp1.open(); return false;">Click here if payment popup didn’t open</a>
    </div>
</body>
</html>
