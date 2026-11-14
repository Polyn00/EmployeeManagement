<?php
require_once __DIR__ . '/auth.php';

function employee_escape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function employee_csrf_token(): string
{
    return auth_csrf_token();
}

function employee_csrf_is_valid($submittedToken): bool
{
    return auth_csrf_is_valid($submittedToken);
}

function employee_set_notice(string $type, string $message): void
{
    $_SESSION['employee_notice'] = ['type' => $type, 'message' => $message];
}

function employee_take_notice(): ?array
{
    if (!isset($_SESSION['employee_notice'])) {
        return null;
    }

    $notice = $_SESSION['employee_notice'];
    unset($_SESSION['employee_notice']);

    return $notice;
}

function employee_set_form_state(string $key, array $values, array $errors): void
{
    $_SESSION[$key] = ['values' => $values, 'errors' => $errors];
}

function employee_take_form_state(string $key, array $defaults): array
{
    if (!isset($_SESSION[$key])) {
        return ['values' => $defaults, 'errors' => []];
    }

    $state = $_SESSION[$key];
    unset($_SESSION[$key]);

    return [
        'values' => array_merge($defaults, $state['values'] ?? []),
        'errors' => $state['errors'] ?? [],
    ];
}

function employee_form_defaults(): array
{
    return [
        'employee_code' => '',
        'first_name' => '',
        'middle_name' => '',
        'last_name' => '',
        'gender' => 'Other',
        'birth_date' => '',
        'email' => '',
        'phone' => '',
        'address' => '',
        'department_id' => '',
        'position' => '',
        'hire_date' => '',
        'employment_type' => 'Regular',
        'employment_status' => 'Active',
        'basic_salary' => '0.00',
    ];
}

function employee_normalize_form(array $source): array
{
    $values = employee_form_defaults();

    foreach ($values as $key => $default) {
        if (isset($source[$key]) && is_scalar($source[$key])) {
            $values[$key] = trim((string) $source[$key]);
        }
    }

    if ($values['basic_salary'] === '') {
        $values['basic_salary'] = '0.00';
    }

    return $values;
}

function employee_string_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function employee_is_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function employee_validate_form(array $values, array $departments): array
{
    $errors = [];
    $requiredFields = [
        'employee_code' => 'Employee code',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'hire_date' => 'Hire date',
    ];
    $maxLengths = [
        'employee_code' => 20,
        'first_name' => 100,
        'middle_name' => 100,
        'last_name' => 100,
        'email' => 150,
        'phone' => 30,
        'address' => 5000,
        'position' => 100,
    ];

    foreach ($requiredFields as $field => $label) {
        if ($values[$field] === '') {
            $errors[] = $label . ' is required.';
        }
    }

    foreach ($maxLengths as $field => $maxLength) {
        if (employee_string_length($values[$field]) > $maxLength) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' must be ' . $maxLength . ' characters or fewer.';
        }
    }

    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($values['birth_date'] !== '' && !employee_is_valid_date($values['birth_date'])) {
        $errors[] = 'Enter a valid birth date.';
    }

    if ($values['hire_date'] !== '' && !employee_is_valid_date($values['hire_date'])) {
        $errors[] = 'Enter a valid hire date.';
    }

    if (!in_array($values['gender'], ['Male', 'Female', 'Other'], true)) {
        $errors[] = 'Select a valid gender.';
    }

    if (!in_array($values['employment_type'], ['Regular', 'Probationary', 'Contractual', 'Part-time'], true)) {
        $errors[] = 'Select a valid employment type.';
    }

    if (!in_array($values['employment_status'], ['Active', 'On Leave', 'Resigned', 'Terminated', 'Retired'], true)) {
        $errors[] = 'Select a valid employment status.';
    }

    if ($values['department_id'] !== ''
        && (!ctype_digit($values['department_id']) || !in_array((int) $values['department_id'], $departments, true))
    ) {
        $errors[] = 'Select a valid department.';
    }

    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $values['basic_salary'])) {
        $errors[] = 'Basic salary must be a non-negative amount with up to 10 whole digits and 2 decimal places.';
    }

    return $errors;
}

function employee_save(PDO $pdo, array $values, ?int $employeeId = null): void
{
    $parameters = [
        ':employee_code' => $values['employee_code'],
        ':first_name' => $values['first_name'],
        ':middle_name' => $values['middle_name'] !== '' ? $values['middle_name'] : null,
        ':last_name' => $values['last_name'],
        ':gender' => $values['gender'],
        ':birth_date' => $values['birth_date'] !== '' ? $values['birth_date'] : null,
        ':email' => $values['email'],
        ':phone' => $values['phone'] !== '' ? $values['phone'] : null,
        ':address' => $values['address'] !== '' ? $values['address'] : null,
        ':department_id' => $values['department_id'] !== '' ? (int) $values['department_id'] : null,
        ':position' => $values['position'] !== '' ? $values['position'] : null,
        ':hire_date' => $values['hire_date'],
        ':employment_type' => $values['employment_type'],
        ':employment_status' => $values['employment_status'],
        ':basic_salary' => $values['basic_salary'],
    ];

    if ($employeeId === null) {
        $statement = $pdo->prepare(
            'INSERT INTO employees (
                employee_code, first_name, middle_name, last_name, gender, birth_date,
                email, phone, address, department_id, position, hire_date,
                employment_type, employment_status, basic_salary
            ) VALUES (
                :employee_code, :first_name, :middle_name, :last_name, :gender, :birth_date,
                :email, :phone, :address, :department_id, :position, :hire_date,
                :employment_type, :employment_status, :basic_salary
            )'
        );
    } else {
        $parameters[':employee_id'] = $employeeId;
        $statement = $pdo->prepare(
            'UPDATE employees SET
                employee_code = :employee_code,
                first_name = :first_name,
                middle_name = :middle_name,
                last_name = :last_name,
                gender = :gender,
                birth_date = :birth_date,
                email = :email,
                phone = :phone,
                address = :address,
                department_id = :department_id,
                position = :position,
                hire_date = :hire_date,
                employment_type = :employment_type,
                employment_status = :employment_status,
                basic_salary = :basic_salary
             WHERE employee_id = :employee_id'
        );
    }

    $statement->execute($parameters);
}

function employee_form_field(
    string $name,
    string $label,
    array $values,
    string $type = 'text',
    bool $required = false,
    string $attributes = ''
): void {
    $value = $values[$name] ?? '';
    ?>
    <div class="form-field">
        <label for="<?= employee_escape($name) ?>">
            <?= employee_escape($label) ?><?= $required ? ' <span class="required-mark">*</span>' : '' ?>
        </label>
        <?php if ($type === 'textarea'): ?>
            <textarea id="<?= employee_escape($name) ?>" name="<?= employee_escape($name) ?>" rows="3" <?= $required ? 'required' : '' ?> <?= $attributes ?>><?= employee_escape($value) ?></textarea>
        <?php else: ?>
            <input id="<?= employee_escape($name) ?>" name="<?= employee_escape($name) ?>" type="<?= employee_escape($type) ?>" value="<?= employee_escape($value) ?>" <?= $required ? 'required' : '' ?> <?= $attributes ?>>
        <?php endif; ?>
    </div>
    <?php
}

function employee_form_select(
    string $name,
    string $label,
    array $values,
    array $options,
    bool $required = false
): void {
    ?>
    <div class="form-field">
        <label for="<?= employee_escape($name) ?>">
            <?= employee_escape($label) ?><?= $required ? ' <span class="required-mark">*</span>' : '' ?>
        </label>
        <select id="<?= employee_escape($name) ?>" name="<?= employee_escape($name) ?>" <?= $required ? 'required' : '' ?>>
            <?php foreach ($options as $optionValue => $optionLabel): ?>
                <option value="<?= employee_escape((string) $optionValue) ?>" <?= (string) ($values[$name] ?? '') === (string) $optionValue ? 'selected' : '' ?>>
                    <?= employee_escape($optionLabel) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php
}
