<?php
// includes/functions.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Authentication & Role-Based Access Control (RBAC)
 */
function is_logged_in() {
    return !empty($_SESSION['user']['id']);
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function is_super_admin() {
    return is_logged_in() && (($_SESSION['user']['role'] ?? '') === 'super_admin');
}

function is_salesman() {
    return is_logged_in() && (($_SESSION['user']['role'] ?? '') === 'salesman');
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit;
    }
}

function require_super_admin() {
    require_login();
    if (!is_super_admin()) {
        set_flash('danger', 'Access Denied: Only Super Admin has access to Profit Reports and Financial Analytics.');
        header("Location: index.php");
        exit;
    }
}

/**
 * Handle Lock / Unlock profit actions via URL query or POST
 */
if (isset($_GET['lock_profit'])) {
    unset($_SESSION['profit_unlocked']);
    // redirect to clean url
    $current_url = strtok($_SERVER["REQUEST_URI"], '?');
    header("Location: " . $current_url);
    exit;
}

/**
 * Check if profit view is unlocked
 */
function is_profit_unlocked() {
    // Only Super Admin can unlock or view profit
    if (!is_super_admin()) {
        return false;
    }
    return !empty($_SESSION['profit_unlocked']);
}

/**
 * Display profit with privacy shield / password lock
 */
function show_profit($amount, $show_sign = true, $decimals = 2) {
    if (!is_super_admin()) {
        return '<span class="badge bg-secondary-subtle text-muted border" title="Restricted to Super Admin"><i class="fa-solid fa-lock me-1 text-secondary"></i>Restricted</span>';
    }
    if (!is_profit_unlocked()) {
        return '<span class="badge bg-light text-muted border profit-shield-badge" role="button" data-bs-toggle="modal" data-bs-target="#profitPinModal" title="Profit is locked. Click to enter PIN and view."><i class="fa-solid fa-lock text-warning me-1"></i>••••••</span>';
    }
    $val = (float)$amount;
    $sign = ($show_sign && $val > 0) ? '+' : '';
    $class = ($val >= 0) ? 'text-success' : 'text-danger';
    return '<span class="fw-bold ' . $class . '">' . $sign . 'Rs. ' . number_format($val, $decimals) . '</span>';
}

/**
 * Display margin percentage with privacy shield
 */
function show_margin($margin_percent) {
    if (!is_super_admin()) {
        return '<span class="badge bg-secondary-subtle text-muted border"><i class="fa-solid fa-lock me-1"></i>••%</span>';
    }
    if (!is_profit_unlocked()) {
        return '<span class="badge bg-light text-muted border profit-shield-badge" role="button" data-bs-toggle="modal" data-bs-target="#profitPinModal"><i class="fa-solid fa-lock text-warning me-1"></i>••%</span>';
    }
    $val = (float)$margin_percent;
    $class = ($val >= 0) ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger';
    return '<span class="badge ' . $class . ' px-2 py-1">' . number_format($val, 1) . '% Margin</span>';
}

/**
 * Display purchase cost with privacy shield
 */
function show_cost($amount, $suffix = '') {
    if (!is_super_admin()) {
        return '<span class="text-muted"><i class="fa-solid fa-lock me-1"></i>Restricted</span>';
    }
    if (!is_profit_unlocked()) {
        return '<span class="text-muted profit-shield-badge" role="button" data-bs-toggle="modal" data-bs-target="#profitPinModal" title="Cost is locked. Click to enter PIN."><i class="fa-solid fa-lock text-warning me-1"></i>••••••</span>';
    }
    return '<span class="fw-bold text-danger">Rs. ' . number_format((float)$amount, 2) . '</span>' . ($suffix ? ' ' . $suffix : '');
}

/**
 * Format currency in Pakistani Rupees
 */
function format_currency($amount, $decimals = 2) {
    return 'Rs. ' . number_format((float)$amount, $decimals);
}

/**
 * Format weight in KG or Ton
 */
function format_weight($kg, $show_ton = true) {
    $kg_val = (float)$kg;
    $output = number_format($kg_val, 2) . ' KG';
    if ($show_ton && $kg_val >= 1000) {
        $ton = $kg_val / 1000;
        $output .= ' <span class="badge bg-secondary-subtle text-secondary">(' . number_format($ton, 2) . ' Ton)</span>';
    }
    return $output;
}

/**
 * Profit calculations
 */
function calc_profit_kg($cost_kg, $sell_kg) {
    return (float)$sell_kg - (float)$cost_kg;
}

function calc_cost_foot($cost_kg, $weight_per_foot) {
    return (float)$cost_kg * (float)$weight_per_foot;
}

function calc_profit_foot($cost_kg, $weight_per_foot, $sell_foot) {
    $cost_foot = calc_cost_foot($cost_kg, $weight_per_foot);
    return (float)$sell_foot - $cost_foot;
}

function calc_cost_piece($cost_kg, $weight_per_piece) {
    return (float)$cost_kg * (float)$weight_per_piece;
}

function calc_profit_piece($cost_kg, $weight_per_piece, $sell_piece) {
    $cost_piece = calc_cost_piece($cost_kg, $weight_per_piece);
    return (float)$sell_piece - $cost_piece;
}

function calc_margin_percent($cost, $sell) {
    if ((float)$cost <= 0) return 0;
    return (( (float)$sell - (float)$cost ) / (float)$cost) * 100;
}

/**
 * Flash notification messaging
 */
function set_flash($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function display_flash() {
    if (isset($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        echo '<div class="alert alert-' . htmlspecialchars($f['type']) . ' alert-dismissible fade show shadow-sm" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i>' . htmlspecialchars($f['message']) . '
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
              </div>';
        unset($_SESSION['flash']);
    }
}
