<?php
declare(strict_types=1);

function esc($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function setFlash(string $type, string $message): void {
    if (!session_id()) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): array {
    if (!session_id()) session_start();
    $flash = $_SESSION['flash'] ?? ['type' => '', 'message' => ''];
    unset($_SESSION['flash']);
    return $flash;
}

function currentUser(): ?array {
    if (!session_id()) session_start();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): void {
    if (!session_id()) session_start();
    if (empty($_SESSION['user'])) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin(): void {
    $user = currentUser();
    if (!$user || ($user['role'] ?? '') !== 'admin') {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied';
        exit;
    }
}

function old($key, $default = '') {
    return esc($_POST[$key] ?? $default);
}

function formatCurrency($value, string $currency = 'RWF'): string {
    return $currency . ' ' . number_format((float)$value, 2);
}

function buildActiveClass(string $page, string $current): string {
    return $page === $current ? 'active' : '';
}
