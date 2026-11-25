-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 21, 2026 at 03:46 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `management_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `attendance_status` enum('Present','Absent','Late','Half-day','On Leave') DEFAULT 'Present',
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `employee_id`, `attendance_date`, `time_in`, `time_out`, `attendance_status`, `remarks`, `created_at`) VALUES
(18, 23, '2026-11-09', '08:14:01', '09:35:25', 'Present', NULL, '2026-11-09 00:14:01'),
(19, 75, '2026-11-21', '10:44:44', NULL, 'Present', NULL, '2026-11-21 02:44:44');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `audit_id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `entity_type` varchar(64) NOT NULL,
  `entity_id` bigint(20) DEFAULT NULL,
  `details` longtext DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`audit_id`, `actor_user_id`, `action`, `entity_type`, `entity_id`, `details`, `occurred_at`) VALUES
(21, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 00:13:44'),
(22, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 00:17:35'),
(23, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-09 00:21:51'),
(24, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 00:22:07'),
(25, 25, 'performance_review_created', 'performance_review', 10, '{\"employee_id\":23,\"review_date\":\"2026-11-09\"}', '2026-11-09 00:43:06'),
(26, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 01:15:43'),
(27, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 01:16:10'),
(28, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 01:18:37'),
(29, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 01:20:28'),
(30, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 01:20:53'),
(31, 25, 'department_created', 'department', 7, '{\"department_name\":\"test\"}', '2026-11-09 01:32:03'),
(32, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 01:32:46'),
(33, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-09 01:33:09'),
(34, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-09 01:33:33'),
(35, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-09 01:33:57'),
(36, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 01:35:10'),
(37, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-09 01:36:32'),
(38, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-09 01:37:24'),
(39, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-09 01:37:36'),
(40, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 14:49:30'),
(41, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 14:50:39'),
(42, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 14:51:03'),
(43, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 15:04:18'),
(44, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:04:56'),
(45, 25, 'payroll_generated', 'payroll', 35, '{\"employee_id\":24,\"employee_name\":\"Andrea Bautista\",\"period_start\":\"2026-11-01\",\"period_end\":\"2026-11-15\",\"basic_salary\":18000}', '2026-11-20 15:28:43'),
(46, 25, 'payroll_generated', 'payroll', 36, '{\"employee_id\":23,\"employee_name\":\"Daniel Mendoza\",\"period_start\":\"2026-11-01\",\"period_end\":\"2026-11-15\",\"basic_salary\":17500}', '2026-11-20 15:28:43'),
(47, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:35:15'),
(48, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:35:33'),
(49, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:35:40'),
(50, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 15:38:25'),
(51, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 15:40:31'),
(52, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-20 15:40:43'),
(53, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-20 15:41:09'),
(54, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:41:43'),
(55, 25, 'payroll_marked_paid', 'payroll', 36, '{\"payment_status\":\"Paid\",\"payment_date\":\"2026-11-20\"}', '2026-11-20 15:41:49'),
(56, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:41:59'),
(57, 24, 'login_succeeded', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 15:42:39'),
(58, 24, 'logout', 'user', 24, '{\"role\":\"Employee\"}', '2026-11-20 15:43:46'),
(59, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:43:58'),
(60, 25, 'payroll_marked_paid', 'payroll', 35, '{\"payment_status\":\"Paid\",\"payment_date\":\"2026-11-20\"}', '2026-11-20 15:44:08'),
(61, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:51:48'),
(62, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 15:52:04'),
(63, 25, 'employee_created', 'employee', 75, '{\"changed_fields\":\"employee_record\"}', '2026-11-20 16:01:16'),
(64, 25, 'user_created', 'user', 26, '{\"username\":\"Emily.edith.22\",\"role\":\"Employee\",\"employee_id\":75}', '2026-11-20 16:03:58'),
(65, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:04:07'),
(66, 26, 'login_succeeded', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-20 16:04:21'),
(67, 26, 'logout', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-20 16:04:35'),
(68, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:04:49'),
(69, 25, 'payroll_generated', 'payroll', 37, '{\"employee_id\":75,\"employee_name\":\"Emily edith Cruz\",\"period_start\":\"2026-11-01\",\"period_end\":\"2026-11-15\",\"basic_salary\":20000}', '2026-11-20 16:05:05'),
(70, 25, 'payroll_updated', 'payroll', 37, '{\"allowances\":500,\"overtime_pay\":150,\"deductions\":250,\"gross_salary\":20650,\"net_salary\":20400}', '2026-11-20 16:07:13'),
(71, 25, 'payroll_updated', 'payroll', 37, '{\"allowances\":500,\"overtime_pay\":150,\"deductions\":250,\"gross_salary\":20650,\"net_salary\":20400}', '2026-11-20 16:07:41'),
(72, 25, 'payroll_marked_paid', 'payroll', 37, '{\"payment_status\":\"Paid\",\"payment_date\":\"2026-11-20\"}', '2026-11-20 16:07:55'),
(73, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:08:09'),
(74, 26, 'login_succeeded', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-20 16:08:20'),
(75, 26, 'logout', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-20 16:13:29'),
(76, NULL, 'login_failed', 'user', NULL, '{\"reason\":\"invalid_credentials\"}', '2026-11-20 16:13:40'),
(77, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:14:13'),
(78, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:15:26'),
(79, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:18:23'),
(80, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-20 16:18:44'),
(81, 26, 'login_succeeded', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-20 16:19:07'),
(82, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-21 02:43:53'),
(83, 25, 'logout', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-21 02:44:18'),
(84, 26, 'login_succeeded', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-21 02:44:35'),
(85, 26, 'logout', 'user', 26, '{\"role\":\"Employee\"}', '2026-11-21 02:44:54'),
(86, 25, 'login_succeeded', 'user', 25, '{\"role\":\"Admin\"}', '2026-11-21 02:45:07');

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `description`, `created_at`) VALUES
(1, 'Human Resources', 'Manages employee records and HR operations', '2026-11-08 10:08:12'),
(2, 'IT Department', 'Manages technology and technical support', '2026-11-08 10:08:12'),
(3, 'Finance', 'Manages financial operations', '2026-11-08 10:08:12'),
(4, 'Marketing', 'Manages marketing activities', '2026-11-08 10:08:12'),
(7, 'test', NULL, '2026-11-09 01:32:03');

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(11) NOT NULL,
  `employee_code` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `gender` enum('Male','Female','Other') DEFAULT 'Other',
  `birth_date` date DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `department_id` int(11) DEFAULT NULL,
  `position` varchar(100) DEFAULT NULL,
  `hire_date` date NOT NULL,
  `employment_type` enum('Regular','Probationary','Contractual','Part-time') DEFAULT 'Regular',
  `employment_status` enum('Active','On Leave','Resigned','Terminated','Retired') DEFAULT 'Active',
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `employee_code`, `first_name`, `middle_name`, `last_name`, `gender`, `birth_date`, `email`, `phone`, `address`, `department_id`, `position`, `hire_date`, `employment_type`, `employment_status`, `basic_salary`, `created_at`, `updated_at`) VALUES
(23, 'EMP-2026-006', 'Daniel', 'Jose', 'Mendoza', 'Male', '1987-07-30', 'daniel.mendoza@gmail.com', '09170000006', 'Sample Address, Cebu City', 2, 'ICT Coordinator', '2021-08-01', 'Regular', 'Active', 35000.00, '2026-11-08 12:08:36', '2026-11-08 12:14:03'),
(24, 'EMP-2026-009', 'Andrea', 'Grace', 'Bautista', 'Female', '1986-12-03', 'andrea.bautista@gmail.com', '09170000009', 'Sample Address, Cebu City', 3, 'Accounting Officer', '2021-01-04', 'Regular', 'Active', 36000.00, '2026-11-08 12:08:36', '2026-11-08 12:14:03'),
(75, 'EMP-2026-008', 'Emily edith', 'Daquiado', 'Cruz', 'Female', '2007-11-30', 'emily@gmail.com', '09821764253', 'Gabi Cordova, Cebu City', 4, 'Financial accountant', '2026-11-21', 'Regular', 'Active', 40000.00, '2026-11-20 16:01:16', '2026-11-20 16:01:16');

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `leave_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `leave_type` enum('Vacation','Sick','Emergency','Maternity','Paternity','Other') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text DEFAULT NULL,
  `leave_status` enum('Pending','Approved','Rejected') DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approval_date` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `payroll_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `pay_period_start` date NOT NULL,
  `pay_period_end` date NOT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `overtime_pay` decimal(12,2) DEFAULT 0.00,
  `deductions` decimal(12,2) DEFAULT 0.00,
  `sss_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  `philhealth_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pagibig_deduction` decimal(10,2) NOT NULL DEFAULT 0.00,
  `gross_salary` decimal(12,2) DEFAULT 0.00,
  `net_salary` decimal(12,2) DEFAULT 0.00,
  `payment_status` enum('Pending','Paid') DEFAULT 'Pending',
  `payment_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`payroll_id`, `employee_id`, `pay_period_start`, `pay_period_end`, `basic_salary`, `allowances`, `overtime_pay`, `deductions`, `sss_deduction`, `philhealth_deduction`, `pagibig_deduction`, `gross_salary`, `net_salary`, `payment_status`, `payment_date`, `created_at`) VALUES
(35, 24, '2026-11-01', '2026-11-15', 18000.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 18000.00, 18000.00, 'Paid', '2026-11-20', '2026-11-20 15:28:43'),
(36, 23, '2026-11-01', '2026-11-15', 17500.00, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 17500.00, 17500.00, 'Paid', '2026-11-20', '2026-11-20 15:28:43'),
(37, 75, '2026-11-01', '2026-11-15', 20000.00, 500.00, 150.00, 250.00, 0.00, 0.00, 0.00, 20650.00, 20400.00, 'Paid', '2026-11-21', '2026-11-20 16:05:05');

-- --------------------------------------------------------

--
-- Table structure for table `performance_reviews`
--

CREATE TABLE `performance_reviews` (
  `review_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `reviewer_id` int(11) DEFAULT NULL,
  `review_date` date NOT NULL,
  `rating` decimal(3,1) NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `performance_reviews`
--

INSERT INTO `performance_reviews` (`review_id`, `employee_id`, `reviewer_id`, `review_date`, `rating`, `comments`, `created_at`) VALUES
(10, 23, 25, '2026-11-09', 2.9, 'Sometimes Late from work.', '2026-11-09 00:43:06');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `employee_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Admin','HR','Employee') DEFAULT 'Employee',
  `account_status` enum('Active','Inactive') DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `employee_id`, `username`, `password`, `role`, `account_status`, `created_at`) VALUES
(24, 23, 'daniel.mendoza', '$2y$10$cb/GWm6I3R9nyKlgv9H2COjmmptG4VRA2i.Y1buuTT3VXHJ8LIuza', 'Employee', 'Active', '2026-11-08 12:17:55'),
(25, NULL, 'admin', '$2y$10$MfQb8L6RcRwcKBFiKkDCUerY4ZJ8AWreWK2BLdGnSB8EWaqPF6JKG', 'Admin', 'Active', '2026-11-09 00:21:14'),
(26, 75, 'Emily.edith.22', '$2y$10$yGOE8DAirYrZ51.PQ5LSCOKj0EChkXXnJKAYWHZNLWzgqpW0sVz4u', 'Employee', 'Active', '2026-11-20 16:03:58');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD UNIQUE KEY `unique_employee_attendance` (`employee_id`,`attendance_date`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_audit_actor` (`actor_user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_occurred_at` (`occurred_at`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `department_name` (`department_name`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `employee_code` (`employee_code`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `department_id` (`department_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `approved_by` (`approved_by`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`payroll_id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `uq_payroll_employee_period` (`employee_id`, `pay_period_start`, `pay_period_end`);

--
-- Indexes for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `reviewer_id` (`reviewer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `employee_id` (`employee_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `audit_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=87;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=76;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `payroll_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_actor` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `leave_requests_ibfk_2` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `performance_reviews`
--
ALTER TABLE `performance_reviews`
  ADD CONSTRAINT `performance_reviews_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `performance_reviews_ibfk_2` FOREIGN KEY (`reviewer_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
