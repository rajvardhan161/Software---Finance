<?php
session_start();

$currencySymbol = '$';

$userName = isset($_SESSION['firstName']) ? htmlspecialchars($_SESSION['firstName']) : "User";


$servername = "localhost";
$dbUsername = "root";
$dbPassword = "";
$dbname = "login";
$conn = null;
$dbErrorMessage = null;

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbUsername, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {

    $dbErrorMessage = "Database connection failed: Could not connect to the database. Please check the configuration.";
    error_log("DB Connection Error (reports.php): " . $e->getMessage());

}


function formatCurrency($amount, $symbol = '$') {

    $amount = floatval($amount);

    $formattedAmount = number_format($amount, 2);

    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . $formattedAmount;
}



$today = date('Y-m-d');

// --- Date Period Selection ---
$selectedPeriod = filter_input(INPUT_GET, 'period', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'this_month';
$customStartDateInput = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_SPECIAL_CHARS);
$customEndDateInput = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_SPECIAL_CHARS);

$reportStartDate = '';
$reportEndDate = $today;
$reportPeriodLabel = 'This Month'; // Default Label


switch ($selectedPeriod) {
    case 'last_month':
        $reportStartDate = date('Y-m-01', strtotime('first day of last month'));
        $reportEndDate = date('Y-m-t', strtotime('last day of last month'));
        $reportPeriodLabel = 'Last Month';
        break;
    case 'last_90_days':
        $reportStartDate = date('Y-m-d', strtotime('-89 days'));
        $reportEndDate = $today;
        $reportPeriodLabel = 'Last 90 Days';
        break;
    case 'this_year':
        $reportStartDate = date('Y-01-01');
        $reportEndDate = $today;
        $reportPeriodLabel = 'This Year';
        break;
    case 'all_time':
        $reportStartDate = null; // No start date filter
        $reportEndDate = null;  // No end date filter
        $reportPeriodLabel = 'All Time';
        break;
    case 'custom':
        // Validate custom dates
        $d1 = DateTime::createFromFormat('Y-m-d', $customStartDateInput);
        $d2 = DateTime::createFromFormat('Y-m-d', $customEndDateInput);

        $defaultStartDate = date('Y-m-01'); // Default start if invalid
        $defaultEndDate = $today;          // Default end if invalid
        $reportStartDate = ($d1 && $d1->format('Y-m-d') === $customStartDateInput) ? $customStartDateInput : $defaultStartDate;
        $reportEndDate = ($d2 && $d2->format('Y-m-d') === $customEndDateInput) ? $customEndDateInput : $defaultEndDate;

        // Swap if start date is after end date
        if ($reportStartDate && $reportEndDate && $reportStartDate > $reportEndDate) {
            list($reportStartDate, $reportEndDate) = [$reportEndDate, $reportStartDate];
        }
        // Update input values to reflect validated/corrected dates
        $customStartDateInput = $reportStartDate;
        $customEndDateInput = $reportEndDate;
        $reportPeriodLabel = htmlspecialchars($reportStartDate) . ' to ' . htmlspecialchars($reportEndDate);
        break;
    case 'this_month':
    default:
        $reportStartDate = date('Y-m-01'); // First day of current month
        $reportEndDate = $today;           // Today
        $reportPeriodLabel = 'This Month (' . date('M Y') . ')';
        break;
}

// --- Initialize Report Variables ---
$fetchedTransactions = [];
$reportTotalIncome = 0;
$reportTotalExpenses = 0;
$reportNetBalance = 0;
$reportIncomeSources = []; // Key: Category, Value: Total Amount
$reportExpenseCategories = []; // Key: Category, Value: Total Amount
$budgetBreakdownReport = []; // Key: Category, Value: ['allocated', 'spent', 'remaining', 'usage_percent']
$budgetAllocatedTotal = 0;
$budgetSpentTotalPeriod = 0; // Total spent across budgeted categories *within the period*
$fetchErrorMessage = null;


$incomeChartLabels = [];
$incomeChartData = [];
$expenseChartLabels = [];
$expenseChartData = [];

// --- Database Queries (only if connection successful) ---
if ($conn && !$dbErrorMessage) {
    try {
        // --- Aggregate Income by Category for the selected period ---
        $sqlIncome = "SELECT income_category, SUM(income_amount) as total_amount FROM incomes";
        $incomeParams = [];
        $incomeWhereConditions = [];
        if ($reportStartDate) {
            $incomeWhereConditions[] = "income_date >= :start_date";
            $incomeParams[':start_date'] = $reportStartDate;
        }
        if ($reportEndDate) {
            $incomeWhereConditions[] = "income_date <= :end_date";
            $incomeParams[':end_date'] = $reportEndDate;
        }
        if (!empty($incomeWhereConditions)) {
            $sqlIncome .= " WHERE " . implode(' AND ', $incomeWhereConditions);
        }
        $sqlIncome .= " GROUP BY income_category ORDER BY total_amount DESC";

        $stmtIncome = $conn->prepare($sqlIncome);
        $stmtIncome->execute($incomeParams);
        $periodIncomeAggregates = $stmtIncome->fetchAll(PDO::FETCH_ASSOC);

        foreach ($periodIncomeAggregates as $agg) {
            $category = isset($agg['income_category']) && !empty(trim($agg['income_category'])) ? trim($agg['income_category']) : 'Uncategorized';
            $amount = floatval($agg['total_amount'] ?? 0);
            $reportTotalIncome += $amount;
            $reportIncomeSources[$category] = ($reportIncomeSources[$category] ?? 0) + $amount; // Add to existing if category repeats (shouldn't with GROUP BY)
            $incomeChartLabels[] = $category;
            $incomeChartData[] = $amount;
        }

        // --- Aggregate Expenses by Category for the selected period ---
        $sqlExpenses = "SELECT expense_category, SUM(expense_amount) as total_amount FROM expenses";
        $expenseParams = []; // Use separate params for expenses query
        $expenseWhereConditions = [];
        if ($reportStartDate) {
            $expenseWhereConditions[] = "expense_date >= :start_date_exp"; // Use different placeholder names
            $expenseParams[':start_date_exp'] = $reportStartDate;
        }
        if ($reportEndDate) {
            $expenseWhereConditions[] = "expense_date <= :end_date_exp";
            $expenseParams[':end_date_exp'] = $reportEndDate;
        }
        if (!empty($expenseWhereConditions)) {
            $sqlExpenses .= " WHERE " . implode(' AND ', $expenseWhereConditions);
        }
        $sqlExpenses .= " GROUP BY expense_category ORDER BY total_amount DESC";

        $stmtExpenses = $conn->prepare($sqlExpenses);
        $stmtExpenses->execute($expenseParams);
        $periodExpenseAggregates = $stmtExpenses->fetchAll(PDO::FETCH_ASSOC);

        foreach ($periodExpenseAggregates as $agg) {
            $category = isset($agg['expense_category']) && !empty(trim($agg['expense_category'])) ? trim($agg['expense_category']) : 'Uncategorized';
            $amount = floatval($agg['total_amount'] ?? 0);
            $reportTotalExpenses += $amount;
            // Store the aggregated expense for this category *within the period*
            $reportExpenseCategories[$category] = ($reportExpenseCategories[$category] ?? 0) + $amount;
            $expenseChartLabels[] = $category;
            $expenseChartData[] = $amount;
        }

        // --- Calculate Net Balance for the period ---
        $reportNetBalance = $reportTotalIncome - $reportTotalExpenses;

        // --- Fetch Individual Transactions for the Log (Income & Expenses) ---
        $combinedParams = [];
        $incomeWhereClauseForLog = "";
        $expenseWhereClauseForLog = "";

        // Build WHERE clauses for the UNION query, ensuring unique parameter names
        if (!empty($incomeWhereConditions)) {
             $incomeLogPlaceholders = [];
             foreach ($incomeWhereConditions as $cond) {
                 if (strpos($cond, ':start_date') !== false) {
                     $incomeLogPlaceholders[] = str_replace(':start_date', ':start_date_income_log', $cond);
                     $combinedParams[':start_date_income_log'] = $incomeParams[':start_date'];
                 } elseif (strpos($cond, ':end_date') !== false) {
                     $incomeLogPlaceholders[] = str_replace(':end_date', ':end_date_income_log', $cond);
                     $combinedParams[':end_date_income_log'] = $incomeParams[':end_date'];
                 } else { $incomeLogPlaceholders[] = $cond; }
             }
             $incomeWhereClauseForLog = " WHERE " . implode(' AND ', $incomeLogPlaceholders);
         }
        if (!empty($expenseWhereConditions)) {
            $expenseLogPlaceholders = [];
            foreach ($expenseWhereConditions as $cond) {
                 if (strpos($cond, ':start_date_exp') !== false) {
                     $expenseLogPlaceholders[] = str_replace(':start_date_exp', ':start_date_expense_log', $cond);
                     $combinedParams[':start_date_expense_log'] = $expenseParams[':start_date_exp'];
                 } elseif (strpos($cond, ':end_date_exp') !== false) {
                     $expenseLogPlaceholders[] = str_replace(':end_date_exp', ':end_date_expense_log', $cond);
                     $combinedParams[':end_date_expense_log'] = $expenseParams[':end_date_exp'];
                 } else { $expenseLogPlaceholders[] = $cond; }
             }
             $expenseWhereClauseForLog = " WHERE " . implode(' AND ', $expenseLogPlaceholders);
        }

        $sqlIncomeLog = "SELECT id, income_date as date, income_description as description, income_category as category, received_method as paymentMethod, income_amount as amount, 'income' as type FROM incomes" . $incomeWhereClauseForLog;
        $sqlExpenseLog = "SELECT id, expense_date as date, expense_description as description, expense_category as category, payment_method as paymentMethod, expense_amount as amount, 'expense' as type FROM expenses" . $expenseWhereClauseForLog;

        $sqlCombinedLog = "($sqlIncomeLog) UNION ALL ($sqlExpenseLog) ORDER BY date DESC, id DESC";
        $stmtCombinedLog = $conn->prepare($sqlCombinedLog);
        $stmtCombinedLog->execute($combinedParams); // Use the combined parameters
        $fetchedTransactions = $stmtCombinedLog->fetchAll(PDO::FETCH_ASSOC);


        // --- Budget Breakdown Calculation ---
        // Fetch all defined budget categories and their allocated amounts (typically fixed, e.g., monthly)
        $stmtBudget = $conn->query("SELECT category, allocated FROM budget_breakdown ORDER BY category ASC");
        $budgetAllocationsDB = $stmtBudget->fetchAll(PDO::FETCH_ASSOC);

        foreach ($budgetAllocationsDB as $budget) {
            $category = $budget['category'];
            $allocated = floatval($budget['allocated']);
            $budgetAllocatedTotal += $allocated; // Sum total allocated budget

            // Get the spending for this specific category *within the selected period*
            // This uses the $reportExpenseCategories array which was already filtered by date.
            $spentInCategoryPeriod = $reportExpenseCategories[$category] ?? 0;

            // Add this period's spending for the category to the overall spent total for the period
            $budgetSpentTotalPeriod += $spentInCategoryPeriod;

            // Calculate remaining budget and usage percentage
            $remaining = $allocated - $spentInCategoryPeriod;
            $usage_percent = ($allocated > 0) ? round(($spentInCategoryPeriod / $allocated) * 100) : 0;

            // Store the results for display
            $budgetBreakdownReport[$category] = [
                'allocated' => $allocated,
                'spent' => $spentInCategoryPeriod, // This is the spend *within the selected period*
                'remaining' => $remaining,
                'usage_percent' => $usage_percent
            ];
        }

    } catch (PDOException $e) {
        $fetchErrorMessage = "Error fetching report data: Could not retrieve information from the database. Please try again later.";
        error_log("Report Data Fetch Error (reports.php): " . $e->getMessage());

        // Reset data arrays on error
        $fetchedTransactions = []; $reportIncomeSources = []; $reportExpenseCategories = []; $budgetBreakdownReport = [];
        $reportTotalIncome = 0; $reportTotalExpenses = 0; $reportNetBalance = 0; $budgetAllocatedTotal = 0; $budgetSpentTotalPeriod = 0;
        $incomeChartLabels = []; $incomeChartData = []; $expenseChartLabels = []; $expenseChartData = [];
    }
} elseif (!$conn && !$dbErrorMessage) {
     // Case where $conn is null but no specific PDO exception was caught earlier
     $fetchErrorMessage = "Database connection is not available.";
}

// --- Prepare data for JavaScript charts ---
$incomeChartLabelsJSON = json_encode($incomeChartLabels);
$incomeChartDataJSON = json_encode($incomeChartData);
$expenseChartLabelsJSON = json_encode($expenseChartLabels);
$expenseChartDataJSON = json_encode($expenseChartData);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Report - FinDash</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <style>
         /* --- START: THEME VARIABLES & SETUP --- */
         :root {
             /* Light Theme (Default) */
             --primary-color-light: #60a5fa;
             --primary-color: #3b82f6;
             --primary-color-dark: #2563eb;
             --primary-gradient: linear-gradient(140deg, var(--primary-color-light), var(--primary-color));
             --primary-gradient-hover: linear-gradient(140deg, var(--primary-color), var(--primary-color-dark));
             --input-focus-border: var(--primary-color);
             --input-focus-shadow: rgba(59, 130, 246, 0.2);

             --success-color-light: #4ade80;
             --success-color: #22c55e;
             --success-color-dark: #16a34a;
             --success-gradient: linear-gradient(140deg, var(--success-color-light), var(--success-color));
             --success-gradient-hover: linear-gradient(140deg, var(--success-color), var(--success-color-dark));
             --success-text: #14532d;
             --success-bg: #f0fdf4;
             --success-border: #bbf7d0;

             --secondary-color: #6b7280; --secondary-hover: #4b5563;
             --warning-color: #f59e0b; --warning-bg: #fffbeb; --warning-border: #fde68a; --warning-text: #b45309;
             --danger-color: #ef4444; --danger-bg: #fef2f2; --danger-border: #fecaca; --danger-text: #991b1b;
             --info-color: #38bdf8; --info-bg: #ecfeff; --info-border: #bae6fd; --info-text: #0369a1;
             --progress-bar-color: linear-gradient(90deg, #38bdf8, #3b82f6);
             --progress-bar-complete-color: linear-gradient(90deg, #4ade80, #22c55e);
             --progress-bar-warning-color: linear-gradient(90deg, #fcd34d, var(--warning-color));
             --progress-bar-danger-color: linear-gradient(90deg, #fca5a5, var(--danger-color));

             --body-bg: #f0f4f8; /* Light background */
             --body-bg-gradient: linear-gradient(180deg, #e0f2fe 0%, var(--body-bg) 25%, var(--body-bg) 75%, #e0f2fe 100%);
             --glass-bg: rgba(255, 255, 255, 0.65); /* Light glass */
             --glass-bg-modal: rgba(255, 255, 255, 0.9);
             --glass-bg-card: rgba(255, 255, 255, 0.78);
             --glass-border-color: rgba(255, 255, 255, 0.35); /* Light borders */
             --glass-border-color-soft: rgba(229, 231, 235, 0.6);
             --input-border-color: #d1d5db;
             --input-bg-color: rgba(255, 255, 255, 0.7);
             --input-disabled-bg: rgba(243, 244, 246, 0.7);

             --text-color: #1f2937; /* Dark text on light bg */
             --text-muted: #6b7280;
             --text-heading: #111827;
             --header-bg: linear-gradient(135deg, #374151, #1f2937); /* Header stays dark */
             --header-color: #f9fafb; /* Header text stays light */

             --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
             --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
             --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
             --shadow-glass: 0 10px 35px rgba(31, 38, 135, 0.1);
             --shadow-card-hover: 0 12px 40px rgba(59, 130, 246, 0.15);

             --chart-tooltip-bg: rgba(0, 0, 0, 0.85);
             --chart-tooltip-text: #ffffff;
             --chart-legend-text: #6b7280;
             --table-hover-bg: rgba(59, 130, 246, 0.06);
             --table-header-bg: rgba(255, 255, 255, 0.2);
             --table-footer-bg: rgba(255, 255, 255, 0.2);
             --progress-track-bg: rgba(209, 213, 219, 0.5);
             --theme-toggle-bg: #e5e7eb;
             --theme-toggle-icon: var(--secondary-color);
             --theme-toggle-bg-hover: #d1d5db;

             /* Non-color Variables */
             --font-family: 'Poppins', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
             --base-font-size: 16px;
             --border-radius: 0.75rem;
             --border-radius-lg: 1rem;
             --border-radius-input: 0.6rem;
             --border-radius-sm: 0.4rem;
             --transition-speed: 0.25s;
             --transition-speed-fast: 0.15s;
             --transition-easing: cubic-bezier(0.4, 0, 0.2, 1);
             --input-padding-x: 1.2rem;
             --input-padding-y: 1rem;
             --input-icon-size: 1rem;
             --input-icon-left-padding: calc(var(--input-padding-x) + var(--input-icon-size) + 0.8rem);
             --label-default-left: var(--input-icon-left-padding);
             --currency-symbol-width: 3.2rem;
             --input-padding-x-sm: 0.8rem;
             --input-padding-y-sm: 0.6rem;
             --label-floated-font-size: 0.75rem;
             --label-padding-x: 0.3rem;
         }

         .dark-theme {
             /* Dark Theme Overrides */
             --primary-color-light: #539afc;
             --primary-color: #4a8fff;
             --primary-color-dark: #3a7be0;
             --input-focus-shadow: rgba(74, 143, 255, 0.25);

             --success-color-light: #59e69d;
             --success-color: #33d17a;
             --success-color-dark: #27a864;
             --success-text: #bbf7d0; /* Lighter text for dark bg */
             --success-bg: rgba(16, 185, 129, 0.15); /* Darker success bg */
             --success-border: rgba(52, 211, 153, 0.4);

             --secondary-color: #9ca3af; --secondary-hover: #e5e7eb;
             --warning-color: #facc15; --warning-bg: rgba(245, 158, 11, 0.15); --warning-border: rgba(250, 204, 21, 0.4); --warning-text: #fde047;
             --danger-color: #f87171; --danger-bg: rgba(239, 68, 68, 0.15); --danger-border: rgba(248, 113, 113, 0.4); --danger-text: #fecaca;
             --info-color: #67e8f9; --info-bg: rgba(6, 182, 212, 0.15); --info-border: rgba(103, 232, 249, 0.4); --info-text: #a5f3fc;

             --body-bg: #111827; /* Dark background */
             --body-bg-gradient: linear-gradient(180deg, #1f2937 0%, var(--body-bg) 25%, var(--body-bg) 75%, #1f2937 100%);
             --glass-bg: rgba(31, 41, 55, 0.6); /* Dark glass */
             --glass-bg-modal: rgba(31, 41, 55, 0.85);
             --glass-bg-card: rgba(31, 41, 55, 0.75); /* Darker card bg */
             --glass-border-color: rgba(75, 85, 99, 0.5); /* Lighter borders */
             --glass-border-color-soft: rgba(55, 65, 81, 0.7);
             --input-border-color: #4b5563;
             --input-bg-color: rgba(55, 65, 81, 0.6);
             --input-disabled-bg: rgba(31, 41, 55, 0.5);

             --text-color: #d1d5db; /* Light text on dark bg */
             --text-muted: #9ca3af;
             --text-heading: #f3f4f6;
             /* Header styles remain the same or can be adjusted if needed */

             --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.15); /* Darker shadows */
             --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.2), 0 2px 4px -2px rgba(0, 0, 0, 0.18);
             --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.25), 0 4px 6px -4px rgba(0, 0, 0, 0.2);
             --shadow-glass: 0 10px 35px rgba(0, 0, 0, 0.3);
             --shadow-card-hover: 0 12px 40px rgba(74, 143, 255, 0.15);

             --chart-tooltip-bg: rgba(229, 231, 235, 0.9); /* Lighter tooltip */
             --chart-tooltip-text: #1f2937; /* Darker text in tooltip */
             --chart-legend-text: #9ca3af;
             --table-hover-bg: rgba(59, 130, 246, 0.12); /* Slightly different hover */
             --table-header-bg: rgba(55, 65, 81, 0.4); /* Darker table header */
             --table-footer-bg: rgba(55, 65, 81, 0.4);
             --progress-track-bg: rgba(75, 85, 99, 0.4);
             --theme-toggle-bg: #374151;
             --theme-toggle-icon: #9ca3af;
             --theme-toggle-bg-hover: #4b5563;
         }
         /* --- END: THEME VARIABLES & SETUP --- */

         /* --- General Styles (Using Variables) --- */
         * { box-sizing: border-box; margin: 0; padding: 0; }
         html { font-size: var(--base-font-size); scroll-behavior: smooth; }
         body {
             font-family: var(--font-family);
             background-color: var(--body-bg); /* Use variable */
             background-image: var(--body-bg-gradient); /* Use variable */
             background-attachment: fixed;
             color: var(--text-color); /* Use variable */
             line-height: 1.7;
             -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale;
             display: flex; flex-direction: column; min-height: 100vh;
             transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease; /* Smooth transition */
         }

         /* --- Header (Added Theme Toggle) --- */
         .header {
             background: var(--header-bg); /* Use variable */
             color: var(--header-color); /* Use variable */
             padding: 1rem 2.5rem;
             display: flex; justify-content: space-between; align-items: center;
             box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); flex-wrap: wrap;
             position: sticky; top: 0; z-index: 1050; border-bottom: 1px solid rgba(255, 255, 255, 0.1);
         }
         .header .logo { font-size: 1.7rem; font-weight: 700; margin: 0; letter-spacing: -0.8px; color: #fff; text-decoration: none;}
         .user-info { margin-left: auto; text-align: right; font-size: 0.95rem; display: flex; align-items: center; gap: 1rem; }
         .user-info span { opacity: 0.9; font-weight: 300;}
         .user-info a { color: #d1d5db; text-decoration: none; transition: color 0.2s ease, background-color 0.2s ease; display: flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.6rem; border-radius: var(--border-radius-input); }
         .user-info a:hover { color: var(--header-color); background-color: rgba(255, 255, 255, 0.1); }
         .user-info a i { font-size: 0.9em; }

         /* --- Theme Toggle Button Styles --- */
         #theme-toggle-button {
            background-color: var(--theme-toggle-bg);
            border: none;
            color: var(--theme-toggle-icon);
            padding: 0.5rem;
            border-radius: 50%;
            cursor: pointer;
            font-size: 1.1rem; /* Adjust icon size */
            width: 40px; /* Fixed size */
            height: 40px; /* Fixed size */
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color var(--transition-speed-fast) ease, color var(--transition-speed-fast) ease, transform var(--transition-speed-fast) ease;
            margin-left: 1rem; /* Space from user info */
         }
         #theme-toggle-button:hover {
            background-color: var(--theme-toggle-bg-hover);
            transform: scale(1.1);
         }
         #theme-toggle-button .fa-sun { display: none; } /* Hide sun by default */
         .dark-theme #theme-toggle-button .fa-sun { display: inline-block; } /* Show sun in dark */
         .dark-theme #theme-toggle-button .fa-moon { display: none; } /* Hide moon in dark */
         /* --- End Theme Toggle --- */

         /* --- Container & Page Header --- */
         .container { max-width: 1200px; margin: 3rem auto; padding: 0 2rem; flex-grow: 1; width: 100%;}
         .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; flex-wrap: wrap; gap: 0.5rem; }
         .page-title { color: var(--text-heading); font-size: 2rem; font-weight: 700; } /* Use variable */
         .period-label {
            font-size: 0.95rem; color: var(--text-muted); /* Use variable */
            background-color: var(--glass-bg); /* Use variable */
            padding: 0.4rem 0.9rem; border-radius: var(--border-radius-sm);
            border: 1px solid var(--glass-border-color-soft); /* Add subtle border */
            transition: background-color var(--transition-speed) ease, color var(--transition-speed) ease, border-color var(--transition-speed) ease;
        }

         /* --- Card Styles --- */
         .card {
            background-color: var(--glass-bg-card); /* Use variable */
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border-color); /* Use variable */
            border-radius: var(--border-radius);
            box-shadow: var(--shadow); /* Use variable */
            margin-bottom: 2rem;
            padding: 0;
            overflow: hidden;
            transition: transform var(--transition-speed) ease-out, box-shadow var(--transition-speed) ease-out, background-color var(--transition-speed) ease, border-color var(--transition-speed) ease;
         }
         .card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); } /* Use variable */
         .card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.2rem 1.8rem;
            border-bottom: 1px solid var(--glass-border-color-soft); /* Use variable */
            background-color: transparent; /* Remove specific background */
            transition: border-color var(--transition-speed) ease;
         }
         .card-title { color: var(--primary-color); font-size: 1.3rem; font-weight: 600; margin: 0; } /* Use variable */
         .card-icon { font-size: 1.6rem; color: var(--primary-color); opacity: 0.6; } /* Use variable */
         .card-body { padding: 1.5rem 1.8rem; }

         /* --- Error Message --- */
         .error-message {
            background-color: var(--danger-bg); /* Use variable */
            color: var(--danger-text); /* Use variable */
            border: 1px solid var(--danger-border); /* Use variable */
            padding: 1rem 1.5rem; border-radius: var(--border-radius-sm); margin-bottom: 1.5rem;
            text-align: center; font-weight: 500; animation: slideDownFadeIn 0.4s var(--transition-easing) forwards; opacity: 0;
         }
         .error-message i { margin-right: 0.6rem; }
         @keyframes slideDownFadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

         /* --- Period Selector Form --- */
         .period-selector-form { display: flex; flex-wrap: wrap; gap: 1.2rem; align-items: flex-end; padding: 1.5rem 1.8rem; }
         .period-selector-form .form-group { position: relative; flex-grow: 1; min-width: 180px; margin-top: 0.8rem; }
         .period-selector-form label {
            position: absolute; left: var(--input-padding-x-sm); top: 50%; transform: translateY(-50%);
            font-weight: 500; color: var(--text-muted); /* Use variable */
            background-color: transparent; padding: 0 var(--label-padding-x);
            transition: all 0.2s ease-out; pointer-events: none; font-size: 0.95rem; white-space: nowrap; z-index: 1;
         }
         .period-selector-form select:focus + label,
         .period-selector-form select.has-value + label,
         .period-selector-form input[type="date"]:focus + label,
         .period-selector-form input[type="date"][value]:not([value=""]) + label,
         .period-selector-form input[type="date"]:not(:placeholder-shown) + label {
            top: 0; transform: translateY(-50%) scale(0.8); font-size: var(--label-floated-font-size);
            font-weight: 600; color: var(--primary-color); /* Use variable */
            /* Use a background derived from card bg for label float */
            background-color: var(--glass-bg-card); /* Use variable */
            border-radius: 4px; z-index: 3; left: 0.8rem;
            padding-left: var(--label-padding-x); padding-right: var(--label-padding-x); /* Added padding */
         }
         .period-selector-form select,
         .period-selector-form input[type="date"] {
            width: 100%; padding: var(--input-padding-y-sm) var(--input-padding-x-sm);
            padding-top: calc(var(--input-padding-y-sm) + 0.7rem);
            border: 1px solid var(--input-border-color); /* Use variable */
            border-radius: var(--border-radius-input);
            font-size: 0.95rem; background-color: var(--input-bg-color); /* Use variable */
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, color 0.2s ease;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); position: relative; z-index: 2;
            color: var(--text-color); /* Use variable */
         }
         .dark-theme .period-selector-form input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(0.8) brightness(1.1); /* Make calendar icon visible in dark mode */
         }
         .period-selector-form select {
             appearance: none; -webkit-appearance: none; -moz-appearance: none;
             /* SVG adjusted for better visibility in both themes */
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='currentColor' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
             background-repeat: no-repeat; background-position: right 0.8rem center;
             background-size: 16px 12px; padding-right: 2.8rem;
             color: var(--text-color); /* Ensure dropdown arrow uses text color */
         }
         .period-selector-form input[type="date"]::placeholder { color: transparent; }
         .period-selector-form input[type="date"]::-webkit-calendar-picker-indicator { opacity: 0.6; cursor: pointer; transition: opacity 0.2s ease, filter 0.2s ease; }
         .period-selector-form input[type="date"]:hover::-webkit-calendar-picker-indicator { opacity: 1; }
         .period-selector-form select:focus,
         .period-selector-form input[type="date"]:focus {
            border-color: var(--input-focus-border); /* Use variable */
            outline: 0;
            box-shadow: 0 0 0 3px var(--input-focus-shadow), inset 0 1px 2px rgba(0,0,0,0.05); /* Use variable */
            /* Slightly lighten background on focus for dark theme */
            /* background-color: var(--input-bg-color); Keep using variable */
             z-index: 4;
         }
         .dark-theme .period-selector-form select:focus,
         .dark-theme .period-selector-form input[type="date"]:focus {
            background-color: rgba(75, 85, 99, 0.7); /* Slightly different focus bg for dark */
         }

         .period-selector-form select:focus-visible,
         .period-selector-form input[type="date"]:focus-visible { outline: none; }
         .period-selector-form .form-buttons { padding-top: 0.8rem; }

         /* --- Button Styles (Using Variables) --- */
         .button {
             display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
             padding: 0.8rem 1.8rem; border: none; border-radius: 50px;
             text-decoration: none; font-size: 1rem; font-weight: 600; cursor: pointer;
             transition: all var(--transition-speed) var(--transition-easing);
             box-shadow: var(--shadow); /* Use variable */
             background-size: 150% auto;
             position: relative; overflow: hidden; letter-spacing: 0.3px;
             line-height: 1.5;
         }
         .button:hover { transform: translateY(-3px); box-shadow: var(--shadow-lg); } /* Use variable */
         .button:active { transform: translateY(0) scale(0.98); box-shadow: var(--shadow-sm); } /* Use variable */
         .button:focus-visible { outline-offset: 3px; }
         .button i { font-size: 1.1em; margin-right: 0.4rem; }

         .button-primary { background-image: var(--primary-gradient); color: #fff; } /* Use variable */
         .button-primary:hover { background-image: var(--primary-gradient-hover); background-position: right center; } /* Use variable */
         .button-primary:focus-visible { outline: 3px solid var(--input-focus-shadow); } /* Use variable */

         .button-success { background-image: var(--success-gradient); color: #fff; } /* Use variable */
         .button-success:hover { background-image: var(--success-gradient-hover); background-position: right center; } /* Use variable */
         .button-success:focus-visible { outline: 3px solid rgba(34, 197, 94, 0.3); }

         .button-secondary { background-color: var(--secondary-color); color: #fff; border: 1px solid var(--secondary-color); } /* Use variable */
         .button-secondary:hover { background-color: var(--secondary-hover); border-color: var(--secondary-hover); } /* Use variable */
         .button-secondary:focus-visible { outline: 3px solid rgba(108, 117, 125, 0.3); }

         .button-outline-primary { color: var(--primary-color); border: 1px solid var(--primary-color); background: transparent;} /* Use variable */
         .button-outline-primary:hover { color: #fff; background-color: var(--primary-color); } /* Use variable */
         .button-outline-primary:focus-visible { outline: 3px solid var(--input-focus-shadow); } /* Use variable */

         /* --- Summary Stats --- */
         .summary-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1.8rem; margin-bottom: 2rem; }
         .stat-card {
            background-color: var(--glass-bg); /* Use variable */
            backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
            border-radius: var(--border-radius); padding: 1.6rem; box-shadow: var(--shadow-sm); /* Use variable */
            display: flex; align-items: center; border: 1px solid var(--glass-border-color); /* Use variable */
            border-left-width: 6px; position: relative; overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease, border-color 0.2s ease;
         }
          .stat-card::before { /* Shine effect - might need adjustment for dark */
              content: ""; position: absolute; top: 0; left: -75%; width: 50%; height: 100%;
              background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.1) 50%, rgba(255,255,255,0) 100%);
              transform: skewX(-25deg); opacity: 0; transition: left var(--transition-speed) ease; pointer-events: none;
          }
          .dark-theme .stat-card::before { /* Lighter shine for dark */
              background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.05) 50%, rgba(255,255,255,0) 100%);
          }
         .stat-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-card-hover); } /* Adjusted hover shadow */
         .dark-theme .stat-card:hover { box-shadow: var(--shadow-card-hover); } /* Ensure hover shadow works in dark */
         .stat-card:hover::before { left: 125%; opacity: 1; }
         .stat-card .icon { font-size: 2.2rem; margin-right: 1.2rem; opacity: 0.7; width: 45px; text-align: center; transition: transform 0.3s ease; }
         .stat-card:hover .icon { transform: scale(1.1) rotate(-5deg); }
         .stat-card .details { flex-grow: 1; }
         .stat-card .label { font-size: 0.95rem; color: var(--text-muted); margin-bottom: 0.2rem; display: block; font-weight: 500; } /* Use variable */
         .stat-card .value { font-size: 1.75rem; font-weight: 700; color: var(--text-heading); line-height: 1.3; } /* Use variable */
         .stat-card.income { border-left-color: var(--success-color); } .stat-card.income .icon { color: var(--success-color); } .stat-card.income .value { color: var(--success-color); } /* Use variable */
         .stat-card.expense { border-left-color: var(--danger-color); } .stat-card.expense .icon { color: var(--danger-color); } .stat-card.expense .value { color: var(--danger-color); } /* Use variable */
         .stat-card.balance { border-left-color: var(--info-color); } .stat-card.balance .icon { color: var(--info-color); } /* Use variable */
         .stat-card.balance .value.positive { color: var(--success-color); } /* Use variable */
         .stat-card.balance .value.negative { color: var(--danger-color); } /* Use variable */
         /* Use theme-specific text colors for balance if needed */
         .dark-theme .stat-card.balance .value.positive { color: var(--success-text); }
         .dark-theme .stat-card.balance .value.negative { color: var(--danger-text); }


         /* --- Report Sections Grid --- */
         .report-sections-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 2rem; }
         .report-sections-grid > section.card:nth-child(1) { grid-column: span 6; }
         .report-sections-grid > section.card:nth-child(2) { grid-column: span 6; }
         .report-sections-grid > section.card:nth-child(3) { grid-column: span 12; }
         .report-sections-grid > section.card:nth-child(4) { grid-column: span 8; }
         .report-sections-grid > section.card:nth-child(5) { grid-column: span 4; }

         /* --- Tables --- */
         .table-container { width: 100%; overflow-x: auto; background-color: transparent; }
         table { width: 100%; border-collapse: collapse; margin-bottom: 0; }
         th, td { text-align: left; padding: 0.9rem 1.1rem; border-bottom: 1px solid var(--glass-border-color-soft); font-size: 0.95rem; vertical-align: middle; transition: background-color 0.15s ease, border-color var(--transition-speed) ease; } /* Use variable */
         th { background-color: var(--table-header-bg); font-weight: 600; color: var(--text-heading); white-space: nowrap; } /* Use variables */
         tbody tr { transition: background-color 0.15s ease; }
         tbody tr:hover { background-color: var(--table-hover-bg); } /* Use variable */
         tfoot tr { font-weight: bold; background-color: var(--table-footer-bg); } /* Use variable */
         tfoot td { border-top: 1px solid var(--glass-border-color); } /* Use variable */
         td:last-child, th:last-child { text-align: right; }
         .income-table td:nth-child(2), .income-table th:nth-child(2),
         .expense-table td:nth-child(2), .expense-table th:nth-child(2) { text-align: right; font-weight: 500; }
         .budget-table th:nth-child(1), .budget-table td:nth-child(1) { text-align: left; font-weight: 500; }
         .budget-table th:nth-child(n+2), .budget-table td:nth-child(n+2) { text-align: right; }
         .budget-table th:last-child, .budget-table td:last-child { text-align: left; min-width: 200px; }
         .transaction-table td:last-child, .transaction-table th:last-child { text-align: right; font-weight: 500; }
         .transaction-table td:nth-child(2) { max-width: 250px; white-space: normal; word-break: break-word; font-size: 0.9em; color: var(--text-muted); } /* Use variable */
         .text-success { color: var(--success-color); } /* Use variable */
         .text-danger { color: var(--danger-color); } /* Use variable */
         .fw-bold { font-weight: 600; }
         /* Specific overrides for dark theme text colors */
         .dark-theme .text-success { color: var(--success-text); }
         .dark-theme .text-danger { color: var(--danger-text); }

         /* --- Charts --- */
         .chart-container { position: relative; margin: 0 auto 1.5rem; max-width: 380px; height: 350px; }
         .chart-container canvas { }

         /* --- Progress Bar --- */
         .progress-container { display: flex; align-items: center; gap: 0.6rem; }
         .progress-value { font-size: 0.9rem; width: 45px; text-align: right; font-weight: 500; color: var(--text-muted); } /* Use variable */
         .progress { flex-grow: 1; height: 12px; background-color: var(--progress-track-bg); border-radius: 50px; overflow: hidden; position: relative; box-shadow: inset 0 1px 2px rgba(0,0,0,0.05); transition: background-color var(--transition-speed) ease; } /* Use variable */
         .progress-bar { height: 100%; background: var(--progress-bar-color); transition: width 0.8s cubic-bezier(0.65, 0, 0.35, 1); display: flex; align-items: center; justify-content: center; color: white; font-size: 0.7rem; border-radius: 50px; } /* Use variable */
         .progress-bar.bg-warning { background: var(--progress-bar-warning-color); color: var(--warning-text); } /* Use variable */
         .progress-bar.bg-danger { background: var(--progress-bar-danger-color); } /* Use variable */
         .dark-theme .progress-bar.bg-warning { color: #422006; } /* Ensure contrast */

         /* --- No Data Message --- */
         .no-data { text-align: center; padding: 2.5rem 1rem; color: var(--text-muted); font-style: italic; } /* Use variable */
         .no-data i { display: block; font-size: 2.5rem; margin-bottom: 0.8rem; opacity: 0.5; }

         /* --- Footer --- */
         .footer { text-align: center; margin-top: auto; padding: 2.5rem 1rem 2rem 1rem; font-size: 0.9rem; color: var(--text-muted); border-top: 1px solid var(--glass-border-color-soft); background-color: transparent; transition: border-color var(--transition-speed) ease, color var(--transition-speed) ease;} /* Use variable */

         /* --- Back Link --- */
         .back-link-container { text-align: center; margin: 3rem 0 1rem 0; }

         /* --- Media Queries (Keep as is, variables will cascade) --- */
         @media (max-width: 1200px) {
            .report-sections-grid > section.card:nth-child(4) { grid-column: span 12; }
            .report-sections-grid > section.card:nth-child(5) { grid-column: span 12; }
         }
         @media (max-width: 992px) {
             .container { max-width: 95%; } .page-title { font-size: 1.8rem; }
            .stat-card .value { font-size: 1.5rem; }
             .chart-container { max-width: 320px; height: 300px; }
             .report-sections-grid > section.card:nth-child(1) { grid-column: span 12; }
             .report-sections-grid > section.card:nth-child(2) { grid-column: span 12; }
              .budget-table th:last-child, .budget-table td:last-child { min-width: 180px; }
         }
         @media (max-width: 768px) {
             /* :root { --base-font-size: 15px; } /* Base font size in root for consistency */
            html { font-size: 15px; } /* Apply base font size directly */
            .header { padding: 0.8rem 1rem; flex-wrap: nowrap; } /* Prevent wrap */
            .header .logo { font-size: 1.4rem; flex-shrink: 0; } /* Prevent shrink */
            .user-info { margin-left: 0.5rem; } /* Adjust spacing */
            .container { margin: 2rem auto; padding: 0 1rem; }
             .page-header { flex-direction: column; align-items: flex-start; gap: 0.8rem; margin-bottom: 1.5rem; }
             .period-selector-form { flex-direction: column; align-items: stretch; gap: 1rem; } .form-buttons { margin-top: 0.8rem; text-align: right; }
             .summary-stats { grid-template-columns: 1fr; gap: 1.2rem; }
             .card { padding: 0; } .card-header { padding: 1rem 1.5rem; } .card-body { padding: 1.2rem 1.5rem;}
             .card-title { font-size: 1.2rem; } .chart-container { max-width: 100%; height: 320px; }
             .transaction-table th:nth-child(4), .transaction-table td:nth-child(4) { display: none; }
             .budget-table th:nth-child(4), .budget-table td:nth-child(4) { display: none; }
             .budget-table th:last-child, .budget-table td:last-child { min-width: auto; }
         }
         @media (max-width: 576px) {
             /* :root { --base-font-size: 14.5px; } */
             html { font-size: 14.5px; }
             .header .user-info span { display: none; } .page-title { font-size: 1.6rem; }
             .stat-card { padding: 1.2rem; } .stat-card .value { font-size: 1.4rem;}
             .stat-card .icon { display: none; }
             .card-header { padding: 0.8rem 1.2rem; } .card-body { padding: 1rem 1.2rem; }
             th, td { padding: 0.7rem 0.9rem; font-size: 0.9rem; }
             .button { font-size: 0.95rem; padding: 0.7rem 1.5rem; }
             .transaction-table th:nth-child(3), .transaction-table td:nth-child(3) { display: none; }
             .period-selector-form { padding: 1.2rem; }
             #theme-toggle-button { margin-left: 0.5rem; width: 36px; height: 36px; font-size: 1rem; }
         }

    </style>

</head>

<body> <!-- Body tag - theme class will be added here by JS -->

<header class="header">
    <a href="dashboard.php" class="logo">FinDash</a>
    <div class="user-info">
        <span>Welcome, <?php echo $userName; ?>!</span>
        <a href="logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
    <!-- Add Theme Toggle Button -->
    <button id="theme-toggle-button" title="Toggle light/dark theme">
        <i class="fas fa-moon"></i> <!-- Moon icon for light theme -->
        <i class="fas fa-sun"></i>  <!-- Sun icon for dark theme -->
    </button>
    <!-- End Theme Toggle Button -->
</header>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">Financial Report</h1>
        <span class="period-label" title="The time range covered by this report">Period: <?php echo $reportPeriodLabel; ?></span>
    </div>

    <?php if (!empty($dbErrorMessage)): ?>
        <div class="error-message" role="alert"><i class="fas fa-database"></i> <?php echo htmlspecialchars($dbErrorMessage); ?></div>
    <?php elseif (!empty($fetchErrorMessage)): ?>
         <div class="error-message" role="alert"><i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($fetchErrorMessage); ?></div>
    <?php endif; ?>


    <div class="card">
        <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="get" class="period-selector-form">
            <div class="form-group">
                <select id="period" name="period" onchange="toggleCustomDates(this.value); updateSelectClass(this);" class="form-select" title="Select a predefined date range">

                     <option value="this_month" <?php echo ($selectedPeriod === 'this_month') ? 'selected' : ''; ?>>This Month</option>
                     <option value="last_month" <?php echo ($selectedPeriod === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                     <option value="last_90_days" <?php echo ($selectedPeriod === 'last_90_days') ? 'selected' : ''; ?>>Last 90 Days</option>
                     <option value="this_year" <?php echo ($selectedPeriod === 'this_year') ? 'selected' : ''; ?>>This Year</option>
                     <option value="all_time" <?php echo ($selectedPeriod === 'all_time') ? 'selected' : ''; ?>>All Time</option>
                     <option value="custom" <?php echo ($selectedPeriod === 'custom') ? 'selected' : ''; ?>>Custom Range</option>
                </select>
                 <label for="period">Select Period</label>
            </div>
             <div class="form-group" id="custom-dates" style="<?php echo ($selectedPeriod !== 'custom') ? 'display: none;' : 'display: block;'; ?>">
                 <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($customStartDateInput ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control" placeholder=" " oninput="this.classList.add('has-value')">
                 <label for="start_date">Start Date</label>
             </div>
             <div class="form-group" id="custom-dates-end" style="<?php echo ($selectedPeriod !== 'custom') ? 'display: none;' : 'display: block;'; ?>">
                 <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($customEndDateInput ?? ''); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control" placeholder=" " oninput="this.classList.add('has-value')">
                 <label for="end_date">End Date</label>
             </div>
            <div class="form-buttons">
                <button type="submit" class="button button-primary"><i class="fas fa-sync-alt"></i> Update Report</button>
            </div>
        </form>
        <script>
             function toggleCustomDates(selectedValue) {
                 const displayStyle = (selectedValue === 'custom') ? 'block' : 'none';
                 document.getElementById('custom-dates').style.display = displayStyle;
                 document.getElementById('custom-dates-end').style.display = displayStyle;
             }

             function updateSelectClass(selectElement) {
                 if (selectElement.value) { selectElement.classList.add('has-value'); }
                 else { selectElement.classList.remove('has-value'); }
             }

             document.addEventListener('DOMContentLoaded', () => {
                 const periodSelect = document.getElementById('period');
                 const startDateInput = document.getElementById('start_date');
                 const endDateInput = document.getElementById('end_date');

                 updateSelectClass(periodSelect);
                 toggleCustomDates(periodSelect.value);


                 if (startDateInput.value) startDateInput.classList.add('has-value');
                 if (endDateInput.value) endDateInput.classList.add('has-value');

                 // Ensure date inputs retain 'has-value' if they have a value on load
                 startDateInput.addEventListener('change', () => { if (startDateInput.value) startDateInput.classList.add('has-value'); else startDateInput.classList.remove('has-value'); });
                 endDateInput.addEventListener('change', () => { if (endDateInput.value) endDateInput.classList.add('has-value'); else endDateInput.classList.remove('has-value'); });
                 // Also handle initial placeholder state for floating label
                 if(startDateInput.placeholder !== " " && startDateInput.value) startDateInput.classList.add('has-value');
                 if(endDateInput.placeholder !== " " && endDateInput.value) endDateInput.classList.add('has-value');
             });
        </script>
    </div>


    <?php if (!$fetchErrorMessage && !$dbErrorMessage): ?>
    <section class="summary-stats">
        <div class="stat-card income">
            <div class="icon"><i class="fas fa-wallet"></i></div>
            <div class="details">
                <span class="label" title="Total income during the selected period">Total Income</span>
                <span class="value"><?php echo formatCurrency($reportTotalIncome, $currencySymbol); ?></span>
            </div>
        </div>
        <div class="stat-card expense">
             <div class="icon"><i class="fas fa-receipt"></i></div>
             <div class="details">
                 <span class="label" title="Total expenses during the selected period">Total Expenses</span>
                 <span class="value"><?php echo formatCurrency($reportTotalExpenses, $currencySymbol); ?></span>
             </div>
        </div>
         <div class="stat-card balance">
             <div class="icon"><i class="fas fa-balance-scale-right"></i></div>
             <div class="details">
                 <span class="label" title="Net result (Income - Expenses) for the period">Net Balance</span>
                 <span class="value <?php echo ($reportNetBalance >= 0) ? 'positive' : 'negative'; ?>">
                     <?php echo formatCurrency($reportNetBalance, $currencySymbol); ?>
                 </span>
             </div>
         </div>
    </section>
    <?php endif; ?>



    <div class="report-sections-grid">


        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Income by Source</h2>
                <i class="fas fa-chart-pie card-icon" style="color: var(--success-color);"></i>
            </div>
            <div class="card-body">
                <?php if ($fetchErrorMessage || $dbErrorMessage): ?> <p class="no-data"><i class="fas fa-exclamation-circle"></i> Data unavailable.</p>
                <?php elseif (empty($reportIncomeSources)): ?> <p class="no-data"><i class="fas fa-info-circle"></i> No income recorded for this period.</p>
                <?php else: ?>
                     <div class="chart-container"> <canvas id="incomeChart"></canvas> </div>
                     <div class="table-container">
                        <table class="income-table">
                            <thead><tr><th title="Source of income">Source</th><th title="Total amount from this source">Amount</th></tr></thead>
                            <tbody> <?php foreach($reportIncomeSources as $source => $amount): ?> <tr> <td title="<?php echo htmlspecialchars($source); ?>"><?php echo htmlspecialchars($source); ?></td> <td class="text-success fw-bold"><?php echo formatCurrency($amount, $currencySymbol); ?></td> </tr> <?php endforeach; ?> </tbody>
                            <tfoot> <tr> <td>Total Income</td> <td class="text-success fw-bold"><?php echo formatCurrency($reportTotalIncome, $currencySymbol); ?></td> </tr> </tfoot>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>


        <section class="card">
             <div class="card-header"> <h2 class="card-title">Expenses by Category</h2> <i class="fas fa-chart-pie card-icon" style="color: var(--danger-color);"></i> </div>
             <div class="card-body">
                 <?php if ($fetchErrorMessage || $dbErrorMessage): ?> <p class="no-data"><i class="fas fa-exclamation-circle"></i> Data unavailable.</p>
                 <?php elseif (empty($reportExpenseCategories)): ?> <p class="no-data"><i class="fas fa-info-circle"></i> No expenses recorded for this period.</p>
                 <?php else: ?>
                      <div class="chart-container"> <canvas id="expenseChart"></canvas> </div>
                     <div class="table-container">
                         <table class="expense-table">
                             <thead><tr><th title="Category of expense">Category</th><th title="Total amount for this category">Amount</th></tr></thead>
                             <tbody> <?php foreach($reportExpenseCategories as $category => $amount): ?> <tr> <td title="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></td> <td class="text-danger fw-bold"><?php echo formatCurrency($amount, $currencySymbol); ?></td> </tr> <?php endforeach; ?> </tbody>
                             <tfoot> <tr> <td>Total Expenses</td> <td class="text-danger fw-bold"><?php echo formatCurrency($reportTotalExpenses, $currencySymbol); ?></td> </tr> </tfoot>
                         </table>
                     </div>
                 <?php endif; ?>
             </div>
        </section>


        <section class="card">
             <div class="card-header"> <h2 class="card-title">Budget Performance</h2> <i class="fas fa-tasks card-icon" style="color: var(--warning-color);"></i> </div>
             <div class="card-body">
                 <?php if ($fetchErrorMessage || $dbErrorMessage): ?> <p class="no-data"><i class="fas fa-exclamation-circle"></i> Data unavailable.</p>
                 <?php elseif (empty($budgetBreakdownReport)): ?> <p class="no-data"><i class="fas fa-folder-plus"></i> No budgets set up. <a href="budget.php" class="button button-outline-primary" style="padding: 0.4rem 0.8rem; font-size: 0.9rem;">Manage Budgets</a></p>
                 <?php else: ?>
                     <div class="table-container">
                         <table class="budget-table">
                             <thead><tr><th title="Budget category name">Category</th><th title="Amount allocated in budget">Budgeted</th><th title="Amount spent during selected period">Spent (Period)</th><th title="Budget remaining (Budgeted - Spent)">Remaining</th><th title="Percentage of budget used">Usage</th></tr></thead>
                             <tbody>
                                 <?php foreach ($budgetBreakdownReport as $category => $data): ?>
                                     <?php $barClass = ''; if ($data['usage_percent'] > 100) $barClass = 'bg-danger'; elseif ($data['usage_percent'] >= 85) $barClass = 'bg-warning'; $remainingClass = ($data['remaining'] >= 0) ? 'text-success' : 'text-danger'; ?>
                                     <tr>
                                         <td title="<?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></td>
                                         <td><?php echo formatCurrency($data['allocated'], $currencySymbol); ?></td>
                                         <td><?php echo formatCurrency($data['spent'], $currencySymbol); ?></td> <!-- Shows spending *within the selected period* -->
                                         <td class="<?php echo $remainingClass; ?>"><?php echo formatCurrency($data['remaining'], $currencySymbol); ?></td>
                                         <td>
                                             <div class="progress-container" title="<?php echo $data['usage_percent']; ?>% Spent">
                                                 <span class="progress-value"><?php echo $data['usage_percent']; ?>%</span>
                                                 <div class="progress"><div class="progress-bar <?php echo $barClass; ?>" role="progressbar" style="width: <?php echo min(100, $data['usage_percent']); ?>%;" aria-valuenow="<?php echo $data['usage_percent']; ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
                                             </div>
                                         </td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                             <tfoot>
                                  <tr>
                                     <td>Total Budgeted</td>
                                     <td><?php echo formatCurrency($budgetAllocatedTotal, $currencySymbol); ?></td>
                                     <td><?php echo formatCurrency($budgetSpentTotalPeriod, $currencySymbol); ?></td> <!-- Shows total spending across categories *within the selected period* -->
                                     <td class="<?php echo (($budgetAllocatedTotal - $budgetSpentTotalPeriod) >= 0) ? 'text-success' : 'text-danger'; ?>"><?php echo formatCurrency($budgetAllocatedTotal - $budgetSpentTotalPeriod, $currencySymbol); ?></td>
                                     <td>
                                         <?php $overallBudgetUsage = ($budgetAllocatedTotal > 0 ? round(($budgetSpentTotalPeriod / $budgetAllocatedTotal) * 100) : 0); $overallBarClass = ''; if ($overallBudgetUsage > 100) $overallBarClass = 'bg-danger'; elseif ($overallBudgetUsage >= 85) $overallBarClass = 'bg-warning'; ?>
                                         <div class="progress-container" title="<?php echo $overallBudgetUsage; ?>% Total Usage">
                                             <span class="progress-value fw-bold"><?php echo $overallBudgetUsage; ?>%</span>
                                             <div class="progress"><div class="progress-bar <?php echo $overallBarClass; ?>" role="progressbar" style="width: <?php echo min(100, $overallBudgetUsage); ?>%;" aria-valuenow="<?php echo $overallBudgetUsage; ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
                                         </div>
                                     </td>
                                  </tr>
                             </tfoot>
                         </table>
                         <p style="font-size: 0.8em; color: var(--text-muted); margin-top: 0.8rem; text-align: center; padding: 0 1rem;">
                            <strong>Note:</strong> "Budgeted" shows the total allocated amount (e.g., monthly). "Spent (Period)" shows expenses recorded *only* during the selected report timeframe (<?php echo $reportPeriodLabel; ?>).
                         </p>
                     </div>
                 <?php endif; ?>
             </div>
        </section>


        <section class="card">
             <div class="card-header"> <h2 class="card-title">Transaction Log</h2> <i class="fas fa-list-ul card-icon"></i> </div>
             <div class="card-body">
                 <?php if ($fetchErrorMessage || $dbErrorMessage): ?> <p class="no-data"><i class="fas fa-exclamation-circle"></i> Data unavailable.</p>
                 <?php elseif (empty($fetchedTransactions)): ?> <p class="no-data"><i class="fas fa-info-circle"></i> No transactions found for this period.</p>
                 <?php else: ?>
                     <div class="table-container">
                         <table class="transaction-table">
                             <thead><tr><th title="Date of transaction">Date</th><th title="Transaction description">Description</th><th title="Category assigned">Category</th><th title="Payment method used">Method</th><th title="Transaction amount">Amount</th></tr></thead>
                             <tbody>
                                 <?php foreach($fetchedTransactions as $tx): ?>
                                     <tr>
                                         <td title="<?php echo htmlspecialchars(date("Y-m-d H:i", strtotime($tx['date'] ?? '')) ?: 'N/A'); ?>"><?php echo htmlspecialchars(date("M d, Y", strtotime($tx['date'] ?? '')) ?: 'N/A'); ?></td>
                                         <td title="<?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?>"><?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?></td>
                                         <td title="<?php echo htmlspecialchars($tx['category'] ?? 'N/A'); ?>"><?php echo htmlspecialchars($tx['category'] ?? 'N/A'); ?></td>
                                         <td title="<?php echo htmlspecialchars($tx['paymentMethod'] ?? 'N/A'); ?>"><?php echo htmlspecialchars($tx['paymentMethod'] ?? 'N/A'); ?></td>
                                         <td class="<?php echo ($tx['type'] === 'income') ? 'text-success' : 'text-danger'; ?> fw-bold"><?php echo ($tx['type'] === 'income' ? '+' : '-') . formatCurrency($tx['amount'] ?? 0, $currencySymbol); ?></td>
                                     </tr>
                                 <?php endforeach; ?>
                             </tbody>
                         </table>
                     </div>
                 <?php endif; ?>
             </div>
        </section>


        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Export Report</h2>
                <i class="fas fa-file-download card-icon"></i>
            </div>
            <div class="card-body" style="text-align: center;">
                <p style="margin-bottom: 1.5rem; font-size: 1.05rem; color: var(--text-muted);">
                    Download report data for: <?php echo htmlspecialchars($reportPeriodLabel); ?>.
                </p>
                <div class="export-buttons" style="display: flex; justify-content: center; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;">

                    <!-- PDF Export Link -->
                    <a href="export_handler.php?format=pdf&period=<?php echo urlencode($selectedPeriod); ?>&start=<?php echo urlencode($customStartDateInput ?? ''); ?>&end=<?php echo urlencode($customEndDateInput ?? ''); ?>"
                       class="button button-secondary"
                       title="Download report as PDF (Requires backend implementation)">
                        <i class="fas fa-file-pdf"></i> Export as PDF
                    </a>

                    <!-- CSV Button Removed -->

                </div>
                <p style="font-size: 0.85em; color: var(--text-muted); margin-top: 1rem;">
                    (Note: Export button requires implementation in `export_handler.php`.)
                </p>
            </div>
        </section>



    </div>

    <div class="back-link-container">
        <a href="dashboard.php" class="button button-outline-primary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>
</div>

<footer class="footer">
    © <?php echo date('Y'); ?> FinDash Demo. Insights at your fingertips.
</footer>


 <?php if (!$fetchErrorMessage && !$dbErrorMessage && (!empty($incomeChartData) || !empty($expenseChartData)) ): ?>
 <script>
     document.addEventListener('DOMContentLoaded', () => {

         // Define chart instances globally to update them later
         let incomeChartInstance = null;
         let expenseChartInstance = null;

         // Function to get current theme's color from CSS variables
         const getThemeColor = (variableName, fallback) => {
             // Ensure styles are computed after potential theme application
             return getComputedStyle(document.body).getPropertyValue(variableName).trim() || fallback;
         }

         // Function to create chart options dynamically based on current theme
         const createChartOptions = () => {
             const isDark = document.body.classList.contains('dark-theme');
             return {
                 responsive: true,
                 maintainAspectRatio: false,
                 plugins: {
                     legend: {
                         position: 'bottom',
                         labels: {
                             padding: 18,
                             font: { family: 'Poppins, sans-serif', size: 12 },
                             boxWidth: 15,
                             usePointStyle: true,
                             color: getThemeColor('--chart-legend-text', '#6b7280') // Use theme variable
                         }
                     },
                     tooltip: {
                         backgroundColor: getThemeColor('--chart-tooltip-bg', 'rgba(0, 0, 0, 0.85)'), // Use theme variable
                         titleColor: getThemeColor('--chart-tooltip-text', '#ffffff'), // Use theme variable
                         bodyColor: getThemeColor('--chart-tooltip-text', '#ffffff'), // Use theme variable
                         titleFont: { weight: 'bold', family: 'Poppins, sans-serif', size: 13 },
                         bodyFont: { size: 12, family: 'Poppins, sans-serif' },
                         padding: 12,
                         displayColors: true,
                         boxPadding: 4,
                         borderColor: 'rgba(255,255,255,0.1)',
                         borderWidth: 1,
                         callbacks: {
                             label: function(context) {
                                 let label = context.label || '';
                                 if (label) { label += ': '; }
                                 if (context.parsed !== null) {
                                     const currencySymbol = '<?php echo $currencySymbol; ?>';
                                     // Format currency robustly
                                     try {
                                        label += new Intl.NumberFormat(
                                            'en-US', // Use a consistent locale or detect user locale
                                            { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }
                                        ).format(context.parsed).replace(/^\$/, currencySymbol); // Replace USD symbol if needed
                                     } catch (e) {
                                        label += currencySymbol + context.parsed.toFixed(2); // Fallback formatting
                                     }
                                 }
                                 return label;
                             }
                         }
                     }
                 },
                 onHover: (event, chartElement) => {
                     const chartArea = event.chart.chartArea;
                      if (chartArea && event.native) { // Check if chartArea and native event exist
                         event.native.target.style.cursor = chartElement[0] ? 'pointer' : 'default';
                      }
                 },
                 animation: {
                     duration: 800,
                     easing: 'easeOutQuart'
                 },
                 cutout: '60%'
             };
         };


         const incomeColors = ['#22c55e', '#10b981', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#6b7280'];
         const expenseColors = ['#ef4444', '#f97316', '#f59e0b', '#eab308', '#8b5cf6', '#d946ef', '#ec4899', '#6b7280'];


         const incomeCtx = document.getElementById('incomeChart')?.getContext('2d');
         if (incomeCtx && <?php echo $incomeChartDataJSON; ?>.length > 0) {
             incomeChartInstance = new Chart(incomeCtx, { // Assign to global instance
                 type: 'doughnut',
                 data: {
                     labels: <?php echo $incomeChartLabelsJSON; ?>,
                     datasets: [{
                         label: 'Income',
                         data: <?php echo $incomeChartDataJSON; ?>,
                         backgroundColor: incomeColors.slice(0, <?php echo count($incomeChartData); ?>),
                         borderColor: getThemeColor('--glass-bg-card', 'rgba(255, 255, 255, 0.7)'), // Dynamic border based on card bg
                         borderWidth: 2,
                         hoverOffset: 10,
                         hoverBorderColor: getThemeColor('--text-heading', '#fff'), // Dynamic hover border
                         hoverBorderWidth: 3
                     }]
                 },
                 options: createChartOptions() // Create options dynamically
             });
         }


         const expenseCtx = document.getElementById('expenseChart')?.getContext('2d');
         if (expenseCtx && <?php echo $expenseChartDataJSON; ?>.length > 0) {
             expenseChartInstance = new Chart(expenseCtx, { // Assign to global instance
                 type: 'doughnut',
                 data: {
                     labels: <?php echo $expenseChartLabelsJSON; ?>,
                     datasets: [{
                         label: 'Expenses',
                         data: <?php echo $expenseChartDataJSON; ?>,
                         backgroundColor: expenseColors.slice(0, <?php echo count($expenseChartData); ?>),
                         borderColor: getThemeColor('--glass-bg-card', 'rgba(255, 255, 255, 0.7)'),// Dynamic border
                         borderWidth: 2,
                         hoverOffset: 10,
                         hoverBorderColor: getThemeColor('--text-heading', '#fff'), // Dynamic hover border
                         hoverBorderWidth: 3
                     }]
                 },
                 options: createChartOptions() // Create options dynamically
             });
         }

         // Function to update charts when theme changes - Called by Theme Toggle JS
         window.updateChartTheme = () => {
             const newOptions = createChartOptions(); // Get fresh options reflecting current theme
             const chartBorderColor = getThemeColor('--glass-bg-card', 'rgba(255, 255, 255, 0.7)');
             const chartHoverBorderColor = getThemeColor('--text-heading', '#fff');

             if (incomeChartInstance) {
                 incomeChartInstance.options = newOptions;
                 // Update dataset border colors too
                 incomeChartInstance.data.datasets.forEach(dataset => {
                     dataset.borderColor = chartBorderColor;
                     dataset.hoverBorderColor = chartHoverBorderColor;
                 });
                 incomeChartInstance.update('none'); // Use 'none' to prevent re-animation
             }
             if (expenseChartInstance) {
                 expenseChartInstance.options = newOptions;
                 expenseChartInstance.data.datasets.forEach(dataset => {
                    dataset.borderColor = chartBorderColor;
                    dataset.hoverBorderColor = chartHoverBorderColor;
                 });
                 expenseChartInstance.update('none'); // Use 'none' to prevent re-animation
             }
         };

     }); // End DOMContentLoaded for charts
 </script>
 <?php endif; ?>

<!-- Theme Toggle JavaScript -->
<script>
    document.addEventListener('DOMContentLoaded', () => { // Ensure DOM is ready for theme toggle too
        const themeToggleButton = document.getElementById('theme-toggle-button');
        const body = document.body;
        const currentTheme = localStorage.getItem('theme');

        // Function to apply the theme AND trigger chart update
        const applyTheme = (theme) => {
            body.classList.remove('dark-theme', 'light-theme'); // Remove any existing theme class
            if (theme === 'dark') {
                body.classList.add('dark-theme');
            } else {
                 body.classList.add('light-theme'); // Optional: add light-theme class for explicit targeting if needed
            }

            // Update chart colors AFTER the theme class has been applied and CSS variables are updated
            if (typeof window.updateChartTheme === 'function') {
                // Use a minimal timeout to allow the browser to compute the new CSS variable values
                setTimeout(window.updateChartTheme, 10);
            }
        };

        // Apply the saved theme or system preference on initial load
        let initialTheme = 'light'; // Default to light
        if (currentTheme) {
            initialTheme = currentTheme;
        } else {
            // Check system preference if no saved theme
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                initialTheme = 'dark';
            }
        }
        applyTheme(initialTheme); // Apply the determined initial theme


        // Add event listener for the toggle button
        if (themeToggleButton) { // Check if button exists
            themeToggleButton.addEventListener('click', () => {
                let newTheme = body.classList.contains('dark-theme') ? 'light' : 'dark';
                applyTheme(newTheme);
                localStorage.setItem('theme', newTheme); // Save the new theme preference
            });
        }

        // Optional: Listen for system preference changes
        const darkModeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        const systemThemeChangeHandler = (event) => {
             // Only change if no theme is explicitly saved by the user in localStorage
             if (!localStorage.getItem('theme')) {
                 applyTheme(event.matches ? 'dark' : 'light');
             }
         };

        try {
            // Newer browsers: addEventListener
            if (darkModeMediaQuery.addEventListener) {
                darkModeMediaQuery.addEventListener('change', systemThemeChangeHandler);
            }
            // Deprecated older browsers: addListener
            else if (darkModeMediaQuery.addListener) {
                darkModeMediaQuery.addListener(systemThemeChangeHandler);
            }
        } catch (e) {
            console.error('Error adding listener for system theme changes:', e);
        }
    }); // End DOMContentLoaded for theme toggle
</script>


<?php $conn = null; // Close database connection ?>
</body>
</html>