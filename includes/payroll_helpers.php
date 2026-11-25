<?php

function payroll_amount_to_cents($amount): ?int
{
    $value = trim((string) $amount);
    if (!preg_match('/\A(\d{1,10})(?:\.(\d{1,2}))?\z/D', $value, $parts)) {
        return null;
    }

    return ((int) $parts[1] * 100)
        + (int) str_pad($parts[2] ?? '', 2, '0');
}

function payroll_cents_to_amount(int $cents): string
{
    $absoluteCents = abs($cents);
    return ($cents < 0 ? '-' : '')
        . intdiv($absoluteCents, 100)
        . '.' . str_pad((string) ($absoluteCents % 100), 2, '0');
}

function payroll_money($amount): string
{
    $value = trim((string) $amount);
    $negative = str_starts_with($value, '-');
    if ($negative) {
        $value = substr($value, 1);
    }

    $cents = payroll_amount_to_cents($value);
    if ($cents === null) {
        $cents = 0;
    }

    return ($negative ? '-' : '') . '₱'
        . number_format(intdiv($cents, 100), 0, '.', ',')
        . '.' . str_pad((string) ($cents % 100), 2, '0');
}

function payroll_statutory_monthly_deductions(int $monthlySalaryCents): array
{
    // SSS Circular 2024-006 sets the current MSC range at ₱5,000–₱35,000 in ₱500 steps.
    $mscCents = min(
        3500000,
        max(500000, intdiv($monthlySalaryCents + 25000, 50000) * 50000)
    );
    $sssCents = intdiv($mscCents * 5 + 50, 100);

    $philhealthBaseCents = min(10000000, max(1000000, $monthlySalaryCents));
    $philhealthCents = intdiv($philhealthBaseCents * 25 + 500, 1000);

    $pagibigBaseCents = min(1000000, $monthlySalaryCents);
    $pagibigRate = $monthlySalaryCents > 150000 ? 2 : 1;
    $pagibigCents = intdiv($pagibigBaseCents * $pagibigRate + 50, 100);

    return [
        'sss' => $sssCents,
        'philhealth' => $philhealthCents,
        'pagibig' => $pagibigCents,
    ];
}

function payroll_cutoff_share(int $monthlyCents, string $cutoff): int
{
    if (!in_array($cutoff, ['first', 'second'], true)) {
        throw new InvalidArgumentException('Invalid payroll cutoff.');
    }

    $firstCutoffCents = intdiv($monthlyCents, 2);
    return $cutoff === 'first'
        ? $firstCutoffCents
        : $monthlyCents - $firstCutoffCents;
}

function payroll_total_deductions_cents(array $record): int
{
    $total = 0;
    foreach (['sss_deduction', 'philhealth_deduction', 'pagibig_deduction', 'deductions'] as $field) {
        $cents = payroll_amount_to_cents($record[$field] ?? '0.00');
        if ($cents === null) {
            throw new UnexpectedValueException('Invalid payroll deduction amount.');
        }
        $total += $cents;
    }

    return $total;
}

function payroll_calculate_totals(
    int $basicSalaryCents,
    int $allowancesCents,
    int $overtimeCents,
    int $otherDeductionsCents,
    int $sssCents,
    int $philhealthCents,
    int $pagibigCents
): array {
    $grossCents = $basicSalaryCents + $allowancesCents + $overtimeCents;
    $totalDeductionsCents = $otherDeductionsCents
        + $sssCents
        + $philhealthCents
        + $pagibigCents;
    if ($grossCents > 999999999999 || $totalDeductionsCents > 999999999999) {
        throw new OverflowException('Payroll totals exceed the supported amount.');
    }

    return [
        'gross' => $grossCents,
        'total_deductions' => $totalDeductionsCents,
        'net' => $grossCents - $totalDeductionsCents,
    ];
}
