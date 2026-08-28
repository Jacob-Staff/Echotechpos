<?php
/**
 * ============================================================
 * EchoTech POS - Zambia Payroll Statutory Engine
 * ============================================================
 *
 * Location:
 *   /admin/actions/zambia_payroll_engine.php
 *
 * 2026 parameters used by this engine:
 *
 * PAYE - ZRA monthly bands:
 *   First K5,100                 0%
 *   Next K2,000 (to K7,100)     20%
 *   Next K2,100 (to K9,200)     30%
 *   Balance above K9,200        37%
 *
 * NAPSA:
 *   Employee: 5% of gross earnings
 *   Employer: 5% of gross earnings
 *   2026 monthly insurable earnings ceiling: K37,236
 *   Maximum employee contribution: K1,861.80
 *   Maximum employer contribution: K1,861.80
 *
 * NHIMA:
 *   Employee: 1% of basic salary
 *   Employer: 1% of basic salary
 *
 * Important:
 *   PAYE is calculated from gross/chargeable pay according to ZRA's
 *   published PAYE guidance. This engine does NOT subtract NAPSA
 *   before PAYE.
 */

declare(strict_types=1);

const ZM_PAYROLL_YEAR = 2026;

const ZM_PAYE_BAND_1 = 5100.00;
const ZM_PAYE_BAND_2 = 7100.00;
const ZM_PAYE_BAND_3 = 9200.00;

const ZM_PAYE_RATE_1 = 0.00;
const ZM_PAYE_RATE_2 = 0.20;
const ZM_PAYE_RATE_3 = 0.30;
const ZM_PAYE_RATE_4 = 0.37;

const ZM_NAPSA_RATE_EMPLOYEE = 0.05;
const ZM_NAPSA_RATE_EMPLOYER = 0.05;
const ZM_NAPSA_CEILING_2026 = 37236.00;
const ZM_NAPSA_MAX_2026 = 1861.80;

const ZM_NHIMA_RATE_EMPLOYEE = 0.01;
const ZM_NHIMA_RATE_EMPLOYER = 0.01;

/**
 * Round payroll values to 2 decimal places.
 */
function zm_money(float $value): float
{
    return round(max(0.0, $value), 2);
}

/**
 * Calculate Zambia PAYE from monthly chargeable/gross pay.
 *
 * This follows the monthly ZRA bands:
 *   0 - 5,100       0%
 *   5,100 - 7,100  20%
 *   7,100 - 9,200  30%
 *   > 9,200        37%
 */
function zm_calculate_paye(float $grossPay): float
{
    $grossPay = max(0.0, $grossPay);
    $tax = 0.0;

    if ($grossPay <= ZM_PAYE_BAND_1) {
        return 0.00;
    }

    $band2 = min($grossPay, ZM_PAYE_BAND_2) - ZM_PAYE_BAND_1;

    if ($band2 > 0) {
        $tax += $band2 * ZM_PAYE_RATE_2;
    }

    $band3 = min($grossPay, ZM_PAYE_BAND_3) - ZM_PAYE_BAND_2;

    if ($band3 > 0) {
        $tax += $band3 * ZM_PAYE_RATE_3;
    }

    $band4 = $grossPay - ZM_PAYE_BAND_3;

    if ($band4 > 0) {
        $tax += $band4 * ZM_PAYE_RATE_4;
    }

    return zm_money($tax);
}

/**
 * Calculate employee NAPSA.
 *
 * NAPSA applies to gross earnings and is capped at the annual
 * monthly insurable earnings ceiling.
 */
function zm_calculate_napsa_employee(float $grossPay): float
{
    $insurable = min(max(0.0, $grossPay), ZM_NAPSA_CEILING_2026);

    return zm_money(
        min(
            $insurable * ZM_NAPSA_RATE_EMPLOYEE,
            ZM_NAPSA_MAX_2026
        )
    );
}

/**
 * Calculate employer NAPSA.
 */
function zm_calculate_napsa_employer(float $grossPay): float
{
    $insurable = min(max(0.0, $grossPay), ZM_NAPSA_CEILING_2026);

    return zm_money(
        min(
            $insurable * ZM_NAPSA_RATE_EMPLOYER,
            ZM_NAPSA_MAX_2026
        )
    );
}

/**
 * Calculate employee NHIMA.
 *
 * NHIMA is based on basic salary, not gross earnings.
 */
function zm_calculate_nhima_employee(float $basicSalary): float
{
    return zm_money(
        max(0.0, $basicSalary) * ZM_NHIMA_RATE_EMPLOYEE
    );
}

/**
 * Calculate employer NHIMA.
 */
function zm_calculate_nhima_employer(float $basicSalary): float
{
    return zm_money(
        max(0.0, $basicSalary) * ZM_NHIMA_RATE_EMPLOYER
    );
}

/**
 * Calculate the full statutory payroll result.
 *
 * Other deductions are supplied separately because they are not
 * statutory deductions and should remain under employer control.
 */
function zm_calculate_zambia_payroll(
    float $basicSalary,
    float $allowances = 0.0,
    float $bonus = 0.0,
    float $overtime = 0.0,
    float $otherEarnings = 0.0,
    float $loan = 0.0,
    float $advance = 0.0,
    float $otherDeductions = 0.0
): array {

    $gross = zm_money(
        $basicSalary
        + $allowances
        + $bonus
        + $overtime
        + $otherEarnings
    );

    $napsaEmployee = zm_calculate_napsa_employee($gross);
    $napsaEmployer = zm_calculate_napsa_employer($gross);

    $nhimaEmployee = zm_calculate_nhima_employee($basicSalary);
    $nhimaEmployer = zm_calculate_nhima_employer($basicSalary);

    /*
     * ZRA's published PAYE guidance states that the chargeable pay
     * calculation is based on gross pay and that NAPSA is not deducted
     * before arriving at chargeable income.
     */
    $paye = zm_calculate_paye($gross);

    $statutoryEmployee =
        $paye
        + $napsaEmployee
        + $nhimaEmployee;

    $otherDeductionsTotal =
        max(0.0, $loan)
        + max(0.0, $advance)
        + max(0.0, $otherDeductions);

    $totalDeductions = zm_money(
        $statutoryEmployee + $otherDeductionsTotal
    );

    $netSalary = zm_money(
        max(0.0, $gross - $totalDeductions)
    );

    $employerStatutory =
        $napsaEmployer
        + $nhimaEmployer;

    $employerCost = zm_money(
        $gross + $employerStatutory
    );

    return [
        'gross_salary' => $gross,

        'paye' => $paye,

        'napsa' => $napsaEmployee,
        'napsa_employee' => $napsaEmployee,
        'napsa_employer' => $napsaEmployer,

        'nhima' => $nhimaEmployee,
        'nhima_employee' => $nhimaEmployee,
        'nhima_employer' => $nhimaEmployer,

        'statutory_employee' => zm_money($statutoryEmployee),
        'other_deductions' => zm_money($otherDeductionsTotal),

        'total_deductions' => $totalDeductions,
        'net_salary' => $netSalary,

        'employer_statutory' => zm_money($employerStatutory),
        'employer_cost' => $employerCost,

        'taxable_pay' => $gross,
        'napsa_insurable_earnings' => zm_money(
            min($gross, ZM_NAPSA_CEILING_2026)
        ),
    ];
}
?>
