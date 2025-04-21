<?php
session_start();

// --- Existing Configuration ---
$currencySymbol = '$'; // Changed to $ as per your request list example
$expenseCategoriesList = ['Groceries', 'Utilities', 'Dining Out', 'Transport', 'Entertainment', 'Shopping', 'Health', 'Rent/Mortgage', 'Subscription', 'Miscellaneous']; // Keep for potential future use?
$paymentMethods = ['Debit Card', 'Credit Card', 'Bank Transfer', 'PayPal', 'Cash', 'Direct Deposit']; // Keep for potential future use?
$dashboardUrl = "dashboard.php"; // Added for back button

// --- Existing Helper Function ---
function formatCurrency($amount, $symbol = '$') {
    $amountValue = is_numeric($amount) ? floatval($amount) : 0.0;
    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format($amountValue, 2);
}

// --- Existing Database Connection ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "login";

$conn = null;
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES 'utf8mb4'");
} catch (PDOException $e) {
    // Consider logging the error instead of dying immediately for better UX
    error_log("Database Connection Error: " . $e->getMessage());
    die("Connection failed: Unable to connect to the database. Please check configuration or contact support."); // User-friendly message
}

// --- Existing Table Creation (with minor error handling improvement) ---
try {
    // Create transactions table if not exists (Required for potential future features like recent income list)
    $conn->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT, -- Consider adding user ID if multi-user
        date DATE,
        timestamp INT,
        paymentMethod VARCHAR(255),
        type VARCHAR(10) NOT NULL, -- 'income' or 'expense'
        category VARCHAR(255) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        description TEXT,
        tags VARCHAR(255) NULL, -- Added for potential tags feature
        receipt_path VARCHAR(255) NULL, -- Added for potential receipt feature
        INDEX (type, category)
        -- FOREIGN KEY (user_id) REFERENCES users(id) -- Example if you have a users table
    )");

    // Create budget_breakdown table if not exists
    $conn->exec("CREATE TABLE IF NOT EXISTS budget_breakdown (
        category VARCHAR(255) PRIMARY KEY,
        -- user_id INT, -- Consider adding user ID
        allocated DECIMAL(10, 2) NOT NULL,
        spent DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        notes TEXT NULL, -- Added for potential notes feature
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        -- FOREIGN KEY (user_id) REFERENCES users(id) -- Example
    )");

} catch (PDOException $e) {
    // Log error if it's not just "table already exists"
    if (strpos(strtolower($e->getMessage()), 'already exists') === false) {
         error_log("Table Creation Error: " . $e->getMessage());
         // Optionally inform the user, but maybe not die
         // $initial_error = "Warning: Could not ensure database tables exist.";
    }
}

// --- Existing Message Handling & POST Request Logic ---
$message = '';
$message_type = ''; // 'success' or 'error'

// --- [ YOUR EXISTING PHP CODE FOR HANDLING POST REQUESTS (add, edit, delete budgets) GOES HERE - UNCHANGED ] ---
// --- [ THIS BLOCK REMAINS EXACTLY AS YOU PROVIDED IT ] ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    try {
        $conn->beginTransaction();

        $action = $_POST['action'];

        if ($action === 'add' || $action === 'edit') {
            $category = isset($_POST['category']) ? trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)) : '';
            $allocated_input = $_POST['allocated'] ?? null;
            $spent_input = $_POST['spent'] ?? null;
            $original_category = isset($_POST['original_category']) ? trim(filter_input(INPUT_POST, 'original_category', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)) : $category;

            $errors = [];
            if (empty($category)) { $errors[] = "Category name cannot be empty."; }
            elseif (strlen($category) > 255) { $errors[] = "Category name is too long (max 255 chars)."; }

            $allocated = filter_var($allocated_input, FILTER_VALIDATE_FLOAT);
            if ($allocated === false || $allocated === null) { $errors[] = "Allocated amount must be a valid number."; }
            elseif ($allocated < 0) { $errors[] = "Allocated amount cannot be negative."; }

            $spent = filter_var($spent_input, FILTER_VALIDATE_FLOAT);
            if ($spent === false || $spent === null) { $errors[] = "Spent amount must be a valid number."; }
            elseif ($spent < 0) { $errors[] = "Spent amount cannot be negative."; }


            if ($action === 'edit' && empty($original_category)) {
                 $errors[] = "Original category name missing for edit action.";
            }

            if (!empty($errors)) { throw new Exception(implode(" ", $errors)); }

            if ($action === 'add') {
                $stmtCheck = $conn->prepare("SELECT category FROM budget_breakdown WHERE category = :category");
                $stmtCheck->bindParam(':category', $category);
                $stmtCheck->execute();
                if ($stmtCheck->fetch()) {
                    throw new Exception("Budget category '" . htmlspecialchars($category) . "' already exists.");
                }

                $sql = "INSERT INTO budget_breakdown (category, allocated, spent) VALUES (:category, :allocated, :spent)";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':category', $category);
                $stmt->bindParam(':allocated', $allocated);
                $stmt->bindParam(':spent', $spent);
                $stmt->execute();
                $message = "Budget category '" . htmlspecialchars($category) . "' added successfully.";

            } elseif ($action === 'edit') {
                 $stmtCheckExists = $conn->prepare("SELECT category FROM budget_breakdown WHERE category = :original_category");
                 $stmtCheckExists->bindParam(':original_category', $original_category);
                 $stmtCheckExists->execute();
                 if (!$stmtCheckExists->fetch()) {
                     throw new Exception("Budget category '" . htmlspecialchars($original_category) . "' not found for editing.");
                 }

                if ($category !== $original_category) {
                    $stmtCheckConflict = $conn->prepare("SELECT category FROM budget_breakdown WHERE category = :category");
                    $stmtCheckConflict->bindParam(':category', $category);
                    $stmtCheckConflict->execute();
                    if ($stmtCheckConflict->fetch()) {
                        throw new Exception("Cannot rename to '" . htmlspecialchars($category) . "' as that category already exists.");
                    }

                    $sql = "UPDATE budget_breakdown SET category = :new_category, allocated = :allocated, spent = :spent WHERE category = :original_category";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':new_category', $category);
                    $stmt->bindParam(':allocated', $allocated);
                    $stmt->bindParam(':spent', $spent);
                    $stmt->bindParam(':original_category', $original_category);

                } else {
                    $sql = "UPDATE budget_breakdown SET allocated = :allocated, spent = :spent WHERE category = :original_category";
                    $stmt = $conn->prepare($sql);
                    $stmt->bindParam(':allocated', $allocated);
                    $stmt->bindParam(':spent', $spent);
                    $stmt->bindParam(':original_category', $original_category);
                }

                $stmt->execute();

                if ($stmt->rowCount() > 0) {
                    $message = "Budget category '" . htmlspecialchars($original_category) . "' updated successfully.";
                     if ($category !== $original_category) {
                        $message .= " (Renamed to '" . htmlspecialchars($category) . "')";
                     }
                } else {
                    $stmtCheckValues = $conn->prepare("SELECT allocated, spent FROM budget_breakdown WHERE category = :category");
                    $stmtCheckValues->bindParam(':category', $category);
                    $stmtCheckValues->execute();
                    $currentValues = $stmtCheckValues->fetch(PDO::FETCH_ASSOC);

                    if ($currentValues && floatval($currentValues['allocated']) == $allocated && floatval($currentValues['spent']) == $spent) {
                         $message = "No changes detected for budget category '" . htmlspecialchars($original_category) . "'.";
                    } else {
                        $message = "Budget category '" . htmlspecialchars($original_category) . "' updated.";
                         if ($category !== $original_category) {
                           $message .= " (Renamed to '" . htmlspecialchars($category) . "')";
                        }
                    }
                }
            }
            $message_type = 'success';

        } elseif ($action === 'delete') {
            $category = isset($_POST['category']) ? trim(filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES)) : '';
            if (empty($category)) { throw new Exception("Category name is required for deletion."); }

            $sql = "DELETE FROM budget_breakdown WHERE category = :category";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':category', $category);
            $deleted = $stmt->execute();

            if ($deleted && $stmt->rowCount() > 0) {
                $message = "Budget category '" . htmlspecialchars($category) . "' deleted successfully.";
                $message_type = 'success';
            } else {
                 throw new Exception("Budget category '" . htmlspecialchars($category) . "' not found or could not be deleted.");
            }
        }

        $conn->commit();

    } catch (PDOException $e) {
        if ($conn->inTransaction()) { $conn->rollBack(); }
        $message = "Database error: " . $e->getMessage() . " (Code: " . $e->getCode() . ")";
        $message_type = 'error';
        error_log("Budget Action DB Error: " . $e->getMessage()); // Log DB errors
    } catch (Exception $e) {
         if ($conn->inTransaction()) { $conn->rollBack(); }
         $message = "Error: " . $e->getMessage();
         $message_type = 'error';
         error_log("Budget Action General Error: " . $e->getMessage()); // Log other errors
    }

    // --- [ NO NEED TO RECALCULATE TOTALS HERE - Redirect happens ] ---

    // Store message in session and redirect (Post/Redirect/Get pattern)
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $message_type;
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}
// --- [ END OF UNCHANGED PHP POST HANDLING BLOCK ] ---


// Retrieve message from session AFTER potential redirect
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    $message_type = $_SESSION['message_type'];
    unset($_SESSION['message']);
    unset($_SESSION['message_type']);
}


// --- Existing Data Fetching for Display ---
$budgetBreakdown = [];
$allocatedTotal = 0;
$spentTotal = 0;
$budgetChartData = ['labels' => [], 'allocated' => [], 'spent' => []]; // For Chart.js

try {
    // Fetch all budget categories for display and chart
    $stmt = $conn->query("SELECT category, allocated, spent FROM budget_breakdown ORDER BY category ASC");
    $budgetsFromDB = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($budgetsFromDB as $budget) {
        $category = $budget['category'];
        $allocated = floatval($budget['allocated']);
        $spent = floatval($budget['spent']);

        $budgetBreakdown[$category] = [
            'allocated' => $allocated,
            'spent' => $spent
        ];
        $allocatedTotal += $allocated;
        $spentTotal += $spent;

        // Prepare data for Chart.js
        $budgetChartData['labels'][] = $category;
        $budgetChartData['allocated'][] = $allocated;
        $budgetChartData['spent'][] = $spent;
    }

} catch (PDOException $e) {
    $message = "Error fetching budget data: " . $e->getMessage(); // Display error fetching data
    $message_type = 'error';
    error_log("Budget Fetch Error: " . $e->getMessage());
    $budgetBreakdown = [];
    $allocatedTotal = 0;
    $spentTotal = 0;
    $budgetChartData = ['labels' => [], 'allocated' => [], 'spent' => []]; // Reset chart data on error
}

// --- Existing Calculations ---
$budgetTotal = round($allocatedTotal, 2);
$budgetSpentTotal = round($spentTotal, 2);
$budgetRemaining = $budgetTotal - $budgetSpentTotal;
$budgetUsedPercent = ($budgetTotal > 0) ? round(($budgetSpentTotal / $budgetTotal) * 100) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Budgets - Finance Tracker</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Lottie Player CDN -->
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>

    <style>
        /* --- Base & Theme Variables --- */
        :root {
            /* Using a blue primary theme */
            --primary-color: #3b82f6; /* Blue */
            --primary-hover: #2563eb;
            --primary-gradient: linear-gradient(135deg, #60a5fa, #3b82f6);
            --primary-gradient-hover: linear-gradient(135deg, #3b82f6, #1d4ed8);
            --input-focus-border: var(--primary-color);
            --input-focus-shadow: rgba(59, 130, 246, 0.25);
            --background-gradient: linear-gradient(160deg, #eff6ff 0%, #f4f7f9 100%); /* Soft blue gradient */

            --success-color: #16a34a; /* Green */
            --success-hover: #15803d;
            --success-gradient: linear-gradient(135deg, #4ade80, #16a34a);
            --success-gradient-hover: linear-gradient(135deg, #16a34a, #14532d);
            --success-bg: #dcfce7;
            --success-border: #bbf7d0;

            --warning-color: #f59e0b; /* Amber */
            --warning-hover: #d97706;
            --danger-color: #ef4444; /* Red */
            --danger-hover: #dc2626;
            --danger-bg: #fee2e2;
            --danger-border: #fecaca;

            --secondary-color: #6b7280; /* Gray */
            --secondary-hover: #4b5563;
            --light-bg: #f9fafb; /* Very light gray for body */
            --white-bg: rgba(255, 255, 255, 0.9); /* Slightly transparent white */
            --card-border-color: rgba(0, 0, 0, 0.07);
            --input-border-color: #d1d5db;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --font-family: 'Poppins', sans-serif;
            --border-radius: 1rem; /* Enhanced radius */
            --border-radius-input: 0.6rem; /* Enhanced radius */
            --box-shadow: 0 10px 35px rgba(100, 116, 139, 0.12); /* Smoother shadow */
            --input-shadow: 0 1px 3px rgba(0,0,0,0.05);
            --content-max-width: 1100px; /* Max width for main content */
            --input-padding-x: 1.1rem;
            --input-padding-y: 0.9rem;
            --label-default-left: var(--input-padding-x);
            --currency-symbol-width: 2.8rem; /* Approx width for '$' or '₹' span */

            /* Dark Mode Variables */
            --dark-bg: #111827; /* Dark Gray */
            --dark-card-bg: rgba(31, 41, 55, 0.85); /* Darker gray, slightly transparent */
            --dark-text-color: #f3f4f6;
            --dark-text-muted: #9ca3af;
            --dark-border-color: rgba(255, 255, 255, 0.15);
            --dark-input-border: #4b5563;
            --dark-input-bg: rgba(55, 65, 81, 0.5);
            --dark-focus-shadow: rgba(96, 165, 250, 0.3); /* Blue focus glow for dark */
            --dark-gradient: linear-gradient(160deg, #1f2937 0%, #111827 100%);
        }

        html[data-theme='dark'] {
            --light-bg: var(--dark-bg);
            --white-bg: var(--dark-card-bg);
            --card-border-color: var(--dark-border-color);
            --input-border-color: var(--dark-input-border);
            --text-color: var(--dark-text-color);
            --text-muted: var(--dark-text-muted);
            --input-focus-shadow: var(--dark-focus-shadow);
            --background-gradient: var(--dark-gradient);
            --input-shadow: 0 2px 5px rgba(0,0,0,0.2);
            --box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);
            --success-bg: rgba(22, 163, 74, 0.2);
            --success-border: rgba(22, 163, 74, 0.5);
            --danger-bg: rgba(239, 68, 68, 0.2);
            --danger-border: rgba(239, 68, 68, 0.5);
        }

        /* --- Global Styles --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; scroll-behavior: smooth; }
        body {
            font-family: var(--font-family);
            background: var(--background-gradient);
            color: var(--text-color);
            line-height: 1.6;
            padding-top: 80px; /* Space for fixed navbar */
            min-height: 100vh;
            display: flex; /* Use flex for footer sticking */
            flex-direction: column; /* Stack content vertically */
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* --- Navigation Bar --- */
        .navbar {
            position: fixed; top: 0; left: 0; width: 100%;
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.8rem 2rem; background-color: var(--white-bg);
            backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid var(--card-border-color);
            z-index: 1000; transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        .navbar-brand {
            font-size: 1.4rem; font-weight: 600; color: var(--primary-color);
            text-decoration: none; display: flex; align-items: center; gap: 0.5rem;
        }
        .navbar-brand i { font-size: 1.2em; }
        .navbar-controls { display: flex; align-items: center; gap: 1rem; }
        .theme-toggle-btn {
            background: none; border: none; color: var(--text-muted);
            font-size: 1.4rem; cursor: pointer; padding: 0.3rem; transition: color 0.3s ease;
        }
        .theme-toggle-btn:hover { color: var(--primary-color); }
        .nav-link {
            color: var(--text-muted); text-decoration: none;
            font-weight: 500; transition: color 0.3s ease; font-size: 0.95rem;
            display: inline-flex; align-items: center; gap: 0.4rem;
        }
        .nav-link:hover { color: var(--primary-color); }
        .nav-link i { margin-right: 0.1rem; }

        /* --- Main Content Container --- */
        .container {
            max-width: var(--content-max-width); margin: 2rem auto;
            padding: 2rem 2.5rem; background-color: var(--white-bg);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-radius: var(--border-radius); box-shadow: var(--box-shadow);
            border: 1px solid var(--card-border-color); flex-grow: 1; /* Make container grow */
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        /* --- Page Header & Section Titles --- */
        .page-header { display: flex; align-items: center; gap: 1rem; color: var(--primary-color); margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--card-border-color); }
        .page-header i { font-size: 2rem; opacity: 0.9; }
        .page-header .lottie-container { width: 50px; height: 50px; margin-right: 0.5rem;}
        .page-header h1 { font-size: 1.9rem; font-weight: 700; margin: 0; }
        h2 { color: var(--text-color); margin-top: 2.5rem; margin-bottom: 1.2rem; font-size: 1.4rem; font-weight: 600; border-bottom: 1px solid var(--card-border-color); padding-bottom: 0.6rem; display: flex; align-items: center; gap: 0.6rem; }
        h2 i { color: var(--text-muted); font-size: 0.9em; opacity: 0.8;}

        /* --- Flash Messages --- */
        .message { padding: 1rem 1.25rem; margin-bottom: 1.5rem; border-radius: var(--border-radius-input); border: 1px solid transparent; display: flex; align-items: flex-start; gap: 0.75rem; font-size: 0.95rem; background-color: var(--white-bg); transition: opacity 0.5s ease-out, transform 0.5s ease-out; opacity: 1; transform: translateY(0);}
        .message.fade-out { opacity: 0; transform: translateY(-10px); }
        .message i { font-size: 1.2rem; margin-top: 0.1rem; } .message-content { flex-grow: 1; }
        .message.success { color: var(--success-color); background-color: var(--success-bg); border-color: var(--success-border); } .message.success i { color: var(--success-color); }
        html[data-theme='dark'] .message.success { color: #a7f3d0; }
        .message.error { color: var(--danger-color); background-color: var(--danger-bg); border-color: var(--danger-border); } .message.error i { color: var(--danger-color); }
        html[data-theme='dark'] .message.error { color: #fecaca; }

        /* --- Summary Section --- */
        .summary-section { background-color: rgba(255, 255, 255, 0.6); padding: 1.5rem 2rem; border-radius: var(--border-radius); margin-bottom: 2.5rem; border: 1px solid var(--card-border-color); box-shadow: 0 4px 15px rgba(0,0,0,0.05); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); transition: background-color 0.3s ease, border-color 0.3s ease;}
        html[data-theme='dark'] .summary-section { background-color: rgba(55, 65, 81, 0.6); }
        .summary-item { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.8rem; font-size: 1rem; padding: 0.4rem 0;}
        .summary-item:not(:last-child) { border-bottom: 1px solid var(--card-border-color); }
        .summary-item strong { color: var(--text-color); font-weight: 500; } .summary-item span { font-weight: 600; font-size: 1.05rem;}
        .positive { color: var(--success-color); } .negative { color: var(--danger-color); } .neutral { color: var(--primary-color); }
        .progress-bar-container { background-color: rgba(229, 231, 235, 0.7); border-radius: 50px; height: 20px; overflow: hidden; position: relative; margin-top: 4px; }
        html[data-theme='dark'] .progress-bar-container { background-color: rgba(75, 85, 99, 0.7); }
        .progress-bar { background-color: var(--primary-color); height: 100%; color: white; text-align: center; line-height: 20px; font-size: 0.75rem; font-weight: 600; transition: width 0.5s ease-in-out; white-space: nowrap; overflow: hidden; padding: 0 8px; display: flex; align-items: center; justify-content: center; border-radius: 50px; }
        .progress-bar.high-usage { background-color: var(--warning-color); color: #333; } .progress-bar.over-budget { background-color: var(--danger-color); }
        .overall-progress-label { display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px; font-size: 0.9rem; font-weight: 500; }
        .overall-progress-label span { font-weight: 600; }

        /* --- Analytics Chart Section --- */
        .analytics-section { margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--card-border-color); }
        .chart-container { position: relative; height: 350px; width: 100%; margin-top: 1rem; }
        .chart-container canvas { border-radius: var(--border-radius-input); }

        /* --- Table Styling --- */
        .table-container { width: 100%; overflow-x: auto; margin-top: 0.5rem; border: 1px solid var(--card-border-color); border-radius: var(--border-radius); box-shadow: 0 4px 15px rgba(0,0,0,0.05); background-color: var(--white-bg); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); transition: background-color 0.3s ease, border-color 0.3s ease;}
        html[data-theme='dark'] .table-container { background-color: var(--dark-card-bg); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.9rem 0.8rem; border-bottom: 1px solid var(--card-border-color); font-size: 0.95rem; vertical-align: middle; }
        thead tr { border-bottom: 2px solid var(--card-border-color); }
        th { background-color: rgba(248, 249, 250, 0.6); font-weight: 600; color: var(--text-color); white-space: nowrap; }
        html[data-theme='dark'] th { background-color: rgba(55, 65, 81, 0.6); }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr { transition: background-color 0.2s ease; }
        tbody tr:hover { background-color: rgba(241, 243, 245, 0.7); }
        html[data-theme='dark'] tbody tr:hover { background-color: rgba(55, 65, 81, 0.7); }
        td:nth-child(n+2):not(:nth-last-child(2)) { text-align: right; font-feature-settings: "tnum"; font-variant-numeric: tabular-nums; } /* Align numbers right */
        th:nth-child(n+2):not(:nth-last-child(2)) { text-align: right; }
        th:first-child, td:first-child { width: 25%; text-align: left; font-weight: 500; }
        td:nth-last-child(2) { width: 22%; } /* Usage column */
        th:last-child, td:last-child { width: 10%; text-align: center; } /* Actions column */
        td .progress-bar-container { height: 16px; margin: 0; } td .progress-bar { line-height: 16px; font-size: 0.7rem; }

        /* --- Action Buttons --- */
        .action-btn { background: none; border: none; cursor: pointer; padding: 5px 8px; font-size: 1rem; color: var(--text-muted); opacity: 0.9; vertical-align: middle; transition: color 0.2s, transform 0.2s; }
        .action-btn:hover { opacity: 1; transform: scale(1.1) rotate(-3deg); }
        .action-btn.edit:hover { color: var(--primary-color); } .action-btn.delete:hover { color: var(--danger-color); }

        /* --- Add Button Section --- */
        .add-budget-section { margin-top: 2.5rem; text-align: center; padding-bottom: 1rem; }

        /* --- General Button Styles --- */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem; padding: 0.8rem 1.8rem; border: none; border-radius: var(--border-radius-input); font-size: 0.95rem; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.25s ease-in-out; white-space: nowrap; letter-spacing: 0.5px; text-transform: uppercase; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn:hover:not(:disabled) { transform: translateY(-2px) scale(1.02); box-shadow: 0 7px 18px rgba(0,0,0,0.15); }
        .btn:active:not(:disabled) { transform: translateY(0) scale(0.98); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-image: none !important; background-color: var(--secondary-color) !important; }
        .btn i, .btn .spinner { font-size: 1em; transition: transform 0.2s ease-out; }
        .btn:hover i:not(.spinner) { transform: rotate(-5deg) scale(1.1); }
        .btn .spinner { display: inline-block; width: 1em; height: 1em; border: 2px solid currentColor; border-right-color: transparent; border-radius: 50%; animation: spinner-border .75s linear infinite; margin-right: 0.5rem; }
        @keyframes spinner-border { to { transform: rotate(360deg); } }
        .btn .btn-icon { display: inline-flex; align-items: center; }

        .btn-success { background-image: var(--success-gradient); color: #fff; background-size: 150% auto; }
        .btn-success:hover:not(:disabled) { background-image: var(--success-gradient-hover); background-position: right center; box-shadow: 0 6px 15px rgba(22, 163, 74, 0.3); }
        .btn-primary { background-image: var(--primary-gradient); color: #fff; background-size: 150% auto; }
        .btn-primary:hover:not(:disabled) { background-image: var(--primary-gradient-hover); background-position: right center; box-shadow: 0 6px 15px rgba(59, 130, 246, 0.3); }
        .btn-secondary { background-color: transparent; color: var(--secondary-color); border: 1px solid var(--card-border-color); box-shadow: none; }
        .btn-secondary:hover:not(:disabled) { background-color: rgba(0,0,0,0.03); border-color: var(--secondary-color); transform: translateY(0); box-shadow: none; }
        html[data-theme='dark'] .btn-secondary { color: var(--dark-text-muted); border-color: var(--dark-border-color); }
        html[data-theme='dark'] .btn-secondary:hover:not(:disabled) { background-color: rgba(255,255,255,0.05); border-color: var(--dark-text-color); color: var(--dark-text-color); }

        /* --- No Results Message --- */
        .no-results { text-align: center; padding: 2.5rem; color: var(--text-muted); font-style: italic; }

        /* --- Modal Styling --- */
        .modal { display: none; position: fixed; z-index: 1050; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
        .modal-content {
            background-color: var(--white-bg);
            backdrop-filter: blur(15px); -webkit-backdrop-filter: blur(15px);
            margin: 8% auto; padding: 25px 30px 30px 30px; border: 1px solid var(--card-border-color);
            width: 90%; max-width: 550px; border-radius: var(--border-radius); position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); animation: fadeInModal 0.3s ease-out;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        html[data-theme='dark'] .modal-content { background-color: var(--dark-card-bg); }
        @keyframes fadeInModal { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
        .modal-close { color: var(--text-muted); position: absolute; top: 15px; right: 20px; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1; transition: color 0.2s; }
        .modal-close:hover, .modal-close:focus { color: var(--danger-color); text-decoration: none; }
        .modal-header { margin-bottom: 1.5rem; padding-bottom: 0.8rem; border-bottom: 1px solid var(--card-border-color); display: flex; align-items: center; gap: 0.6rem; }
        .modal-header i { font-size: 1.5rem; color: var(--primary-color); }
        .modal-header h2 { margin: 0; font-size: 1.5rem; font-weight: 600; color: var(--text-color); border: none; padding: 0; }

        /* --- Modal Form Styling (Floating Labels) --- */
        .modal-form .form-group { position: relative; margin-top: 1.2rem; margin-bottom: 1.3rem; }
        .modal-form label {
            position: absolute; left: var(--label-default-left); top: 50%; transform: translateY(-50%);
            font-weight: 500; color: var(--text-muted); background-color: transparent; padding: 0 0.3rem;
            transition: all 0.2s ease-out, background-color 0.3s ease; pointer-events: none; font-size: 1rem; white-space: nowrap;
        }
        .modal-form label[for="modalAllocated"],
        .modal-form label[for="modalSpent"] { left: calc(var(--currency-symbol-width) + var(--input-padding-x)); }

        .modal-form .form-control:focus + label,
        .modal-form .form-control:not(:placeholder-shown) + label {
            top: 0; transform: translateY(-50%) scale(0.85); font-weight: 600;
            color: var(--primary-color); background-color: var(--white-bg);
             border-radius: 4px; z-index: 1; left: var(--label-default-left);
        }
        html[data-theme='dark'] .modal-form .form-control:focus + label,
        html[data-theme='dark'] .modal-form .form-control:not(:placeholder-shown) + label {
             background-color: var(--dark-card-bg); /* Match dark modal background */
        }

        .modal-form .form-control {
             width: 100%; padding: var(--input-padding-y) var(--input-padding-x);
             border: 1px solid var(--input-border-color); border-radius: var(--border-radius-input);
             font-size: 1rem; font-family: inherit;
             transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out, background-color 0.3s ease;
             background-color: var(--white-bg); color: var(--text-color);
             box-shadow: var(--input-shadow); position: relative; z-index: 0;
        }
        html[data-theme='dark'] .modal-form .form-control { background-color: var(--dark-input-bg); }
        .modal-form .form-control::placeholder { color: transparent; } /* Hide placeholder for label trick */
        .modal-form .form-control:focus { border-color: var(--input-focus-border); outline: 0; box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow); z-index: 2;}
        .modal-form .form-control:focus-visible { outline: none; box-shadow: 0 0 0 3px var(--input-focus-shadow), 0 0 10px 2px var(--input-focus-shadow), var(--input-shadow); border-color: var(--input-focus-border); }

        /* --- Modal Input Group --- */
        .modal-form .input-group {
            position: relative; display: flex; align-items: stretch; width: 100%;
             box-shadow: var(--input-shadow); border-radius: var(--border-radius-input);
             overflow: hidden; border: 1px solid var(--input-border-color);
             transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
             background-color: var(--white-bg); /* Group background */
        }
        html[data-theme='dark'] .modal-form .input-group { background-color: var(--dark-input-bg); }
        .modal-form .input-group:focus-within { border-color: var(--input-focus-border); box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow); z-index: 2; }
        .modal-form .input-group:focus-within:has(:focus-visible) { outline: none; box-shadow: 0 0 0 3px var(--input-focus-shadow), 0 0 10px 2px var(--input-focus-shadow), var(--input-shadow); border-color: var(--input-focus-border); }
        .modal-form .input-group-text {
            display: flex; align-items: center; padding: var(--input-padding-y) var(--input-padding-x);
             font-size: 1rem; font-weight: 400; color: var(--text-muted); text-align: center;
             background-color: rgba(248, 249, 250, 0.7); border: none; border-right: 1px solid var(--input-border-color);
             width: var(--currency-symbol-width); justify-content: center; transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        html[data-theme='dark'] .modal-form .input-group-text { background-color: rgba(55, 65, 81, 0.6); border-right: 1px solid var(--dark-input-border); color: var(--dark-text-muted); }
        .modal-form .input-group .form-control { flex: 1 1 auto; width: 1%; min-width: 0; border-radius: 0; border: none; box-shadow: none; z-index: 0; background-color: transparent; /* Input inside group is transparent */ padding-left: 0.8rem; }
        .modal-form .input-group:focus-within .form-control:focus,
        .modal-form .input-group:focus-within .form-control:focus-visible { box-shadow: none; border-color: transparent; outline: none; }

        /* --- Modal Validation --- */
        .modal-form .form-control.is-invalid {
            border-color: var(--danger-color) !important;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23ef4444'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23ef4444' stroke='none'/%3e%3c/svg%3e");
            background-repeat: no-repeat; background-position: right 1.1rem center; background-size: 1em 1em;
            padding-right: calc(1.5em + var(--input-padding-x)) !important;
        }
        .modal-form .input-group.is-invalid { border-color: var(--danger-color) !important; }
        .modal-form .input-group.is-invalid .form-control {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23ef4444'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23ef4444' stroke='none'/%3e%3c/svg%3e");
             background-repeat: no-repeat; background-position: right 0.8rem center; background-size: 1em 1em;
             padding-right: calc(1.5em + 0.8rem) !important;
        }
        .modal-form .form-control.is-invalid:focus,
        .modal-form .input-group.is-invalid:focus-within {
            border-color: var(--danger-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(239, 68, 68, 0.25), var(--input-shadow);
        }
        .modal-form .input-group.is-invalid:focus-within:has(:focus-visible) { box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.25), 0 0 10px 2px rgba(239, 68, 68, 0.25), var(--input-shadow); }
        .error-message { color: var(--danger-color); font-size: 0.875em; margin-top: 0.3rem; display: block; min-height: 1em; }

        .modal-buttons { text-align: right; margin-top: 2rem; display: flex; justify-content: flex-end; gap: 0.75rem;}
        .modal-buttons .btn { padding: 0.7rem 1.3rem; }

        /* --- Footer --- */
        .footer { text-align: center; margin-top: auto; /* Push footer down */ padding: 1.5rem 1rem; font-size: 0.85rem; color: var(--text-muted); border-top: 1px solid var(--card-border-color); background-color: var(--white-bg); backdrop-filter: blur(5px); transition: background-color 0.3s ease, border-color 0.3s ease;}
        html[data-theme='dark'] .footer { background-color: var(--dark-card-bg); }

        /* --- Responsive Adjustments --- */
        @media (max-width: 992px) {
            .navbar { padding: 0.8rem 1rem; }
            .container { margin: 1.5rem auto; padding: 2rem 1.5rem; }
            .analytics-section { flex-direction: column; gap: 2rem; } /* Stack chart if needed */
        }
        @media (max-width: 768px) {
            body { padding-top: 70px; }
            .container { margin: 1rem auto; padding: 1.5rem; }
            .page-header h1 { font-size: 1.6rem; } .page-header .lottie-container { width: 40px; height: 40px;}
            h2 { font-size: 1.3rem; }
            .summary-item { font-size: 0.95rem; flex-wrap: wrap; }
            th, td { font-size: 0.9rem; padding: 0.7rem 0.5rem;}
            td:nth-last-child(2) { width: 30%; } /* Usage column wider */
            th:last-child, td:last-child { width: 15%; } /* Actions wider */
            .chart-container { height: 300px; } /* Adjust chart height */
            .modal-content { width: 90%; margin: 15% auto; padding: 20px; }
             .modal-header h2 { font-size: 1.3rem; } .action-btn { padding: 4px;}
        }
        @media (max-width: 480px) {
            html { font-size: 15px; } body { padding-top: 65px; }
            .navbar { padding: 0.6rem 1rem; } .navbar-brand { font-size: 1.2rem; }
            .container { padding: 1.5rem 1rem; margin: 0.5rem auto; }
            .page-header h1 { font-size: 1.4rem; } h2 { font-size: 1.2rem; }
            .modal-buttons { flex-direction: column-reverse; align-items: stretch; gap: 0.5rem;} .modal-buttons .btn { width: 100%; }
            .modal-form label[for="modalAllocated"],
            .modal-form label[for="modalSpent"] { left: calc(var(--currency-symbol-width) + 1rem); } /* Adjust label position for small screens */
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="navbar-brand">
             <i class="fa-solid fa-coins"></i> Finance Tracker
        </a>
        <div class="navbar-controls">
             <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="nav-link" title="Go to Dashboard">
                 <i class="fa-solid fa-arrow-left"></i> Dashboard
             </a>
            <button id="theme-toggle" class="theme-toggle-btn" title="Toggle light/dark theme">
                <i class="fa-solid fa-moon"></i> <!-- Icon changes via JS -->
            </button>
        </div>
    </nav>

    <div class="container">
        <header class="page-header">
            <!-- Lottie Animation -->
            <div class="lottie-container">
                 <lottie-player src="https://assets6.lottiefiles.com/packages/lf20_g0uhufn7.json" background="transparent" speed="1" style="width: 100%; height: 100%;" loop autoplay title="Budgeting icon"></lottie-player>
             </div>
            <h1>Manage Budgets</h1>
        </header>

        <!-- Flash Message Display -->
        <?php if ($message): ?>
        <div id="flash-message" class="message <?php echo htmlspecialchars($message_type); ?>">
             <i class="fa-solid <?php echo ($message_type === 'success') ? 'fa-check-circle' : 'fa-triangle-exclamation'; ?>"></i>
             <div class="message-content"><?php echo htmlspecialchars($message); ?></div>
        </div>
        <?php endif; ?>

        <!-- Overall Budget Summary -->
        <section class="summary-section">
             <h2><i class="fa-solid fa-chart-pie"></i>Overall Status</h2>
             <div class="summary-item">
                 <strong>Total Allocated:</strong>
                 <span><?php echo formatCurrency($budgetTotal, $currencySymbol); ?></span>
             </div>
             <div class="summary-item">
                 <strong>Total Spent:</strong>
                 <span class="negative"><?php echo formatCurrency($budgetSpentTotal, $currencySymbol); ?></span>
             </div>
             <div class="summary-item">
                 <strong>Total Remaining:</strong>
                 <span class="<?php echo ($budgetRemaining >= 0) ? 'positive' : 'negative'; ?>">
                    <?php echo formatCurrency($budgetRemaining, $currencySymbol); ?>
                 </span>
             </div>
             <div style="margin-top: 1.2rem;">
                 <div class="overall-progress-label">
                    <strong>Overall Budget Usage:</strong>
                    <span><?php echo $budgetUsedPercent; ?>%</span>
                 </div>
                 <div class="progress-bar-container" title="<?php echo $budgetUsedPercent; ?>% Used">
                     <?php
                         $overallProgressClass = '';
                         if ($budgetUsedPercent > 100) $overallProgressClass = 'over-budget';
                         elseif ($budgetUsedPercent >= 85) $overallProgressClass = 'high-usage';
                     ?>
                     <div class="progress-bar <?php echo $overallProgressClass; ?>" style="width: <?php echo min(max(0, $budgetUsedPercent), 100); ?>%;">
                         <?php if ($budgetUsedPercent > 10) echo $budgetUsedPercent . '%'; ?>
                     </div>
                 </div>
             </div>
        </section>

        <!-- Budget Categories Table -->
        <h2><i class="fa-solid fa-list-check"></i>Budget Categories</h2>
        <div class="table-container">
             <table>
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Allocated</th>
                        <th>Spent</th>
                        <th>Remaining</th>
                        <th>Usage</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($budgetBreakdown)): ?>
                        <tr>
                            <td colspan="6" class="no-results">No budget categories defined yet. Add one below!</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($budgetBreakdown as $category => $data): ?>
                            <?php
                                $allocated = $data['allocated'];
                                $spent = $data['spent'];
                                $remaining = $allocated - $spent;
                                $percentSpent = ($allocated > 0) ? round(($spent / $allocated) * 100) : 0;
                                $barClass = '';
                                if ($percentSpent > 100) $barClass = 'over-budget';
                                elseif ($percentSpent >= 85) $barClass = 'high-usage';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($category); ?></td>
                                <td><?php echo formatCurrency($allocated, $currencySymbol); ?></td>
                                <td><?php echo formatCurrency($spent, $currencySymbol); ?></td>
                                <td class="<?php echo ($remaining >= 0) ? 'positive' : 'negative'; ?>">
                                    <?php echo formatCurrency($remaining, $currencySymbol); ?>
                                </td>
                                <td>
                                     <div class="progress-bar-container" title="<?php echo $percentSpent; ?>% Spent">
                                         <div class="progress-bar <?php echo $barClass; ?>" style="width: <?php echo min(max(0,$percentSpent), 100); ?>%;">
                                            <?php if ($percentSpent > 15) echo $percentSpent . '%'; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <!-- Edit Button -->
                                    <button class="action-btn edit" title="Edit Budget"
                                            data-category="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-allocated="<?php echo $allocated; ?>"
                                            data-spent="<?php echo $spent; ?>"
                                            onclick="openBudgetModal('edit', this)">
                                        <i class="fa-solid fa-pencil"></i>
                                    </button>
                                    <!-- Delete Form -->
                                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" style="display: inline;" onsubmit="return confirmDelete('<?php echo htmlspecialchars(addslashes($category), ENT_QUOTES, 'UTF-8'); ?>');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="category" value="<?php echo htmlspecialchars($category, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="action-btn delete" title="Delete Budget">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Add New Budget Button -->
        <div class="add-budget-section">
             <button class="btn btn-success" onclick="openBudgetModal('add')">
                 <i class="fa-solid fa-plus-circle"></i> Add New Budget Category
             </button>
        </div>

        <!-- Analytics Chart Section -->
        <?php if (!empty($budgetChartData['labels'])): ?>
        <section class="analytics-section">
            <h2><i class="fa-solid fa-chart-bar"></i>Budget Allocation vs. Spent</h2>
            <div class="chart-container">
                <canvas id="budgetChart"></canvas>
            </div>
        </section>
        <?php endif; ?>

    </div><!-- End .container -->

    <!-- Budget Modal -->
    <div id="budgetModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeBudgetModal()" title="Close">×</span>
             <div class="modal-header">
                 <i class="fa-solid fa-cash-register"></i>
                 <h2 id="modalTitle">Add Budget Category</h2>
            </div>

            <form id="budgetForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="modal-form" onsubmit="return validateModalForm();">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="original_category" id="modalOriginalCategory">

                <div class="form-group">
                    <input type="text" id="modalCategory" name="category" class="form-control" required maxlength="255" placeholder=" " title="Enter a unique name for this budget category (e.g., Groceries, Transport)">
                    <label for="modalCategory">Category Name:</label>
                    <span class="error-message" id="modalCategoryError"></span>
                </div>

                <div class="form-group">
                    <div class="input-group">
                        <span class="input-group-text"><?php echo htmlspecialchars($currencySymbol); ?></span>
                        <input type="number" id="modalAllocated" name="allocated" class="form-control" required step="0.01" min="0" placeholder=" " title="How much do you plan to allocate?">
                    </div>
                     <label for="modalAllocated">Allocated Amount:</label>
                     <span class="error-message" id="modalAllocatedError"></span>
                </div>

                <div class="form-group">
                    <div class="input-group">
                        <span class="input-group-text"><?php echo htmlspecialchars($currencySymbol); ?></span>
                        <input type="number" id="modalSpent" name="spent" class="form-control" required step="0.01" min="0" placeholder=" " value="0.00" title="Enter the amount already spent (optional, default is 0)">
                    </div>
                    <label for="modalSpent">Spent Amount:</label>
                    <span class="error-message" id="modalSpentError"></span>
                </div>

                <!-- Placeholder for future Notes/Tags/Receipt fields -->
                <!--
                <div class="form-group">
                    <textarea id="modalNotes" name="notes" class="form-control" placeholder=" " rows="3"></textarea>
                    <label for="modalNotes">Notes (Optional):</label>
                </div>
                -->

                <div class="modal-buttons">
                    <button type="button" class="btn btn-secondary" onclick="closeBudgetModal()">Cancel</button>
                    <button type="submit" class="btn" id="modalSubmitButton">
                        <span class="btn-icon"><i class="fa-solid fa-save"></i></span>
                        <span class="btn-text">Save Budget</span>
                    </button>
                </div>
            </form>
        </div>
    </div><!-- End Modal -->

    <footer class="footer">
        © <?php echo date('Y'); ?> Your Finance Tracker. All rights reserved.
    </footer>

    <script>
        // --- Existing Modal JS + Enhancements ---
        const modal = document.getElementById('budgetModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalAction = document.getElementById('modalAction');
        const modalOriginalCategoryInput = document.getElementById('modalOriginalCategory');
        const modalCategoryInput = document.getElementById('modalCategory');
        const modalAllocatedInput = document.getElementById('modalAllocated');
        const modalSpentInput = document.getElementById('modalSpent');
        const modalSubmitButton = document.getElementById('modalSubmitButton');
        const modalSubmitButtonText = modalSubmitButton.querySelector('.btn-text');
        const modalSubmitButtonIcon = modalSubmitButton.querySelector('.btn-icon');
        const originalIconHTML = modalSubmitButtonIcon ? modalSubmitButtonIcon.innerHTML : '<i class="fa-solid fa-save"></i>'; // Store original icon HTML

        const budgetForm = document.getElementById('budgetForm');
        const modalCategoryError = document.getElementById('modalCategoryError');
        const modalAllocatedError = document.getElementById('modalAllocatedError');
        const modalSpentError = document.getElementById('modalSpentError');

        // Function to handle floating label logic
        const handleModalLabelFloat = (inputElement) => {
            const group = inputElement.closest('.form-group');
            if (!group) return;
            const label = group.querySelector('label');
            if (!label) return;

            const isInputGroup = inputElement.closest('.input-group');
            const checkInput = isInputGroup ? inputElement.closest('.input-group').querySelector('.form-control') : inputElement;

            const shouldFloat = checkInput && (
                (checkInput.value && checkInput.value !== "" && checkInput.value !== null) ||
                document.activeElement === checkInput ||
                checkInput.matches(':autofill') // Handle browser autofill
            );

            const whiteBg = getComputedStyle(document.documentElement).getPropertyValue(
                document.documentElement.hasAttribute('data-theme') ? '--dark-card-bg' : '--white-bg'
            ).trim();


            if (shouldFloat) {
                label.style.top = '0';
                label.style.transform = 'translateY(-50%) scale(0.85)';
                label.style.fontWeight = '600';
                label.style.color = 'var(--primary-color)';
                label.style.backgroundColor = whiteBg;
                label.style.zIndex = '1';
                label.style.left = 'var(--label-default-left)';
            } else {
                // Reset styles
                label.style.top = '50%';
                label.style.transform = 'translateY(-50%)';
                label.style.fontWeight = '500';
                label.style.color = 'var(--text-muted)';
                label.style.backgroundColor = 'transparent';
                label.style.zIndex = 'auto';
                if (label.getAttribute('for') === 'modalAllocated' || label.getAttribute('for') === 'modalSpent') {
                    label.style.left = 'calc(var(--currency-symbol-width) + var(--input-padding-x))';
                } else {
                    label.style.left = 'var(--label-default-left)';
                }
            }
        };

        // Add event listeners for floating labels in the modal
        document.querySelectorAll('#budgetModal .form-control').forEach(input => {
            input.addEventListener('focus', () => handleModalLabelFloat(input));
            input.addEventListener('blur', () => handleModalLabelFloat(input));
            input.addEventListener('input', () => handleModalLabelFloat(input));
            // Initial check in case of pre-filled values (edit mode) or autofill
             setTimeout(() => handleModalLabelFloat(input), 50); // Delay for autofill detection
        });


        function clearModalErrors() {
            modalCategoryError.textContent = '';
            modalAllocatedError.textContent = '';
            modalSpentError.textContent = '';
            modalCategoryInput.classList.remove('is-invalid');
            modalAllocatedInput.classList.remove('is-invalid');
            modalSpentInput.classList.remove('is-invalid');
            modalAllocatedInput.closest('.input-group')?.classList.remove('is-invalid');
            modalSpentInput.closest('.input-group')?.classList.remove('is-invalid');

            // Reset label positions only if input is empty
            document.querySelectorAll('#budgetModal .form-control').forEach(input => {
                handleModalLabelFloat(input);
            });
        }

        function openBudgetModal(mode, buttonElement = null) {
            budgetForm.reset();
            clearModalErrors();
            modalCategoryInput.disabled = false; // Re-enable category input

            // Reset button state
             modalSubmitButton.disabled = false;
             modalSubmitButtonText.textContent = 'Save Budget';
             modalSubmitButtonIcon.innerHTML = originalIconHTML;


            if (mode === 'add') {
                modalTitle.textContent = 'Add Budget Category';
                modalAction.value = 'add';
                modalOriginalCategoryInput.value = '';
                modalSpentInput.value = '0.00'; // Default spent to 0
                modalSubmitButtonText.textContent = 'Add Budget';
                modalSubmitButton.className = 'btn btn-success'; // Use success color for add

            } else if (mode === 'edit' && buttonElement) {
                modalTitle.textContent = 'Edit Budget Category';
                modalAction.value = 'edit';

                const category = buttonElement.getAttribute('data-category');
                const allocated = buttonElement.getAttribute('data-allocated');
                const spent = buttonElement.getAttribute('data-spent');

                modalCategoryInput.value = category;
                modalAllocatedInput.value = parseFloat(allocated).toFixed(2);
                modalSpentInput.value = parseFloat(spent).toFixed(2);
                modalOriginalCategoryInput.value = category;

                modalSubmitButtonText.textContent = 'Update Budget';
                modalSubmitButton.className = 'btn btn-primary'; // Use primary color for edit

                // Force labels to float for pre-filled data
                 handleModalLabelFloat(modalCategoryInput);
                 handleModalLabelFloat(modalAllocatedInput);
                 handleModalLabelFloat(modalSpentInput);

            } else {
                console.error("Invalid mode or missing button element for edit.");
                return;
            }

            modal.style.display = 'block';
            setTimeout(() => { modalCategoryInput.focus(); }, 50); // Focus after modal is visible
        }

        function closeBudgetModal() {
            modal.style.display = 'none';
        }

        // Close modal on outside click or Escape key
        window.onclick = function(event) {
            if (event.target == modal) { closeBudgetModal(); }
        }
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape" && modal.style.display === 'block') { closeBudgetModal(); }
        });

        // --- Existing Delete Confirmation ---
        function confirmDelete(categoryName) {
            return confirm(`Are you sure you want to delete the budget category "${categoryName}"? This action cannot be undone.`);
        }

        // --- Enhanced Client-Side Validation ---
        function validateModalForm() {
            let isValid = true;
            clearModalErrors(); // Clear previous errors first

            const categoryValue = modalCategoryInput.value.trim();
            if (categoryValue === '') {
                 modalCategoryError.textContent = 'Category name is required.';
                 modalCategoryInput.classList.add('is-invalid');
                 isValid = false;
            } else if (categoryValue.length > 255) {
                 modalCategoryError.textContent = 'Category name is too long (max 255 chars).';
                 modalCategoryInput.classList.add('is-invalid');
                 isValid = false;
            }

            const allocatedValue = modalAllocatedInput.value;
            if (allocatedValue.trim() === '') {
                 modalAllocatedError.textContent = 'Allocated amount is required.';
                 modalAllocatedInput.classList.add('is-invalid');
                 modalAllocatedInput.closest('.input-group')?.classList.add('is-invalid');
                 isValid = false;
            } else {
                const allocatedNum = parseFloat(allocatedValue);
                if (isNaN(allocatedNum) || allocatedNum < 0) {
                     modalAllocatedError.textContent = 'Please enter a valid non-negative number.';
                     modalAllocatedInput.classList.add('is-invalid');
                     modalAllocatedInput.closest('.input-group')?.classList.add('is-invalid');
                     isValid = false;
                 }
            }

            const spentValue = modalSpentInput.value;
            if (spentValue.trim() === '') {
                 modalSpentError.textContent = 'Spent amount is required.';
                 modalSpentInput.classList.add('is-invalid');
                 modalSpentInput.closest('.input-group')?.classList.add('is-invalid');
                 isValid = false;
            } else {
                const spentNum = parseFloat(spentValue);
                if (isNaN(spentNum) || spentNum < 0) {
                     modalSpentError.textContent = 'Please enter a valid non-negative number.';
                     modalSpentInput.classList.add('is-invalid');
                     modalSpentInput.closest('.input-group')?.classList.add('is-invalid');
                     isValid = false;
                 }
            }

            if (!isValid) {
                const firstInvalid = budgetForm.querySelector('.is-invalid');
                if(firstInvalid) {
                    firstInvalid.focus(); // Focus the first invalid field
                }
                return false; // Prevent submission
            }

            // --- Add Loading State on Submit ---
             modalSubmitButton.disabled = true;
             modalSubmitButtonText.textContent = 'Saving...';
             modalSubmitButtonIcon.innerHTML = '<span class="spinner"></span>'; // Replace icon with spinner

            return true; // Allow submission
        }


        // --- Dark Mode Toggle ---
         const themeToggle = document.getElementById('theme-toggle');
         const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null;
         const sunIconClass = 'fa-solid fa-sun';
         const moonIconClass = 'fa-solid fa-moon';

         const setMode = (isDark) => {
             const iconElement = themeToggle.querySelector('i');
             if (isDark) {
                 document.documentElement.setAttribute('data-theme', 'dark');
                 localStorage.setItem('theme', 'dark');
                 iconElement.className = sunIconClass;
                 themeToggle.title = 'Switch to light theme';
             } else {
                 document.documentElement.removeAttribute('data-theme');
                 localStorage.setItem('theme', 'light');
                 iconElement.className = moonIconClass;
                 themeToggle.title = 'Switch to dark theme';
             }
             // Re-render chart if it exists
             if (window.budgetChartInstance) {
                 window.budgetChartInstance.destroy();
                 renderBudgetChart(); // Re-render chart with new theme colors
             }
              // Update floating label backgrounds if modal is open
             if (modal.style.display === 'block') {
                 document.querySelectorAll('#budgetModal .form-control').forEach(input => {
                     handleModalLabelFloat(input);
                 });
             }
         };

         // Set initial mode and icon
         const initialIconClass = (currentTheme === 'dark') ? sunIconClass : moonIconClass;
         themeToggle.querySelector('i').className = initialIconClass;
         if (currentTheme === 'dark') {
             document.documentElement.setAttribute('data-theme', 'dark');
             themeToggle.title = 'Switch to light theme';
         } else {
             themeToggle.title = 'Switch to dark theme';
         }

         themeToggle.addEventListener('click', () => {
             setMode(!document.documentElement.hasAttribute('data-theme'));
         });


         // --- Flash Message Auto-Hide ---
         const flashMessageDiv = document.getElementById('flash-message');
         if (flashMessageDiv) {
             setTimeout(() => {
                 flashMessageDiv.classList.add('fade-out');
                 flashMessageDiv.addEventListener('transitionend', () => {
                     if(flashMessageDiv.parentNode) { flashMessageDiv.parentNode.removeChild(flashMessageDiv); }
                 }, { once: true });
             }, 5000); // Hide after 5 seconds
         }


        // --- Budget Chart Rendering ---
        window.budgetChartInstance = null; // Global chart instance

        const renderBudgetChart = () => {
            const ctx = document.getElementById('budgetChart');
            if (!ctx) return;

            const budgetDataPHP = <?php echo json_encode($budgetChartData); ?>;
            if (!budgetDataPHP || !budgetDataPHP.labels || budgetDataPHP.labels.length === 0) {
                // Optional: Display a message if no data
                ctx.getContext('2d').clearRect(0, 0, ctx.width, ctx.height);
                // You could draw text here: ctx.fillText("No budget data for chart.", 10, 50);
                return;
            }

             // Destroy existing chart if it exists
             if (window.budgetChartInstance) {
                 window.budgetChartInstance.destroy();
                 window.budgetChartInstance = null;
             }

            const isDarkMode = document.documentElement.hasAttribute('data-theme');
            const textColor = getComputedStyle(document.documentElement).getPropertyValue(isDarkMode ? '--dark-text-color' : '--text-color').trim();
            const gridColor = getComputedStyle(document.documentElement).getPropertyValue(isDarkMode ? '--dark-border-color' : '--card-border-color').trim() + '80'; // Add alpha
            const allocatedColor = getComputedStyle(document.documentElement).getPropertyValue('--primary-color').trim();
            const spentColor = getComputedStyle(document.documentElement).getPropertyValue('--danger-color').trim();
            const tooltipBg = isDarkMode ? 'rgba(31, 41, 55, 0.9)' : 'rgba(255, 255, 255, 0.9)';
            const tooltipText = isDarkMode ? '#f3f4f6' : '#1f2937';

            window.budgetChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: budgetDataPHP.labels,
                    datasets: [
                        {
                            label: 'Allocated',
                            data: budgetDataPHP.allocated,
                            backgroundColor: allocatedColor + 'B3', // Add alpha ~70%
                            borderColor: allocatedColor,
                            borderWidth: 1,
                            borderRadius: 4, // Rounded bars
                            borderSkipped: false,
                        },
                        {
                            label: 'Spent',
                            data: budgetDataPHP.spent,
                            backgroundColor: spentColor + 'B3', // Add alpha ~70%
                            borderColor: spentColor,
                            borderWidth: 1,
                            borderRadius: 4,
                            borderSkipped: false,
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                color: textColor,
                                padding: 15,
                                font: { size: 12, family: "'Poppins', sans-serif" }
                            }
                        },
                        tooltip: {
                            backgroundColor: tooltipBg,
                            titleColor: tooltipText,
                            bodyColor: tooltipText,
                            titleFont: { family: "'Poppins', sans-serif", size: 13, weight: '600' },
                            bodyFont: { family: "'Poppins', sans-serif", size: 12 },
                            padding: 10,
                            cornerRadius: 4,
                            boxPadding: 4,
                            callbacks: {
                                label: function(context) {
                                    let label = context.dataset.label || '';
                                    if (label) { label += ': '; }
                                    if (context.parsed.y !== null) {
                                        label += new Intl.NumberFormat(document.documentElement.lang || 'en-US', { style: 'currency', currency: '<?php echo ($currencySymbol === '$' ? 'USD' : 'USD'); ?>' }).format(context.parsed.y);
                                    }
                                    return label;
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: textColor,
                                font: { family: "'Poppins', sans-serif" },
                                // Format Y-axis ticks as currency
                                callback: function(value, index, values) {
                                    return new Intl.NumberFormat(document.documentElement.lang || 'en-US', { style: 'currency', currency: '<?php echo ($currencySymbol === '$' ? 'USD' : 'USD'); ?>', notation: 'compact' }).format(value);
                                }
                            },
                            grid: {
                                color: gridColor,
                                drawBorder: false,
                            }
                        },
                        x: {
                            ticks: {
                                color: textColor,
                                font: { family: "'Poppins', sans-serif" }
                            },
                            grid: {
                                display: false, // Hide vertical grid lines
                                drawBorder: false,
                            }
                        }
                    }
                }
            });
        };

        // Initial chart render on page load
        document.addEventListener('DOMContentLoaded', renderBudgetChart);


    </script>

</body>
</html>
<?php
// Close the database connection at the very end
if ($conn) {
    $conn = null;
}
?>