<?php
/**
 * ============================================================
 * EchoTech POS
 * Payroll Browser Entry Point
 * ============================================================
 *
 * URL:
 *     /admin/payroll.php
 *
 * The complete Payroll module is handled by:
 *     /admin/actions/payroll.php
 *
 * IMPORTANT:
 * - Do NOT duplicate authentication here.
 * - Do NOT call require_admin() here.
 * - Human Resource must be allowed to enter Payroll.
 * - Final payroll approval is handled inside the Payroll controller.
 * - Admin and Human Resource use their respective sidebars.
 * ============================================================
 */

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Load the actual Payroll controller
|--------------------------------------------------------------------------
|
| The controller is responsible for:
|
|  - session initialization
|  - authentication
|  - role verification
|  - pharmacy verification
|  - Admin / HR permissions
|  - payroll calculations
|  - employee payroll
|  - loans and advances
|  - deductions
|  - payslips
|  - final Admin approval
|
*/

require_once __DIR__ . '/actions/payroll.php';

exit;
