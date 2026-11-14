<?php
require_once __DIR__ . '/auth.php';

function department_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function department_set_notice(string $type, string $message): void
{
    $_SESSION['department_notice'] = ['type' => $type, 'message' => $message];
}

function department_take_notice(): ?array
{
    if (!isset($_SESSION['department_notice']) || !is_array($_SESSION['department_notice'])) {
        return null;
    }

    $notice = $_SESSION['department_notice'];
    unset($_SESSION['department_notice']);

    return $notice;
}

function department_set_form_state(string $key, array $values, array $errors): void
{
    $_SESSION[$key] = ['values' => $values, 'errors' => $errors];
}

function department_take_form_state(string $key, array $defaults): array
{
    if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
        return ['values' => $defaults, 'errors' => []];
    }

    $state = $_SESSION[$key];
    unset($_SESSION[$key]);

    return [
        'values' => array_merge($defaults, is_array($state['values'] ?? null) ? $state['values'] : []),
        'errors' => is_array($state['errors'] ?? null) ? $state['errors'] : [],
    ];
}

function department_form_values(array $source): array
{
    return [
        'department_name' => isset($source['department_name']) && is_string($source['department_name'])
            ? trim($source['department_name'])
            : '',
        'description' => isset($source['description']) && is_string($source['description'])
            ? trim($source['description'])
            : '',
    ];
}

function department_validate(array $values): array
{
    $errors = [];
    $nameLength = function_exists('mb_strlen')
        ? mb_strlen($values['department_name'], 'UTF-8')
        : strlen($values['department_name']);
    if ($values['department_name'] === '') {
        $errors[] = 'Department name is required.';
    } elseif ($nameLength > 100) {
        $errors[] = 'Department name must be 100 characters or fewer.';
    }

    if (strlen($values['description']) > 65535) {
        $errors[] = 'Description is too long.';
    }

    return $errors;
}

function department_save(PDO $pdo, array $values, ?int $departmentId = null): void
{
    if ($departmentId === null) {
        $statement = $pdo->prepare(
            'INSERT INTO departments (department_name, description)
             VALUES (:department_name, :description)'
        );
        $statement->execute([
            ':department_name' => $values['department_name'],
            ':description' => $values['description'] !== '' ? $values['description'] : null,
        ]);
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE departments
         SET department_name = :department_name, description = :description
         WHERE department_id = :department_id'
    );
    $statement->execute([
        ':department_name' => $values['department_name'],
        ':description' => $values['description'] !== '' ? $values['description'] : null,
        ':department_id' => $departmentId,
    ]);
}
