<?php
session_start();

$currencySymbol = '$'; 
$userName = isset($_SESSION['firstName']) ? htmlspecialchars($_SESSION['firstName']) : "User";


$servername = "localhost";
$dbUsername = "root";
$dbPassword = "";
$dbname = "login"; 
$conn = null;
$errorMessage = null;


$totalIncomeThisMonth = 0;
$totalExpensesThisMonth = 0;
$netBalanceThisMonth = 0;
$currentAccountBalance = 0;
$recentTransactions = [];
$budgetBreakdown = [];
$budgetTotal = 0;
$budgetSpentTotal = 0;
$budgetRemaining = 0;
$budgetUsedPercent = 0;
$incomeSources = [];
$expenseCategories = [];
$savingsGoals = [];
$alerts = [];
$overviewChartData = [['label' => 'Income', 'value' => 0], ['label' => 'Expenses', 'value' => 0]];
$monthlySpendingSummaryData = [];



try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $dbUsername, $dbPassword);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $errorMessage = "Database connection failed. Please check configuration and ensure the database server is running.";
    error_log("Dashboard DB Connection Error: " . $e->getMessage());
}


function formatCurrency($amount, $symbol = '$') {
    if (!is_numeric($amount)) {
        $amount = 0;
    }
    $rawValue = floatval($amount);
    $formattedValue = htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format($rawValue, 2);
    return "<span data-value=\"{$rawValue}\">{$formattedValue}</span>";
}



if ($conn && !$errorMessage) { 
    try {
        
        $currentMonthStart = date('Y-m-01');
        $currentMonthEnd = date('Y-m-t');

        
        $sqlIncomeMonth = "SELECT income_category, SUM(income_amount) as total_amount
                           FROM incomes
                           WHERE income_date BETWEEN :start_date AND :end_date
                           GROUP BY income_category";
        $stmtIncomeMonth = $conn->prepare($sqlIncomeMonth);
        $stmtIncomeMonth->execute([':start_date' => $currentMonthStart, ':end_date' => $currentMonthEnd]);
        $monthIncomeAggregates = $stmtIncomeMonth->fetchAll(PDO::FETCH_ASSOC);
        $totalIncomeThisMonth = 0; $incomeSources = [];
        foreach ($monthIncomeAggregates as $agg) { $category = isset($agg['income_category']) && !empty(trim($agg['income_category'])) ? trim($agg['income_category']) : 'Uncategorized'; $amount = floatval($agg['total_amount'] ?? 0); $totalIncomeThisMonth += $amount; $incomeSources[$category] = ($incomeSources[$category] ?? 0) + $amount; }
        arsort($incomeSources);

        
        $sqlExpensesMonth = "SELECT expense_category, SUM(expense_amount) as total_amount
                             FROM expenses
                             WHERE expense_date BETWEEN :start_date AND :end_date
                             GROUP BY expense_category";
        $stmtExpensesMonth = $conn->prepare($sqlExpensesMonth);
        $stmtExpensesMonth->execute([':start_date' => $currentMonthStart, ':end_date' => $currentMonthEnd]);
        $monthExpenseAggregates = $stmtExpensesMonth->fetchAll(PDO::FETCH_ASSOC);
        $totalExpensesThisMonth = 0; $expenseCategories = [];
        foreach ($monthExpenseAggregates as $agg) { $category = isset($agg['expense_category']) && !empty(trim($agg['expense_category'])) ? trim($agg['expense_category']) : 'Uncategorized'; $amount = floatval($agg['total_amount'] ?? 0); $totalExpensesThisMonth += $amount; $expenseCategories[$category] = ($expenseCategories[$category] ?? 0) + $amount; }
        arsort($expenseCategories);

        
        $netBalanceThisMonth = $totalIncomeThisMonth - $totalExpensesThisMonth;
        $overviewChartData = [ ['label' => 'Income', 'value' => round($totalIncomeThisMonth, 2)], ['label' => 'Expenses', 'value' => round($totalExpensesThisMonth, 2)] ];

        
        $sqlRecentIncome = "SELECT id, income_date as date, income_description as description, income_category as category, received_method as paymentMethod, income_amount as amount, 'income' as type FROM incomes ORDER BY income_date DESC, id DESC LIMIT 10";
        $stmtRecentIncome = $conn->query($sqlRecentIncome); $recentIncome = $stmtRecentIncome->fetchAll(PDO::FETCH_ASSOC);
        $sqlRecentExpenses = "SELECT id, expense_date as date, expense_description as description, expense_category as category, payment_method as paymentMethod, expense_amount as amount, 'expense' as type FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 10";
        $stmtRecentExpenses = $conn->query($sqlRecentExpenses); $recentExpenses = $stmtRecentExpenses->fetchAll(PDO::FETCH_ASSOC);
        $combinedTransactions = array_merge($recentIncome, $recentExpenses);
        usort($combinedTransactions, function($a, $b) { $dateA = $a['date'] ?? '0000-00-00'; $dateB = $b['date'] ?? '0000-00-00'; if ($dateA == $dateB) { return ($b['id'] ?? 0) <=> ($a['id'] ?? 0); } return strtotime($dateB) <=> strtotime($dateA); });
        $recentTransactions = array_slice($combinedTransactions, 0, 10);

        
        $budgetBreakdown = []; $budgetTotal = 0; $budgetSpentTotal = $totalExpensesThisMonth;
        $stmtBudget = $conn->query("SELECT category, allocated FROM budget_breakdown ORDER BY category ASC");
        $budgetAllocationsDB = $stmtBudget->fetchAll(PDO::FETCH_ASSOC);
        foreach ($budgetAllocationsDB as $budget) { $category = $budget['category']; $allocated = floatval($budget['allocated']); $budgetTotal += $allocated; $spentInCategoryMonth = $expenseCategories[$category] ?? 0; $budgetBreakdown[$category] = [ 'allocated' => round($allocated, 2), 'spent' => round($spentInCategoryMonth, 2) ]; }
        $budgetRemaining = ($budgetTotal > 0) ? ($budgetTotal - $budgetSpentTotal) : 0;
        $budgetUsedPercent = ($budgetTotal > 0) ? round(($budgetSpentTotal / $budgetTotal) * 100) : 0;

        
        $stmtGoals = $conn->query("SELECT name, target, current, deadline FROM financial_goals ORDER BY deadline ASC, name ASC LIMIT 4");
        $savingsGoals = $stmtGoals->fetchAll(PDO::FETCH_ASSOC);

        
        $alerts = [];
        if (!empty($budgetBreakdown)) { $warningAlertGenerated = false; $dangerAlertGenerated = false; foreach ($budgetBreakdown as $category => $data) { if (!isset($data['allocated']) || !isset($data['spent']) || $data['allocated'] <= 0) continue; $percentSpent = round(($data['spent'] / $data['allocated']) * 100); if (!$dangerAlertGenerated && $percentSpent > 105) { $alerts[] = [ 'type' => 'danger', 'message' => "Over Budget: ".htmlspecialchars($category)." ({$percentSpent}%)!" ]; $dangerAlertGenerated = true; } elseif (!$warningAlertGenerated && !$dangerAlertGenerated && $percentSpent >= 90) { $alerts[] = [ 'type' => 'warning', 'message' => "Budget Warning: ".htmlspecialchars($category)." ({$percentSpent}% used)." ]; $warningAlertGenerated = true; } if ($warningAlertGenerated && $dangerAlertGenerated) break; } }
        if (empty($alerts) || (count($alerts) === 1 && $alerts[0]['type'] === 'info' && !$warningAlertGenerated && !$dangerAlertGenerated)) {
            $alerts = [['type' => 'info', 'message' => "No critical alerts."]]; 
        }

        
         $sqlWeekly = "SELECT FLOOR((DAYOFMONTH(expense_date) - 1) / 7) + 1 as week_num, SUM(expense_amount) as weekly_total FROM expenses WHERE expense_date BETWEEN :start_date AND :end_date GROUP BY week_num ORDER BY week_num ASC";
         $stmtWeekly = $conn->prepare($sqlWeekly); $stmtWeekly->execute([':start_date' => $currentMonthStart, ':end_date' => $currentMonthEnd]); $weeklyData = $stmtWeekly->fetchAll(PDO::FETCH_ASSOC); $num_weeks_in_month = ceil(date('t', strtotime($currentMonthStart)) / 7); $monthlySpendingSummaryData = []; for ($w = 1; $w <= $num_weeks_in_month; $w++) { $monthlySpendingSummaryData["Week " . $w] = 0; } foreach($weeklyData as $week) { $weekKey = "Week " . intval($week['week_num']); if (isset($monthlySpendingSummaryData[$weekKey])) { $monthlySpendingSummaryData[$weekKey] = round(floatval($week['weekly_total']), 2); } }

        
        $stmtTotalIncome = $conn->query("SELECT SUM(income_amount) as total FROM incomes");
        $totalIncomeAllTime = floatval($stmtTotalIncome->fetchColumn() ?? 0);
        $stmtTotalExpense = $conn->query("SELECT SUM(expense_amount) as total FROM expenses");
        $totalExpenseAllTime = floatval($stmtTotalExpense->fetchColumn() ?? 0);
        $currentAccountBalance = $totalIncomeAllTime - $totalExpenseAllTime;

    } catch (PDOException $e) {
        $errorMessage = "Error fetching data: " . htmlspecialchars($e->getMessage());
        error_log("Dashboard Data Fetch Error: " . $e->getMessage());
        
        $recentTransactions = []; $budgetBreakdown = []; $incomeSources = []; $expenseCategories = []; $savingsGoals = []; $alerts = []; $monthlySpendingSummaryData = []; $overviewChartData = [['label' => 'Income', 'value' => 0], ['label' => 'Expenses', 'value' => 0]]; $totalIncomeThisMonth = 0; $totalExpensesThisMonth = 0; $netBalanceThisMonth = 0; $budgetTotal = 0; $budgetSpentTotal = 0; $budgetRemaining = 0; $budgetUsedPercent = 0; $currentAccountBalance = 0;
    }
} 


$jsData = json_encode([
    'currencySymbol' => $currencySymbol,
    'overviewChartData' => $overviewChartData,
    'budgetBreakdown' => $budgetBreakdown,
    'monthlySpendingSummary' => $monthlySpendingSummaryData,
    'incomeSources' => $incomeSources,
    'expenseCategories' => $expenseCategories,
], JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK | JSON_INVALID_UTF8_IGNORE);



$financialTips = [ "Review bank statements regularly.", "Stick to your budget.", "Build an emergency fund.", "Pay high-interest debt first.", "Save for retirement early.", "Automate savings.", "Wait 24h before large buys.", "Compare prices.", "Needs vs wants.", "Set SMART goals." ];
$financialTip = $financialTips[array_rand($financialTips)];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard - <?php echo $userName; ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        
        :root {
            --sidebar-width: 250px;
            --sidebar-collapsed-width: 0px;
            --header-height: 65px;

            
            --primary-color: #0a2342; 
            --primary-hover: #081c35; 
            --success-color: #7fffd4; 
            --success-hover-color: #5fdec9; 
            --accent-color-1: #2ca58d; 
            --accent-color-2: #1d3557; 
            --accent-color-3: #f0f9ff; 
            --accent-color-4: #e0f2fe; 

            
            --secondary-color: #64748b; 
            --danger-color: #ef4444; 
            --warning-color: #f59e0b; 
            --info-color: #3b82f6; 

            
            --light-bg: var(--accent-color-3); 
            --card-bg: rgba(255, 255, 255, 0.65); 
            --card-border-color: rgba(255, 255, 255, 0.4); 
            --card-border: 1px solid var(--card-border-color);
            --header-bg: rgba(255, 255, 255, 0.85); 

            
            --text-primary: #1e293b; 
            --text-secondary: #475569; 
            --text-muted: #94a3b8; 
            --text-light: #f8fafc; 

            
            --sidebar-bg: #1a2a3a; 
            --sidebar-text: var(--text-light); 
            --sidebar-icon: #b0b0b0; 
            --sidebar-active-bg: var(--success-color); 
            --sidebar-active-text: var(--primary-color); 
            --sidebar-hover-bg: rgba(44, 62, 80, 0.7); 
            --sidebar-separator: rgba(44, 62, 80, 0.5); 

            
            --input-bg-light: #f8f9fa; 
            --input-border-light: #ced4da; 
            --input-focus-border-light: #80bdff; 
            --input-focus-bg-light: #ffffff; 

            
            --error-bg-light: #fee2e2; 
            --error-text-dark: #b91c1c; 
            --error-border-light: #fecaca; 
            --success-bg-light: #dcfce7; 
            --success-text-dark: #15803d; 
            --success-border-light: #bbf7d0; 

            
            --font-family-base: 'Inter', sans-serif;
            --border-radius: 10px; 
            --card-shadow: 0 8px 32px 0 rgba(31, 38, 135, 0.17); 
            --button-shadow: 0 2px 5px rgba(0,0,0,0.15);
            --transition-speed: 0.3s;
            --transition-function: ease-in-out;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-family-base);
            background-color: var(--light-bg);
            color: var(--text-primary);
            line-height: 1.6;
            display: flex;
            min-height: 100vh;
            font-size: 16px;
            transition: padding-left var(--transition-speed) var(--transition-function);
        }

        
        @keyframes gradientFlowSidebar { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        @keyframes gradientFlowMain { 0% { background-position: 10% 0%; } 50% { background-position: 91% 100%; } 100% { background-position: 10% 0%; } }

        
        .sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(-45deg, var(--sidebar-bg), var(--primary-color), var(--accent-color-2), var(--accent-color-1));
            background-size: 400% 400%;
            animation: gradientFlowSidebar 18s ease infinite;
            color: var(--sidebar-text);
            position: fixed; top: 0; left: 0; height: 100%;
            z-index: 1030; overflow-x: hidden; overflow-y: auto;
            display: flex; flex-direction: column;
            transition: width var(--transition-speed) var(--transition-function), transform var(--transition-speed) var(--transition-function);
            border-right: 1px solid var(--sidebar-separator);
        }

        
        .sidebar-profile-link { display: block; text-decoration: none; color: inherit; background-color: rgba(0,0,0, 0.1); transition: background-color var(--transition-speed) var(--transition-function); }
        .sidebar-profile-link:hover { background-color: rgba(0,0,0, 0.2); }
        .sidebar-profile { padding: 25px 20px; text-align: center; border-bottom: 1px solid var(--sidebar-separator); flex-shrink: 0; position: relative; z-index: 2; }
        .profile-image { width: 70px; height: 70px; border-radius: 50%; background: linear-gradient(135deg, var(--success-color), var(--accent-color-1)); margin: 0 auto 12px auto; display: flex; align-items: center; justify-content: center; font-size: 2.8rem; color: var(--primary-color); box-shadow: 0 2px 4px rgba(0,0,0,0.2); border: 2px solid rgba(255,255,255,0.5); }
        .profile-username { color: #f9fafb; font-weight: 600; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        
        .sidebar nav { padding-top: 1rem; flex-grow: 1; position: relative; z-index: 2;}
        .sidebar nav ul { list-style: none; padding: 0; margin: 0; }
        .sidebar nav ul li a { display: flex; align-items: center; color: var(--sidebar-text); text-decoration: none; padding: 0.9rem 1.5rem; font-size: 0.95rem; transition: background-color var(--transition-speed) var(--transition-function), color var(--transition-speed) var(--transition-function), border-left-color var(--transition-speed) var(--transition-function), transform 0.2s ease-out; border-left: 4px solid transparent; white-space: nowrap; font-weight: 500; position: relative; background-color: transparent; border-radius: 0 var(--border-radius) var(--border-radius) 0; margin-right: 4px; }
        .sidebar nav ul li a i { margin-right: 15px; width: 20px; text-align: center; flex-shrink: 0; color: var(--sidebar-icon); font-size: 1.1em; transition: color 0.2s ease; vertical-align: middle; }
        .sidebar nav ul li a:hover { background-color: var(--sidebar-hover-bg); color: #ffffff; transform: translateX(3px); }
        .sidebar nav ul li a:hover i { color: #ffffff; }
        .sidebar nav ul li a.active { background-color: var(--sidebar-active-bg); color: var(--sidebar-active-text); font-weight: 600; border-left-color: var(--primary-color); box-shadow: inset 3px 0 8px -2px rgba(10, 35, 66, 0.3); }
        .sidebar nav ul li a.active i { color: var(--sidebar-active-text); }

        
        body.sidebar-collapsed .sidebar { width: var(--sidebar-collapsed-width); animation: none; }
        body.sidebar-collapsed .sidebar .sidebar-profile-link, body.sidebar-collapsed .sidebar nav ul li a span { opacity: 0; pointer-events: none; transition: opacity 0.1s ease; }
        body.sidebar-collapsed .main-content { margin-left: var(--sidebar-collapsed-width); width: calc(100% - var(--sidebar-collapsed-width)); }
        body.sidebar-collapsed .page-header { left: var(--sidebar-collapsed-width); }

        
        .main-content { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 1.5rem; flex-grow: 1; padding-top: calc(var(--header-height) + 1.5rem); transition: margin-left var(--transition-speed) var(--transition-function), width var(--transition-speed) var(--transition-function); background: linear-gradient(60deg, var(--accent-color-3), var(--accent-color-4), var(--accent-color-3)); background-size: 300% 300%; animation: gradientFlowMain 25s ease infinite alternate; }

        
        .page-header { display: flex; justify-content: space-between; align-items: center; height: var(--header-height); padding: 0 1.5rem; background-color: var(--header-bg); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); box-shadow: 0 1px 3px rgba(0,0,0,0.05); position: fixed; top: 0; left: var(--sidebar-width); right: 0; z-index: 1020; border-bottom: 1px solid var(--card-border-color); transition: left var(--transition-speed) var(--transition-function); }
        .page-header .header-left { display: flex; align-items: center; gap: 15px; }
        #sidebarToggle { background: none; border: none; font-size: 1.5rem; color: var(--text-secondary); cursor: pointer; padding: 5px; line-height: 1; transition: color 0.2s ease, transform 0.3s ease; border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; }
        #sidebarToggle:hover { color: var(--primary-color); background-color: rgba(10, 35, 66, 0.1); }
        .page-header h1 { font-size: 1.5rem; font-weight: 600; color: var(--text-primary); margin: 0; }
        .user-welcome span { font-size: 0.95rem; color: var(--text-secondary); }
        .user-welcome strong { color: var(--text-primary); font-weight: 600; }

        
        .dashboard-grid { display: grid; grid-template-columns: repeat(12, 1fr); gap: 1.5rem; }
        .grid-item {  }
        .col-span-12 { grid-column: span 12; } .col-span-8 { grid-column: span 8; } .col-span-6 { grid-column: span 6; } .col-span-4 { grid-column: span 4; } .col-span-3 { grid-column: span 3; }

        
        .card { background-color: var(--card-bg); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); border-radius: var(--border-radius); box-shadow: var(--card-shadow); border: var(--card-border); padding: 1.5rem; display: flex; flex-direction: column; overflow: hidden; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card-header { display: flex; align-items: center; gap: 0.75rem; margin: -1.5rem -1.5rem 1.5rem -1.5rem; padding: 1rem 1.5rem; border-bottom: var(--card-border); background-color: rgba(255, 255, 255, 0.3); }
        .card-header h2 { color: var(--text-primary); margin: 0; font-size: 1.15rem; font-weight: 600; flex-grow: 1; }
        .card-header i { font-size: 1.1rem; color: var(--primary-color); }
        .card-content { flex-grow: 1; }
        .card-footer { margin-top: auto; padding-top: 1rem; border-top: var(--card-border); display: flex; justify-content: flex-end; gap: 0.5rem; margin-left: -1.5rem; margin-right: -1.5rem; margin-bottom: -1.5rem; padding: 1rem 1.5rem; background-color: rgba(255, 255, 255, 0.3); }

        
        .overview-item, .summary-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; font-size: 0.95rem; padding: 0.2rem 0; }
        .overview-item:last-child, .summary-item:last-child { margin-bottom: 0; }
        .overview-item strong, .summary-item strong { color: var(--text-secondary); font-weight: 500; }
        .overview-item span.counter-value span, .summary-item span.counter-value span { font-weight: 600; text-align: right; display: inline-block; }
        .positive span { color: var(--success-color); text-shadow: 0 0 5px rgba(127, 255, 212, 0.5); }
        .negative span { color: var(--danger-color); }
        .neutral span { color: var(--text-primary); }

        
        .progress-bar-container { background-color: rgba(229, 231, 235, 0.7); border-radius: 99px; height: 10px; margin-top: 8px; overflow: hidden; position: relative; }
        .progress-bar { background-color: var(--primary-color); height: 100%; color: white; text-align: center; font-size: 0.7rem; font-weight: 500; transition: width 0.8s cubic-bezier(0.25, 1, 0.5, 1); white-space: nowrap; overflow: hidden; display: flex; align-items: center; justify-content: center; border-radius: 99px; }
        .progress-bar.high-usage { background-color: var(--warning-color); }
        .progress-bar.over-budget { background-color: var(--danger-color); }
        .goal-progress .progress-bar { background: linear-gradient(90deg, var(--accent-color-1), var(--success-color)); height: 12px; transition: width 0.8s cubic-bezier(0.25, 1, 0.5, 1); }
        .goal-progress .progress-bar-container { height: 12px; background-color: rgba(229, 231, 235, 0.7); }
        .goal-progress .progress-bar span { color: var(--primary-color); mix-blend-mode: screen; font-weight: 600; }

        
        .table-container { max-height: 380px; overflow-y: auto; margin-bottom: 1rem; width: 100%; border: var(--card-border); border-radius: var(--border-radius); }
        table.data-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .data-table th, .data-table td { text-align: left; padding: 0.8rem 1rem; border-bottom: var(--card-border); font-size: 0.9rem; vertical-align: middle; word-wrap: break-word; }
        .data-table th { background-color: rgba(249, 250, 251, 0.5); font-weight: 600; color: var(--text-secondary); position: sticky; top: 0; z-index: 1; border-top: none; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background-color: rgba(248, 250, 252, 0.6); }
        .transactions-table th:nth-child(1), .transactions-table td:nth-child(1) { width: 15%; } .transactions-table th:nth-child(2), .transactions-table td:nth-child(2) { width: 35%; } .transactions-table th:nth-child(3), .transactions-table td:nth-child(3) { width: 25%; } .transactions-table th:nth-child(4) { display: none; } .transactions-table td:nth-child(4) { display: none; } .transactions-table th:nth-child(5), .transactions-table td:nth-child(5) { width: 25%; text-align: right; }
        .amount-income span { color: var(--success-color); text-shadow: 0 0 5px rgba(127, 255, 212, 0.5); font-weight: 600; }
        .amount-expense span { color: var(--danger-color); font-weight: 600; }
        .transaction-category { font-style: normal; color: var(--text-secondary); font-size: 0.85rem; }

        
        .alert { padding: 0.9rem 1.2rem; margin-bottom: 1rem; border-radius: var(--border-radius); border: 1px solid transparent; font-size: 0.9rem; display: flex; align-items: center; gap: 10px; }
        .alert i { font-size: 1.2rem; line-height: 1; }
        .alert-info { color: #0c5460; background-color: #d1ecf1; border-color: #bee5eb; } .alert-info i { color: #0c5460; }
        .alert-warning { color: #856404; background-color: #fff3cd; border-color: #ffeeba; } .alert-warning i { color: #856404; }
        .alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; } .alert-danger i { color: #721c24; }

        
        .literacy-tip { background-color: rgba(224, 247, 250, 0.7); padding: 1.2rem; border-left: 4px solid var(--primary-color); margin: 0; font-style: normal; color: #004d40; border-radius: 0 var(--border-radius) var(--border-radius) 0; }
        .literacy-tip i { margin-right: 8px; color: var(--primary-color); }

        
        .chart-container { position: relative; min-height: 220px; width: 100%; margin-top: 1rem; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); border-radius: var(--border-radius); overflow: hidden; background: rgba(255,255,255,0.1); }
        .chart-placeholder, .chart-error { min-height: 180px; display: flex; flex-direction: column; align-items: center; justify-content: center; background-color: rgba(248, 250, 252, 0.5); border: 1px dashed var(--card-border-color); color: var(--text-muted); font-size: 0.9rem; text-align: center; padding: 1rem; border-radius: var(--border-radius); margin-top: 1rem; gap: 8px; }
        .chart-placeholder i, .chart-error i { font-size: 2rem; color: var(--text-muted); }
        .chart-error { color: var(--danger-color); background-color: rgba(254, 226, 226, 0.7); border-color: rgba(254, 202, 202, 0.8); }
        .chart-error i { color: var(--danger-color); }

        
        .button { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background-color: var(--primary-color); color: #fff; padding: 0.6rem 1.2rem; border: none; border-radius: var(--border-radius); text-decoration: none; font-size: 0.9rem; font-weight: 500; cursor: pointer; transition: background-color 0.2s ease, box-shadow 0.2s ease, transform 0.1s ease; text-align: center; line-height: 1.4; box-shadow: var(--button-shadow); }
        .button:hover { background-color: var(--primary-hover); box-shadow: 0 4px 8px rgba(0,0,0,0.15); transform: translateY(-1px); }
        .button:active { transform: translateY(0px); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .button i { line-height: 1; }
        .button-success { background-color: var(--success-color); color: var(--primary-color); }
        .button-success:hover { background-color: var(--success-hover-color); }
        .button-secondary { background-color: var(--secondary-color); color: white;}
        .button-secondary:hover { background-color: #475569; }
        .button-danger { background-color: var(--danger-color); color: white; }
        .button-danger:hover { background-color: #dc2626; }
        .button-sm { padding: 0.4rem 0.8rem; font-size: 0.8rem; }

        
        .error-message { background-color: var(--error-bg-light); color: var(--error-text-dark); border: 1px solid var(--error-border-light); padding: 1rem 1.5rem; border-radius: var(--border-radius); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.75rem; }
        .error-message i { font-size: 1.2rem; }

        
        .no-data-message { text-align: center; color: var(--text-muted); margin-top: 1rem; padding: 1.5rem; font-style: italic; font-size: 0.95rem; }

        
        .footer { text-align: center; margin-top: 2.5rem; padding: 1.5rem; font-size: 0.9rem; color: var(--text-muted); border-top: 1px solid var(--card-border-color); }

        
        .open-chatbot-btn { position: fixed; bottom: 25px; right: 25px; width: 55px; height: 55px; background-color: var(--primary-color); color: var(--text-light); border: none; border-radius: 50%; font-size: 1.5rem; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2); z-index: 1040; transition: transform 0.2s ease, background-color 0.2s ease; }
        .open-chatbot-btn:hover { background-color: var(--primary-hover); transform: scale(1.1); }
        .chatbot-widget { position: fixed; bottom: 95px; right: 25px; width: 350px; max-width: calc(100% - 40px); height: 500px; max-height: calc(100vh - 120px); background-color: var(--card-bg); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border-radius: var(--border-radius); box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15); border: var(--card-border); z-index: 1050; display: flex; flex-direction: column; overflow: hidden; opacity: 0; transform: translateY(20px) scale(0.95); visibility: hidden; transition: opacity 0.3s ease, transform 0.3s ease, visibility 0.3s; }
        .chatbot-widget.chat-widget-open { opacity: 1; transform: translateY(0) scale(1); visibility: visible; }
        .chatbot-header { display: flex; justify-content: space-between; align-items: center; padding: 0.8rem 1rem; background-color: rgba(255, 255, 255, 0.5); border-bottom: var(--card-border); color: var(--primary-color); font-weight: 600; flex-shrink: 0; }
        .chatbot-header span i { margin-right: 8px; }
        .close-chatbot-btn { background: none; border: none; font-size: 1.6rem; font-weight: bold; color: var(--text-secondary); cursor: pointer; line-height: 1; padding: 0 5px; }
        .close-chatbot-btn:hover { color: var(--danger-color); }
        .chatbot-messages { flex-grow: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.8rem; }
        .message { display: flex; max-width: 85%; }
        .message-content { padding: 0.6rem 0.9rem; border-radius: 12px; font-size: 0.9rem; line-height: 1.5; word-wrap: break-word; }
        .message.user { margin-left: auto; justify-content: flex-end; }
        .message.user .message-content { background-color: var(--primary-color); color: var(--text-light); border-bottom-right-radius: 4px; }
        .message.bot { margin-right: auto; justify-content: flex-start; }
        .message.bot .message-content { background-color: #e9ecef; color: var(--text-primary); border-bottom-left-radius: 4px; }
        .message.error .message-content { background-color: var(--error-bg-light); color: var(--error-text-dark); border: 1px solid var(--error-border-light); }
        .chatbot-loading { padding: 0.5rem 1rem; text-align: center; font-size: 0.85rem; color: var(--text-muted); flex-shrink: 0; }
        .chatbot-loading i { margin-right: 5px; }
        .chatbot-input-area { border-top: var(--card-border); padding: 0.7rem; flex-shrink: 0; background-color: rgba(255, 255, 255, 0.3); }
        .chatbot-input-area form { display: flex; align-items: center; gap: 0.5rem; }
        #chatbot-input { flex-grow: 1; padding: 0.6rem 0.8rem; border: 1px solid var(--input-border-light); border-radius: var(--border-radius-md); font-size: 0.9rem; outline: none; transition: border-color 0.2s ease; }
        #chatbot-input:focus { border-color: var(--input-focus-border-light); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2); }
        #chatbot-send { background-color: var(--success-color); color: var(--primary-color); border: none; border-radius: 50%; width: 38px; height: 38px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 1rem; cursor: pointer; transition: background-color 0.2s ease; }
        #chatbot-send:hover { background-color: var(--success-hover-color); }
        #chatbot-send:disabled { opacity: 0.6; cursor: not-allowed; }


        
        @media (max-width: 1200px) { .col-span-4 { grid-column: span 6; } .col-span-8 { grid-column: span 12; } .col-span-3 { grid-column: span 6; } .transactions-table th:nth-child(3), .transactions-table td:nth-child(3) { width: 30%; } .transactions-table th:nth-child(5), .transactions-table td:nth-child(5) { width: 20%; } }
        @media (max-width: 992px) { .col-span-6 { grid-column: span 12; } .main-content { padding: 1rem; padding-top: calc(var(--header-height) + 1rem); animation: none; } .page-header { padding: 0 1rem; } .card { padding: 1.2rem; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); } .card-header { padding: 0.8rem 1.2rem; margin: -1.2rem -1.2rem 1.2rem -1.2rem; } .card-footer { padding: 0.8rem 1.2rem; margin-left: -1.2rem; margin-right: -1.2rem; margin-bottom: -1.2rem;} }
        @media (max-width: 768px) { .sidebar { transition: transform var(--transition-speed) ease; transform: translateX(-100%); width: var(--sidebar-width); animation: none; } body:not(.sidebar-collapsed) .sidebar { transform: translateX(0); box-shadow: 0 0 20px rgba(0,0,0,0.2); animation: gradientFlowSidebar 18s ease infinite; } body.sidebar-collapsed .sidebar { transform: translateX(-100%); animation: none; } .main-content { margin-left: 0; width: 100%; transition: none; } .page-header { left: 0; transition: none; backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); } .user-welcome span { display: none; } .user-welcome strong { font-size: 1rem; } .page-header h1 { font-size: 1.3rem; } #sidebarToggle { display: block; } .transactions-table th:nth-child(3), .transactions-table td:nth-child(3) { display: none; } .transactions-table th:nth-child(1), .transactions-table td:nth-child(1) { width: 25%; } .transactions-table th:nth-child(2), .transactions-table td:nth-child(2) { width: 50%; } .transactions-table th:nth-child(5), .transactions-table td:nth-child(5) { width: 25%; } .data-table th, .data-table td { padding: 0.6rem 0.5rem; font-size: 0.85rem; } .card-footer { flex-direction: column; align-items: stretch; } .button { width: 100%; } }
        @media (max-width: 576px) { .page-header { height: auto; flex-direction: column; align-items: flex-start; padding-top: 0.8rem; padding-bottom: 0.8rem;} .page-header .header-left { width: 100%; justify-content: space-between; margin-bottom: 0.5rem; } .user-welcome { text-align: left; width: 100%; } .main-content { padding-top: 1rem; } .overview-item, .summary-item { flex-direction: column; align-items: flex-start; gap: 0.2rem;} .overview-item span.counter-value span, .summary-item span.counter-value span { text-align: left; width: 100%; margin-top: 2px;} h1 { font-size: 1.2rem; } h2 { font-size: 1.1rem; } .card { padding: 1rem; backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); } .card-header { padding: 0.7rem 1rem; margin: -1rem -1rem 1rem -1rem; } .card-footer { padding: 0.7rem 1rem; margin-left: -1rem; margin-right: -1rem; margin-bottom: -1rem;} }

    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
</head>
<body class="sidebar-collapsed"> 

    
    <aside class="sidebar">
        <a href="profile.php" class="sidebar-profile-link" title="View Profile">
            <div class="sidebar-profile">
                <div class="profile-image"><i class="fas fa-user"></i></div>
                <div class="profile-username"><?php echo $userName; ?></div>
            </div>
        </a>
        <nav>
            <ul>
             <?php
                $currentPage = basename($_SERVER['PHP_SELF']);
                function navItem($href, $iconClass, $text, $currentPage) {
                    $activeClass = ($currentPage == $href) ? ' active' : '';
                    echo '<li><a href="' . htmlspecialchars($href) . '" class="' . $activeClass . '" title="' . htmlspecialchars($text) . '"><i class="' . $iconClass . '"></i><span>' . htmlspecialchars($text) . '</span></a></li>';
                }
                navItem('dashboard.php', 'fas fa-chart-line', 'Dashboard', $currentPage);
                navItem('add_income.php', 'fas fa-plus-circle', 'Add Income', $currentPage);
                navItem('add_expense.php', 'fas fa-minus-circle', 'Add Expense', $currentPage);
                navItem('budget.php', 'fas fa-piggy-bank', 'Budget', $currentPage);
                navItem('goals.php', 'fas fa-bullseye', 'Goals', $currentPage);
                navItem('financial_tips.php', 'fas fa-lightbulb', 'Tips', $currentPage);
                navItem('reports.php', 'fas fa-file-alt', 'Reports', $currentPage);
                echo '<li><a href="logout.php" title="Logout"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>';
              ?>
            </ul>
        </nav>
    </aside>
    


    
    <div class="main-content">
        <header class="page-header">
            <div class="header-left">
                 <button id="sidebarToggle" title="Toggle Sidebar" aria-label="Toggle Sidebar">
                     <i class="fas fa-bars"></i>
                 </button>
                 <h1>Dashboard</h1>
            </div>
            <div class="user-welcome"><span>Welcome back, <strong><?php echo $userName; ?></strong>!</span></div>
        </header>

        <?php if (!empty($errorMessage)): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Error:</strong> <?php echo $errorMessage; ?>
            </div>
        <?php endif; ?>

        <main class="dashboard-grid">

            
            <section class="card col-span-12 md:col-span-4">
                <div class="card-header"> <i class="fas fa-wallet"></i><h2>Overview</h2> </div>
                 <?php if (!$errorMessage): ?>
                <div class="card-content">
                    <div class="overview-item"> <strong>Total Balance:</strong> <span class="counter-value <?php echo ($currentAccountBalance >= 0) ? 'neutral' : 'negative'; ?>" data-target-value="<?php echo floatval($currentAccountBalance); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                    <div class="overview-item"> <strong>Income (Month):</strong> <span class="counter-value positive" data-target-value="<?php echo floatval($totalIncomeThisMonth); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                    <div class="overview-item"> <strong>Expenses (Month):</strong> <span class="counter-value negative" data-target-value="<?php echo floatval($totalExpensesThisMonth); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                    <div class="overview-item"> <strong>Net (Month):</strong> <span class="counter-value <?php echo ($netBalanceThisMonth >= 0) ? 'positive' : 'negative'; ?>" data-target-value="<?php echo floatval($netBalanceThisMonth); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                    <div class="chart-container" style="min-height: 180px; margin-top:1.5rem;"> <canvas id="overviewPieChart"></canvas> <div id="overviewPieChart-nodata" class="chart-placeholder" style="display: none;"> <i class="fas fa-chart-pie"></i><span>No overview data yet.</span> </div> </div>
                </div>
                 <?php else: ?> <div class="card-content no-data-message">Overview unavailable.</div> <?php endif; ?>
            </section>

             
             <section class="card col-span-12 md:col-span-4">
                 <div class="card-header"> <i class="fas fa-chart-pie"></i><h2>Budget vs. Actual</h2> </div>
                 <?php if (!$errorMessage): ?>
                 <div class="card-content">
                     <?php if ($budgetTotal > 0 || $totalExpensesThisMonth > 0): ?>
                        <div class="summary-item"> <strong>Total Budget:</strong> <span class="counter-value neutral" data-target-value="<?php echo floatval($budgetTotal); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                        <div class="summary-item"> <strong>Total Spent:</strong> <span class="counter-value negative" data-target-value="<?php echo floatval($totalExpensesThisMonth); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                        <?php if ($budgetTotal > 0): ?>
                            <div class="summary-item"> <strong>Remaining:</strong> <span class="counter-value <?php echo ($budgetRemaining >= 0) ? 'positive' : 'negative'; ?>" data-target-value="<?php echo floatval($budgetRemaining); ?>"><span><?php echo formatCurrency(0, $currencySymbol); ?></span></span> </div>
                            <div style="margin-top: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.2rem;"> <strong style="font-size: 0.9rem; color: var(--text-secondary);">Overall Usage:</strong> <span style="font-weight: 600; font-size: 0.9rem;"><?php echo $budgetUsedPercent; ?>%</span> </div>
                                <div class="progress-bar-container"> <?php $overallProgressClass = ''; if ($budgetUsedPercent > 100) $overallProgressClass = 'over-budget'; elseif ($budgetUsedPercent >= 90) $overallProgressClass = 'high-usage'; ?> <div class="progress-bar <?php echo $overallProgressClass; ?>" data-progress="<?php echo min(max(0,$budgetUsedPercent), 100); ?>" style="width: 0%;" title="<?php echo $budgetUsedPercent; ?>% Used"></div> </div>
                            </div>
                        <?php endif; ?>
                         <div class="chart-container" style="min-height: 180px; margin-top:1.5rem;"> <canvas id="budgetCategoryBars"></canvas> <div id="budgetCategoryBars-nodata" class="chart-placeholder" style="display: none;"> <i class="fas fa-tasks"></i><span>No budget data.</span> </div> </div>
                     <?php else: ?> <p class="no-data-message">No budget or expenses found for this month.</p> <?php endif; ?>
                 </div>
                 <div class="card-footer"> <a href="budget.php" class="button button-sm"><i class="fas fa-edit"></i> Manage Budgets</a> </div>
                 <?php else: ?> <div class="card-content no-data-message">Budget data unavailable.</div> <?php endif; ?>
            </section>

            
            <section class="card col-span-12 md:col-span-4">
                 <div class="card-header"> <i class="fas fa-bell"></i><h2>Alerts</h2> </div>
                  <?php if (!$errorMessage): ?>
                 <div class="card-content" style="padding-top: 5px;">
                     <?php if (empty($alerts) || (count($alerts) === 1 && $alerts[0]['type'] === 'info')): ?>
                        <p class="no-data-message" style="margin-top: 1rem;"><i class="fas fa-check-circle" style="color: var(--success-color); text-shadow: 0 0 8px rgba(127, 255, 212, 0.7); font-size: 1.5rem; display:block; margin-bottom: 0.5rem;"></i>All clear!</p>
                     <?php else: ?>
                        <?php foreach ($alerts as $alert): if ($alert['type'] === 'info') continue; $iconClass = 'fas fa-info-circle'; if ($alert['type'] == 'warning') $iconClass = 'fas fa-exclamation-triangle'; if ($alert['type'] == 'danger') $iconClass = 'fas fa-exclamation-circle'; ?>
                            <div class="alert alert-<?php echo htmlspecialchars($alert['type']); ?>"> <i class="<?php echo $iconClass; ?>"></i> <span><?php echo $alert['message']; ?></span> </div>
                        <?php endforeach; ?>
                     <?php endif; ?>
                 </div>
                  <?php else: ?> <div class="card-content no-data-message">Alerts unavailable.</div> <?php endif; ?>
            </section>

            
            <section class="card col-span-12 lg:col-span-8">
                 <div class="card-header"> <i class="fas fa-exchange-alt"></i><h2>Recent Transactions</h2> </div>
                 <?php if (!$errorMessage): ?>
                 <div class="card-content">
                     <div class="table-container">
                         <table class="data-table transactions-table">
                             <thead> <tr> <th>Date</th> <th>Description</th> <th>Category</th> <th>Method</th> <th>Amount</th> </tr> </thead>
                             <tbody>
                                 <?php if (empty($recentTransactions)): ?> <tr> <td colspan="5" class="no-data-message" style="padding: 2rem 1rem;">No recent activity found.</td> </tr>
                                 <?php else: ?> <?php foreach ($recentTransactions as $tx): ?>
                                    <tr> <td><?php echo htmlspecialchars(date('M d, Y', strtotime($tx['date'] ?? ''))); ?></td> <td><?php echo htmlspecialchars($tx['description'] ?? 'N/A'); ?></td> <td class="transaction-category"><?php echo htmlspecialchars($tx['category'] ?? 'Uncategorized'); ?></td> <td class="transaction-method"><?php echo htmlspecialchars($tx['paymentMethod'] ?? 'N/A'); ?></td> <td class="<?php echo ($tx['type'] == 'income') ? 'amount-income' : 'amount-expense'; ?>"> <?php echo ($tx['type'] == 'income' ? '+' : '-') . formatCurrency($tx['amount'] ?? 0, $currencySymbol); ?> </td> </tr>
                                    <?php endforeach; ?> <?php endif; ?>
                             </tbody>
                         </table>
                     </div>
                 </div>
                 <div class="card-footer"> <a href="reports.php" class="button button-secondary button-sm"><i class="fas fa-file-alt"></i> View Reports</a> <a href="add_expense.php" class="button button-danger button-sm"><i class="fas fa-minus"></i> Add Expense</a> <a href="add_income.php" class="button button-success button-sm"><i class="fas fa-plus"></i> Add Income</a> </div>
                 <?php else: ?> <div class="card-content no-data-message">Transactions unavailable.</div> <?php endif; ?>
            </section>

             
             <section class="card col-span-12 lg:col-span-4">
                 <div class="card-header"> <i class="fas fa-chart-pie"></i><h2>Monthly Expenses</h2> </div>
                  <?php if (!$errorMessage): ?>
                 <div class="card-content"> <div class="chart-container"> <canvas id="expenseCategoryPie"></canvas> <div id="expenseCategoryPie-nodata" class="chart-placeholder" style="display: none;"> <i class="fas fa-shopping-cart"></i><span>No expenses recorded this month.</span> </div> </div> </div>
                 <div class="card-footer"> <a href="reports.php?type=expense" class="button button-sm">View Details</a> </div>
                  <?php else: ?> <div class="card-content no-data-message">Chart unavailable.</div> <?php endif; ?>
            </section>

             
             <section class="card col-span-12 lg:col-span-6">
                 <div class="card-header"> <i class="fas fa-calendar-alt"></i><h2>Weekly Spending</h2> </div>
                  <?php if (!$errorMessage): ?>
                 <div class="card-content"> <div class="chart-container"> <canvas id="monthlySpendingChart"></canvas> <div id="monthlySpendingChart-nodata" class="chart-placeholder" style="display: none;"> <i class="fas fa-chart-line"></i><span>Not enough spending data for this month.</span> </div> </div> </div>
                 <div class="card-footer"> <a href="reports.php" class="button button-sm">Full Report</a> </div>
                  <?php else: ?> <div class="card-content no-data-message">Chart unavailable.</div> <?php endif; ?>
            </section>

              
            <section class="card col-span-12 lg:col-span-6">
                <div class="card-header"> <i class="fas fa-hand-holding-usd"></i><h2>Monthly Income</h2> </div>
                 <?php if (!$errorMessage): ?>
                 <div class="card-content"> <div class="chart-container"> <canvas id="incomeSourcePie"></canvas> <div id="incomeSourcePie-nodata" class="chart-placeholder" style="display: none;"> <i class="fas fa-dollar-sign"></i><span>No income recorded this month.</span> </div> </div> </div>
                 <div class="card-footer"> <a href="reports.php?type=income" class="button button-sm">Income Report</a> </div>
                 <?php else: ?> <div class="card-content no-data-message">Chart unavailable.</div> <?php endif; ?>
            </section>

             
            <section class="card col-span-12 lg:col-span-8">
                 <div class="card-header"> <i class="fas fa-bullseye"></i><h2>Savings Goals</h2> </div>
                  <?php if (!$errorMessage): ?>
                 <div class="card-content">
                     <?php if (empty($savingsGoals)): ?> <p class="no-data-message">No savings goals set yet. Start planning!</p>
                     <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.5rem;">
                         <?php foreach ($savingsGoals as $goal): $target = floatval($goal['target'] ?? 0); $current = floatval($goal['current'] ?? 0); $progress = ($target > 0) ? round(($current / $target) * 100) : 0; $progress = min(100, max(0, $progress)); ?>
                            <div class="goal-progress">
                                <div style="margin-bottom: 0.5rem;"> <strong style="font-size: 0.95rem; display: block; margin-bottom: 0.2rem;"><?php echo htmlspecialchars($goal['name'] ?? 'Unnamed Goal'); ?></strong> <span style="font-size: 0.85rem; color: var(--text-secondary);"><?php echo formatCurrency($current, $currencySymbol); ?> / <?php echo formatCurrency($target, $currencySymbol); ?></span> </div>
                                <div class="progress-bar-container"> <div class="progress-bar" data-progress="<?php echo $progress; ?>" style="width: 0%;" title="<?php echo $progress; ?>% Complete"> <?php if ($progress > 15) echo '<span>'.$progress . '%</span>'; ?> </div> </div>
                                <?php if (!empty($goal['deadline'])): ?> <div style="font-size: 0.8rem; color: var(--text-muted); text-align: right; margin-top: 0.4rem;"> <i class="far fa-calendar-alt"></i> Target: <?php echo htmlspecialchars(date('M j, Y', strtotime($goal['deadline']))); ?> </div> <?php endif; ?>
                            </div>
                         <?php endforeach; ?>
                        </div>
                     <?php endif; ?>
                 </div>
                 <div class="card-footer"> <a href="goals.php" class="button button-success button-sm"><i class="fas fa-plus"></i> Add/View Goals</a> </div>
                  <?php else: ?> <div class="card-content no-data-message">Goals unavailable.</div> <?php endif; ?>
            </section>

            
            <section class="card col-span-12 lg:col-span-4">
                 <div class="card-header"> <i class="fas fa-lightbulb"></i><h2>Quick Tip</h2> </div>
                 <div class="card-content"> <div class="literacy-tip"> <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($financialTip); ?> </div> <p style="font-size: 0.9rem; color: var(--text-muted); margin-top: 1rem; text-align: center;"> Stay informed, stay ahead! </p> </div>
                 <div class="card-footer"> <a href="financial_tips.php" class="button button-sm">Learn More</a> </div>
            </section>

        </main>

        <footer class="footer">© <?php echo date('Y'); ?> FinDash. All rights reserved.</footer>

    </div> 

    
    <script id="php-data" type="application/json">
        <?php
             $isValidJson = false;
             if (isset($jsData)) { json_decode($jsData); if (json_last_error() === JSON_ERROR_NONE) { $isValidJson = true; } else { error_log("PHP Data JSON Encode Error (dashboard.php): " . json_last_error_msg()); } }
             echo $isValidJson ? $jsData : '{}';
        ?>
    </script>

    
    <div id="chatbot-widget" class="chatbot-widget">
        <div class="chatbot-header">
            <span><i class="fas fa-robot"></i> Finance Assistant</span>
            <button id="close-chatbot" class="close-chatbot-btn" title="Close Chat" aria-label="Close Chat">×</button>
        </div>
        <div id="chatbot-messages" class="chatbot-messages">
            
            <div class="message bot"><div class="message-content">Hello <?php echo $userName; ?>! How can I help you with your finances today?</div></div>
        </div>
        <div id="chatbot-loading" class="chatbot-loading" style="display: none;">
            <i class="fas fa-spinner fa-spin"></i> Thinking...
        </div>
        <div class="chatbot-input-area">
            <form id="chatbot-form">
                <input type="text" id="chatbot-input" placeholder="Ask something..." autocomplete="off">
                <button type="submit" id="chatbot-send" title="Send Message"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <button id="open-chatbot" class="open-chatbot-btn" title="Open Finance Assistant">
        <i class="fas fa-comments"></i>
    </button>
    

    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        
        const sidebarToggle = document.getElementById('sidebarToggle');
        const body = document.body;
        const sidebarStateKey = 'sidebarCollapsedState';
        const isMobile = () => window.innerWidth < 768;

        function setSidebarState(collapsed) {
            if (collapsed) {
                body.classList.add('sidebar-collapsed');
                if (sidebarToggle) sidebarToggle.innerHTML = isMobile() ? '<i class="fas fa-bars"></i>' : '<i class="fas fa-arrow-right"></i>';
                if (sidebarToggle) sidebarToggle.setAttribute('aria-label', 'Open Sidebar');
                if (!isMobile()) localStorage.setItem(sidebarStateKey, 'true');
            } else {
                body.classList.remove('sidebar-collapsed');
                if (sidebarToggle) sidebarToggle.innerHTML = '<i class="fas fa-times"></i>';
                if (sidebarToggle) sidebarToggle.setAttribute('aria-label', 'Close Sidebar');
                 if (!isMobile()) localStorage.setItem(sidebarStateKey, 'false');
            }
        }

        const initialStateCollapsed = isMobile() ? true : (localStorage.getItem(sidebarStateKey) === 'true');
        setSidebarState(initialStateCollapsed);

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', () => {
                setSidebarState(!body.classList.contains('sidebar-collapsed'));
            });
        } else {
            console.warn("Sidebar toggle button not found.");
        }

        document.addEventListener('click', function(event) {
            const sidebar = document.querySelector('.sidebar');
            if (isMobile() && !body.classList.contains('sidebar-collapsed') && sidebar && sidebarToggle &&
                !sidebar.contains(event.target) && !sidebarToggle.contains(event.target)) {
                setSidebarState(true);
            }
        });


        
        let phpData;
        try {
            const dataScript = document.getElementById('php-data');
            if (!dataScript || !dataScript.textContent) throw new Error("PHP data script tag not found or empty.");
            phpData = JSON.parse(dataScript.textContent || '{}') || {};
        } catch (e) {
            console.error("Error parsing PHP data:", e);
            phpData = {};
            document.querySelectorAll('.chart-container').forEach(el => {
                const canvas = el.querySelector('canvas');
                const placeholder = el.querySelector('.chart-placeholder, .chart-error');
                if(canvas) canvas.style.display = 'none';
                if(placeholder) {
                    placeholder.innerHTML = '<i class="fas fa-exclamation-triangle"></i><span>Error loading chart data.</span>';
                    placeholder.classList.add('chart-error');
                    placeholder.style.display = 'flex';
                } else {
                     el.innerHTML = '<div class="chart-error"><i class="fas fa-exclamation-triangle"></i><span>Error loading chart data.</span></div>';
                }
            });
        }

        const currencySymbol = phpData?.currencySymbol || '$';

        function formatValueAsCurrency(value) {
             if (value == null || isNaN(value)) return currencySymbol + '0.00';
             try { return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value).replace('US$', currencySymbol).replace('$', currencySymbol); }
             catch (formatError) { return currencySymbol + parseFloat(value).toFixed(2); }
        };

        function animateCounter(element) {
            const targetValue = parseFloat(element.getAttribute('data-target-value'));
            const displayElement = element.querySelector('span');
            if (isNaN(targetValue) || !displayElement) return;
            const duration = 1500; const startTime = performance.now();
            function updateCounter(currentTime) {
                const elapsedTime = currentTime - startTime; const progress = Math.min(1, elapsedTime / duration);
                const easedProgress = 1 - Math.pow(1 - progress, 3); let currentValue = easedProgress * targetValue;
                if (targetValue < 0 && currentValue > 0) { currentValue = currentValue * -1; }
                displayElement.textContent = formatValueAsCurrency(currentValue);
                if (progress < 1) { requestAnimationFrame(updateCounter); }
                else { displayElement.textContent = formatValueAsCurrency(targetValue); }
            } requestAnimationFrame(updateCounter);
        }

        function animateProgressBars() {
            const progressBars = document.querySelectorAll('.progress-bar[data-progress]');
            progressBars.forEach(bar => {
                const targetWidth = bar.getAttribute('data-progress');
                 setTimeout(() => { bar.style.width = targetWidth + '%'; }, 100);
            });
        }

        document.querySelectorAll('.counter-value').forEach(animateCounter);
        animateProgressBars();

        if (Object.keys(phpData).length > 0 && typeof Chart !== 'undefined') {
            const tooltipFormat = (context) => { let label = context.dataset.label || context.label || ''; if (label) { label += ': '; } let value = context.parsed?.y ?? context.parsed ?? context.raw ?? NaN; label += formatValueAsCurrency(value); return label; };
            const pieTooltipFormat = (context) => { let label = context.label || ''; if (label) { label += ': '; } let value = context.parsed || 0; label += formatValueAsCurrency(value); let total = context.dataset.data.reduce((a, b) => a + b, 0); let percentage = total > 0 ? ((value / total) * 100).toFixed(1) + '%' : '0%'; label += ` (${percentage})`; return label; };
            const compactTickFormat = (value) => { if (Math.abs(value) >= 1000000) return formatValueAsCurrency(value / 1000000) + 'M'; if (Math.abs(value) >= 1000) return formatValueAsCurrency(value / 1000) + 'k'; return formatValueAsCurrency(value); };
            Chart.defaults.font.family = "'Inter', sans-serif"; Chart.defaults.plugins.legend.position = 'bottom'; Chart.defaults.plugins.legend.labels.padding = 15; Chart.defaults.plugins.legend.labels.boxWidth = 12; Chart.defaults.plugins.legend.labels.usePointStyle = true; Chart.defaults.plugins.tooltip.backgroundColor = 'rgba(0, 0, 0, 0.8)'; Chart.defaults.plugins.tooltip.titleFont = { weight: 'bold' }; Chart.defaults.plugins.tooltip.bodyFont = { size: 12 }; Chart.defaults.plugins.tooltip.padding = 10; Chart.defaults.plugins.tooltip.displayColors = false; Chart.defaults.animation.duration = 600; Chart.defaults.animation.easing = 'easeOutQuart';
            const pieOptions = { responsive: true, maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: pieTooltipFormat } } } }; const doughnutOptions = { ...pieOptions, cutout: '65%' }; const lineOptions = { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: compactTickFormat, font: { size: 10 }, maxTicksLimit: 6 } }, x: { ticks: { font: { size: 10 }, autoSkip: true, maxTicksLimit: 7 } } }, plugins: { legend: { display: false }, tooltip: { callbacks: { label: tooltipFormat } } }, interaction: { intersect: false, mode: 'index' } }; const budgetBarOptions = { responsive: true, maintainAspectRatio: false, indexAxis: 'y', scales: { x: { beginAtZero: true, stacked: false, ticks: { callback: compactTickFormat, font: { size: 10 }, maxTicksLimit: 5 } }, y: { stacked: false, ticks: { font: { size: 10 }, autoSkip: false } } }, plugins: { legend: { display: true, position: 'top' }, tooltip: { callbacks: { label: tooltipFormat } } } };
            function createChart(elementId, chartConfig, dataCheck = true) { const canvas = document.getElementById(elementId); const placeholder = document.getElementById(`${elementId}-nodata`); if (!canvas) { console.warn(`Canvas "${elementId}" not found.`); return; } const ctx = canvas.getContext('2d'); if (!ctx) { console.warn(`Context for "${elementId}" failed.`); if(placeholder) placeholder.style.display = 'flex'; canvas.style.display = 'none'; return; } const existingChart = Chart.getChart(canvas); if (existingChart) { existingChart.destroy(); } if (dataCheck) { try { new Chart(ctx, chartConfig); canvas.style.display = 'block'; if (placeholder) placeholder.style.display = 'none'; } catch (e) { console.error(`Error creating chart "${elementId}":`, e); canvas.style.display = 'none'; if (placeholder) { placeholder.innerHTML = `<i class="fas fa-exclamation-triangle"></i><span>Chart error.</span>`; placeholder.classList.add('chart-error'); placeholder.style.display = 'flex'; } } } else { canvas.style.display = 'none'; if (placeholder) { placeholder.style.display = 'flex'; placeholder.classList.remove('chart-error'); } } }
            const mintColor = '#7fffd4'; const navyColor = '#0a2342'; const redColor = '#ef4444'; const orangeColor = '#f97316'; const amberColor = '#f59e0b'; const blueColor = '#3b82f6'; const slateColor = '#64748b'; const tealColor = '#2ca58d';
            const overviewDataValid = phpData.overviewChartData?.length > 0 && phpData.overviewChartData.some(d => (d?.value || 0) > 0); createChart('overviewPieChart', { type: 'doughnut', data: { labels: phpData.overviewChartData?.map(d => d?.label || 'N/A') ?? ['N/A'], datasets: [{ label: 'Overview', data: phpData.overviewChartData?.map(d => d?.value || 0) ?? [0], backgroundColor: [`rgba(127, 255, 212, 0.7)`, `rgba(239, 68, 68, 0.7)`], borderColor: [mintColor, redColor], hoverOffset: 8, borderWidth: 1.5, hoverBorderColor: [navyColor, '#b91c1c'] }] }, options: doughnutOptions }, overviewDataValid);
            let budgetLabels = phpData.budgetBreakdown ? Object.keys(phpData.budgetBreakdown) : []; let allocatedData = budgetLabels.map(label => Number(phpData.budgetBreakdown[label]?.allocated) || 0); let spentData = budgetLabels.map(label => Number(phpData.budgetBreakdown[label]?.spent) || 0); const budgetDataValid = budgetLabels.length > 0 && (allocatedData.some(v => v > 0) || spentData.some(v => v > 0)); createChart('budgetCategoryBars', { type: 'bar', data: { labels: budgetLabels, datasets: [ { label: 'Spent', data: spentData, backgroundColor: `rgba(239, 68, 68, 0.7)`, borderColor: redColor, borderWidth: 1, order: 2 }, { label: 'Budgeted', data: allocatedData, backgroundColor: `rgba(10, 35, 66, 0.6)`, borderColor: navyColor, borderWidth: 1, order: 1 } ] }, options: budgetBarOptions }, budgetDataValid);
            let expenseLabels = []; let expenseData = []; let expenseDataValid = false; const expenseColors = [redColor, orangeColor, amberColor, '#dc2626', '#b91c1c', '#fbbf24', '#d97706', slateColor]; if (phpData.expenseCategories && Object.keys(phpData.expenseCategories).length > 0) { let sortedExpenses = Object.entries(phpData.expenseCategories).map(([l, v]) => [l, Number(v) || 0]).filter(([,v]) => v > 0).sort(([,a],[,b]) => b - a); if (sortedExpenses.length > 0) { expenseDataValid = true; let maxPieCategories = 7; let topExpenses = sortedExpenses.slice(0, maxPieCategories); let otherSum = sortedExpenses.slice(maxPieCategories).reduce((s, [, v]) => s + v, 0); expenseLabels = topExpenses.map(([l,]) => l); expenseData = topExpenses.map(([, v]) => v); if (otherSum > 0) { expenseLabels.push('Other'); expenseData.push(otherSum); } } } createChart('expenseCategoryPie', { type: 'pie', data: { labels: expenseLabels, datasets: [{ label: 'Expenses', data: expenseData, backgroundColor: expenseColors.slice(0, expenseLabels.length).map(c => c + 'B3'), borderColor: expenseColors.slice(0, expenseLabels.length), hoverOffset: 8, borderWidth: 1, hoverBorderColor: expenseColors.slice(0, expenseLabels.length).map(c => c) }] }, options: pieOptions }, expenseDataValid);
            let spendingLabels = phpData.monthlySpendingSummary ? Object.keys(phpData.monthlySpendingSummary) : []; let spendingData = spendingLabels.map(label => Number(phpData.monthlySpendingSummary[label]) || 0); const spendingDataValid = spendingLabels.length > 0 && spendingData.some(d => d > 0); createChart('monthlySpendingChart', { type: 'line', data: { labels: spendingLabels, datasets: [{ label: 'Weekly Spending', data: spendingData, fill: true, backgroundColor: 'rgba(239, 68, 68, 0.1)', borderColor: redColor, tension: 0.4, pointBackgroundColor: redColor, pointBorderColor: '#fff', pointHoverRadius: 7, pointHoverBackgroundColor: redColor, pointHoverBorderColor: '#fff', pointRadius: 4, }] }, options: lineOptions }, spendingDataValid);
            let incomeLabels = []; let incomeData = []; let incomeDataValid = false; const incomeColors = [mintColor, tealColor, '#48d1cc', '#5fdec9', '#20b2aa', blueColor, navyColor]; if (phpData.incomeSources && Object.keys(phpData.incomeSources).length > 0) { let sortedIncome = Object.entries(phpData.incomeSources).map(([l, v]) => [l, Number(v) || 0]).filter(([, v]) => v > 0).sort(([, a], [, b]) => b - a); if (sortedIncome.length > 0) { incomeDataValid = true; let maxPieCategories = 7; let topIncome = sortedIncome.slice(0, maxPieCategories); let otherIncomeSum = sortedIncome.slice(maxPieCategories).reduce((s, [,v]) => s + v, 0); incomeLabels = topIncome.map(([l,]) => l); incomeData = topIncome.map(([,v]) => v); if (otherIncomeSum > 0) { incomeLabels.push('Other'); incomeData.push(otherIncomeSum); } } } createChart('incomeSourcePie', { type: 'pie', data: { labels: incomeLabels, datasets: [{ label: 'Income Sources', data: incomeData, backgroundColor: incomeColors.slice(0, incomeLabels.length).map(c => c + 'B3'), borderColor: incomeColors.slice(0, incomeLabels.length), hoverOffset: 8, borderWidth: 1, hoverBorderColor: incomeColors.slice(0, incomeLabels.length).map(c => c) }] }, options: pieOptions }, incomeDataValid);
        }


        
        const openChatbotBtn = document.getElementById('open-chatbot');
        const closeChatbotBtn = document.getElementById('close-chatbot');
        const chatbotWidget = document.getElementById('chatbot-widget');
        const chatbotMessages = document.getElementById('chatbot-messages');
        const chatbotForm = document.getElementById('chatbot-form');
        const chatbotInput = document.getElementById('chatbot-input');
        const chatbotSendBtn = document.getElementById('chatbot-send');
        const chatbotLoading = document.getElementById('chatbot-loading');
        let isBotReplying = false; 

        function scrollChatToBottom() {
            if (chatbotMessages) {
                chatbotMessages.scrollTop = chatbotMessages.scrollHeight;
            }
        }

        function addMessageToChat(text, type = 'bot', isError = false) {
            if (!chatbotMessages || !text) return;
            const messageDiv = document.createElement('div');
            messageDiv.classList.add('message', type);
            if (isError) {
                messageDiv.classList.add('error');
            }
            const contentDiv = document.createElement('div');
            contentDiv.classList.add('message-content');
            
            
            const sanitizedText = text.replace(/</g, "<").replace(/>/g, ">");
            contentDiv.innerHTML = sanitizedText.replace(/\n/g, '<br>'); 
            messageDiv.appendChild(contentDiv);
            chatbotMessages.appendChild(messageDiv);
            scrollChatToBottom();
        }

        function toggleLoading(show) {
            if (chatbotLoading) {
                 chatbotLoading.style.display = show ? 'block' : 'none';
            }
            if (chatbotSendBtn) {
                 chatbotSendBtn.disabled = show;
            }
             if (chatbotInput) {
                 chatbotInput.disabled = show;
             }
             isBotReplying = show;
        }

        
        if (openChatbotBtn && chatbotWidget) {
            openChatbotBtn.addEventListener('click', () => {
                chatbotWidget.classList.add('chat-widget-open');
                openChatbotBtn.style.display = 'none'; 
                 
                 setTimeout(() => { if (chatbotInput) chatbotInput.focus(); }, 350);
            });
        }
        if (closeChatbotBtn && chatbotWidget) {
            closeChatbotBtn.addEventListener('click', () => {
                chatbotWidget.classList.remove('chat-widget-open');
                 if (openChatbotBtn) openChatbotBtn.style.display = 'flex'; 
            });
        }

        
        if (chatbotForm && chatbotInput) {
            chatbotForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const userMessage = chatbotInput.value.trim();
                if (!userMessage || isBotReplying) {
                    return; 
                }

                addMessageToChat(userMessage, 'user');
                chatbotInput.value = ''; 
                toggleLoading(true);

                try {
                    const response = await fetch('chatbot_api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({ prompt: userMessage })
                    });

                    const responseData = await response.json();

                    if (!response.ok) {
                        
                        addMessageToChat(`Error: ${responseData.error || 'Failed to get response from assistant.'}`, 'bot', true);
                    } else if (responseData.error) {
                         
                         addMessageToChat(`Error: ${responseData.error}`, 'bot', true);
                    } else if (responseData.reply) {
                         addMessageToChat(responseData.reply, 'bot');
                    } else {
                         addMessageToChat('Sorry, I received an empty response.', 'bot', true);
                    }

                } catch (error) {
                    console.error('Chatbot Fetch Error:', error);
                    addMessageToChat('Sorry, something went wrong while contacting the assistant. Please check your connection and try again.', 'bot', true);
                } finally {
                    toggleLoading(false);
                     if (chatbotInput) chatbotInput.focus(); 
                }
            });
        }

    }); 
    </script>

</body>
</html>