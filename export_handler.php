<?php
session_start(); // Resume the session to potentially access user info if needed

// Include the Composer autoloader to load Dompdf
require 'vendor/autoload.php';

// Reference the Dompdf namespace
use Dompdf\Dompdf;
use Dompdf\Options;

// --- Configuration and Setup ---
$currencySymbol = '$'; // Ensure this matches reports.php
$servername = "localhost";
$dbUsername = "root";
$dbPassword = "";
$dbname = "login";
$conn = null;
$dbErrorMessage = null;

// --- Helper Function (Copied from reports.php) ---
function formatCurrency($amount, $symbol = '$') {
    $amount = floatval($amount);
    $formattedAmount = number_format($amount, 2);
    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . $formattedAmount;
}

// --- Database Connection (Copied from reports.php) ---
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbUsername, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    $dbErrorMessage = "Database connection failed: " . $e->getMessage();
    // Optionally log the error
    error_log("DB Connection Error (export_handler.php): " . $e->getMessage());
    // Output a user-friendly error and exit - PDF cannot be generated
    header('Content-Type: text/plain');
    die("Error: Could not connect to the database to generate the report.");
}

// --- Get Parameters from URL ---
$format = filter_input(INPUT_GET, 'format', FILTER_SANITIZE_SPECIAL_CHARS);
$selectedPeriod = filter_input(INPUT_GET, 'period', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'this_month';
$customStartDateInput = filter_input(INPUT_GET, 'start', FILTER_SANITIZE_SPECIAL_CHARS);
$customEndDateInput = filter_input(INPUT_GET, 'end', FILTER_SANITIZE_SPECIAL_CHARS);

// --- Exit if not PDF format ---
if ($format !== 'pdf') {
    header('Content-Type: text/plain');
    die("Error: Invalid export format specified.");
}

// --- Determine Date Range (Copied & Adapted from reports.php) ---
$today = date('Y-m-d');
$reportStartDate = '';
$reportEndDate = $today;
$reportPeriodLabel = 'This Month';
$filenameSuffix = date('Y-m'); // Default filename part

switch ($selectedPeriod) {
    case 'last_month':
        $reportStartDate = date('Y-m-01', strtotime('first day of last month'));
        $reportEndDate = date('Y-m-t', strtotime('last day of last month'));
        $reportPeriodLabel = 'Last Month';
        $filenameSuffix = date('Y-m', strtotime('last month'));
        break;
    case 'last_90_days':
        $reportStartDate = date('Y-m-d', strtotime('-89 days'));
        $reportEndDate = $today;
        $reportPeriodLabel = 'Last 90 Days';
        $filenameSuffix = 'last90days_' . date('Y-m-d');
        break;
    case 'this_year':
        $reportStartDate = date('Y-01-01');
        $reportEndDate = $today;
        $reportPeriodLabel = 'This Year';
        $filenameSuffix = date('Y');
        break;
    case 'all_time':
        $reportStartDate = null;
        $reportEndDate = null;
        $reportPeriodLabel = 'All Time';
         $filenameSuffix = 'alltime';
        break;
    case 'custom':
        $d1 = DateTime::createFromFormat('Y-m-d', $customStartDateInput);
        $d2 = DateTime::createFromFormat('Y-m-d', $customEndDateInput);
        $reportStartDate = ($d1 && $d1->format('Y-m-d') === $customStartDateInput) ? $customStartDateInput : date('Y-m-01');
        $reportEndDate = ($d2 && $d2->format('Y-m-d') === $customEndDateInput) ? $customEndDateInput : $today;
        if ($reportStartDate && $reportEndDate && $reportStartDate > $reportEndDate) {
            list($reportStartDate, $reportEndDate) = [$reportEndDate, $reportStartDate];
        }
        $reportPeriodLabel = htmlspecialchars($reportStartDate) . ' to ' . htmlspecialchars($reportEndDate);
        $filenameSuffix = str_replace('-', '', $reportStartDate) . '_to_' . str_replace('-', '', $reportEndDate);
        break;
    case 'this_month':
    default:
        $reportStartDate = date('Y-m-01');
        $reportEndDate = $today;
        $reportPeriodLabel = 'This Month (' . date('M Y') . ')';
        $filenameSuffix = date('Y-m');
        break;
}

// --- Fetch Data (Copied & Adapted from reports.php) ---
// Initialize variables needed for the report
$reportTotalIncome = 0;
$reportTotalExpenses = 0;
$reportNetBalance = 0;
$reportIncomeSources = [];
$reportExpenseCategories = [];
$budgetBreakdownReport = [];
$budgetAllocatedTotal = 0;
$budgetSpentTotalPeriod = 0;
$fetchedTransactions = [];
$fetchErrorMessage = null; // Use this to track data fetch errors

if ($conn) { // Proceed only if DB connection is valid
    try {
        // Aggregate Income
        $sqlIncome = "SELECT income_category, SUM(income_amount) as total_amount FROM incomes";
        $incomeParams = []; $incomeWhereConditions = [];
        if ($reportStartDate) { $incomeWhereConditions[] = "income_date >= :start_date"; $incomeParams[':start_date'] = $reportStartDate; }
        if ($reportEndDate) { $incomeWhereConditions[] = "income_date <= :end_date"; $incomeParams[':end_date'] = $reportEndDate; }
        if (!empty($incomeWhereConditions)) { $sqlIncome .= " WHERE " . implode(' AND ', $incomeWhereConditions); }
        $sqlIncome .= " GROUP BY income_category ORDER BY total_amount DESC";
        $stmtIncome = $conn->prepare($sqlIncome); $stmtIncome->execute($incomeParams);
        $periodIncomeAggregates = $stmtIncome->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periodIncomeAggregates as $agg) {
            $category = isset($agg['income_category']) && !empty(trim($agg['income_category'])) ? trim($agg['income_category']) : 'Uncategorized';
            $amount = floatval($agg['total_amount'] ?? 0); $reportTotalIncome += $amount;
            $reportIncomeSources[$category] = ($reportIncomeSources[$category] ?? 0) + $amount;
        }

        // Aggregate Expenses
        $sqlExpenses = "SELECT expense_category, SUM(expense_amount) as total_amount FROM expenses";
        $expenseParams = []; $expenseWhereConditions = [];
        if ($reportStartDate) { $expenseWhereConditions[] = "expense_date >= :start_date_exp"; $expenseParams[':start_date_exp'] = $reportStartDate; }
        if ($reportEndDate) { $expenseWhereConditions[] = "expense_date <= :end_date_exp"; $expenseParams[':end_date_exp'] = $reportEndDate; }
        if (!empty($expenseWhereConditions)) { $sqlExpenses .= " WHERE " . implode(' AND ', $expenseWhereConditions); }
        $sqlExpenses .= " GROUP BY expense_category ORDER BY total_amount DESC";
        $stmtExpenses = $conn->prepare($sqlExpenses); $stmtExpenses->execute($expenseParams);
        $periodExpenseAggregates = $stmtExpenses->fetchAll(PDO::FETCH_ASSOC);
        foreach ($periodExpenseAggregates as $agg) {
            $category = isset($agg['expense_category']) && !empty(trim($agg['expense_category'])) ? trim($agg['expense_category']) : 'Uncategorized';
            $amount = floatval($agg['total_amount'] ?? 0); $reportTotalExpenses += $amount;
            $reportExpenseCategories[$category] = ($reportExpenseCategories[$category] ?? 0) + $amount;
        }

        // Net Balance
        $reportNetBalance = $reportTotalIncome - $reportTotalExpenses;

        // Fetch Transactions Log
        $combinedParams = [];
        $incomeWhereClauseForLog = ""; $expenseWhereClauseForLog = "";
        // Build WHERE clauses for the UNION query (copied logic from reports.php)
         if (!empty($incomeWhereConditions)) {
             $incomeLogPlaceholders = [];
             foreach ($incomeWhereConditions as $cond) {
                 if (strpos($cond, ':start_date') !== false) { $incomeLogPlaceholders[] = str_replace(':start_date', ':start_date_income_log', $cond); $combinedParams[':start_date_income_log'] = $incomeParams[':start_date']; }
                 elseif (strpos($cond, ':end_date') !== false) { $incomeLogPlaceholders[] = str_replace(':end_date', ':end_date_income_log', $cond); $combinedParams[':end_date_income_log'] = $incomeParams[':end_date']; }
                 else { $incomeLogPlaceholders[] = $cond; }
             } $incomeWhereClauseForLog = " WHERE " . implode(' AND ', $incomeLogPlaceholders);
         }
        if (!empty($expenseWhereConditions)) {
            $expenseLogPlaceholders = [];
            foreach ($expenseWhereConditions as $cond) {
                 if (strpos($cond, ':start_date_exp') !== false) { $expenseLogPlaceholders[] = str_replace(':start_date_exp', ':start_date_expense_log', $cond); $combinedParams[':start_date_expense_log'] = $expenseParams[':start_date_exp']; }
                 elseif (strpos($cond, ':end_date_exp') !== false) { $expenseLogPlaceholders[] = str_replace(':end_date_exp', ':end_date_expense_log', $cond); $combinedParams[':end_date_expense_log'] = $expenseParams[':end_date_exp']; }
                 else { $expenseLogPlaceholders[] = $cond; }
             } $expenseWhereClauseForLog = " WHERE " . implode(' AND ', $expenseLogPlaceholders);
        }
        $sqlIncomeLog = "SELECT id, income_date as date, income_description as description, income_category as category, received_method as paymentMethod, income_amount as amount, 'income' as type FROM incomes" . $incomeWhereClauseForLog;
        $sqlExpenseLog = "SELECT id, expense_date as date, expense_description as description, expense_category as category, payment_method as paymentMethod, expense_amount as amount, 'expense' as type FROM expenses" . $expenseWhereClauseForLog;
        $sqlCombinedLog = "($sqlIncomeLog) UNION ALL ($sqlExpenseLog) ORDER BY date DESC, id DESC";
        $stmtCombinedLog = $conn->prepare($sqlCombinedLog);
        $stmtCombinedLog->execute($combinedParams);
        $fetchedTransactions = $stmtCombinedLog->fetchAll(PDO::FETCH_ASSOC);

        // Budget Breakdown
        $stmtBudget = $conn->query("SELECT category, allocated FROM budget_breakdown ORDER BY category ASC");
        $budgetAllocationsDB = $stmtBudget->fetchAll(PDO::FETCH_ASSOC);
        foreach ($budgetAllocationsDB as $budget) {
            $category = $budget['category']; $allocated = floatval($budget['allocated']);
            $budgetAllocatedTotal += $allocated;
            $spentInCategoryPeriod = $reportExpenseCategories[$category] ?? 0;
            $budgetSpentTotalPeriod += $spentInCategoryPeriod;
            $remaining = $allocated - $spentInCategoryPeriod;
            $usage_percent = ($allocated > 0) ? round(($spentInCategoryPeriod / $allocated) * 100) : 0;
            $budgetBreakdownReport[$category] = ['allocated' => $allocated, 'spent' => $spentInCategoryPeriod, 'remaining' => $remaining, 'usage_percent' => $usage_percent];
        }

    } catch (PDOException $e) {
        $fetchErrorMessage = "Error fetching report data: " . $e->getMessage();
        error_log("Report Data Fetch Error (export_handler.php): " . $e->getMessage());
        header('Content-Type: text/plain');
        die("Error: Could not retrieve data to generate the report. " . $fetchErrorMessage);
    }
} else {
    // If $conn was null initially
    header('Content-Type: text/plain');
    die("Error: Database connection issue prevented report generation.");
}

// --- Generate HTML Content for PDF ---
// Start output buffering to capture HTML
ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Report - <?php echo htmlspecialchars($reportPeriodLabel); ?></title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #333; } /* DejaVu Sans supports more characters */
        h1, h2 { text-align: center; color: #111827; margin-bottom: 15px; }
        h1 { font-size: 18px; }
        h2 { font-size: 14px; border-bottom: 1px solid #eee; padding-bottom: 5px; margin-top: 25px; }
        .period-label { text-align: center; font-size: 12px; color: #555; margin-bottom: 20px; }
        .summary-stats { margin: 20px 0; padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9; border-radius: 5px; }
        .summary-stats table { width: 100%; border-collapse: collapse; }
        .summary-stats td { padding: 5px; font-size: 11px; }
        .summary-stats .label { font-weight: bold; color: #555; width: 40%;}
        .summary-stats .value { text-align: right; font-weight: bold; }
        .summary-stats .value.positive { color: #16a34a; }
        .summary-stats .value.negative { color: #ef4444; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 9px; }
        th, td { border: 1px solid #ccc; padding: 6px; text-align: left; vertical-align: top; word-wrap: break-word; }
        th { background-color: #e9ecef; font-weight: bold; }
        tbody tr:nth-child(even) { background-color: #f8f9fa; }
        tfoot td { background-color: #e9ecef; font-weight: bold; }
        .text-right { text-align: right; }
        .text-success { color: #16a34a; }
        .text-danger { color: #dc3545; }
        .fw-bold { font-weight: bold; }
        .description-col { max-width: 200px; } /* Limit description width */
        .category-col { max-width: 120px; }
        .progress-container { white-space: nowrap; } /* Prevent progress bar wrapping */
        .progress { display: inline-block; border: 1px solid #ccc; height: 8px; width: 80px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; vertical-align: middle; margin-left: 5px;}
        .progress-bar { height: 100%; background-color: #0d6efd; display: block;}
        .progress-bar.bg-warning { background-color: #ffc107; }
        .progress-bar.bg-danger { background-color: #dc3545; }
        .footer { text-align: center; font-size: 8px; color: #888; margin-top: 30px; position: fixed; bottom: 0; left: 0; right: 0; } /* Fixed footer for page numbers maybe */
    </style>
</head>
<body>

    <h1>Financial Report</h1>
    <div class="period-label">Period: <?php echo htmlspecialchars($reportPeriodLabel); ?></div>

    <!-- Summary Section -->
    <div class="summary-stats">
        <table>
            <tr>
                <td class="label">Total Income:</td>
                <td class="value text-success"><?php echo formatCurrency($reportTotalIncome, $currencySymbol); ?></td>
            </tr>
            <tr>
                <td class="label">Total Expenses:</td>
                <td class="value text-danger"><?php echo formatCurrency($reportTotalExpenses, $currencySymbol); ?></td>
            </tr>
             <tr>
                <td class="label">Net Balance:</td>
                <td class="value <?php echo ($reportNetBalance >= 0) ? 'positive' : 'negative'; ?>">
                    <?php echo formatCurrency($reportNetBalance, $currencySymbol); ?>
                </td>
            </tr>
        </table>
    </div>

    <!-- Income Breakdown -->
    <?php if (!empty($reportIncomeSources)): ?>
        <h2>Income by Source</h2>
        <table>
            <thead><tr><th>Source</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($reportIncomeSources as $source => $amount): ?>
                <tr>
                    <td><?php echo htmlspecialchars($source); ?></td>
                    <td class="text-right text-success fw-bold"><?php echo formatCurrency($amount, $currencySymbol); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td>Total Income</td><td class="text-right text-success fw-bold"><?php echo formatCurrency($reportTotalIncome, $currencySymbol); ?></td></tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <!-- Expense Breakdown -->
     <?php if (!empty($reportExpenseCategories)): ?>
        <h2>Expenses by Category</h2>
        <table>
            <thead><tr><th>Category</th><th class="text-right">Amount</th></tr></thead>
            <tbody>
                <?php foreach ($reportExpenseCategories as $category => $amount): ?>
                <tr>
                    <td><?php echo htmlspecialchars($category); ?></td>
                    <td class="text-right text-danger fw-bold"><?php echo formatCurrency($amount, $currencySymbol); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
             <tfoot>
                <tr><td>Total Expenses</td><td class="text-right text-danger fw-bold"><?php echo formatCurrency($reportTotalExpenses, $currencySymbol); ?></td></tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <!-- Budget Performance -->
    <?php if (!empty($budgetBreakdownReport)): ?>
        <h2>Budget Performance</h2>
        <table>
             <thead><tr><th>Category</th><th class="text-right">Budgeted</th><th class="text-right">Spent (Period)</th><th class="text-right">Remaining</th><th>Usage (%)</th></tr></thead>
             <tbody>
                 <?php foreach ($budgetBreakdownReport as $category => $data): ?>
                     <?php
                       $barClass = '';
                       if ($data['usage_percent'] > 100) $barClass = 'bg-danger';
                       elseif ($data['usage_percent'] >= 85) $barClass = 'bg-warning';
                       $remainingClass = ($data['remaining'] >= 0) ? 'text-success' : 'text-danger';
                     ?>
                     <tr>
                         <td><?php echo htmlspecialchars($category); ?></td>
                         <td class="text-right"><?php echo formatCurrency($data['allocated'], $currencySymbol); ?></td>
                         <td class="text-right"><?php echo formatCurrency($data['spent'], $currencySymbol); ?></td>
                         <td class="text-right <?php echo $remainingClass; ?>"><?php echo formatCurrency($data['remaining'], $currencySymbol); ?></td>
                         <td>
                            <span class="progress-container">
                                <?php echo $data['usage_percent']; ?>%
                                <span class="progress">
                                    <span class="progress-bar <?php echo $barClass; ?>" style="width: <?php echo min(100, $data['usage_percent']); ?>%;"></span>
                                </span>
                            </span>
                         </td>
                     </tr>
                 <?php endforeach; ?>
             </tbody>
             <tfoot>
                  <tr>
                     <td>Total</td>
                     <td class="text-right"><?php echo formatCurrency($budgetAllocatedTotal, $currencySymbol); ?></td>
                     <td class="text-right"><?php echo formatCurrency($budgetSpentTotalPeriod, $currencySymbol); ?></td>
                     <td class="text-right <?php echo (($budgetAllocatedTotal - $budgetSpentTotalPeriod) >= 0) ? 'text-success' : 'text-danger'; ?>">
                        <?php echo formatCurrency($budgetAllocatedTotal - $budgetSpentTotalPeriod, $currencySymbol); ?>
                     </td>
                     <td>
                         <?php
                           $overallBudgetUsage = ($budgetAllocatedTotal > 0 ? round(($budgetSpentTotalPeriod / $budgetAllocatedTotal) * 100) : 0);
                           $overallBarClass = '';
                           if ($overallBudgetUsage > 100) $overallBarClass = 'bg-danger';
                           elseif ($overallBudgetUsage >= 85) $overallBarClass = 'bg-warning';
                         ?>
                         <span class="progress-container">
                             <?php echo $overallBudgetUsage; ?>%
                             <span class="progress">
                                 <span class="progress-bar <?php echo $overallBarClass; ?>" style="width: <?php echo min(100, $overallBudgetUsage); ?>%;"></span>
                             </span>
                         </span>
                     </td>
                  </tr>
             </tfoot>
        </table>
         <p style="font-size: 8px; color: #666; text-align: center;">* "Spent (Period)" shows expenses recorded during the selected report timeframe only.</p>
    <?php endif; ?>


    <!-- Transaction Log -->
    <?php if (!empty($fetchedTransactions)): ?>
        <h2>Transaction Log</h2>
        <table>
             <thead><tr><th>Date</th><th class="description-col">Description</th><th class="category-col">Category</th><th>Method</th><th class="text-right">Amount</th></tr></thead>
             <tbody>
                 <?php foreach($fetchedTransactions as $tx): ?>
                     <tr>
                         <td><?php echo htmlspecialchars(date("Y-m-d", strtotime($tx['date'] ?? '')) ?: 'N/A'); ?></td>
                         <td class="description-col"><?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?></td>
                         <td class="category-col"><?php echo htmlspecialchars($tx['category'] ?? 'N/A'); ?></td>
                         <td><?php echo htmlspecialchars($tx['paymentMethod'] ?? 'N/A'); ?></td>
                         <td class="text-right <?php echo ($tx['type'] === 'income') ? 'text-success' : 'text-danger'; ?> fw-bold">
                            <?php echo ($tx['type'] === 'income' ? '+' : '-') . formatCurrency($tx['amount'] ?? 0, $currencySymbol); ?>
                         </td>
                     </tr>
                 <?php endforeach; ?>
             </tbody>
        </table>
    <?php else: ?>
         <h2>Transaction Log</h2>
         <p style="text-align: center; color: #666;">No transactions found for this period.</p>
    <?php endif; ?>

    <!-- Optional Footer for PDF -->
    <div class="footer">
        Generated on <?php echo date('Y-m-d H:i:s'); ?> by FinDash
    </div>

</body>
</html>
<?php
// Get the generated HTML content from the buffer
$html = ob_get_clean();

// --- Instantiate and use Dompdf ---
$options = new Options();
$options->set('isRemoteEnabled', false); // Disable remote images/CSS for security unless needed
$options->set('defaultFont', 'DejaVu Sans'); // Set default font supporting more characters
$dompdf = new Dompdf($options);

// Load HTML content
$dompdf->loadHtml($html);

// (Optional) Set Paper Size and Orientation
$dompdf->setPaper('A4', 'portrait'); // or 'landscape'

// Render the HTML as PDF
$dompdf->render();

// --- Output the generated PDF to Browser ---
$pdfFilename = "financial_report_" . $filenameSuffix . ".pdf";

// Set headers to force download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $pdfFilename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Stream the PDF to the browser
// Use $dompdf->output(); to get the PDF string if you want to save it first
echo $dompdf->output();

// Close DB connection (optional as script ends, but good practice)
$conn = null;
exit; // Important to prevent any further output

?>