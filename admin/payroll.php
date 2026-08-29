<?php
/**
 * EchoTech POS - Payroll browser entry point
 * URL: /admin/payroll.php
 *
 * The complete Payroll module is kept in:
 * /admin/actions/payroll.php
 *
 * IMPORTANT: do not start a second session here. The Payroll controller
 * owns session initialization and authentication.
 */
declare(strict_types=1);

require_once __DIR__ . '/actions/payroll.php';
