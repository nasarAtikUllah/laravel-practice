
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order {{ $order->number }} | RFL Store</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f8fafc;
            color: #1e293b;
        }

        header {
            background: #fff;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0, 0, 0, .05);
        }

        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #15803d;
        }

        header a {
            color: #15803d;
            text-decoration: none;
            font-weight: 600;
        }

        .container {
            max-width: 1100px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 28px;
        }

        .card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 22px;
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }

        .muted {
            color: #64748b;
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 7px 13px;
            border-radius: 20px;
            background: #dcfce7;
            color: #166534;
            font-size: 13px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status.pending,
        .status.pending_payment,
        .status.pending_confirmation {
            background: #fef3c7;
            color: #92400e;
        }

        .status.cancelled,
        .status.failed,
        .status.expired {
            background: #fee2e2;
            color: #991b1b;
        }

        .status.refunded {
            background: #ede9fe;
            color: #5b21b6;
        }

        .section-title {
            font-size: 18px;
            margin-bottom: 18px;
        }

        .order-items {
            width: 100%;
            border-collapse: collapse;
        }

        .order-items th,
        .order-items td {
            padding: 15px 10px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            font-size: 14px;
        }

        .order-items th {
            color: #64748b;
            font-weight: 600;
        }

        .order-items td:last-child,
        .order-items th:last-child {
            text-align: right;
        }

        .summary {
            max-width: 400px;
            margin-left: auto;
            margin-top: 20px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 9px 0;
            font-size: 14px;
        }

        .summary-row.total {
            border-top: 1px solid #e2e8f0;
            margin-top: 10px;
            padding-top: 16px;
            font-size: 19px;
            font-weight: bold;
            color: #15803d;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 25px;
        }

        .info-item {
            margin-bottom: 12px;
        }

        .info-item strong {
            display: block;
            font-size: 13px;
            color: #64748b;
            margin-bottom: 5px;
        }

        .info-item span {
            font-size: 15px;
        }

        footer {
            margin-top: 60px;
            padding: 30px;
            text-align: center;
            background: #14532d;
            color: white;
        }

        @media(max-width: 650px) {
            .container {
                margin-top: 25px;
            }

            .card {
                padding: 17px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .order-items th,
            .order-items td {
                padding: 12px 6px;
                font-size: 12px;
            }

            .page-heading h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

    <header>
        <div class="logo">RFL STORE</div>
        <a href="{{ route('shop') }}">Continue Shopping</a>
    </header>

    <main class="container">

        {{-- Order Heading --}}
        <div class="page-heading">
            <div>
                <h1>Order Details</h1>

                <p class="muted" style="margin-top: 8px;">
                    Order #{{ $order->number }}
                </p>
            </div>

            <span class="status {{ strtolower(str_replace(' ', '_', $order->status->value ?? $order->status)) }}">
                {{ str_replace('_', ' ', $order->status->value ?? $order->status) }}
            </span>
        </div>

        {{-- Order Information --}}
        <section class="card">

            <div class="order-header">

                <div>
                    <p class="muted">Order Number</p>
                    <h3 style="margin-top: 6px;">
                        {{ $order->number }}
                    </h3>
                </div>

                <div>
                    <p class="muted">Order Date</p>
                    <h3 style="margin-top: 6px;">
                        {{ $order->created_at->format('d M Y, h:i A') }}
                    </h3>
                </div>

                <div>
                    <p class="muted">Payment Method</p>
                    <h3 style="margin-top: 6px;">
                        {{ $order->payment_method->value ?? $order->payment_method ?? 'N/A' }}
                    </h3>
                </div>

                <div>
                    <p class="muted">Payment Status</p>
                    <h3 style="margin-top: 6px;">
                        {{ str_replace('_', ' ', $order->payment_status->value ?? $order->payment_status ?? 'N/A') }}
                    </h3>
                </div>

            </div>

        </section>

        {{-- Ordered Products --}}
        <section class="card">

            <h2 class="section-title">Ordered Products</h2>

            <div style="overflow-x:auto;">

                <table class="order-items">

                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>SKU</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>

                        @forelse($order->items as $item)

                            <tr>
                                <td>
                                    <strong>{{ $item->product_name }}</strong>

                                    @if($item->variant_title)
                                        <br>
                                        <small class="muted">
                                            {{ $item->variant_title }}
                                        </small>
                                    @endif
                                </td>

                                <td>{{ $item->sku ?? '-' }}</td>

                                <td>{{ $item->quantity }}</td>

                                <td>
                                    ৳{{ number_format((float) $item->unit_price, 2) }}
                                </td>

                                <td>
                                    ৳{{ number_format((float) $item->line_total, 2) }}
                                </td>
                            </tr>

                        @empty

                            <tr>
                                <td colspan="5" style="text-align:center;">
                                    No order items found.
                                </td>
                            </tr>

                        @endforelse

                    </tbody>

                </table>

            </div>

            {{-- Price Summary --}}
            <div class="summary">

                <div class="summary-row">
                    <span>Subtotal</span>
                    <strong>
                        ৳{{ number_format((float) $order->items_total, 2) }}
                    </strong>
                </div>

                <div class="summary-row">
                    <span>Delivery Fee</span>
                    <strong>
                        ৳{{ number_format((float) $order->delivery_fee, 2) }}
                    </strong>
                </div>

                @if((float) ($order->discount_total ?? 0) > 0)
                    <div class="summary-row">
                        <span>Discount</span>
                        <strong>
                            - ৳{{ number_format((float) $order->discount_total, 2) }}
                        </strong>
                    </div>
                @endif

                <div class="summary-row total">
                    <span>Grand Total</span>
                    <strong>
                        ৳{{ number_format((float) $order->grand_total, 2) }}
                    </strong>
                </div>

            </div>

        </section>

        {{-- Shipping Information --}}
        <section class="card">

            <h2 class="section-title">Shipping Information</h2>

            <div class="info-grid">

                <div class="info-item">
                    <strong>District</strong>
                    <span>{{ $order->shipping_district ?? 'N/A' }}</span>
                </div>

                <div class="info-item">
                    <strong>Shipping Address</strong>
                    <span>{{ $order->shipping_address ?? 'N/A' }}</span>
                </div>

                <div class="info-item">
                    <strong>Shipping Name</strong>
                    <span>{{ $order->shipping_name ?? 'N/A' }}</span>
                </div>

                <div class="info-item">
                    <strong>Phone</strong>
                    <span>{{ $order->shipping_phone ?? 'N/A' }}</span>
                </div>

            </div>

        </section>

    </main>

    <footer>
        <p>© {{ date('Y') }} RFL Store. All rights reserved.</p>
    </footer>

</body>
</html>
