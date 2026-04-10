<?php
session_start(); // Start the session at the very beginning

// Add anti-caching headers
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header("Location: /fardar_it/dist/dashboard/index.php");
    exit();
}

// Include both database connection files
include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/connection/db_connection.php');

// Check connections
if ($conn->connect_error) {
    die("Order Management DB Connection failed: " . $conn->connect_error);
}

// Initialize variables
$error_message = "";
$logo_url = "../assets/images/placeholder.png"; // fallback logo

// ✅ Fetch branding logo from database
$sql_branding = "SELECT logo_url FROM branding WHERE active = 1 LIMIT 1";
$result_branding = $conn->query($sql_branding);
if ($result_branding && $result_branding->num_rows > 0) {
    $branding = $result_branding->fetch_assoc();
    $logo_url = $branding['logo_url'];
}

// ✅ Handle form submit
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize inputs
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']); // Check if "Remember Me" is checked

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format";
    } else {
        // ✅ STEP 1: Check if user exists in the system
        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            // ✅ STEP 2: Verify password first
            if (password_verify($password, $user['password']) || $password == $user['password']) {
                
                // ✅ STEP 3: Check customer/company subscription status FIRST
                $customer_status_check = true;
                $customer_inactive_message = "";
                $customer_id = null;
                $customer_name = null;
                
                // ✅ STEP 4: Only check individual user status if company subscription is active
                if ($customer_status_check) {
                    // Now check if the individual user account is active
                    if ($user['status'] != 'active') {
                        $error_message = "Your user account is inactive. Please contact your administrator.";
                        error_log("User $email account is inactive (status: " . $user['status'] . ") but company subscription is active");
                    } else {
                        // ✅ STEP 5: Both company and user are active - proceed with login
                        $_SESSION['user'] = $email;
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['role_id'] = $user['role_id'];
                        $_SESSION['name'] = $user['name'];
                        $_SESSION['logged_in'] = true;
                        $_SESSION['customer_id'] = $customer_id;

                        // ✅ Handle "Remember Me"
                        if ($remember) {
                            setcookie("email", $email, time() + (86400 * 30), "/"); // 30 days
                        } else {
                            setcookie("email", "", time() - 3600, "/");
                        }

                        error_log("User $email successfully logged in with customer_id: $customer_id");

                        // Log successful login action
                        try {
                            $log_action = 'Login';
                            $log_details = "User successfully logged in.";
                            $log_sql = "INSERT INTO user_logs (user_id, action_type, details, created_at) VALUES (?, ?, ?, NOW())";
                            $log_stmt = $conn->prepare($log_sql);
                            if ($log_stmt) {
                                $log_stmt->bind_param("iss", $_SESSION['user_id'], $log_action, $log_details);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }
                        } catch (Exception $e) {
                            error_log("Failed to log login action: " . $e->getMessage());
                        }

                        // ✅ Redirect by role
                        switch ($user['role_id']) {
                            case 1: // Superadmin
                            case 2: // Regular user
                            case 3: // Other roles
                            default:
                                header("Location: /fardar_it/dist/dashboard/index.php");
                        }
                        exit();
                    }
                } else {
                    // Company/Customer subscription is inactive
                    $error_message = $customer_inactive_message;
                    error_log("Login denied for user $email - Company subscription inactive: $customer_inactive_message");
                }
            } else {
                $error_message = "Invalid password.";
                error_log("Invalid password attempt for user: $email");
            }
        } else {
            $error_message = "No user found with that email address.";
            error_log("Login attempt with non-existent email: $email");
        }
        $stmt->close();
    }
}


?>
<!doctype html>
<html lang="en" data-pc-preset="preset-1" data-pc-sidebar-caption="true" data-pc-direction="ltr" dir="ltr" data-pc-theme="light">
<head>
    <title>Login | Order Management Admin Portal</title>
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/head.php'); ?>
    <script>
        // Force reload if page is loaded from back-forward cache
        window.addEventListener('pageshow', function(event) {
            if (event.persisted || (window.performance && window.performance.navigation.type === 2)) {
                window.location.reload();
            }
        });
    </script>
</head>
  <link rel="stylesheet" href="../assets/css/login.css" id="main-style-link" />

<body>
    <!-- Loader -->
    <div class="loader-bg fixed inset-0 bg-white dark:bg-themedark-cardbg z-[1034]">
        <div class="loader-track h-[5px] w-full inline-block absolute overflow-hidden top-0">
            <div class="loader-fill w-[300px] h-[5px] bg-primary-500 absolute top-0 left-0 animate-[hitZak_0.6s_ease-in-out_infinite_alternate]"></div>
        </div>
    </div>

    <div class="auth-main relative">
        <div class="auth-wrapper v1 flex items-center w-full h-full min-h-screen">
            <div class="auth-form flex items-center justify-center grow flex-col min-h-screen relative p-6 ">
                <div class="w-full max-w-[350px] relative">
                    <div class="auth-bg ">
                        <span class="absolute top-[-100px] right-[-100px] w-[300px] h-[300px] block rounded-full bg-theme-bg-1 animate-[floating_7s_infinite]"></span>
                        <span class="absolute top-[150px] right-[-150px] w-5 h-5 block rounded-full bg-primary-500 animate-[floating_9s_infinite]"></span>
                        <span class="absolute left-[-150px] bottom-[150px] w-5 h-5 block rounded-full bg-theme-bg-1 animate-[floating_7s_infinite]"></span>
                        <span class="absolute left-[-100px] bottom-[-100px] w-[300px] h-[300px] block rounded-full bg-theme-bg-2 animate-[floating_9s_infinite]"></span>
                    </div>

                    <div class="card sm:my-12 w-full shadow-none">
                        <div class="card-body ">
                            <div class="text-center mb-8">
                                <!-- Branding logo -->
                                <a href="#"><img src="<?php echo $logo_url; ?>" alt="Company Logo" class="w-40 mx-auto" /></a>
                            </div>

                            <h4 class="text-center font-medium mb-4">Login</h4>

                            <!-- Error Message -->
                            <?php if (!empty($error_message)): ?>
                                <div class="error-message text-red-600 text-center mb-3 p-3 bg-red-50 rounded-md border border-red-200">
                                    <?php echo $error_message; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Login Form -->
                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
                                <div class="mb-3">
                                    <input type="email" class="form-control" name="email" placeholder="Email Address" 
                                           value="<?php echo isset($_COOKIE['email']) ? $_COOKIE['email'] : ''; ?>" required />
                                </div>
                                <div class="mb-4">
                                    <div class="relative flex items-center">
                                        <input type="password" class="form-control pr-10" id="floatingInput1" name="password" placeholder="Password" required />
                                        <span class="password-toggle absolute right-0 flex items-center pr-3" id="togglePassword" style="cursor: pointer;">
                                            <i class="fas fa-eye"></i>
                                        </span>
                                    </div>
                                </div>

                                <div class="flex mt-1 justify-between items-center flex-wrap">
                                    <div class="form-check">
                                        <input class="form-check-input input-primary" type="checkbox" name="remember" style="cursor: pointer;" 
                                               <?php echo isset($_COOKIE['email']) ? 'checked' : ''; ?> />
                                        <label class="form-check-label text-muted">Remember me?</label>
                                    </div>
                                </div>

                                <div class="mt-4 text-center">
                                    <button type="submit" class="btn btn-primary mx-auto shadow-2xl">Login</button>
                                </div>
                            </form>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- FOOTER -->
          <!-- <div class="footer-wrapper container-fluid mx-10">
        <div class="grid grid-cols-12 gap-1.5">
          <div class="col-span-12 sm:col-span-6 my-1">
            <p class="m-0"></p>
              <a href="https://www.feitsolutions.com/" class="text-theme-bodycolor dark:text-themedark-bodycolor hover:text-primary-500 dark:hover:text-primary-500" target="_blank">
              Copyright © 2025 FEITSolutions</a>
               ,Designed by FEIT All rights reserved
            </p>
          </div> -->
          <!-- <div class="col-span-12 sm:col-span-6 my-1 justify-self-end">
                   <p class="inline-block max-sm:mr-3 sm:ml-2">Distributed by <a href="https://themewagon.com" target="_blank">Themewagon</a></p>
          </div> -->
        <!-- </div>
      </div> -->
    <!-- END FOOTER -->

    <!-- SCRIPTS -->
    <?php include($_SERVER['DOCUMENT_ROOT'] . '/fardar_it/dist/include/scripts.php'); ?>
    <!-- END SCRIPTS -->

    <!-- Password Toggle Script -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('floatingInput1');

        if (togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);

                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
            });
        }
    });
    </script>
</body>
</html>
<?php
// Close connections at the very end ensuring all includes have access to DB if needed
if (isset($conn)) $conn->close();
?>