<?php
// login.php - User Login (Super Admin & Salesman)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    header("Location: index.php");
    exit;
}

$error = '';
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $username_val = $username;

    if (empty($username) || empty($password)) {
        $error = "Please enter both username and password.";
    } elseif (!isset($pdo)) {
        $error = "Database is not connected. Please check XAMPP MySQL.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Set session
                $_SESSION['user'] = [
                    'id'       => (int)$user['id'],
                    'username' => $user['username'],
                    'fullname' => $user['fullname'],
                    'role'     => $user['role'],
                    'phone'    => $user['phone']
                ];

                set_flash('success', "Welcome back, " . htmlspecialchars($user['fullname']) . "! Signed in as " . ($user['role'] === 'super_admin' ? 'Super Admin' : 'Salesman') . ".");

                // Salesmen can go straight to POS billing; Super Admin to Dashboard
                if ($user['role'] === 'salesman') {
                    header("Location: pos.php");
                } else {
                    header("Location: index.php");
                }
                exit;
            } else {
                $error = "Invalid username or password.";
            }
        } catch (PDOException $e) {
            $error = "Authentication error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Iron Store Management POS</title>
    
    <!-- Bootstrap 5.3 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome 6 Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 20px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .login-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            padding: 30px 25px;
            text-align: center;
            color: #ffffff;
            border-bottom: 3px solid #f59e0b;
        }

        .login-body {
            padding: 30px 25px;
        }

        .demo-chip {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .demo-chip:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>

    <div class="login-card">
        <!-- Header -->
        <div class="login-header">
            <div class="d-inline-flex p-3 rounded-circle bg-warning text-dark mb-3 shadow">
                <i class="fa-solid fa-cubes-stacked fs-3"></i>
            </div>
            <h4 class="fw-bold mb-1">IRON STORE POS</h4>
            <p class="text-light opacity-75 small mb-0">Inventory, Billing & Profit Management</p>
        </div>

        <!-- Body -->
        <div class="login-body">
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 px-3 small d-flex align-items-center mb-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php display_flash(); ?>

            <form method="POST" action="login.php" id="loginForm">
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark small text-uppercase">Username</label>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="username" id="inputUsername" class="form-control" placeholder="Enter username" value="<?= htmlspecialchars($username_val) ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-bold text-dark small text-uppercase mb-0">Password</label>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-light text-muted"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" name="password" id="inputPassword" class="form-control" placeholder="Enter password" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePassword()">
                            <i class="fa-solid fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" name="login" class="btn btn-warning w-100 fw-bold py-2 shadow-sm text-dark fs-6 mt-2">
                    <i class="fa-solid fa-right-to-bracket me-1"></i> Sign In to Store POS
                </button>
            </form>

            <!-- Quick Demo Credentials for Convenience -->
            <div class="mt-4 pt-3 border-top">
                <div class="text-center small text-muted mb-2 fw-semibold">Quick 1-Click Role Login:</div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="p-2 border rounded-3 bg-light text-center demo-chip" onclick="fillCreds('admin', 'admin123')">
                            <span class="badge bg-primary mb-1"><i class="fa-solid fa-user-shield me-1"></i>Super Admin</span>
                            <div class="small fw-bold text-dark">admin</div>
                            <div class="small text-muted font-monospace">admin123</div>
                            <div class="text-success small fw-semibold">Full Profit Access</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 border rounded-3 bg-light text-center demo-chip" onclick="fillCreds('salesman', '123456')">
                            <span class="badge bg-secondary mb-1"><i class="fa-solid fa-user me-1"></i>Salesman</span>
                            <div class="small fw-bold text-dark">salesman</div>
                            <div class="small text-muted font-monospace">123456</div>
                            <div class="text-danger small fw-semibold">No Profit Tab</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Register Link -->
            <div class="text-center mt-3 pt-2">
                <span class="small text-muted">Need a new staff account?</span>
                <a href="register.php" class="small fw-bold text-primary text-decoration-none ms-1">Register User &rarr;</a>
            </div>

        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('inputPassword');
            const icon = document.getElementById('toggleIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }

        function fillCreds(user, pass) {
            document.getElementById('inputUsername').value = user;
            document.getElementById('inputPassword').value = pass;
        }
    </script>
</body>
</html>

