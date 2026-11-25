-- MariaDB 10.4+ migration. Existing contribution values and payroll data are preserved.
ALTER TABLE `payroll`
  ADD COLUMN IF NOT EXISTS `sss_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `philhealth_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `pagibig_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  ADD UNIQUE INDEX IF NOT EXISTS `uq_payroll_employee_period`
    (`employee_id`, `pay_period_start`, `pay_period_end`);
