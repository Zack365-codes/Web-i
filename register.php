<?php
// register.php - User Registration (Staff / Salesman / Admin)
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';

$fullname_val = '';
$username_val = '';
$phone_val = '';
$role_val = 'salesman';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim(strtolower($_POST['username'] ?? ''));
    $phone    = trim($_POST['phone'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['super_admin', 'salesman']) ? $_POST['role'] : 'salesman';
    $password = $_POST['password'] ?? '';
    $confirm_pwd = $_POST['confirm_password'] ?? '';

    $fullname_val = $fullname;
    $username_val = $username;
    $phone_val    = $phone;
    $role_val     = $role;

    if (empty($fullname) || empty($username) || empty($password)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($username) < 3) {
        $error = "Username must be at least 3 characters long.";
    } elseif (strlen($password) < 5) {
        $error = "Password must be at least 5 characters long.";
    } elseif ($password !== $confirm_pwd) {
        $error = "Passwords do not match. Please re-enter.";
    } elseif (!isset($pdo)) {
        $error = "Database is not connected.";
    } else {
        try {
            // Check if username already taken
            $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetchColumn() > 0) {
                $error = "Username '{$username}' is already taken. Please choose another.";
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, fullname, phone, role, password) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $fullname, $phone, $role, $hash]);

                set_flash('success', "Account for '{$fullname}' created successfully! You can now log in.");
                header("Location: login.php");
                exit;
            }
        } catch (PDOException $e) {
            $error = "Registration error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register User - Iron Store Management POS</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 30px 15px;
        }

        .register-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 500px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .register-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            padding: 25px;
            text-align: center;
            color: #ffffff;
            border-bottom: 3px solid #3b82f6;
        }

        .register-body {
            padding: 30px 25px;
        }
    </style>
</head>
<body>

    <div class="register-card">
        <div class="register-header">
            <div class="d-inline-flex p-3 rounded-circle bg-primary text-white mb-2 shadow">
                <i class="fa-solid fa-user-plus fs-3"></i>
            </div>
            <h4 class="fw-bold mb-1">Create Staff / Admin Account</h4>
            <p class="text-light opacity-75 small mb-0">Iron Store POS User Registration</p>
        </div>

        <div class="register-body">
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger py-2 px-3 small d-flex align-items-center mb-3">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php">
                <div class="mb-3">
                    <label class="form-label fw-bold text-dark small text-uppercase">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="fullname" class="form-control" required placeholder="e.g. Muhammad Bilal" value="<?= htmlspecialchars($fullname_val) ?>">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required placeholder="e.g. bilal_pos" value="<?= htmlspecialchars($username_val) ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold text-dark small text-uppercase">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="0300-1234567" value="<?= htmlspecialchars($phone_val) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold text-dark small text-uppercase">Account Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select fw-semibold" required>
                        <option value="salesman" <?= $role_val === 'salesman' ? 'selected' : '' ?>>
                            Salesman (Counter Billing & Rates Only - No Profit Tab)
                        </option>
                        <option value="super_admin" <?= $role_val === 'super_admin' ? 'selected' : '' ?>>
                            Super Admin / Shop Owner (Full Profit Access & Supplier Khata)
                        </option>
                    </select>
                    <div class="form-text small text-muted">
                        Salesmen will never see purchase costs or profit reports. They only see selling rates and minimum bargain rates.
                    </div>
                </div>

                <div class="row g-2 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required placeholder="Min 5 characters">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-bold text-dark small text-uppercase">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" required placeholder="Re-type password">
                    </div>
                </div>

                <button type="submit" name="register" class="btn btn-primary w-100 fw-bold py-2 shadow-sm fs-6">
                    <i class="fa-solid fa-check-circle me-1"></i> Register Account
                </button>

                <div class="text-center mt-3 pt-2 border-top">
                    <span class="small text-muted">Already have an account?</span>
                    <a href="login.php" class="small fw-bold text-warning text-decoration-none ms-1">Sign In &rarr;</a>
                </div>
            </form>
        </div>
    </div>

</body>
</html>

