<?php
// Start session at the very beginning
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    // Clear any existing output buffers
    if (ob_get_level()) {
        ob_end_clean();
    }
    header("Location: /fardar_it/dist/pages/login.php");
    exit();
}

// Include the database connection file
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

// Check if user has admin role (role_id = 1)
if (!isset($_SESSION['user_id'])) {
    header("Location: /fardar_it/dist/pages/login.php");
    exit();
}

// Get user's role from database
$user_id = $_SESSION['user_id'];
$role_check_sql = "SELECT u.role_id, r.name as role_name 
                   FROM users u 
                   LEFT JOIN roles r ON u.role_id = r.id 
                   WHERE u.id = ? AND u.status = 'active'";
$role_stmt = $conn->prepare($role_check_sql);
$role_stmt->bind_param("i", $user_id);
$role_stmt->execute();
$role_result = $role_stmt->get_result();

if ($role_result->num_rows === 0) {
    // User not found or inactive
    session_destroy();
    header("Location: /fardar_it/dist/pages/login.php");
    exit();
}

$user_role = $role_result->fetch_assoc();

// Check if user is admin (role_id = 1)
if ($user_role['role_id'] != 1) {
    // User is not admin, redirect to dashboard
    header("Location: /fardar_it/dist/dashboard/index.php");
    exit();
}

// Handle search and filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$person_name_filter = isset($_GET['person_name_filter']) ? trim($_GET['person_name_filter']) : '';
$company_name_filter = isset($_GET['company_name_filter']) ? trim($_GET['company_name_filter']) : '';
$phone_filter = isset($_GET['phone_filter']) ? trim($_GET['phone_filter']) : '';
$status_filter = isset($_GET['status_filter']) ? trim($_GET['status_filter']) : '';

// Pagination settings
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Base SQL for counting total records
$countSql = "SELECT COUNT(*) as total FROM suplier";

// Main query - updated to match your actual database schema
$sql = "SELECT s.id, s.sup_company_name, s.person_name, s.phone_sup, s.address_sup, s.status_sup 
        FROM suplier s";

// Build search conditions
$searchConditions = [];

// General search condition
if (!empty($search)) {
    $searchTerm = $conn->real_escape_string($search);
    $searchConditions[] = "(
                        s.sup_company_name LIKE '%$searchTerm%' OR 
                        s.person_name LIKE '%$searchTerm%' OR 
                        s.phone_sup LIKE '%$searchTerm%')";
}

// Specific Person Name filter
if (!empty($person_name_filter)) {
    $personTerm = $conn->real_escape_string($person_name_filter);
    $searchConditions[] = "s.person_name LIKE '%$personTerm%'";
}

// Specific Company Name filter
if (!empty($company_name_filter)) {
    $companyTerm = $conn->real_escape_string($company_name_filter);
    $searchConditions[] = "s.sup_company_name LIKE '%$companyTerm%'";
}

// Specific Phone filter
if (!empty($phone_filter)) {
    $phoneTerm = $conn->real_escape_string($phone_filter);
    $searchConditions[] = "s.phone_sup LIKE '%$phoneTerm%'";
}

// Status filter
if (!empty($status_filter)) {
    $statusTerm = $conn->real_escape_string($status_filter);
    $searchConditions[] = "s.status_sup = '$statusTerm'";
}

// Apply all search conditions
if (!empty($searchConditions)) {
    $finalSearchCondition = " WHERE " . implode(' AND ', $searchConditions);
    $countSql .= $finalSearchCondition;
    $sql .= $finalSearchCondition;
}

// Add ordering and pagination
$sql .= " ORDER BY s.id DESC LIMIT $limit OFFSET $offset";

// Execute queries
$countResult = $conn->query($countSql);
$totalRows = 0;
if ($countResult && $countResult->num_rows > 0) {
    $totalRows = $countResult->fetch_assoc()['total'];
}
$totalPages = ceil($totalRows / $limit);
$result = $conn->query($sql);

// Debug: Check if query failed
if (!$result) {
    die("Query failed: " . $conn->error);
}
?>

<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">

<head>
    <title>Order Management Admin Portal - Supplier Management</title>
    
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/head.php'); ?>
    
    <!-- Stylesheets -->
    <link rel="stylesheet" href="../assets/css/style.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/orders.css" id="main-style-link" />
    <link rel="stylesheet" href="../assets/css/customers.css" id="main-style-link" />
</head>

<body>
    <!-- Page Loader -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/loader.php'); 
    include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/navbar.php');
    include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/sidebar.php');?>

    <div class="pc-container">
        <div class="pc-content">
            
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-block">
                    <div class="page-header-title">
                        <h5 class="mb-0 font-medium">Supplier Management</h5>
                        <small class="text-muted">Manage your business partners</small>
                    </div>
                </div>
            </div>

            <div class="main-content-wrapper">
                
                <!-- User Filter Section -->
                <div class="tracking-container">
                    <form class="tracking-form" method="GET" action="">
                        <div class="form-group">
                            <label for="person_name_filter">Contact Person</label>
                            <input type="text" id="person_name_filter" name="person_name_filter" 
                                   placeholder="Enter person name" 
                                   value="<?php echo htmlspecialchars($person_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="company_name_filter">Company Name</label>
                            <input type="text" id="company_name_filter" name="company_name_filter" 
                                   placeholder="Enter company name" 
                                   value="<?php echo htmlspecialchars($company_name_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="phone_filter">Phone</label>
                            <input type="text" id="phone_filter" name="phone_filter" 
                                   placeholder="Enter phone number" 
                                   value="<?php echo htmlspecialchars($phone_filter); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="status_filter">Status</label>
                            <select id="status_filter" name="status_filter">
                                <option value="">All Status</option>
                                <option value="active" <?php echo ($status_filter == 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo ($status_filter == 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <div class="button-group">
                                <button type="submit" class="search-btn">
                                    <i class="fas fa-search"></i>
                                    Search
                                </button>
                                <button type="button" class="search-btn" onclick="clearFilters()" style="background: #6c757d;">
                                    <i class="fas fa-times"></i>
                                    Clear
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Suplier Count Display -->
                <div class="order-count-container">
                    <div class="order-count-number"><?php echo number_format($totalRows); ?></div>
                    <div class="order-count-dash">-</div>
                    <div class="order-count-subtitle">Total Suppliers</div>
                </div>

                <!-- Suplier Table -->
                <div class="table-wrapper">
                    <table class="orders-table">
                        <thead>
                            <tr>
                                <th>Supplier Info</th>
                                <th>Contact Number</th>
                                <th>Address</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="usersTableBody">
                            <?php if ($result && $result->num_rows > 0): ?>
                                <?php while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <!-- User Info -->
                                        <td class="customer-name">
                                            <div class="customer-info">
                                                <h6 style="margin: 0; font-size: 14px; font-weight: 600;"><?php echo htmlspecialchars($row['sup_company_name']); ?></h6>
                                                <small style="color: #6c757d; font-size: 12px;"><?php echo htmlspecialchars($row['person_name']); ?> (ID: <?php echo htmlspecialchars($row['id']); ?>)</small>
                                            </div>
                                        </td>
                                        
                                        <!-- Contact Info -->
                                        <td>
                                            <div style="line-height: 1.4;">
                                                <div style="font-weight: 500; margin-bottom: 2px;"><?php echo htmlspecialchars($row['phone_sup'] ?: 'N/A'); ?></div>
                                            </div>
                                        </td>
                                        
                                        <!-- Address -->
                                        <td>
                                            <div style="font-size: 12px; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                                <?php echo htmlspecialchars($row['address_sup'] ?: 'N/A'); ?>
                                            </div>
                                        </td>

                                        <!-- Status -->
                                        <td>
                                            <?php if ($row['status_sup'] === 'active'): ?>
                                                <span class="status-badge pay-status-paid">Active</span>
                                            <?php else: ?>
                                                <span class="status-badge pay-status-unpaid">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        
                                        <!-- Action Buttons -->
                                        <td class="actions">
                                            <div class="action-buttons-group">
                                                <button type="button" class="action-btn view-btn view-supplier-btn"
                                                        data-sup-id="<?= $row['id'] ?>"
                                                        data-sup-company="<?= htmlspecialchars($row['sup_company_name']) ?>"
                                                        data-sup-person="<?= htmlspecialchars($row['person_name']) ?>"
                                                        data-sup-phone="<?= htmlspecialchars($row['phone_sup']) ?>"
                                                        data-sup-address="<?= htmlspecialchars($row['address_sup']) ?>"
                                                        data-sup-status="<?= htmlspecialchars($row['status_sup']) ?>"
                                                        title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                
                                                <button class="action-btn dispatch-btn" title="Edit Supplier" 
                                                        onclick="editSupplier(<?php echo $row['id']; ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                          
                                                <!-- Status Toggle Button -->
                                                <button type="button" class="action-btn <?= $row['status_sup'] == 'active' ? 'deactivate-btn' : 'activate-btn' ?> toggle-status-btn"
                                                        data-sup-id="<?= $row['id'] ?>"
                                                        data-current-status="<?= $row['status_sup'] ?>"
                                                        data-sup-name="<?= htmlspecialchars($row['sup_company_name']) ?>"
                                                        title="<?= $row['status_sup'] == 'active' ? 'Deactivate' : 'Activate' ?>">
                                                    <i class="fas <?= $row['status_sup'] == 'active' ? 'fa-user-times' : 'fa-user-check' ?>"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center" style="padding: 40px; text-align: center; color: #666;">
                                        <i class="fas fa-users" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                                        No suppliers found
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Controls -->
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($offset + $limit, $totalRows); ?> of <?php echo $totalRows; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <?php if ($page > 1): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&person_name_filter=<?php echo urlencode($person_name_filter); ?>&company_name_filter=<?php echo urlencode($company_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <button class="page-btn <?php echo ($i == $page) ? 'active' : ''; ?>" 
                                    onclick="window.location.href='?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&person_name_filter=<?php echo urlencode($person_name_filter); ?>&company_name_filter=<?php echo urlencode($company_name_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search); ?>'">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <button class="page-btn" onclick="window.location.href='?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&user_name_filter=<?php echo urlencode($user_name_filter); ?>&email_filter=<?php echo urlencode($email_filter); ?>&phone_filter=<?php echo urlencode($phone_filter); ?>&nic_filter=<?php echo urlencode($nic_filter); ?>&role_filter=<?php echo urlencode($role_filter); ?>&status_filter=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&search=<?php echo urlencode($search); ?>'">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Supplier Details Modal -->
    <div id="supplierDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h4>Supplier Details</h4>
                <span class="close" onclick="closeSupplierModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="customer-detail-row">
                    <span class="detail-label">Supplier ID:</span>
                    <span class="detail-value" id="modal-sup-id"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Company Name:</span>
                    <span class="detail-value" id="modal-sup-company"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Contact Person:</span>
                    <span class="detail-value" id="modal-sup-person"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Phone:</span>
                    <span class="detail-value" id="modal-sup-phone"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Address:</span>
                    <span class="detail-value" id="modal-sup-address"></span>
                </div>
                <div class="customer-detail-row">
                    <span class="detail-label">Status:</span>
                    <span class="detail-value">
                        <span id="modal-sup-status" class="status-badge"></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Status Confirmation Modal -->
    <div id="statusConfirmationModal" class="modal confirmation-modal">
        <div class="modal-content confirmation-modal-content">
            <div class="modal-header">
                <h4>Are you sure?</h4>
                <span class="close" onclick="closeConfirmationModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="confirmation-icon">
                    <i class="ti ti-alert-triangle"></i>
                </div>
                <div class="confirmation-text">
                    You are about to <span class="action-highlight" id="action-text"></span> supplier:
                </div>
                <div class="confirmation-text">
                    <span class="user-name-highlight" id="confirm-user-name"></span>
                </div>
                <div class="modal-buttons">
                    <button class="btn-confirm" id="confirmActionBtn">
                        <span id="confirm-button-text">Yes, proceed!</span>
                    </button>
                    <button class="btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/footer.php'); ?>

    <!-- Scripts -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/scripts.php'); ?>

    <script>
// Complete JavaScript code for supplier management page

function clearFilters() {
    window.location.href = 'suplier_list.php';
}

// Supplier Details Modal Functions
function openSupplierModal(button) {
    const modal = document.getElementById('supplierDetailsModal');
    
    // Extract data from button attributes
    const supId = button.getAttribute('data-sup-id');
    const company = button.getAttribute('data-sup-company');
    const person = button.getAttribute('data-sup-person');
    const phone = button.getAttribute('data-sup-phone');
    const address = button.getAttribute('data-sup-address');
    const status = button.getAttribute('data-sup-status');

    // Populate modal fields
    document.getElementById('modal-sup-id').textContent = supId;
    document.getElementById('modal-sup-company').textContent = company || 'N/A';
    document.getElementById('modal-sup-person').textContent = person || 'N/A';
    document.getElementById('modal-sup-phone').textContent = phone || 'N/A';
    document.getElementById('modal-sup-address').textContent = address || 'N/A';
    
    // Set status badge
    const statusElement = document.getElementById('modal-sup-status');
    statusElement.textContent = status === 'active' ? 'Active' : 'Inactive';
    if (status === 'active') {
        statusElement.className = 'status-badge pay-status-paid';
    } else {
        statusElement.className = 'status-badge pay-status-unpaid';
    }

    // Show modal
    modal.style.display = 'block';
}

function closeUserModal() {
    document.getElementById('supplierDetailsModal').style.display = 'none';
}

// Status Toggle Functions - Updated to match customer page style
function closeConfirmationModal() {
    document.getElementById('statusConfirmationModal').style.display = 'none';
}

function openConfirmationModal(button) {
    const supId = button.getAttribute('data-sup-id');
    const supName = button.getAttribute('data-sup-name');
    const currentStatus = button.getAttribute('data-current-status');
    
    // Determine action based on current status
    const isActive = currentStatus.toLowerCase() === 'active';
    const actionText = isActive ? 'deactivate' : 'activate';
    const buttonText = isActive ? 'Yes, deactivate!' : 'Yes, activate!';
    
    // Update modal content
    document.getElementById('action-text').textContent = actionText;
    document.getElementById('confirm-user-name').textContent = supName;
    document.getElementById('confirm-button-text').textContent = buttonText;
    
    // Store data for confirmation
    const confirmBtn = document.getElementById('confirmActionBtn');
    confirmBtn.setAttribute('data-sup-id', supId);
    confirmBtn.setAttribute('data-new-status', isActive ? 'inactive' : 'active');
    
    // Add click handler to confirm button
    confirmBtn.onclick = function() {
        toggleSupplierStatus(supId, isActive ? 'inactive' : 'active');
    };
    
    // Show modal
    document.getElementById('statusConfirmationModal').style.display = 'block';
}

function toggleSupplierStatus(supId, newStatus) {
    fetch('toggle_user_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            user_id: userId,
            new_status: newStatus
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Close confirmation modal
            closeConfirmationModal();
            
            // Show success message
            alert('Supplier status updated successfully!');
            
            // Reload page to reflect changes
            location.reload();
        } else {
            alert('Error updating status: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while updating the status.');
    });
}

// Edit Supplier Function
function editSupplier(supId) {
    window.location.href = `edit_suplier.php?id=${supId}`;
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // View supplier button event listeners
    const viewButtons = document.querySelectorAll('.view-supplier-btn');
    viewButtons.forEach(button => {
        button.addEventListener('click', function() {
            openUserModal(this);
        });
    });
    
    // Status toggle button event listeners
    const statusToggleButtons = document.querySelectorAll('.toggle-status-btn');
    statusToggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            openConfirmationModal(this);
        });
    });
    
    // Close modals when clicking outside
    window.onclick = function(event) {
        const supplierModal = document.getElementById('supplierDetailsModal');
        const statusModal = document.getElementById('statusConfirmationModal');
        
        if (event.target === supplierModal) {
            closeSupplierModal();
        }
        if (event.target === statusModal) {
            closeConfirmationModal();
        }
    };
    
    // Escape key to close modals
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            closeSupplierModal();
            closeConfirmationModal();
        }
    });
});

// Search functionality (if needed)
function performSearch() {
    const searchForm = document.querySelector('.tracking-form');
    if (searchForm) {
        searchForm.submit();
    }
}

// Auto-submit search on Enter key
document.addEventListener('DOMContentLoaded', function() {
    const searchInputs = document.querySelectorAll('#user_name_filter, #email_filter, #phone_filter, #nic_filter');
    searchInputs.forEach(input => {
        input.addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                performSearch();
            }
        });
    });
});
</script>

</body>
</html>