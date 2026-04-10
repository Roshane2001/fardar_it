<?php
/**
 * Four Thirteen Bulk Print (Simple Labels)
 * Prints 6 simple labels per A4 page (2 columns × 3 rows layout) - LANDSCAPE
 * Each label: Larger size with maximized font sizes
 * FIXED: Full text display for city and products (no truncation)
 * FIXED: Removed tracking status indicator
 */

// Start session management
session_start();

// Authentication check
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /fardar_it/dist/pages/login.php");
    exit();
}

// Include database connection
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

/**
 * GET FILTER PARAMETERS FROM URL
 */

// date
// time_from
// time_to
// status_filter
// date_filter
// limit
$date = isset($_GET['date']) ? trim($_GET['date']) : date('Y-m-d');
$time_from = isset($_GET['time_from']) ? trim($_GET['time_from']) : '';
$time_to = isset($_GET['time_to']) ? trim($_GET['time_to']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : 'all';

// Tracking filter parameters
$tracking_filter = isset($_GET['tracking_filter']) ? trim($_GET['tracking_filter']) : 'all';
$tracking_number = isset($_GET['tracking_number']) ? trim($_GET['tracking_number']) : '';

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
/**
 * BUILD QUERY TO FETCH ORDERS
 */
$sql = "SELECT o.order_id, o.customer_id, o.full_name, o.mobile, o.address_line1, o.address_line2, o.notes,
               o.status, o.updated_at, o.interface, o.tracking_number, o.total_amount, o.currency,
               o.delivery_fee, o.discount, o.issue_date, o.pay_status,
               c.name as customer_name, c.phone as customer_phone, 
               c.email as customer_email, c.city_id,
               cr.courier_name as delivery_service,
               
               -- City information from city_table
               ct.city_name,
               
               -- Display name with proper fallback
               COALESCE(NULLIF(o.full_name, ''), c.name, 'Unknown Customer') as display_name,
               
               -- Display mobile with proper fallback
               COALESCE(NULLIF(o.mobile, ''), c.phone, 'No phone') as display_mobile,
               
               -- Customer address lines separately for better control
               c.address_line1 as customer_address_line1,
               c.address_line2 as customer_address_line2,
               
               -- Customer address with city (for fallback)
               CONCAT_WS(', ', 
                   NULLIF(c.address_line1, ''), 
                   NULLIF(c.address_line2, ''), 
                   ct.city_name
               ) as customer_address
               
        FROM order_header o 
        LEFT JOIN customers c ON o.customer_id = c.customer_id
        LEFT JOIN couriers cr ON o.courier_id = cr.courier_id
        LEFT JOIN city_table ct ON o.city_id = ct.city_id AND ct.is_active = 1
        WHERE o.interface IN ('individual', 'leads') AND o.status = 'dispatch'";

// Role-based access: Admin (role_id 1) sees all, others see only their own orders
if (isset($_SESSION['role_id']) && $_SESSION['role_id'] != 1) {
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $sql .= " AND o.user_id = $current_user_id";
}

// Build search conditions

// Default date
if ($date === "" || !isset($_GET['date'])) {
    $date = date("Y-m-d");
}

// Normalize time inputs (HH:MM format)
$time_from = preg_match('/^\d{1,2}:\d{2}$/', $time_from) ? $time_from : "";
$time_to   = preg_match('/^\d{1,2}:\d{2}$/', $time_to) ? $time_to : "";

// Default start and end
$startDateTime = $date . " 00:00:00";
$endDateTime   = $date . " 23:59:59";

// Apply time range filter
if ($time_from !== "" && $time_to !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime   = $date . " $time_to:59";
} elseif ($time_from !== "") {
    $startDateTime = $date . " $time_from:00";
    $endDateTime   = $date . " 23:59:59";
} elseif ($time_to !== "") {
    $startDateTime = $date . " 00:00:00";
    $endDateTime   = $date . " $time_to:59";
}

// Tracking filter conditions
if (!empty($tracking_filter) && $tracking_filter !== 'all') {
    switch ($tracking_filter) {
        case 'with_tracking':
            $sql .= " AND o.tracking_number IS NOT NULL AND o.tracking_number != '' AND TRIM(o.tracking_number) != ''";
            break;
        case 'without_tracking':
            $sql .= " AND (o.tracking_number IS NULL OR o.tracking_number = '' OR TRIM(o.tracking_number) = '')";
            break;
        case 'specific_tracking':
            if (!empty($tracking_number)) {
                $trackingTerm = $conn->real_escape_string($tracking_number);
                $sql .= " AND o.tracking_number LIKE '%$trackingTerm%'";
            }
            break;
    }
}

// Apply filter on selected date field
$sql .= " AND o.updated_at BETWEEN '$startDateTime' AND '$endDateTime' ORDER BY o.order_id ASC LIMIT $limit";

// Execute query
$result = $conn->query($sql);
if (!$result) {
    die("Query failed: " . $conn->error);
}

// Fetch orders
$orders = [];
while ($order = $result->fetch_assoc()) {
    $orders[] = $order;
}

// Function to get products for an order
function getOrderProducts($conn, $order_id) {
    $sql = "SELECT oi.product_id, oi.quantity, p.name as product_name, p.id as product_id
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $products = [];
    while ($row = $result->fetch_assoc()) {
        $products[] = $row;
    }
    
    return $products;
}

// Fetch branding info (active branding)
$branding_sql = "SELECT * FROM branding WHERE active = 1 ORDER BY branding_id DESC LIMIT 1";
$branding_result = $conn->query($branding_sql);

if ($branding_result && $branding_result->num_rows > 0) {
    $branding = $branding_result->fetch_assoc();
    $company = [
        'name'    => $branding['company_name'] ?? 'Company Name',
        'address' => $branding['address'] ?? 'Address not set',
        'email'   => $branding['email'] ?? '',
        'phone'   => $branding['hotline'] ?? ''
    ];
} else {
    $company = [
        'name'    => '',
        'address' => '',
        'email'   => '',
        'phone'   => ''
    ];
}

/**
 * HELPER FUNCTIONS
 */
function getCurrencySymbol($currency) {
    return (strtolower($currency) == 'usd') ? '$' : 'Rs.';
}

function getBarcodeUrl($data) {
    return "../include/barcode.php?code=" . $data;
}

function hasTracking($tracking_number) {
    return !empty($tracking_number) && trim($tracking_number) !== '';
}

function getTrackingFilterText($tracking_filter, $tracking_number = '') {
    switch ($tracking_filter) {
        case 'with_tracking':
            return 'Orders WITH tracking numbers';
        case 'without_tracking':
            return 'Orders WITHOUT tracking numbers';
        case 'specific_tracking':
            return !empty($tracking_number) ? "Tracking contains: '{$tracking_number}'" : 'Specific tracking (no number provided)';
        default:
            return 'All orders (no tracking filter)';
    }
}

// Count orders by tracking status
$tracking_stats = [
    'with_tracking' => 0,
    'without_tracking' => 0,
    'total' => count($orders)
];

foreach ($orders as $order) {
    if (hasTracking($order['tracking_number'])) {
        $tracking_stats['with_tracking']++;
    } else {
        $tracking_stats['without_tracking']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Bulk Print Labels (<?php echo count($orders); ?> orders) - A4 Landscape 6 Labels</title>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }

        /* Print Instructions (hidden when printing) */
        .print-instructions {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border: 1px solid #dee2e6;
        }

        .print-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            margin: 5px;
            border-radius: 4px;
            cursor: pointer;
        }

        .print-button:hover {
            background: #0056b3;
        }

        /* Main container for labels */
        .labels-container {
            width: 297mm;
            margin: 0 auto;
        }

        /* Page wrapper - A4 landscape for 6 labels (2 columns × 3 rows) */
        .page-wrapper {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: repeat(3, 1fr);
            gap: 5mm;
            width: 297mm;
            height: 210mm;
            padding: 8mm;
        }

        /* Individual label styling - LARGER SIZE */
        .simple-label {
            border: 2px dashed #333;
            padding: 3mm;
            display: flex;
            flex-direction: row;
            justify-content: space-between;
            align-items: stretch;
            width: 138mm;
            height: 62mm;
            background: white;
            position: relative;
        }

        /* Left section - From and To info */
        .left-section {
            display: flex;
            flex-direction: column;
            flex: 1;
            /* margin-right: 2mm; */
            justify-content: space-between;
        }

        /* From section - LARGER FONT */
        .from-section {
            border-bottom: 1px solid #ccc;
            /* padding-bottom: 2mm; */
            margin-bottom: 2mm;
        }

        .from-label {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 1mm;
            color: #333;
        }

        .from-details {
            font-size: 10px;
            line-height: 1.3;
        }

        .from-company {
            display: inline;
        }

        /* To section - LARGER FONT */
        .to-section {
            flex-grow: 1;
        }

        .to-label {
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 0.5mm;
            color: #333;
        }

        .to-details {
            font-size: 13px;
            line-height: 1.2;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        .to-name,
        .to-phone,
        .to-address,
        .to-city {
            display: block;
        }

        /* City name - full display with wrapping - LARGER FONT */
        .city-name {
            display: block;
            word-wrap: break-word;
            overflow-wrap: break-word;
            line-height: 1.3;
            font-weight: 600;
        }

        /* Products section styling - LARGER FONT */
        .products-section {
            margin-top: 1mm;
            border-top: 1px dotted #ccc;
            padding-top: 1mm;
        }

        .products-label {
            font-weight: bold;
            font-size: 10px;
            color: #666;
            margin-bottom: 1mm;
        }

        .product-item {
            font-size: 11px;
            color: #333;
            line-height: 1.4;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }

        /* Notes section styling */
        .notes-section {
            margin-top: 1mm;
            border-top: 1px dotted #ccc;
            padding-top: 1mm;
            font-size: 10px;
            color: #000000ff;
        }

        /* Right section - Order info, Barcode and Total */
        .right-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: space-between;
            width: 50mm;
            text-align: center;
            border-left: 1px solid #ccc;
            padding-left: 3mm;
        }

        /* Order info section at the top right */
        .order-info-section {
            text-align: center;
            width: 100%;
        }

        .order-id {
            font-weight: bold;
            font-size: 15px;
            color: #000;
            margin-bottom: 1mm;
        }

        .order-date {
            font-size: 14px;
            color: #666;
        }

        .barcode-section {
            text-align: center;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .barcode-image {
            height: 30mm;
            max-width: 50mm;
            object-fit: contain;
        }

        .barcode-text {
            font-size: 8px;
            margin-top: 1mm;
            font-weight: bold;
        }

        .no-tracking-barcode {
            font-size: 9px;
            color: #dc3545;
            margin-bottom: 2mm;
        }

        .total-section {
            text-align: center;
        }

        .total-label {
            font-size: 12px;
            color: #666;
        }

        .total-amount {
            font-weight: bold;
            font-size: 16px;
            margin-top: 0mm;
            color: #000;
        }

        /* Page break */
        .page-break {
            page-break-before: always;
        }

        /* Print styles */
        @media print {
            .print-instructions {
                display: none !important;
            }
            
            body {
                margin: 0;
                padding: 0;
            }
            
            .labels-container {
                margin: 0;
            }
            
            .page-wrapper {
                margin: 0;
                padding: 8mm;
                width: 297mm !important;
                height: 210mm !important;
            }
            
            .simple-label {
                width: 138mm !important;
                height: 62mm !important;
            }

            @page {
                size: A4 landscape;
                margin: 0;
            }
        }

        .no-orders {
            text-align: center;
            padding: 50px;
            color: #666;
        }
    </style>
</head>
<body>
    <!-- Labels Container -->
    <div class="labels-container">
        <?php if (empty($orders)): ?>
            <div class="no-orders">
                <h3>No Orders Found</h3>
                <p>No orders match the selected filters.</p>
                <?php if ($tracking_filter !== 'all'): ?>
                    <p><em>Try adjusting your tracking filter: <?php echo htmlspecialchars(getTrackingFilterText($tracking_filter, $tracking_number)); ?></em></p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php 
            $labels_per_page = 6;
            $total_orders = count($orders);
            $current_page_labels = 0;
            ?>
            
            <?php foreach ($orders as $index => $order): ?>
                <?php
                if ($current_page_labels == 0): ?>
                    <div class="page-wrapper">
                <?php endif; ?>
                
                <?php
                $order_id = $order['order_id'];
                $currency_symbol = getCurrencySymbol($order['currency'] ?? 'lkr');
                
                $tracking_number_val = !empty($order['tracking_number']) ? trim($order['tracking_number']) : '';
                $has_tracking = hasTracking($tracking_number_val);
                
                if ($has_tracking) {
                    $barcode_data = $tracking_number_val;
                    $barcode_url = getBarcodeUrl($barcode_data);
                } else {
                    $barcode_data = str_pad($order_id, 10, '0', STR_PAD_LEFT);
                    $barcode_url = getBarcodeUrl($barcode_data);
                }
                
                $total_amount = floatval($order['total_amount']);
                
                $order_date = '';
                if (!empty($order['issue_date'])) {
                    $order_date = date('d/m/Y', strtotime($order['issue_date']));
                } elseif (!empty($order['updated_at'])) {
                    $order_date = date('d/m/Y', strtotime($order['updated_at']));
                } else {
                    $order_date = date('d/m/Y');
                }

                $products = getOrderProducts($conn, $order_id);
                ?>
                
                <div class="simple-label">
                    <!-- Left Section: From and To -->
                    <div class="left-section">
                        <!-- From Section -->
                        <div class="from-section">
                            <div class="from-details">
                                <strong>From: <?php echo htmlspecialchars($company['name']); ?></strong>, <?php echo htmlspecialchars($company['address']); ?><br>
                                <span style="padding-left: 40px; display: inline-block;"><?php echo htmlspecialchars($company['phone']); ?></span>
                            </div>
                        </div>

                        <!-- To Section -->
                        <div class="to-section">
                            <div class="to-label">To:</div>
                            <div class="to-details">
                                <span class="to-name"><?php echo htmlspecialchars($order['display_name']); ?></span>
                                <span class="to-phone"><?php echo htmlspecialchars($order['display_mobile']); ?></span>
                                <?php 
                                // Build address with only address lines (no city)
                                $address_parts = [];
                                
                                if (!empty($order['address_line1'])) {
                                    $address_parts[] = trim($order['address_line1']);
                                }
                                if (!empty($order['address_line2'])) {
                                    $address_parts[] = trim($order['address_line2']);
                                }
                                
                                if (empty($address_parts)) {
                                    if (!empty($order['customer_address_line1'])) {
                                        $address_parts[] = trim($order['customer_address_line1']);
                                    }
                                    if (!empty($order['customer_address_line2'])) {
                                        $address_parts[] = trim($order['customer_address_line2']);
                                    }
                                }
                                
                                // Display each address line separately
                                if (!empty($address_parts)) {
                                    foreach ($address_parts as $address_line) {
                                        echo '<span class="to-address">' . htmlspecialchars($address_line) . '</span>';
                                    }
                                } else {
                                    echo '<span class="to-address">Address not available</span>';
                                }
                                ?>
                                <span class="to-city"><?php 
                                    $city_display = !empty($order['city_name']) ? $order['city_name'] : 'City not specified';
                                    echo htmlspecialchars($city_display);
                                ?></span>
                            </div>

                            <!-- Products Section -->
                            <?php if (!empty($products)): ?>
                            <div class="products-section">
                                <?php 
                                $grouped_products = [];
                                foreach ($products as $product) {
                                    $product_id = $product['product_id'];
                                    if (isset($grouped_products[$product_id])) {
                                        $grouped_products[$product_id]['quantity'] += $product['quantity'];
                                    } else {
                                        $grouped_products[$product_id] = [
                                            'product_id' => $product_id,
                                            'product_name' => $product['product_name'],
                                            'quantity' => $product['quantity']
                                        ];
                                    }
                                }
                                
                                $total_unique_products = count($grouped_products);
                                ?>
                                <div class="products-label">Products (<?php echo $total_unique_products; ?>):</div>
                                <div class="product-item">
                                    <?php 
                                    $product_list = [];
                                    foreach ($grouped_products as $product) {
                                        $product_name = htmlspecialchars($product['product_name']);
                                        $product_list[] = $product['product_id'] . "-" . $product_name . "(" . $product['quantity'] . ")";
                                    }
                                    echo implode(', ', $product_list);
                                    ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- Additional Note Section -->
                            <?php if (!empty($order['notes'])): ?>
                            <div class="notes-section">
                                <strong>Note:</strong> <?php echo htmlspecialchars($order['notes']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right Section: Order Info, Barcode and Total -->
                    <div class="right-section">
                        <div class="order-info-section">
                            <div class="order-id">Order #<?php echo $order_id; ?></div>
                            <div class="order-date"><?php echo $order_date; ?></div>
                        </div>

                        <div class="barcode-section">
                            <?php if ($has_tracking): ?>
                                <img src="<?php echo $barcode_url; ?>" alt="Tracking Barcode" class="barcode-image" onerror="this.style.display='none'">
                                <div style="font-size: 16px; margin-top: 2px; color: #000; font-weight: bold; font-family: sans-serif; text-align:center;">
                                    <?php echo htmlspecialchars($order['tracking_number']); ?>
                                </div>
                            <?php else: ?>
                                <div class="no-tracking-barcode">
                                    NO TRACKING<br>
                                    Order: <?php echo $order_id; ?>
                                </div>
                                <img src="<?php echo $barcode_url; ?>" alt="Order Barcode" class="barcode-image" onerror="this.style.display='none'">
                                <div class="barcode-text"><?php echo $barcode_data; ?></div>
                            <?php endif; ?>
                        </div>
                        
                      <div class="total-section">
                        <?php if ($order['pay_status'] !== 'paid'): ?>
                            <div class="total-label">Total:</div>
                            <div class="total-amount"><?php echo $currency_symbol . ' ' . number_format($total_amount, 2); ?></div>
                        <?php else: ?>
                            <div style="color: green; font-weight: bold; font-size: 14px; margin-top: 2mm;">
                                ✔ PAID
                            </div>
                        <?php endif; ?>
                    </div>
                    </div>
                </div>

                <?php 
                $current_page_labels++;
                
                if ($current_page_labels == $labels_per_page || $index == $total_orders - 1): 
                    $current_page_labels = 0; ?>
                    </div>
                    
                    <?php if ($index < $total_orders - 1): ?>
                        <div class="page-break"></div>
                    <?php endif; ?>
                <?php endif; ?>
                
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 1000);
        });

        window.addEventListener('afterprint', function() {
            console.log('Print completed');
        });

        console.log('Simple bulk print loaded: <?php echo count($orders); ?> orders');
        console.log('Orders with tracking: <?php echo $tracking_stats['with_tracking']; ?>');
        console.log('Orders without tracking: <?php echo $tracking_stats['without_tracking']; ?>');
    </script>
</body>
</html>

<?php
$conn->close();
?>