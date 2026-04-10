<?php
// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

// Set content type to JSON
header('Content-Type: application/json');

// Allow access from courier domain
header('Access-Control-Allow-Origin: https://www.fdedomestic.com');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Get the courier callback parameters
$waybill_id = isset($_POST["waybill_id"]) ? $_POST["waybill_id"] : '';
$delivery_status = isset($_POST["current_status"]) ? $_POST["current_status"] : '';
$last_update_time = isset($_POST["last_update_time"]) ? $_POST["last_update_time"] : date('Y-m-d H:i:s');

// $waybill_id = 'API2553980';
// $delivery_status = 'Return Pending';
// $last_update_time = '2024-06-25 10:00:05'; 

// Always ensure valid datetime
// $update_time = empty($last_update_time) ? date('Y-m-d H:i:s') : $last_update_time;

// Map status
if ($delivery_status == 'Reschedule' || $delivery_status == 'Date Changed') {
    $status_update = 'Pending to Deliver';
} elseif ($delivery_status == 'Dispatched') {
    $status_update = 'Courier Dispatch';
} else {
    $status_update = $delivery_status;
}

// Update query
$sql = "UPDATE order_header 
        SET status = ?, 
            updated_at = ? 
        WHERE tracking_number = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $status_update, $last_update_time, $waybill_id);

if ($stmt->execute()) {
    // Check if any row was updated
    if ($stmt->affected_rows > 0) {
        echo json_encode([
            'success' => true,
            'message' => 'Order status updated successfully',
            'waybill_id' => $waybill_id,
            'delivery_status' => $delivery_status,
            'mapped_status' => $status_update,
            'last_update_time' => $last_update_time
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No order found with the provided waybill_id',
            'waybill_id' => $waybill_id
        ]);
    }
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Database update failed: ' . $conn->error]);
}

// Close the statement and connection
$stmt->close();
$conn->close();
?>
