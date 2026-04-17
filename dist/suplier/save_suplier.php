<?php
// save_suplier.php - Logic for saving new suppliers
// Enable strict error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0); // Hide errors from output for AJAX

// Set content type to JSON
header('Content-Type: application/json');

// Start output buffering immediately
ob_start();

// Start session
session_start();

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

// Function to return JSON response and exit
function jsonResponse($success, $message, $errors = null, $data = null) {
    // Clean any existing output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($errors !== null) {
        $response['errors'] = $errors;
    }
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method');
}

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    jsonResponse(false, 'Authentication required. Please login again.');
}

// Function to log user actions - SIMPLIFIED
function logUserAction($conn, $userId, $actionType, $targetUserId, $details = '') {
    try {
        $logSql = "INSERT INTO user_logs (user_id, action_type, inquiry_id, details, created_at) VALUES (?, ?, ?, ?, NOW())";
        $logStmt = $conn->prepare($logSql);
        
        if (!$logStmt) {
            error_log("Failed to prepare user log statement: " . $conn->error);
            return false;
        }
        
        $logStmt->bind_param("isis", $userId, $actionType, $targetUserId, $details);
        $result = $logStmt->execute();
        
        if (!$result) {
            error_log("Failed to log user action: " . $logStmt->error);
        }
        
        $logStmt->close();
        return $result;
    } catch (Exception $e) {
        error_log("Exception in logUserAction: " . $e->getMessage());
        return false;
    }
}

// Main processing
try {
    // Get current user ID for logging
    $currentUserId = $_SESSION['user_id'] ?? null;
    if (!$currentUserId) {
        jsonResponse(false, 'User session not found. Please login again.');
    }

    // Get and sanitize inputs (frontend validation already handled most cases)
    $sup_company_name = trim($_POST['sup_company_name'] ?? '');
    $person_name = trim($_POST['person_name'] ?? '');
    $phone_sup = trim($_POST['mobile'] ?? '');
    $status_sup = strtolower(trim($_POST['status'] ?? 'active'));
    $address_sup = trim($_POST['address'] ?? '');
    
    // Essential server-side validation (security-critical only)
    $fieldErrors = [];
    
    if (empty($sup_company_name)) $fieldErrors['sup_company_name'] = "Company name is required.";
    if (empty($person_name)) $fieldErrors['person_name'] = "Responsibility person name is required.";
    if (empty($phone_sup)) {
        $fieldErrors['mobile'] = "Mobile number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone_sup)) {
        $fieldErrors['mobile'] = "Please enter a valid 10-digit mobile number.";
    }
    if (empty($address_sup)) $fieldErrors['address'] = "Address is required.";

    // If validation errors exist, return them
    if (!empty($fieldErrors)) {
        jsonResponse(false, 'Please correct the errors and try again.', $fieldErrors);
    }

    // Check for duplicate company name
    $stmt = $conn->prepare("SELECT id FROM suplier WHERE sup_company_name = ?");
    $stmt->bind_param("s", $sup_company_name);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $fieldErrors['sup_company_name'] = "A supplier with this company name already exists.";
        $stmt->close();
        jsonResponse(false, 'Duplicate company name detected.', $fieldErrors);
    }
    $stmt->close();

    // Begin database transaction
    $conn->begin_transaction();

    // Prepare insert query based on suplier.sql schema
    $stmt = $conn->prepare("INSERT INTO suplier (sup_company_name, person_name, phone_sup, address_sup, status_sup) VALUES (?, ?, ?, ?, ?)");
    
    if ($stmt === false) {
        $conn->rollback();
        jsonResponse(false, 'Database error occurred. Please try again.');
    }
    
    $stmt->bind_param("sssss", $sup_company_name, $person_name, $phone_sup, $address_sup, $status_sup);

    // Execute insert
    if ($stmt->execute()) {
        $newSupplierId = $conn->insert_id;
        $stmt->close();
        
        // Log supplier creation
        $logDetails = "New supplier created - Company: {$sup_company_name}, Person: {$person_name}";
        logUserAction($conn, $currentUserId, 'supplier_create', $newSupplierId, $logDetails);
        
        $conn->commit();
        
        // Return success response
        $userData = [
            'id' => $newSupplierId,
            'company' => $sup_company_name,
            'status' => ucfirst($status_sup)
        ];
        
        jsonResponse(true, "Supplier '{$sup_company_name}' has been successfully added.", null, $userData);
        
    } else {
        // Rollback transaction on failure
        $conn->rollback();
        $stmt->close();
        jsonResponse(false, 'Failed to add supplier. Please try again.');
    }

} catch (Exception $e) {
    // Rollback transaction if active
    if (isset($conn) && $conn->ping()) {
        $conn->rollback();
    }
    
    // Log the error for debugging
    error_log("Error in save_suplier.php: " . $e->getMessage());
    
    // Return generic error message to user
    jsonResponse(false, 'An unexpected error occurred while saving the supplier.');
    
} finally {
    // Ensure connection is closed
    if (isset($conn) && $conn->ping()) {
        $conn->close();
    }
    
    // Clean up output buffer
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
}
?>