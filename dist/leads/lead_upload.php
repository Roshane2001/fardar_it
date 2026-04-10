<?php
// File: templates/lead_upload.php
// Start output buffering to prevent header issues
ob_start();

// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    ob_end_clean();
    header("Location: /fardar_it/dist/pages/login.php");
    exit();
}

/**
 * Handle Failed Rows CSV Download
 */
if (isset($_GET['download_errors']) && isset($_SESSION['failed_rows_data'])) {
    $failedRows = $_SESSION['failed_rows_data'];
    
    // Set headers for download
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="failed_leads_import_' . date('Y-m-d_H-i-s') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    $output = fopen('php://output', 'w');
    
    // Add UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");
    
    // Write header (original columns + error reason)
    if (!empty($failedRows)) {
        fputcsv($output, array_keys($failedRows[0]));
        
        foreach ($failedRows as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit();
}

// Include the database connection file early
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

// Initialize transaction flag
$transactionStarted = false;

// Function to log user actions
function logUserAction($conn, $user_id, $action_type, $inquiry_id, $details) {
    $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details) VALUES (?, ?, ?, ?)";
    $logStmt = $conn->prepare($logSql);
    if ($logStmt) {
        $logStmt->bind_param("isis", $user_id, $action_type, $inquiry_id, $details);
        $logStmt->execute();
        $logStmt->close();
    }
}

// Process CSV upload if form is submitted
if ($_POST && isset($_FILES['csv_file']) && isset($_POST['users'])) {
    // Clear previous failed rows
    unset($_SESSION['failed_rows_data']);
    
    try {
        // Validate file upload
        if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload failed with error code: " . $_FILES['csv_file']['error']);
        }
        
        // Validate file type
        $fileInfo = pathinfo($_FILES['csv_file']['name']);
        if (strtolower($fileInfo['extension']) !== 'csv') {
            throw new Exception("Only CSV files are allowed.");
        }
        
        // Validate file size (10MB limit)
        if ($_FILES['csv_file']['size'] > 10 * 1024 * 1024) {
            throw new Exception("File size must be less than 10MB.");
        }
        
        // Get selected users
        $selectedUsers = $_POST['users'];
        if (empty($selectedUsers)) {
            throw new Exception("Please select at least one user.");
        }
        
        // Validate Product Selection
        if (empty($_POST['product_id'])) {
            throw new Exception("Please select a product related to this upload.");
        }
        $selectedProductCode = $_POST['product_id'];
        
        // Get the logged-in user ID who is performing the import
        $loggedInUserId = $_SESSION['user_id'];
        
        if (!$loggedInUserId) {
            throw new Exception("Unable to determine logged-in user.");
        }
        
        // Validate selected users exist and are active
        $userPlaceholders = str_repeat('?,', count($selectedUsers) - 1) . '?';
        $userValidationSql = "SELECT id FROM users WHERE id IN ($userPlaceholders) AND status = 'active'";
        $userValidationStmt = $conn->prepare($userValidationSql);
        if (!$userValidationStmt) {
            throw new Exception("Failed to prepare user validation query: " . $conn->error);
        }
        $userValidationStmt->bind_param(str_repeat('i', count($selectedUsers)), ...$selectedUsers);
        $userValidationStmt->execute();
        $validUsersResult = $userValidationStmt->get_result();
        
        if ($validUsersResult->num_rows !== count($selectedUsers)) {
            throw new Exception("One or more selected users are invalid or inactive.");
        }
        $userValidationStmt->close();
        
        // Process CSV file
        $csvFile = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($csvFile, 'r');
        
        if (!$handle) {
            throw new Exception("Could not open CSV file.");
        }
        
        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        
        // Read header row
        $headers = fgetcsv($handle);
        if (!$headers) {
            throw new Exception("CSV file is empty or invalid.");
        }
        
        // Normalize headers (trim whitespace and convert to lowercase)
        $normalizedHeaders = array_map(function($h) {
            return strtolower(trim($h));
        }, $headers);
        
        // Expected headers (lowercase)
        $expectedHeaders = [
            'full name', 
            'phone number', 
            'phone number 2', 
            'city', 
            'email', 
            'address line 1', 
            'address line 2', 
            'quantity',
            'other'
        ];
        
        // Create mapping of header name to column index
        $headerMap = array_flip($normalizedHeaders);
        
        // Check if all required headers exist
        $missingHeaders = [];
        foreach ($expectedHeaders as $expected) {
            if (!isset($headerMap[$expected])) {
                $missingHeaders[] = ucwords($expected);
            }
        }
        
        if (!empty($missingHeaders)) {
            throw new Exception(
                "Missing required CSV headers: " . implode(', ', $missingHeaders) . "\n\n" .
                "Found headers: " . implode(', ', $headers) . "\n\n" .
                "Please download a fresh template and ensure all headers are present."
            );
        }
        
        // Initialize counters
        $successCount = 0;
        $errorCount = 0;
        $errorMessages = [];
        $failedRowsData = [];
        $rowNumber = 1;
        $successfulOrderIds = [];
        
        // Begin transaction
        $conn->begin_transaction();
        $transactionStarted = true;

        // Initialize round-robin counter
        $userIndex = 0;
        
// Function to calculate customer success rate
function cs_condition($conn, $customer_id) {
    if (!$customer_id) return 4; // Default to New if no ID

    // Total orders
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM order_header WHERE customer_id = ?");
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $totalOrders = $stmt->get_result()->fetch_assoc()['total'] ?? 0;
    $stmt->close();

    if ($totalOrders == 0) return 4; // New

    // Failed orders (return + cancel)
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS failed
         FROM order_header
         WHERE customer_id = ?
         AND status IN ('cancel', 'return', 'return complete', 'return_handover', 'return pending', 'return transfer','removed')"
    );
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $failedOrders = $stmt->get_result()->fetch_assoc()['failed'] ?? 0;
    $stmt->close();

    // If no failed orders → Excellent
    if ($failedOrders == 0) return 0;

    $rate = ($failedOrders / $totalOrders) * 100;
    
    if (($rate > 0) && ($rate <= 25)) return 0; // Excellent
    if (($rate > 25) && ($rate <= 50)) return 1;  // Good
    if (($rate > 50) && ($rate <= 75)) return 2;  // Average
    if (($rate > 75)) return 3;                  // Bad
}

// Process each row
        while (($row = fgetcsv($handle)) !== FALSE) {
            $rowNumber++;

            try {
                // Skip empty rows
                if (empty(array_filter($row))) {
                    continue;
                }
                
                // Map CSV columns using header positions (with fallback to empty string)
                $fullName = trim($row[$headerMap['full name']] ?? '');
                $phoneNumber = trim($row[$headerMap['phone number']] ?? '');
                $phoneNumber2 = trim($row[$headerMap['phone number 2']] ?? '');
                $city = trim($row[$headerMap['city']] ?? '');
                $email = trim($row[$headerMap['email']] ?? '');
                $addressLine1 = trim($row[$headerMap['address line 1']] ?? '');
                $addressLine2 = trim($row[$headerMap['address line 2']] ?? '');
                
                $quantityInput = isset($headerMap['quantity']) ? trim($row[$headerMap['quantity']] ?? '') : '';
                
                // Allow empty or 0, default to 1
                if ($quantityInput === '' || $quantityInput === '0') {
                    $quantity = 1;
                } else {
                    if (!is_numeric($quantityInput) || (int)$quantityInput < 0) {
                        throw new Exception("Quantity must be a positive number (got: '$quantityInput')");
                    }
                    $quantity = (int)$quantityInput;
                }
                
                $other = trim($row[$headerMap['other']] ?? '');

                // ===============================
                // FIX: Preserve leading 0 in phone numbers
                // ===============================
               
                // Convert +94XXXXXXXXX → 0XXXXXXXXX
                if (strlen($phoneNumber) === 12 && substr($phoneNumber, 0, 3) === '+94') {
                    $phoneNumber = '0' . substr($phoneNumber, 3);
                } elseif (strlen($phoneNumber) === 11 && substr($phoneNumber, 0, 2) === '94') {
                    $phoneNumber = '0' . substr($phoneNumber, 2);
                }

                if (!empty($phoneNumber2)) {
                    if (strlen($phoneNumber2) === 12 && substr($phoneNumber2, 0, 3) === '+94') {
                        $phoneNumber2 = '0' . substr($phoneNumber2, 3);
                    } elseif (strlen($phoneNumber2) === 11 && substr($phoneNumber2, 0, 2) === '94') {
                        $phoneNumber2 = '0' . substr($phoneNumber2, 2);
                    }
                }

                // Excel removed leading 0 → add it back
                if (strlen($phoneNumber) === 9 && ctype_digit($phoneNumber)) {
                    $phoneNumber = '0' . $phoneNumber;
                }

                if (!empty($phoneNumber2) && strlen($phoneNumber2) === 9 && ctype_digit($phoneNumber2)) {
                    $phoneNumber2 = '0' . $phoneNumber2;
                }

                // Normalize email for DB
                $emailForDb = null;
                if (!empty($email) && !in_array(strtolower($email), ['-', 'null', 'n/a', 'na', '', ' -'])) {
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format: '$email'");
                    }
                    $emailForDb = $email;
                }
                
                // Handle phone number 2 - normalize empty values
                if (empty($phoneNumber2) || $phoneNumber2 === 'NULL' || $phoneNumber2 === 'null' || $phoneNumber2 === 'N/A' || $phoneNumber2 === 'n/a' || $phoneNumber2 === '-') {
                    $phoneNumber2 = '';
                }
                
                // Validate required fields
                if (empty($fullName)) {
                    throw new Exception("Full Name is required");
                }
                if (empty($phoneNumber)) {
                    throw new Exception("Phone Number is required");
                }
                
                // MUST be exactly 10 digits and start with 0
                if (!preg_match('/^0\d{9}$/', $phoneNumber)) {
                    throw new Exception("Phone Number must be exactly 10 digits and start with 0 (got: '$phoneNumber')");
                }

                if (!empty($phoneNumber2) && !preg_match('/^0\d{9}$/', $phoneNumber2)) {
                    throw new Exception("Phone Number 2 must be exactly 10 digits and start with 0 (got: '$phoneNumber2')");
                }
                

             // Get city_id from city name 
$cityError = null;  
if (empty($city)) {
    $cityError = "City is missing.";
    $cityId = null;
} else {
    $citySql = "SELECT city_id FROM city_table WHERE LOWER(city_name) = LOWER(?) LIMIT 1";
    $cityStmt = $conn->prepare($citySql);
    if (!$cityStmt) {
        throw new Exception("Failed to prepare city query: " . $conn->error);
    }
    $cityStmt->bind_param("s", $city);
    $cityStmt->execute();
    $cityResult = $cityStmt->get_result();

    if ($cityResult->num_rows === 0) {
        // City not found - store error but continue processing
        $cityError = "City '$city' not found.";
        $cityId = null; // Will be NULL in database
    } else {
        $cityData = $cityResult->fetch_assoc();
        $cityId = $cityData['city_id'];
    }
    $cityStmt->close();
}

// Validate address line 1 
$addressError = null;
if (empty($addressLine1)) {
    $addressError = "Address Line 1 is missing.";
}

// Combine errors for upload_error
$upload_error = null;
if ($cityError || $addressError) {
    $errors = array_filter([$cityError, $addressError]);
    $upload_error = implode(" | ", $errors);
}
                
                
                // Get selected product details
                $selectedProductCode = $_POST['product_id'];
                
                $productSql = "SELECT id, lkr_price, product_code FROM products WHERE id = ? AND LOWER(status) = 'active'";
                $productStmt = $conn->prepare($productSql);
                if (!$productStmt) {
                    throw new Exception("Failed to prepare product query: " . $conn->error);
                }

                $productStmt->bind_param("i", $selectedProductCode);
                $productStmt->execute();
                $productResult = $productStmt->get_result();

                if ($productResult->num_rows === 0) {
                    throw new Exception("Product code not found or inactive");
                }

                $product = $productResult->fetch_assoc();
                $productId = $product['id'];
                $unitPrice = (float)$product['lkr_price'];
                $productCode = $product['product_code'];
                $subtotal = $unitPrice;
                $productStmt->close();

                // Check and deduct stock
                if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1) {
                    $updateStockSql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?";
                    $stockUpdateStmt = $conn->prepare($updateStockSql);
                    if (!$stockUpdateStmt) {
                        throw new Exception("Failed to prepare stock update query: " . $conn->error);
                    }
                    $stockUpdateStmt->bind_param("iii", $quantity, $productId, $quantity);
                    
                    if (!$stockUpdateStmt->execute()) {
                        throw new Exception("Failed to update stock for product code: " . $productCode);
                    }
                    
                    if ($stockUpdateStmt->affected_rows === 0) {
                        // Fetch product name
                        $getNameSql = "SELECT name FROM products WHERE id = ?";
                        $nameStmt = $conn->prepare($getNameSql);
                        $nameStmt->bind_param("i", $productId);
                        $nameStmt->execute();
                        $productResult = $nameStmt->get_result();
                        $productName = $productCode; // Fallback
                        if ($productResult && $productRow = $productResult->fetch_assoc()) {
                            $productName = $productRow['name'];
                        }
                        $nameStmt->close();
                        throw new Exception("Insufficient stock for product: " . $productName . " (Code: " . $productCode . ")");
                    }
                    $stockUpdateStmt->close();
                }

                // Check if customer exists by phone1, phone_2, or email
                $customerId = null;
                $customerFound = false;
                
                // Build dynamic query based on available data
                $customerCheckConditions = [];
                $customerCheckParams = [];
                $customerCheckTypes = '';
                
                // Check Phone Number 1 (always required)
                $customerCheckConditions[] = "phone = ?";
                $customerCheckParams[] = $phoneNumber;
                $customerCheckTypes .= 's';
                
                // Check Phone Number 2 if provided
                if (!empty($phoneNumber2)) {
                    $customerCheckConditions[] = "phone = ?";
                    $customerCheckConditions[] = "phone_2 = ?";
                    $customerCheckParams[] = $phoneNumber2;
                    $customerCheckParams[] = $phoneNumber2;
                    $customerCheckTypes .= 'ss';
                }
             
                // Check Email if provided
                // if (!empty($emailForDb)) {
                //     $customerCheckConditions[] = "email = ?";
                //     $customerCheckParams[] = $emailForDb;
                //     $customerCheckTypes .= 's';
                // }
                
                // Build the query
                $customerCheckSql = "SELECT customer_id FROM customers WHERE " . implode(' OR ', $customerCheckConditions) . " LIMIT 1";
                $customerCheckStmt = $conn->prepare($customerCheckSql);
                
                if (!$customerCheckStmt) {
                    throw new Exception("Failed to prepare customer check query: " . $conn->error);
                }
                
                // Bind parameters dynamically
                $customerCheckStmt->bind_param($customerCheckTypes, ...$customerCheckParams);
                $customerCheckStmt->execute();
                $customerCheckResult = $customerCheckStmt->get_result();
                
                if ($customerCheckResult->num_rows > 0) {
                    // Customer EXISTS - Use existing customer ID, NO UPDATE
                    $customerId = $customerCheckResult->fetch_assoc()['customer_id'];
                    $customerFound = true;
                } else {
                    // Customer DOES NOT EXIST - Create NEW customer
                    $customerInsertSql = "INSERT INTO customers (name, email, phone, phone_2, address_line1, address_line2, city_id) 
                                         VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $customerInsertStmt = $conn->prepare($customerInsertSql);
                    if (!$customerInsertStmt) {
                        throw new Exception("Failed to prepare customer insert query: " . $conn->error);
                    }
                    $customerInsertStmt->bind_param("ssssssi", $fullName, $emailForDb, $phoneNumber, $phoneNumber2, $addressLine1, $addressLine2, $cityId);
                    
                    if (!$customerInsertStmt->execute()) {
                        throw new Exception("Failed to create customer: " . $customerInsertStmt->error);
                    }
                    
                    $customerId = $conn->insert_id;
                    $customerInsertStmt->close();
                    $customerFound = false;
                }
                $customerCheckStmt->close();

                // Fetch branding info and delivery fee for this customer
// Fetch delivery fee from active branding table (get first active branding entry)
$deliveryFee = 0; // default initialization
$brandingSql = "SELECT delivery_fee FROM branding WHERE active = 1 ORDER BY branding_id ASC LIMIT 1";
$brandingStmt = $conn->prepare($brandingSql);
if (!$brandingStmt) {
    throw new Exception("Failed to prepare branding query: " . $conn->error);
}
$brandingStmt->execute();
$brandingResult = $brandingStmt->get_result();

if ($brandingResult->num_rows > 0) {
    $brandingData = $brandingResult->fetch_assoc();
    $deliveryFee = (float)$brandingData['delivery_fee'];
} else {
    throw new Exception("No active branding configuration found. Please set up branding first.");
}
$brandingStmt->close();

// Calculate total amount including delivery fee
$totalAmountWithDelivery = $subtotal + $deliveryFee;

// Randomly assign to one of the selected users
$assignedUserId = $selectedUsers[$userIndex % count($selectedUsers)];
$userIndex++;

// Calculate customer success rate
$rate = cs_condition($conn, $customerId);

// Create order header with CSV data 
$orderSql = "INSERT INTO order_header (
    customer_id, user_id, issue_date, due_date, subtotal, discount, notes, 
    pay_status, pay_by, total_amount, currency, status, product_code, interface, 
    mobile, mobile_2, city_id, address_line1, address_line2, full_name, email, delivery_fee, call_log, created_by, `condition`, upload_error
) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, 0.00, ?, 
         'unpaid', 'NULL', ?, 'lkr', 'pending', ?, 'leads', ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)";

$orderStmt = $conn->prepare($orderSql);
if (!$orderStmt) {
    throw new Exception("Failed to prepare order query: " . $conn->error);
}
$notes = !empty($other) ? $other : 'Imported from CSV';
 

$orderStmt->bind_param("iidsdississssiiis", 
    $customerId,                // customer_id (int)
    $assignedUserId,            // user_id (int)
    $subtotal,                  // subtotal (decimal) - WITHOUT delivery
    $notes,                     // notes (string)
    $totalAmountWithDelivery,   // total_amount (decimal) - WITH delivery
    $productId,               // product_id (int)
    $phoneNumber,               // mobile (string)
    $phoneNumber2,              // mobile_2 (string)
    $cityId,                    // city_id (int)
    $addressLine1,              // address_line1 (string)
    $addressLine2,              // address_line2 (string)
    $fullName,                  // full_name (string)
    $emailForDb,                // email (string)
    $deliveryFee,               // delivery_fee (decimal)
    $loggedInUserId,            // created_by (int)
    $rate,                      // condition (int)
    $upload_error               // upload_error
);

if (!$orderStmt->execute()) {
    throw new Exception("Failed to create order: " . $orderStmt->error);
}

$orderId = $conn->insert_id;
$orderStmt->close();
                
                // Create order item
                // Quantity is already determined above
                $itemTotalAmount = $unitPrice * $quantity;
                $itemSql = "INSERT INTO order_items (
                    order_id, product_id, unit_price, discount, total_amount, quantity, pay_status, status, description
                ) VALUES (?, ?, ?, 0.00, ?, ?, 'unpaid', 'pending', ?)";
                
                $itemStmt = $conn->prepare($itemSql);
                if (!$itemStmt) {
                    throw new Exception("Failed to prepare order item query: " . $conn->error);
                }
                $description = "Product: $productCode";
                
                $itemStmt->bind_param("iiddis", 
                    $orderId, $productId, $unitPrice, $itemTotalAmount, $quantity, $description
                );
                
                if (!$itemStmt->execute()) {
                    throw new Exception("Failed to create order item: " . $itemStmt->error);
                }
                
                $itemStmt->close();
                
                // Track successful order ID
                $successfulOrderIds[] = $orderId;
                $successCount++;
                
            } catch (Exception $e) {
                $errorCount++;
                $errorMessage = $e->getMessage();
                $errorMessages[] = "Row $rowNumber: " . $errorMessage;
                
                // Capture row data for error download
                $failedRow = [];
                foreach ($headers as $index => $headerName) {
                    $failedRow[$headerName] = $row[$index] ?? '';
                }
                $failedRow['Error Reason'] = $errorMessage;
                $failedRowsData[] = $failedRow;
                
                continue;
            }
        }
        
        fclose($handle);
        
        // Store failed rows in session
        if (!empty($failedRowsData)) {
            $_SESSION['failed_rows_data'] = $failedRowsData;
        }
        
        // Commit transaction
        $conn->commit();
        $transactionStarted = false;
        
        // Log the import summary
        if ($successCount > 0 || $errorCount > 0) {
            $logDetails = "Lead uploaded - Success($successCount) | Failed($errorCount)";
            if (!empty($selectedUsers)) {
                $logDetails .= " | Selected User IDs: " . implode(',', $selectedUsers);
            }
            $logOrderId = !empty($successfulOrderIds) ? $successfulOrderIds[0] : 0;
            logUserAction($conn, $loggedInUserId, "lead_upload", $logOrderId, $logDetails);
        }
        
        // Store results in session
        $_SESSION['import_result'] = [
            'success' => $successCount,
            'errors' => $errorCount,
            'messages' => $errorMessages
        ];
        
        // Redirect to avoid resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($transactionStarted) {
            $conn->rollback();
            $transactionStarted = false;
        }
        
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }
        
        $_SESSION['import_error'] = $e->getMessage();
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Fetch only active users
$usersSql = "SELECT id, name FROM users WHERE status = 'active' AND id != 1 ORDER BY name ASC";
$usersStmt = $conn->prepare($usersSql);
if (!$usersStmt) {
    die("Failed to prepare users query: " . $conn->error);
}
$usersStmt->execute();
$usersResult = $usersStmt->get_result();
$users = [];
if ($usersResult && $usersResult->num_rows > 0) {
    while ($user = $usersResult->fetch_assoc()) {
        $users[] = $user;
    }
}
$usersStmt->close();

// Fetch active products for dropdown
$productsSql = "SELECT id, name, product_code, lkr_price, stock_quantity FROM products WHERE status = 'active' ORDER BY name ASC";
$productsResult = $conn->query($productsSql);
$products = [];
if ($productsResult && $productsResult->num_rows > 0) {
    while ($row = $productsResult->fetch_assoc()) {
        $products[] = $row;
    }
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Lead Upload</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/head.php'); ?>
    
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/leads.css" id="main-style-link" />
</head>

<style>
.alert-info {
    background-color: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

.alert-info h4 {
    margin-bottom: 0.5rem;
    color: #0c5460;
}

.alert-info ul {
    margin-bottom: 0;
    padding-left: 1.5rem;
}

.alert-info li {
    margin-bottom: 0.3rem;
}

.alert-warning {
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
    padding: 1rem;
    margin-bottom: 1.5rem;
    border-radius: 5px;
}

.error-section {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
    padding: 1rem;
    margin-top: 1rem;
    border-radius: 5px;
}

.error-section h5 {
    color: #721c24;
    margin-bottom: 0.5rem;
}

.download-errors-btn {
    display: inline-block;
    background-color: #dc3545;
    color: white;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    text-decoration: none;
    font-weight: bold;
    margin-top: 1rem;
    transition: background-color 0.2s;
}

.download-errors-btn:hover {
    background-color: #c82333;
    color: white;
}

.product-option:hover {
    background-color: #f5f5f5;
}

.product-option.active {
    background-color: #e9ecef;
}

/* Custom layout for lead upload */
.upload-grid-row {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    margin-bottom: 2rem;
}

.upload-column {
    flex: 1;
    min-width: 300px;
}

@media (max-width: 768px) {
    .upload-grid-row {
        flex-direction: column;
        gap: 1.5rem;
    }
}
</style>

<body>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/loader.php'); 
    include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/sidebar.php');?>

    <div class="pc-container">
        <div class="pc-content">
            
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title" style="display: flex; justify-content: space-between; align-items: center;">
                        <h5 class="mb-0 font-medium">Lead Management</h5>
                        <a href="/fardar_it/dist/templates/generate_template.php" class="choose-file-btn">Download CSV Template</a>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                <?php if (isset($_SESSION['import_result'])): ?>
                    <div class="alert alert-<?php echo $_SESSION['import_result']['errors'] > 0 ? 'warning' : 'success'; ?>">
                        <h4>Import Results</h4>
                        <p><strong>Successfully imported:</strong> <?php echo $_SESSION['import_result']['success']; ?> records</p>
                        <?php if ($_SESSION['import_result']['errors'] > 0): ?>
                            <p><strong>Failed imports:</strong> <?php echo $_SESSION['import_result']['errors']; ?> records</p>
                            
                            <!-- NEW: Download Failed Rows Button -->
                            <?php if (isset($_SESSION['failed_rows_data'])): ?>
                                <a href="?download_errors=1" class="download-errors-btn">
                                    📥 Download Failed Rows CSV
                                </a>
                                <p style="margin-top: 0.5rem; font-size: 0.9rem;">
                                    <em>Download the CSV file containing only the failed rows with error reasons. Fix the issues and re-upload.</em>
                                </p>
                            <?php endif; ?>
                            
                            <?php if (!empty($_SESSION['import_result']['messages'])): ?>
                                <details style="margin-top: 1rem;">
                                    <summary style="cursor: pointer; font-weight: bold;">View Error Details</summary>
                                    <div class="error-section">
                                        <ul class="mt-2">
                                            <?php foreach ($_SESSION['import_result']['messages'] as $message): ?>
                                                <li><?php echo htmlspecialchars($message); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php unset($_SESSION['import_result']); ?>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['import_error'])): ?>
                    <div class="alert alert-danger">
                        <strong>Error:</strong> <?php echo htmlspecialchars($_SESSION['import_error']); ?>
                    </div>
                    <?php unset($_SESSION['import_error']); ?>
                <?php endif; ?>

                <div class="lead-upload-container">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="upload-grid-row">
                            <!-- Left Column: Product Selection -->
                            <div class="upload-column product-selection-section">
                                <h2 class="section-title">Select Product <span style="color: red;">*</span></h2>
                                
                                <div class="form-group" style="position: relative;">
                                    
                                    <input type="text" id="product_search" class="form-control" placeholder="Type to search product..." autocomplete="off" style="width: 100%; padding: 10px; border: 1px solid #ced4da; border-radius: 4px;">
                                    <input type="hidden" name="product_id" id="product_id" required>
                                    <div id="product_dropdown" style="display: none; position: absolute; background: white; border: 1px solid #ced4da; border-top: none; max-height: 150px; overflow-y: auto; width: 100%; z-index: 1000; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                                        <?php foreach ($products as $prod): ?>
                                            <div class="product-option" data-id="<?php echo $prod['id']; ?>" data-name="<?php echo htmlspecialchars($prod['name']); ?>" data-code="<?php echo htmlspecialchars($prod['product_code']); ?>" style="padding: 10px; cursor: pointer; border-bottom: 1px solid #f0f0f0;">
                                                <strong><?php echo htmlspecialchars($prod['name']); ?></strong> (<?php echo htmlspecialchars($prod['product_code']); ?>)
                                                <?php if (isset($_SESSION['allow_inventory']) && $_SESSION['allow_inventory'] == 1): ?>
                                                    <span style="color: #6c757d; font-size: 0.9em;"> - Stock: <?php echo $prod['stock_quantity']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                        <div id="no_products_found" style="display: none; padding: 10px; color: #999; text-align: center;">No products found</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Right Column: CSV Upload -->
                            <div class="upload-column file-upload-section" style="margin-bottom: 0; padding-bottom: 0;">
                                <h2 class="section-title">CSV Upload</h2>
                                <div class="file-upload-box" style="margin-top: 0.5rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <p id="file-name" style="margin-bottom: 0.5rem;">No file selected</p>
                                    <input type="file" id="csv_file" name="csv_file" accept=".csv" style="display: none;">
                                    <button type="button" class="choose-file-btn" onclick="document.getElementById('csv_file').click()">Choose File</button>
                                </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <br>

                        <div class="users-section">
                            <h2 class="section-title">Select Users</h2>
                            <p class="text-muted">Choose which users will receive the imported leads</p>
                            
                            <ul class="users-list" id="usersList">
                                <?php if (!empty($users)): ?>
                                    <?php foreach ($users as $user): ?>
                                        <li>
                                            <input type="checkbox" id="user_<?php echo $user['id']; ?>" name="users[]" value="<?php echo $user['id']; ?>">
                                            <label for="user_<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['name']); ?></label>
                                        </li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li class="no-users">No active users found</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        
                        <?php if (!empty($users)): ?>
                            <button type="button" class="select-all-btn" id="toggleSelectAll">Select All</button>
                        <?php endif; ?>
                        
                        <hr>
                        

                        <div class="action-buttons">
                            <button type="button" class="action-btn reset-btn" id="resetBtn">Reset</button>
                            <button type="submit" class="action-btn import-btn" id="importBtn">
                                 Import Leads
                            </button>
                        </div>
                        
                                
                        <br>

                        <!-- Instructions Box -->
                        <div class="alert alert-info">
                            <h4>📋 How to Import Leads</h4>
                            <ol>
                                <li>Select a product from the dropdown</li>
                                <li>Download and fill the CSV template</li>
                                <li>Upload the CSV and select users to distribute leads</li>
                            </ol>
                            <p class="mt-2"><strong>Required fields:</strong> Full Name, Phone Number (10 digits), City, Address Line 1</p>
                            <p><strong>File:</strong> CSV only, max 10MB</p>
                            <p><strong>Note:</strong> Failed rows can be downloaded as CSV to fix and re-upload.</p>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/footer.php'); ?>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/scripts.php'); ?>
    
    
    <script>
        // Product Search Autocomplete
        const productSearch = document.getElementById('product_search');
        const productId = document.getElementById('product_id');
        const productDropdown = document.getElementById('product_dropdown');
        const productOptions = document.querySelectorAll('.product-option');
        
        // Show dropdown when input is focused or typed in
        productSearch.addEventListener('focus', function() {
            this.select(); 
            filterProducts(this.value);
            productDropdown.style.display = 'block';
        });
        
        productSearch.addEventListener('input', function() {
            filterProducts(this.value);
            productDropdown.style.display = 'block';
        });
        
        // Filter products based on search term
        function filterProducts(searchTerm) {
            const term = searchTerm.toLowerCase().trim();
            const noProductsFound = document.getElementById('no_products_found');
            let hasVisibleOptions = false;
            
            productOptions.forEach(option => {
                const name = option.dataset.name.toLowerCase();
                const code = option.dataset.code.toLowerCase();
                const combined = (name + ' (' + code + ')').toLowerCase();
                
    
                if (term === '' || name.includes(term) || code.includes(term) || combined === term) {
                    option.style.display = 'block';
                    hasVisibleOptions = true;
                } else {
                    option.style.display = 'none';
                }
            });
            
            if (noProductsFound) {
                noProductsFound.style.display = hasVisibleOptions ? 'none' : 'block';
            }
        }
        
        // Handle product selection
        productOptions.forEach(option => {
            option.addEventListener('click', function() {
                const id = this.dataset.id;
                const name = this.dataset.name;
                const code = this.dataset.code;
                
                productId.value = id;
                productSearch.value = name + ' (' + code + ')';
                productDropdown.style.display = 'none';
            });
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!productSearch.contains(e.target) && !productDropdown.contains(e.target)) {
                productDropdown.style.display = 'none';
            }
        });
        
        // Keyboard navigation
        let activeIndex = -1;
        productSearch.addEventListener('keydown', function(e) {
            const visibleOptions = Array.from(productOptions).filter(opt => opt.style.display !== 'none');
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, visibleOptions.length - 1);
                updateActiveOption(visibleOptions);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(activeIndex - 1, 0);
                updateActiveOption(visibleOptions);
            } else if (e.key === 'Enter' && activeIndex >= 0) {
                e.preventDefault();
                visibleOptions[activeIndex].click();
            } else if (e.key === 'Escape') {
                productDropdown.style.display = 'none';
            }
        });
        
        function updateActiveOption(visibleOptions) {
            productOptions.forEach(opt => opt.classList.remove('active'));
            if (activeIndex >= 0 && activeIndex < visibleOptions.length) {
                visibleOptions[activeIndex].classList.add('active');
                visibleOptions[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }
    </script>
    <script>
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('csv_file');
            const userCheckboxes = document.querySelectorAll('#usersList input[type="checkbox"]:checked');
            
            if (!fileInput.files.length) {
                alert('Please select a CSV file to upload.');
                e.preventDefault();
                return false;
            }
            
            if (userCheckboxes.length === 0) {
                alert('Please select at least one user to assign the leads to.');
                e.preventDefault();
                return false;
            }
            
            const importBtn = document.getElementById('importBtn');
            importBtn.disabled = true;
            importBtn.innerHTML = 'Importing...';
            
            return true;
        });
        
        const toggleBtn = document.getElementById('toggleSelectAll');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                const checkboxes = document.querySelectorAll('#usersList input[type="checkbox"]');
                const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
                
                checkboxes.forEach(checkbox => {
                    checkbox.checked = !allChecked;
                });
                
                this.textContent = allChecked ? 'Select All' : 'Deselect All';
            });
        }
        
        document.getElementById('resetBtn').addEventListener('click', function() {
            if (confirm('Are you sure you want to reset the form?')) {
                document.querySelectorAll('#usersList input[type="checkbox"]').forEach(checkbox => {
                    checkbox.checked = false;
                });
                
                document.getElementById('csv_file').value = '';
                document.getElementById('file-name').textContent = 'No file selected';
                
                if (toggleBtn) {
                    toggleBtn.textContent = 'Select All';
                }
                
                const importBtn = document.getElementById('importBtn');
                importBtn.disabled = false;
                importBtn.innerHTML = 'Import Leads';
            }
        });
        
        document.getElementById('csv_file').addEventListener('change', function() {
            const file = this.files[0];
            const fileNameEl = document.getElementById('file-name');
            
            if (file) {
                const validExtensions = ['.csv'];
                const fileName = file.name.toLowerCase();
                const isValidExtension = validExtensions.some(ext => fileName.endsWith(ext));
                
                if (!isValidExtension) {
                    alert('Please select a valid CSV file.');
                    this.value = '';
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                const maxSize = 10 * 1024 * 1024;
                if (file.size > maxSize) {
                    alert('File size must be less than 10MB.');
                    this.value = '';
                    fileNameEl.textContent = 'No file selected';
                    return;
                }
                
                fileNameEl.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
            } else {
                fileNameEl.textContent = 'No file selected';
            }
        });
    </script>
</body>
</html>