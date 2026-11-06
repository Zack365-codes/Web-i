<?php
// product_delete.php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

require_super_admin();

$id = intval($_GET['id'] ?? 0);

if ($id > 0 && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $stmt->execute([$id]);
        set_flash('success', 'Product was deleted successfully.');
    } catch (PDOException $e) {
        set_flash('danger', 'Failed to delete product: ' . $e->getMessage());
    }
} else {
    set_flash('danger', 'Invalid Product ID.');
}

header("Location: products.php");
exit;
