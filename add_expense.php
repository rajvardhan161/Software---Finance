<?php
session_start();

// --- Configuration ---
$currencySymbol = '₹'; // Using Rupee symbol
$expenseCategoriesList = ['Groceries', 'Utilities', 'Dining Out', 'Transport', 'Entertainment', 'Shopping', 'Health', 'Rent/Mortgage', 'Subscription', 'Miscellaneous', 'Other'];
$paymentMethods = ['Debit Card', 'Credit Card', 'Bank Transfer', 'UPI', 'PayPal', 'Cash', 'Direct Deposit', 'Check', 'Other']; // Added UPI
$userName = $_SESSION['username'] ?? "User";
$dashboardUrl = "dashboard.php"; // URL for the back/dashboard button

// --- Database Connection ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "login";
$conn = null;
$dbConnectionError = null;
$recentExpenses = []; // Changed from income
$categoryDataForChart = [];
$monthlyTotalExpense = 0; // Changed from income

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Set default fetch mode

    // Ensure table exists (Updated table name and columns)
    $conn->exec("CREATE TABLE IF NOT EXISTS expenses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        expense_date DATE NOT NULL,
        expense_category VARCHAR(255) NOT NULL,
        expense_amount DECIMAL(12, 2) NOT NULL,
        payment_method VARCHAR(255) NOT NULL, -- Kept NOT NULL as per original code
        expense_description TEXT NULL,
        receipt_path VARCHAR(255) NULL -- For Receipt Upload feature (requires PHP processing change)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // --- Fetch Data for Sidebar/Analytics (Expense Focused) ---
    // Fetch Recent Expenses (Last 5)
    $stmtRecent = $conn->query("SELECT id, expense_date, expense_category, expense_amount FROM expenses ORDER BY expense_date DESC, id DESC LIMIT 5");
    $recentExpenses = $stmtRecent->fetchAll();

    // Fetch Data for Pie Chart (Expense Category Totals)
    $stmtChart = $conn->query("SELECT expense_category, SUM(expense_amount) as total FROM expenses GROUP BY expense_category ORDER BY total DESC");
    $categoryDataRaw = $stmtChart->fetchAll();
    // Prepare for Chart.js
    $categoryLabels = [];
    $categoryTotals = [];
    foreach ($categoryDataRaw as $row) {
        $categoryLabels[] = $row['expense_category'];
        $categoryTotals[] = $row['total'];
    }
    $categoryDataForChart = [
        'labels' => $categoryLabels,
        'totals' => $categoryTotals
    ];

    // Fetch Monthly Expense Summary
    $stmtMonthly = $conn->prepare("SELECT SUM(expense_amount) as monthly_total FROM expenses WHERE MONTH(expense_date) = MONTH(CURDATE()) AND YEAR(expense_date) = YEAR(CURDATE())");
    $stmtMonthly->execute();
    $resultMonthly = $stmtMonthly->fetch();
    $monthlyTotalExpense = $resultMonthly['monthly_total'] ?? 0;


} catch(PDOException $e) {
    $dbConnectionError = "Database connection failed: " . $e->getMessage();
    error_log("Add Expense DB Connection/Query Error: " . $e->getMessage());
    // Assign to $errors only if it's intended to block form submission entirely
    // $errors['database_connection'] = $dbConnectionError; // Consider if needed
}


// --- Form Processing ---
$errors = []; // Initialize errors array
$submittedData = $_POST; // Use $_POST directly, getValue handles defaults
$flashMessage = '';
$flashType = 'success'; // Can be 'success' or 'error'

// Retrieve flash message from session if it exists
if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// --- Form Processing Logic ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Only proceed if DB connection was successful initially
    if ($dbConnectionError) {
        $flashMessage = "Cannot process form: Database connection error.";
        $flashType = 'error';
        // Add a general error to prevent processing further if needed
        $errors['database_connection'] = $dbConnectionError;
    } else {
        // Sanitize and Validate Inputs
        $expense_date = filter_input(INPUT_POST, 'expense_date', FILTER_SANITIZE_SPECIAL_CHARS);
        $expense_amount_raw = filter_input(INPUT_POST, 'expense_amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $expense_category = filter_input(INPUT_POST, 'expense_category', FILTER_SANITIZE_SPECIAL_CHARS);
        $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_SPECIAL_CHARS);
        $expense_description = filter_input(INPUT_POST, 'expense_description', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_FLAG_NO_ENCODE_QUOTES);
        $expense_description = trim($expense_description ?? ''); // Ensure it's a string before trimming

        // Validation Logic
        if (empty($expense_date)) {
            $errors['expense_date'] = "Date is required.";
        } else {
            $d = DateTime::createFromFormat('Y-m-d', $expense_date);
            if (!$d || $d->format('Y-m-d') !== $expense_date) {
                $errors['expense_date'] = "Invalid date format. Please use YYYY-MM-DD.";
            }
        }

        // Validate Amount specifically for numeric and positive
         if ($expense_amount_raw === null || $expense_amount_raw === false || !is_numeric($expense_amount_raw)) {
             $errors['expense_amount'] = "Invalid amount. Please enter a valid number.";
             $expense_amount = null; // Set to null if invalid
         } else {
             $expense_amount = floatval($expense_amount_raw); // Convert to float
             if ($expense_amount <= 0) {
                 $errors['expense_amount'] = "Amount must be positive.";
             }
         }


        if (empty($expense_category)) {
            $errors['expense_category'] = "Category is required.";
        } elseif (!in_array($expense_category, $expenseCategoriesList)) {
             $errors['expense_category'] = "Invalid category selected."; // Optional: Validate against the list
        }

        if (empty($payment_method)) {
            $errors['payment_method'] = "Payment method is required.";
        } elseif (!in_array($payment_method, $paymentMethods)) {
             $errors['payment_method'] = "Invalid payment method selected."; // Optional: Validate against the list
        }

        // --- Database Insertion (If no validation errors and connection is still valid) ---
        if (empty($errors)) {
            // Re-establish connection within the POST block if needed, or ensure $conn is still valid
             try {
                 // Ensure $conn is not null before proceeding (might be null from initial catch)
                 if (!$conn) {
                    // Try to reconnect or handle error appropriately
                     $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
                     $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                     $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                 }

                $conn->beginTransaction();

                $sql = "INSERT INTO expenses (expense_date, expense_category, expense_amount, payment_method, expense_description)
                        VALUES (:expense_date, :expense_category, :expense_amount, :payment_method, :expense_description)";

                $stmt = $conn->prepare($sql);

                $stmt->bindParam(':expense_date', $expense_date);
                $stmt->bindParam(':expense_category', $expense_category);
                $stmt->bindParam(':expense_amount', $expense_amount); // Use the validated float value
                $stmt->bindParam(':payment_method', $payment_method);

                $expense_description_param = !empty($expense_description) ? $expense_description : null;
                $stmt->bindParam(':expense_description', $expense_description_param, $expense_description_param === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

                // File upload logic would go here if implemented

                $stmt->execute();
                $conn->commit();

                $_SESSION['flash_message'] = "Expense of " . htmlspecialchars($currencySymbol) . number_format($expense_amount, 2) . " for '" . htmlspecialchars($expense_category) . "' added successfully!";
                $_SESSION['flash_type'] = 'success';

                // Clear submitted data only on success
                $submittedData = [];

                // Redirect after successful submission (Post/Redirect/Get pattern)
                header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
                exit();

            } catch(PDOException $e) {
                if ($conn && $conn->inTransaction()) {
                     $conn->rollBack();
                 }
                $errors['database_insert'] = "Database error saving expense. Please try again. Details: " . $e->getMessage(); // More details for debugging
                error_log("Expense Insert Error [SQLSTATE " . $e->getCode() . "]: " . $e->getMessage());
                $flashMessage = "Failed to add expense due to a database error.";
                $flashType = 'error';
            }
        } else {
             // Validation errors occurred
            $flashMessage = "Please fix the errors in the form below.";
            $flashType = 'error';
        }
    }
}
// --- End of Form Processing ---


// Close connection if it was opened and is not needed anymore
// If the connection might be needed later on the page (unlikely here), don't close it yet.
if ($conn) {
    $conn = null;
}

// --- Helper Functions ---
function getValue(string $fieldName, $default = '') {
    global $submittedData;
    // Use submitted data first if available (e.g., on error), otherwise default
    // Ensure the key exists before accessing
    $value = isset($submittedData[$fieldName]) ? $submittedData[$fieldName] : $default;
    // Convert potential null to string before htmlspecialchars
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function hasError(string $fieldName): bool {
    global $errors;
    return isset($errors[$fieldName]);
}
function displayError(string $fieldName): string {
    global $errors;
    if (isset($errors[$fieldName])) {
        return '<div class="error-message">' . htmlspecialchars($errors[$fieldName]) . '</div>';
    }
    return '';
}

function formatCurrency($amount, $symbol = '$') {
    // Ensure amount is numeric before formatting
    $numericAmount = is_numeric($amount) ? floatval($amount) : 0.0;
    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format($numericAmount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Expense - Finance Tracker</title>
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
            /* Expense Theme Colors */
            --primary-color: #dc3545; /* Red for expenses */
            --primary-hover: #bb2d3b;
            --primary-gradient: linear-gradient(135deg, #fd7e14, #dc3545);
            --primary-gradient-hover: linear-gradient(135deg, #dc3545, #a61e29);
            --input-focus-border: var(--primary-color);
            --input-focus-shadow: rgba(220, 53, 69, 0.3); /* Red focus glow */
            --background-gradient: linear-gradient(160deg, #fef0f1 0%, #f4f7f9 100%); /* Soft red-tinted gradient */

            /* Common Variables */
            --secondary-color: #6c757d;
            --secondary-hover: #5a6268;
            --success-color: #198754; /* Green for success messages */
            --success-bg: #d1e7dd;
            --success-border: #a3cfbb;
            --error-color: var(--primary-color); /* Use primary red for errors */
            --error-bg: #f8d7da;
            --error-border: #f1aeb5;
            --light-bg: #f4f7f9;
            --white-bg: #ffffff; /* Solid white background */
            --card-border-color: rgba(0, 0, 0, 0.08);
            --input-border-color: #ced4da;
            --text-color: #212529;
            --text-muted: #6c757d;
            --font-family: 'Poppins', sans-serif;
            --border-radius: 1rem; /* Bigger radius */
            --border-radius-input: 0.6rem; /* Bigger radius */
            --box-shadow: 0 12px 35px rgba(40, 40, 90, 0.12); /* Smoother shadow */
            --input-shadow: 0 2px 5px rgba(0,0,0,0.05);
            --content-max-width: 1200px;

            /* Dark Mode Variables */
            --dark-bg: #1a1a2e;
            --dark-card-bg: rgba(26, 26, 46, 0.85);
            --dark-text-color: #e4e6eb;
            --dark-text-muted: #adb5bd;
            --dark-border-color: rgba(255, 255, 255, 0.15);
            --dark-input-border: #4f4f6f;
            --dark-input-bg: rgba(40, 40, 60, 0.5);
            --dark-focus-shadow: rgba(240, 98, 115, 0.3); /* Dark mode red glow */
            --dark-gradient: linear-gradient(160deg, #2a1a2e 0%, #1a1a2e 100%); /* Dark gradient with red hint */
        }

        html[data-theme='dark'] {
            --light-bg: var(--dark-bg);
            --white-bg: var(--dark-card-bg); /* Use dark card bg for general white bg */
            --card-border-color: var(--dark-border-color);
            --input-border-color: var(--dark-input-border);
            --text-color: var(--dark-text-color);
            --text-muted: var(--dark-text-muted);
            --input-focus-shadow: var(--dark-focus-shadow);
            --background-gradient: var(--dark-gradient);
            --input-shadow: 0 2px 5px rgba(0,0,0,0.2);
            --box-shadow: 0 12px 35px rgba(0, 0, 0, 0.2);

             /* Adjust success/error backgrounds for dark mode */
            --success-bg: rgba(25, 135, 84, 0.2);
            --success-border: rgba(25, 135, 84, 0.5);
            --error-bg: rgba(220, 53, 69, 0.2);
            --error-border: rgba(220, 53, 69, 0.5);
        }


        /* --- Global Styles --- */
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { font-size: 16px; scroll-behavior: smooth; }

        body {
            font-family: var(--font-family);
            background: var(--background-gradient); /* Use gradient */
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
            padding-top: 80px; /* Space for fixed navbar */
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
            font-size: 1.4rem; font-weight: 600; color: var(--primary-color); /* Use expense color */
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
            font-weight: 500; transition: color 0.3s ease;
        }
        .nav-link:hover { color: var(--primary-color); }
        .nav-link i { margin-right: 0.4rem; }

        /* --- Main Layout --- */
        .main-wrapper {
            max-width: var(--content-max-width); margin: 2rem auto; padding: 0 1rem;
            display: flex; gap: 2.5rem;
        }
        .form-container { flex: 1 1 60%; min-width: 300px; }
        .sidebar-container { flex: 1 1 35%; min-width: 280px; display: flex; flex-direction: column; gap: 1.5rem; }

        /* --- Form Card Styling --- */
        .form-card {
            width: 100%; padding: 2.5rem 3rem; background-color: var(--white-bg);
            backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
            border-radius: var(--border-radius); box-shadow: var(--box-shadow);
            border: 1px solid var(--card-border-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }

        /* --- Page Header --- */
        .page-header {
            display: flex; align-items: center; gap: 1rem; color: var(--primary-color); /* Use expense color */
            margin-bottom: 2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--card-border-color);
        }
        .page-header i { font-size: 2rem; opacity: 0.9; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; margin: 0; }
        .lottie-container { width: 60px; height: 60px; margin-right: 1rem; }

        /* --- Form Styling (Using original .expense-form class) --- */
        .expense-form { display: flex; flex-direction: column; gap: 1.5rem; } /* Increased gap */
        .form-group { position: relative; }

        /* --- Label Styling (Floating Label Logic) --- */
        /* Initial state - label inside */
        .form-group label:not(.static-label) { /* Exclude static labels */
            position: absolute; left: 1.1rem; top: 50%; /* Vertically centered */
            transform: translateY(-50%); font-weight: 500; color: var(--text-muted);
            background-color: transparent; padding: 0 0.3rem; transition: all 0.2s ease-out;
            pointer-events: none; font-size: 1rem; white-space: nowrap;
            display: flex; align-items: center; gap: 0.5rem;
        }
        /* Adjust label for amount input initially */
        label[for="expense_amount"] { left: 3.5rem; /* Approx width of currency symbol + padding */ }

        /* Floated state - label above */
        .form-control:focus + label:not(.static-label),
        .form-control:not(:placeholder-shown) + label:not(.static-label),
        .form-select:focus + label:not(.static-label),
        .form-select:not([value=""]):not(.placeholder-shown) + label:not(.static-label),
        /* Target label after input-group */
        .input-group:focus-within + label[for="expense_amount"],
        .input-group .form-control:not(:placeholder-shown) ~ label[for="expense_amount"] /* Needs adjustment */
        {
            top: 0; transform: translateY(-50%) scale(0.85);
            font-weight: 600; color: var(--primary-color); /* Use expense color */
            background-color: var(--white-bg); border-radius: 4px; z-index: 1;
            left: 1.1rem; /* Reset left position when floated */
            transition: all 0.2s ease-out, background-color 0.3s ease;
        }
        html[data-theme='dark'] .form-control:focus + label:not(.static-label),
        html[data-theme='dark'] .form-control:not(:placeholder-shown) + label:not(.static-label),
        html[data-theme='dark'] .form-select:focus + label:not(.static-label),
        html[data-theme='dark'] .form-select:not([value=""]):not(.placeholder-shown) + label:not(.static-label),
        html[data-theme='dark'] .input-group:focus-within + label[for="expense_amount"],
        html[data-theme='dark'] .input-group .form-control:not(:placeholder-shown) ~ label[for="expense_amount"]
        {
            background-color: var(--dark-card-bg); /* Adjust background for dark mode */
        }


        textarea.form-control:focus + label:not(.static-label),
        textarea.form-control:not(:placeholder-shown) + label:not(.static-label) {
             top: 1rem; /* Adjust for textarea padding */
             transform: translateY(-100%) scale(0.85);
        }

        /* --- Input Fields --- */
        .form-control, .form-select {
            width: 100%; padding: 1rem 1.2rem; /* Consistent padding */
            border: 1px solid var(--input-border-color); border-radius: var(--border-radius-input);
            font-size: 1rem; font-family: inherit;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out, background-color 0.3s ease;
            /* --- MODIFIED: Default background to white --- */
            background-color: var(--white-bg);
            color: var(--text-color);
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            box-shadow: var(--input-shadow); position: relative; z-index: 0;
        }
        html[data-theme='dark'] .form-control,
        html[data-theme='dark'] .form-select {
             background-color: var(--dark-input-bg); /* Dark mode background */
             color: var(--dark-text-color); /* Dark mode text */
        }

        .form-control::placeholder { color: var(--text-muted); opacity: 0.7; }


        .form-select {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
             background-repeat: no-repeat; background-position: right 1.1rem center;
             background-size: 16px 12px; padding-right: 3rem;
             color: var(--text-muted); /* Initial placeholder color */
        }
        .form-select:valid { /* Style when an option is selected */
             color: var(--text-color);
        }

        html[data-theme='dark'] .form-select {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23adb5bd' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
            color: var(--dark-text-muted); /* Dark mode placeholder color */
        }
        html[data-theme='dark'] .form-select:valid {
             color: var(--dark-text-color);
         }

        /* Interactive Field Focus */
        .form-control:focus, .form-select:focus {
            border-color: var(--input-focus-border); outline: 0;
            box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow); z-index: 2;
        }
        .form-control:focus-visible, .form-select:focus-visible { /* Glowing effect */
            outline: none;
            box-shadow: 0 0 0 3px var(--input-focus-shadow), 0 0 10px 2px var(--input-focus-shadow), var(--input-shadow);
            border-color: var(--input-focus-border);
        }
        textarea.form-control {
             min-height: 120px;
             resize: vertical;
             padding-top: 1.5rem;
             /* --- MODIFIED: Explicit white bg for textarea in light mode --- */
             background-color: var(--white-bg);
         }
        html[data-theme='dark'] textarea.form-control {
              background-color: var(--dark-input-bg); /* Restore dark bg for textarea */
        }


        /* --- Input Group (for Amount) --- */
        .input-group {
            position: relative; display: flex; align-items: stretch; width: 100%;
            box-shadow: var(--input-shadow); border-radius: var(--border-radius-input);
            overflow: hidden; border: 1px solid var(--input-border-color);
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
             /* --- MODIFIED: Default input group bg to white --- */
            background-color: var(--white-bg);
        }
         html[data-theme='dark'] .input-group {
             background-color: var(--dark-input-bg); /* Dark mode background */
         }

         .input-group:focus-within {
            border-color: var(--input-focus-border); box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow); z-index: 2;
         }
         .input-group:focus-within:has(:focus-visible) { /* Apply glow to group */
             outline: none;
             box-shadow: 0 0 0 3px var(--input-focus-shadow), 0 0 10px 2px var(--input-focus-shadow), var(--input-shadow);
             border-color: var(--input-focus-border);
         }
        .input-group-text {
            display: flex; align-items: center; padding: 1rem 1.2rem; font-size: 1rem; font-weight: 500;
            color: var(--text-muted); text-align: center; white-space: nowrap;
            background-color: rgba(248, 249, 250, 0.7); border: none; border-right: 1px solid var(--input-border-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        html[data-theme='dark'] .input-group-text {
            background-color: rgba(50, 50, 80, 0.6); border-right: 1px solid var(--dark-input-border);
             color: var(--dark-text-muted);
        }
        .input-group .form-control {
            position: relative; flex: 1 1 auto; width: 1%; min-width: 0; border-radius: 0;
            border: none; box-shadow: none; z-index: 0; background-color: transparent; /* Input inside group is transparent */
            /* Adjust padding slightly for input group */
            padding-left: 0.8rem;
        }
        .input-group:focus-within .form-control:focus, .input-group:focus-within .form-control:focus-visible {
            box-shadow: none; border-color: transparent; outline: none;
        }
        /* --- Date Input Icon --- */
        .date-input-container { /* Wrapper div for date input and icon */
            position: relative;
            width: 100%;
        }
        .date-icon {
            position: absolute;
            right: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none; /* Don't block input clicks */
            z-index: 3; /* Above input, potentially below floated label */
        }
        /* Make icon slightly darker on focus */
        .date-input-container .form-control:focus ~ .date-icon {
            color: var(--text-color);
        }
        /* Add padding to the date input itself */
        .date-input-container .form-control {
             padding-right: 3rem; /* Make space for the icon */
        }


        /* --- Validation States --- */
        /* Using original validation styling, adapted slightly */
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--error-color) !important;
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-repeat: no-repeat !important; background-position: right 1.1rem center !important; background-size: 1em 1em !important;
             padding-right: calc(1.5em + 1.2rem) !important; /* Adjust padding */
        }
         /* Special handling for date input with its own icon */
         .date-input-container .form-control.is-invalid {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             /* Position error icon to the left of the date icon */
             background-position: center right 3.2rem !important; /* Adjust position */
             padding-right: calc(1.5em + 3rem) !important; /* Increase padding */
         }
        /* Ensure select arrow doesn't overlap error icon */
         .form-select.is-invalid {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"), url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-position: right 1.1rem center, center right 3rem !important; /* Adjust second position */
             background-size: 16px 12px, 1em 1em !important;
             padding-right: 4.5rem !important; /* Increased padding for both icons */
         }
         html[data-theme='dark'] .form-select.is-invalid {
              background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23adb5bd' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"), url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
         }


        .input-group.is-invalid { border-color: var(--error-color) !important; }
        /* Error icon inside input-group */
        .input-group.is-invalid .form-control {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-repeat: no-repeat !important; background-position: right 0.8rem center !important; background-size: 1em 1em !important; /* Adjust position */
             padding-right: calc(1.5em + 0.8rem) !important; /* Adjust padding */
         }

        .form-control.is-invalid:focus, .form-select.is-invalid:focus, .input-group.is-invalid:focus-within {
            border-color: var(--error-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25), var(--input-shadow);
        }
        .input-group.is-invalid:focus-within:has(:focus-visible) {
             box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.25), 0 0 10px 2px rgba(220, 53, 69, 0.25), var(--input-shadow);
        }
        .error-message { color: var(--error-color); font-size: 0.85rem; margin-top: 0.5rem; font-weight: 500; }

        /* --- Buttons --- */
        .button-container { margin-top: 2.5rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
            padding: 0.8rem 1.8rem; border: none; border-radius: var(--border-radius); font-size: 0.95rem;
            font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.25s ease-in-out;
            white-space: nowrap; letter-spacing: 0.5px; text-transform: uppercase;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn:hover { transform: translateY(-2px) scale(1.02); box-shadow: 0 7px 18px rgba(0,0,0,0.15); }
        .btn:active { transform: translateY(0) scale(0.98); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .btn:disabled { opacity: 0.65; cursor: not-allowed; transform: none; box-shadow: 0 4px 10px rgba(0,0,0,0.1); background-image: none !important; background-color: var(--secondary-color) !important;}
        .btn .spinner {
            display: inline-block; width: 1em; height: 1em; border: 2px solid currentColor;
            border-right-color: transparent; border-radius: 50%; animation: spinner-border .75s linear infinite; margin-right: 0.5rem; /* Add margin */
        }
        @keyframes spinner-border { to { transform: rotate(360deg); } }
        .btn-primary { /* Uses expense theme */
            background-image: var(--primary-gradient); color: #fff; order: 2;
            background-size: 150% auto; transition: all 0.3s ease-in-out;
        }
        .btn-primary:hover:not(:disabled) { /* Don't apply hover effect when disabled */
            background-image: var(--primary-gradient-hover); background-position: right center;
            box-shadow: 0 8px 20px rgba(220, 53, 69, 0.35); /* Red shadow */
        }
        .btn-secondary {
            background-color: transparent; color: var(--secondary-color); border: 1px solid var(--card-border-color);
            order: 1; box-shadow: none; transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }
        .btn-secondary:hover:not(:disabled) { background-color: rgba(0,0,0,0.03); border-color: var(--secondary-color); transform: translateY(0); box-shadow: none; color: var(--secondary-hover); }
        html[data-theme='dark'] .btn-secondary { color: var(--dark-text-muted); border-color: var(--dark-border-color); }
        html[data-theme='dark'] .btn-secondary:hover:not(:disabled) { background-color: rgba(255,255,255,0.05); border-color: var(--dark-text-color); color: var(--dark-text-color); }
        .btn i { font-size: 1em; transition: transform 0.2s ease-out; }
        .btn:hover i:not(.spinner) { transform: rotate(-5deg) scale(1.1); }
        .btn .btn-icon { display: inline-flex; align-items: center; } /* Helper for icon replacement */

        /* --- Flash Messages & Errors --- */
        .message {
            padding: 1rem 1.25rem; margin-bottom: 1.5rem; border-radius: var(--border-radius-input);
            border: 1px solid transparent; display: flex; align-items: flex-start; gap: 0.8rem;
            font-size: 0.95rem; background-color: var(--white-bg);
            transition: opacity 0.5s ease-out, transform 0.5s ease-out; opacity: 1; transform: translateY(0);
        }
        .message.fade-out { opacity: 0; transform: translateY(-10px); }
        .message i { font-size: 1.3rem; margin-top: 0.15rem; flex-shrink: 0; }
        .message-content { flex-grow: 1; }
        .message-content strong { display: block; margin-bottom: 0.3rem; font-weight: 600; }
        .message-success { color: var(--success-color); background-color: var(--success-bg); border-color: var(--success-border); }
        .message-success i { color: var(--success-color); }
        html[data-theme='dark'] .message-success { color: #a3cfbb; /* Adjust text color for dark bg */ }
        .message-error { color: var(--error-color); background-color: var(--error-bg); border-color: var(--error-border); }
        .message-error i { color: var(--error-color); }
        html[data-theme='dark'] .message-error { color: #f1aeb5; /* Adjust text color for dark bg */ }
        .message-error ul { margin-top: 0.5rem; margin-bottom: 0; padding-left: 1.25rem; }
        .message-error li { margin-bottom: 0.3rem; }
        .database-error-message { margin-top: 0.5rem; font-weight: 500; }
        .db-connection-error-block { text-align: center; }
        .db-connection-error-block i { font-size: 2.5rem; color: var(--error-color); margin-bottom: 1rem; }
        .db-connection-error-block strong { display: block; font-size: 1.1rem; margin-bottom: 0.5rem; }
        .db-connection-error-block p { color: var(--text-muted); margin-bottom: 1.5rem; }

        /* --- Sidebar Widgets --- */
        .sidebar-widget {
            background-color: var(--white-bg); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px);
            border-radius: var(--border-radius); padding: 1.5rem; box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid var(--card-border-color); transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        .widget-title {
            font-size: 1.1rem; font-weight: 600; margin-bottom: 1rem; padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--card-border-color); display: flex; align-items: center;
            gap: 0.6rem; color: var(--primary-color); /* Use expense color */
        }
        .widget-title i { opacity: 0.8; }

        /* Monthly Summary */
        .monthly-summary .amount {
            font-size: 1.8rem; font-weight: 700; color: var(--error-color); /* Red for expense total */
            margin-bottom: 0.5rem; display: block;
        }
        .monthly-summary .label { font-size: 0.9rem; color: var(--text-muted); margin-bottom: 1rem; }
        /* Progress bar omitted for expenses unless a budget comparison is added */

        /* Recent Entries List */
        .recent-entries-list {
            list-style: none; padding: 0; margin: 0; max-height: 300px;
            overflow-y: auto; padding-right: 0.5rem;
        }
        .recent-entry {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.8rem 0.2rem; border-bottom: 1px dashed var(--card-border-color);
            transition: background-color 0.2s ease;
        }
        .recent-entry:last-child { border-bottom: none; }
        .recent-entry:hover { background-color: rgba(0,0,0,0.02); }
        html[data-theme='dark'] .recent-entry:hover { background-color: rgba(255,255,255,0.04); }
        .entry-details .category { font-weight: 500; color: var(--text-color); display: block; font-size: 0.95rem; }
        .entry-details .date { font-size: 0.8rem; color: var(--text-muted); }
        .entry-amount { font-weight: 600; color: var(--error-color); font-size: 1rem; } /* Red for expense amount */

        /* Pie Chart */
        .chart-container { position: relative; height: 280px; width: 100%; }

        /* --- Custom Scrollbar (Webkit) --- */
        .recent-entries-list::-webkit-scrollbar { width: 8px; }
        .recent-entries-list::-webkit-scrollbar-track { background: transparent; border-radius: 10px; }
        .recent-entries-list::-webkit-scrollbar-thumb {
            background-color: rgba(0, 0, 0, 0.2); border-radius: 10px;
            border: 2px solid transparent; background-clip: content-box;
        }
        .recent-entries-list::-webkit-scrollbar-thumb:hover { background-color: rgba(0, 0, 0, 0.4); }
        html[data-theme='dark'] .recent-entries-list::-webkit-scrollbar-thumb { background-color: rgba(255, 255, 255, 0.2); }
        html[data-theme='dark'] .recent-entries-list::-webkit-scrollbar-thumb:hover { background-color: rgba(255, 255, 255, 0.4); }

        /* --- Footer --- */
        .footer {
            text-align: center; margin: 3rem auto 1rem auto; padding: 1rem;
            font-size: 0.85rem; color: var(--text-muted); width: 100%; max-width: var(--content-max-width);
        }

        /* --- File Input Styling --- */
         .static-label { position: static; transform: none; font-size: 0.9rem; color: var(--text-muted); margin-bottom: 0.5rem; display: block; font-weight: 500;}
         .file-input { padding-top: 0.8rem; padding-bottom: 0.8rem;}
         /* Align file input text better */
         input[type="file"].form-control { line-height: 1.8; }


        /* --- Responsive Design --- */
        @media (max-width: 992px) {
            .main-wrapper { flex-direction: column; gap: 2rem; }
            .form-container, .sidebar-container { flex-basis: auto; width: 100%; }
            .navbar { padding: 0.8rem 1rem; }
        }
        @media (max-width: 768px) {
            body { padding-top: 70px; }
            .form-card { padding: 2rem 1.5rem; }
            .page-header h1 { font-size: 1.5rem; } .page-header i { font-size: 1.7rem; }
            .button-container { justify-content: center; }
            .recent-entries-list { max-height: 250px; }
            .chart-container { height: 250px; }
        }
        @media (max-width: 480px) {
            html { font-size: 15px; }
            .navbar { padding: 0.6rem 1rem; } .navbar-brand { font-size: 1.2rem; }
            .theme-toggle-btn { font-size: 1.3rem; } body { padding-top: 65px; }
            .form-card { padding: 1.5rem 1rem; }
            .page-header { margin-bottom: 1.5rem; gap: 0.8rem; }
            .page-header h1 { font-size: 1.35rem; } .page-header i { font-size: 1.5rem; }
            .form-control, .form-select, .input-group-text { padding: 0.9rem 1rem; } /* Ensure good tap size */
            /* Keep label adjustments for smaller screens */
            .form-group label:not(.static-label) { left: 1rem; }
            label[for="expense_amount"] { left: 3.2rem; /* Adjust for smaller padding */ }
            .form-control:focus + label:not(.static-label),
            .form-control:not(:placeholder-shown) + label:not(.static-label),
            .form-select:focus + label:not(.static-label),
            .form-select:not([value=""]):not(.placeholder-shown) + label:not(.static-label),
            .input-group:focus-within + label[for="expense_amount"]
             { left: 1rem; }

            .btn { padding: 0.9rem 1.5rem; font-size: 0.9rem; width: 100%; }
            .button-container { flex-direction: column-reverse; align-items: stretch; gap: 0.8rem; }
            .footer { margin-top: 2rem; } .main-wrapper { margin-top: 1rem; }
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="navbar-brand">
             <i class="fa-solid fa-credit-card"></i> Finance Tracker <!-- Changed Icon -->
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

    <!-- Main Content Area -->
    <div class="main-wrapper">

        <!-- Form Section -->
        <div class="form-container">
            <div class="form-card">
                <header class="page-header">
                     <!-- Optional Lottie Animation Placeholder -->
                     <div class="lottie-container">
                          <!-- Example: Receipt animation -->
                         <lottie-player src="https://assets10.lottiefiles.com/packages/lf20_rIg0v5.json" background="transparent" speed="1" style="width: 100%; height: 100%;" loop autoplay></lottie-player>
                     </div>
                    <h1>Add New Expense</h1>
                </header>

                <!-- Flash Messages & Errors -->
                <?php if (!empty($flashMessage)): ?>
                    <div class="message message-<?php echo $flashType; ?> <?php echo ($flashType == 'success') ? 'js-flash-success' : ''; ?>" role="alert">
                        <i class="fa-solid <?php echo ($flashType == 'success') ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                        <div class="message-content">
                            <strong><?php echo ($flashType == 'success') ? 'Success!' : 'Notice:'; ?></strong>
                            <p><?php echo htmlspecialchars($flashMessage); ?></p>
                            <?php if ($flashType == 'error' && !empty($errors)): // Show all errors if flash type is error ?>
                                <?php if (!isset($errors['database_connection']) && !isset($errors['database_insert'])): ?>
                                    <ul>
                                        <?php foreach ($errors as $field => $error): ?>
                                            <li><?php echo htmlspecialchars($error); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php elseif (isset($errors['database_insert'])): ?>
                                     <p class="database-error-message"><?php echo htmlspecialchars($errors['database_insert']); ?></p>
                                <?php elseif (isset($errors['database_connection'])): ?>
                                     <p class="database-error-message"><?php echo htmlspecialchars($errors['database_connection']); ?></p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($dbConnectionError) && $_SERVER['REQUEST_METHOD'] !== 'POST'): // Show initial DB error only if not a POST request with other errors ?>
                     <div class="message message-error db-connection-error-block">
                          <i class="fa-solid fa-database"></i>
                          <div class="message-content">
                              <strong>Database Connection Failed</strong>
                              <p><?php echo htmlspecialchars($dbConnectionError); ?></p>
                              <p>Unable to load or save expense data. Please check settings or contact support.</p>
                          </div>
                     </div>
                <?php endif; ?>

                <!-- Expense Form -->
                <?php if (empty($dbConnectionError) || !empty($errors)): // Show form if DB was initially OK OR if there are POST errors (even if DB failed initially) ?>
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="expense-form" id="expense-form" novalidate enctype="multipart/form-data">
                        <div class="form-group">
                           <div class="date-input-container"> <!-- Wrap input and icon -->
                                <input type="date" id="expense_date" name="expense_date" class="form-control <?php echo hasError('expense_date') ? 'is-invalid' : ''; ?>"
                                       value="<?php echo getValue('expense_date', date('Y-m-d')); ?>" required aria-describedby="date-error" placeholder=" ">
                                 <span class="date-icon"><i class="fa-solid fa-calendar-days"></i></span>
                            </div>
                             <label for="expense_date" title="The date the expense occurred"><i class="fa-regular fa-calendar-days"></i> Date</label>
                            <?php echo displayError('expense_date'); ?>
                        </div>


                        <div class="form-group">
                             <div class="input-group <?php echo hasError('expense_amount') ? 'is-invalid' : ''; ?>">
                                <span class="input-group-text"><?php echo htmlspecialchars($currencySymbol); ?></span>
                                <input type="number" id="expense_amount" name="expense_amount" step="0.01" min="0.01" placeholder=" "
                                       class="form-control"
                                       value="<?php echo getValue('expense_amount'); ?>" required aria-describedby="amount-error">
                                 <!-- placeholder=" " is used for the floating label CSS trick -->
                             </div>
                             <label for="expense_amount" title="Enter the cost of the expense"> Amount</label>
                             <?php echo displayError('expense_amount'); ?>
                        </div>

                        <div class="form-group">
                            <select id="expense_category" name="expense_category" class="form-select <?php echo hasError('expense_category') ? 'is-invalid' : ''; ?>" required aria-describedby="category-error">
                                <option value="" disabled <?php echo (getValue('expense_category') == '') ? 'selected' : ''; ?> class="placeholder-shown"></option>
                                <?php foreach ($expenseCategoriesList as $category):
                                    $selected = (getValue('expense_category') === $category) ? 'selected' : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="expense_category" title="Choose the category that best describes this expense"><i class="fa-solid fa-tags"></i> Category</label>
                            <?php echo displayError('expense_category'); ?>
                        </div>

                        <div class="form-group">
                            <select id="payment_method" name="payment_method" class="form-select <?php echo hasError('payment_method') ? 'is-invalid' : ''; ?>" required aria-describedby="method-error">
                                 <option value="" disabled <?php echo (getValue('payment_method') == '') ? 'selected' : ''; ?> class="placeholder-shown"></option>
                                 <?php foreach ($paymentMethods as $method):
                                     $selected = (getValue('payment_method') === $method) ? 'selected' : '';
                                 ?>
                                    <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($method); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="payment_method" title="How did you pay for this expense?"><i class="fa-solid fa-credit-card"></i> Payment Method</label>
                            <?php echo displayError('payment_method'); ?>
                        </div>

                         <div class="form-group">
                            <textarea id="expense_description" name="expense_description" rows="3" placeholder=" "
                                      class="form-control <?php echo hasError('expense_description') ? 'is-invalid' : ''; ?>" aria-describedby="description-error"><?php echo getValue('expense_description'); ?></textarea>
                             <!-- The placeholder=" " is used for the floating label CSS trick -->
                             <!-- Use a real placeholder visually via JS or CSS if needed -->
                             <label for="expense_description" title="Add any relevant notes or details (Optional)"><i class="fa-regular fa-pen-to-square"></i> Notes / Description</label>
                            <?php echo displayError('expense_description'); ?>
                        </div>

                        <!-- Receipt Upload Field -->
                        <div class="form-group">
                             <label for="receipt_upload" class="static-label"><i class="fa-solid fa-receipt"></i> Upload Receipt (Optional)</label>
                            <input type="file" id="receipt_upload" name="receipt_upload" class="form-control file-input" accept="image/*,.pdf">
                             <!-- File upload processing requires PHP backend changes -->
                        </div>
                        <!-- End Receipt Upload Field -->


                        <div class="button-container">
                             <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-secondary">
                                 <i class="fa-solid fa-xmark"></i> Cancel
                             </a>
                             <button type="submit" class="btn btn-primary" id="submit-button" <?php if($dbConnectionError && empty($errors)) echo 'disabled'; /* Disable submit if initial DB error and no other errors */ ?> >
                                 <span class="btn-icon"><i class="fa-solid fa-plus-circle"></i></span>
                                 <span class="btn-text">Add Expense</span>
                             </button>
                        </div>

                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Section -->
        <aside class="sidebar-container">
            <!-- Monthly Expense Summary -->
            <div class="sidebar-widget monthly-summary">
                <h3 class="widget-title"><i class="fa-solid fa-calendar-day"></i> This Month's Expenses</h3>
                <span class="amount"><?php echo formatCurrency($monthlyTotalExpense, $currencySymbol); ?></span>
                <div class="label">Total expenses recorded this month</div>
                 <!-- Progress bar could be added here comparing to a budget -->
            </div>

            <!-- Expense Category Pie Chart -->
            <div class="sidebar-widget">
                 <h3 class="widget-title"><i class="fa-solid fa-chart-pie"></i> Expense Categories</h3>
                 <div class="chart-container">
                     <?php if (!empty($categoryDataForChart['labels']) && !$dbConnectionError): // Only show chart if data and DB OK ?>
                         <canvas id="expenseCategoryChart"></canvas>
                     <?php else: ?>
                         <p style="text-align: center; color: var(--text-muted); padding-top: 2rem;">
                            <?php echo $dbConnectionError ? 'Cannot load chart data (DB Error).' : 'No expense data yet to display chart.'; ?>
                         </p>
                     <?php endif; ?>
                 </div>
            </div>

            <!-- Recent Expense List -->
            <div class="sidebar-widget">
                <h3 class="widget-title"><i class="fa-solid fa-list-ul"></i> Recent Expenses</h3>
                <?php if (!empty($recentExpenses) && !$dbConnectionError): // Only show list if data and DB OK ?>
                    <ul class="recent-entries-list">
                        <?php foreach ($recentExpenses as $entry): ?>
                            <li class="recent-entry">
                                <div class="entry-details">
                                    <span class="category"><?php echo htmlspecialchars($entry['expense_category']); ?></span>
                                    <span class="date"><?php echo date("M d, Y", strtotime($entry['expense_date'])); ?></span>
                                </div>
                                <span class="entry-amount"><?php echo formatCurrency($entry['expense_amount'], $currencySymbol); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                     <p style="text-align: center; color: var(--text-muted); padding-top: 1rem;">
                        <?php echo $dbConnectionError ? 'Cannot load recent expenses (DB Error).' : 'No recent expense entries found.'; ?>
                     </p>
                <?php endif; ?>
            </div>
        </aside>

    </div>

     <footer class="footer">
         © <?php echo date('Y'); ?> Finance Tracker App. All rights reserved.
     </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {

            // Function to handle floating label logic
            const handleLabelFloat = (inputElement) => {
                const group = inputElement.closest('.form-group');
                if (!group) return;

                const label = group.querySelector('label:not(.static-label)');
                if (!label) return; // No label found or it's static

                 // Specific handling for input inside an input-group
                 let checkInput = inputElement;
                 if (inputElement.closest('.input-group')) {
                     checkInput = inputElement.closest('.input-group').querySelector('.form-control');
                     // Find the label associated with this input group's input
                     // This assumes label follows the input-group div
                     const potentialLabel = inputElement.closest('.input-group').nextElementSibling;
                     if (potentialLabel && potentialLabel.tagName === 'LABEL' && potentialLabel.htmlFor === checkInput.id) {
                        // We found the correct label for the input group
                     } else {
                         // If label isn't immediately after, this logic might fail. Adjust as needed.
                         return; // Cannot reliably find label for input group
                     }
                 }


                 const shouldFloat = (checkInput.value && checkInput.value !== "" && checkInput.value !== null && checkInput.type !== 'file') || // Has value (and not file input)
                                   (checkInput.type === 'date' && checkInput.value) || // Is date input with value
                                   (checkInput.tagName === 'SELECT' && checkInput.value !== "") || // Is select with value
                                   (document.activeElement === checkInput) || // Is focused
                                   (checkInput.placeholder && checkInput.placeholder !== " " && checkInput.value); // Has a real placeholder shown (less common with this pattern)


                  // Get correct background color based on theme
                  const isDark = document.documentElement.hasAttribute('data-theme');
                  const labelBgColor = getComputedStyle(document.documentElement).getPropertyValue(isDark ? '--dark-card-bg' : '--white-bg').trim();


                 if (shouldFloat) {
                     label.style.top = '0';
                     label.style.transform = 'translateY(-50%) scale(0.85)';
                     label.style.fontWeight = '600';
                     label.style.color = 'var(--primary-color)'; // Use CSS variable
                     label.style.backgroundColor = labelBgColor; // Apply correct bg
                     label.style.zIndex = '1';
                     label.style.left = '1.1rem'; // Reset left for floated state
                     label.style.borderRadius = '4px'; // Ensure radius is applied
                     label.style.padding = '0 0.3rem'; // Ensure padding is applied

                     if (checkInput.tagName === 'TEXTAREA') {
                         label.style.top = '1rem'; // Adjusted for textarea
                         label.style.transform = 'translateY(-100%) scale(0.85)';
                     }
                 } else {
                     // Reset styles if not floating
                     label.style.top = '50%';
                     label.style.transform = 'translateY(-50%)';
                     label.style.fontWeight = '500';
                     label.style.color = 'var(--text-muted)';
                     label.style.backgroundColor = 'transparent';
                     label.style.zIndex = '0';
                     label.style.borderRadius = '0'; // Remove radius
                     label.style.padding = '0 0.3rem';
                     // Reset specific left position for amount label
                     if (label.htmlFor === 'expense_amount') {
                        label.style.left = '3.5rem'; // Initial position for amount
                         // Adjust for small screens if needed
                         if (window.matchMedia("(max-width: 480px)").matches) {
                             label.style.left = '3.2rem';
                         }
                     } else {
                        label.style.left = '1.1rem'; // Default initial position
                         // Adjust for small screens if needed
                         if (window.matchMedia("(max-width: 480px)").matches) {
                             label.style.left = '1rem';
                         }
                     }
                 }
            };


            // --- Select Placeholder Styling ---
            const selects = document.querySelectorAll('.form-select');
            selects.forEach(select => {
                const updateSelectPlaceholder = () => {
                     const isDark = document.documentElement.hasAttribute('data-theme');
                    if (select.value === "" || (select.options[select.selectedIndex] && select.options[select.selectedIndex].disabled)) {
                        select.classList.add('placeholder-shown');
                        select.style.color = isDark ? 'var(--dark-text-muted)' : 'var(--text-muted)'; // Use muted color for placeholder state
                    } else {
                        select.classList.remove('placeholder-shown');
                        select.style.color = isDark ? 'var(--dark-text-color)' : 'var(--text-color)'; // Use normal text color
                    }
                    handleLabelFloat(select); // Update label on change
                };
                updateSelectPlaceholder(); // Initial check
                select.addEventListener('change', updateSelectPlaceholder);
                 select.addEventListener('focus', () => handleLabelFloat(select));
                 select.addEventListener('blur', () => {
                     handleLabelFloat(select);
                     updateSelectPlaceholder(); // Also update color on blur
                 });
            });

            // --- Float Label Initial State & Event Listeners ---
             const inputsAndSelects = document.querySelectorAll('.form-control, .form-select');
             inputsAndSelects.forEach(element => {
                  // Ensure placeholder=" " is set for inputs/textareas if not using JS to force float
                  if (element.tagName !== 'SELECT' && !element.hasAttribute('placeholder')) {
                       element.setAttribute('placeholder', ' ');
                  }

                 handleLabelFloat(element); // Check initial state on load

                 element.addEventListener('focus', () => handleLabelFloat(element));
                 element.addEventListener('blur', () => handleLabelFloat(element));
                 element.addEventListener('input', () => handleLabelFloat(element)); // Handle typing/pasting

                 // For date inputs, the 'input' event might not cover all changes
                 if (element.type === 'date') {
                     element.addEventListener('change', () => handleLabelFloat(element));
                 }
             });

             // Re-check background color for labels on theme change
             const applyLabelBackground = () => {
                 document.querySelectorAll('.form-group label:not(.static-label)').forEach(label => {
                      // Check if label is currently floated (approximated by top style)
                      if (label.style.transform.includes('scale(0.85)')) { // Check if floated
                           const isDark = document.documentElement.hasAttribute('data-theme');
                           label.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue(isDark ? '--dark-card-bg' : '--white-bg').trim();
                      }
                 });
             };


            // --- Success Message Fade Out ---
            const successFlash = document.querySelector('.js-flash-success');
            if (successFlash) {
                setTimeout(() => {
                    successFlash.classList.add('fade-out');
                    // Use 'transitionend' event for smoother removal after fade
                    successFlash.addEventListener('transitionend', () => {
                         if(successFlash.parentNode) { successFlash.parentNode.removeChild(successFlash); }
                    }, { once: true }); // Ensure listener runs only once
                }, 5000); // Start fade after 5 seconds
            }

            // --- Form Loading State ---
            const expenseForm = document.getElementById('expense-form');
            const submitButton = document.getElementById('submit-button');
            const buttonText = submitButton ? submitButton.querySelector('.btn-text') : null;
            const buttonIconSpan = submitButton ? submitButton.querySelector('.btn-icon') : null; // Target the span
            const originalIconHTML = buttonIconSpan ? buttonIconSpan.innerHTML : '<i class="fa-solid fa-plus-circle"></i>'; // Store original HTML

            if (expenseForm && submitButton && buttonText && buttonIconSpan) {
                expenseForm.addEventListener('submit', (e) => {
                    // Optional: Add basic client-side validation check here if needed
                    // if (!expenseForm.checkValidity()) {
                    //     e.preventDefault(); // Prevent submission if HTML5 validation fails
                    //     // Optionally trigger custom validation feedback
                    //     return;
                    // }

                    submitButton.disabled = true;
                    buttonText.textContent = 'Saving...';
                    buttonIconSpan.innerHTML = '<span class="spinner"></span>'; // Replace content with spinner span
                });
            }

             // --- Dark Mode Toggle ---
             const themeToggle = document.getElementById('theme-toggle');
             const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null;
             const sunIconClass = 'fa-solid fa-sun'; // Store class names
             const moonIconClass = 'fa-solid fa-moon';

             const setMode = (isDark) => {
                 const iconElement = themeToggle.querySelector('i');
                 if (isDark) {
                     document.documentElement.setAttribute('data-theme', 'dark');
                     localStorage.setItem('theme', 'dark');
                     iconElement.className = sunIconClass; // Set class
                     themeToggle.title = 'Switch to light theme';
                 } else {
                     document.documentElement.removeAttribute('data-theme');
                     localStorage.setItem('theme', 'light');
                     iconElement.className = moonIconClass; // Set class
                     themeToggle.title = 'Switch to dark theme';
                 }
                 // Re-render chart if it exists and update label backgrounds
                 if (typeof renderExpenseChart === 'function') { // Ensure function exists
                    if (window.expenseChartInstance) {
                        window.expenseChartInstance.destroy();
                    }
                     renderExpenseChart(); // Use expense chart function
                 }
                 applyLabelBackground(); // Update label backgrounds on theme change
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

            // --- Expense Pie Chart Rendering ---
            // Declare chart instance globally in the scope
            window.expenseChartInstance = null;
            const renderExpenseChart = () => {
                 const ctx = document.getElementById('expenseCategoryChart');
                 if (!ctx) return;

                 // Destroy existing chart if it exists
                 if (window.expenseChartInstance) {
                     window.expenseChartInstance.destroy();
                     window.expenseChartInstance = null;
                 }

                 const categoryDataPHP = <?php echo json_encode($categoryDataForChart); ?>;
                 // Check if data is valid and not empty
                 if (!categoryDataPHP || !categoryDataPHP.labels || categoryDataPHP.labels.length === 0 || <?php echo json_encode(!empty($dbConnectionError)); ?>) {
                     // Clear canvas or show message if no data / DB error
                     ctx.getContext('2d').clearRect(0, 0, ctx.width, ctx.height);
                     // Optionally display a message directly on the canvas or rely on the PHP fallback message
                     return;
                 }


                 const isDarkMode = document.documentElement.hasAttribute('data-theme');
                 const labelColor = isDarkMode ? 'var(--dark-text-color)' : 'var(--text-color)'; // Use CSS variables
                 const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
                 // Expense chart colors
                 const expenseChartColors = [
                     '#dc3545', '#fd7e14', '#ffc107', '#0dcaf0', '#6f42c1',
                     '#20c997', '#d63384', '#6c757d', '#adb5bd', '#343a40'
                 ];
                 // Generate background colors with appropriate alpha based on mode
                 const backgroundColors = categoryDataPHP.labels.map((_, i) => expenseChartColors[i % expenseChartColors.length] + (isDarkMode ? 'B3' : 'CC')); // ~70% or 80% opacity
                 const borderColors = categoryDataPHP.labels.map((_, i) => expenseChartColors[i % expenseChartColors.length]);

                 // Get currency symbol/code from PHP
                 const currencyCode = '<?php echo $currencySymbol === '₹' ? 'INR' : ($currencySymbol === '$' ? 'USD' : 'USD'); ?>'; // Determine code

                 window.expenseChartInstance = new Chart(ctx, {
                     type: 'pie',
                     data: {
                         labels: categoryDataPHP.labels,
                         datasets: [{
                             label: 'Expense by Category',
                             data: categoryDataPHP.totals,
                             backgroundColor: backgroundColors,
                             borderColor: borderColors,
                             borderWidth: 1,
                             hoverOffset: 8
                         }]
                     },
                     options: {
                         responsive: true,
                         maintainAspectRatio: false,
                         plugins: {
                             legend: {
                                 position: 'bottom',
                                 labels: {
                                    color: labelColor, // Use dynamic color
                                    padding: 15,
                                    font: { size: 12, family: "'Poppins', sans-serif" }
                                }
                             },
                             tooltip: {
                                 callbacks: {
                                     label: function(context) {
                                         let label = context.label || '';
                                         if (label) { label += ': '; }
                                         if (context.parsed !== null) {
                                             // Format currency using Intl.NumberFormat
                                             try {
                                                 label += new Intl.NumberFormat(document.documentElement.lang || 'en-IN', { // Default to en-IN for Rupee
                                                     style: 'currency',
                                                     currency: currencyCode, // Use determined code
                                                     minimumFractionDigits: 2,
                                                     maximumFractionDigits: 2
                                                 }).format(context.parsed);
                                             } catch (e) {
                                                  console.error("Currency formatting error:", e);
                                                  // Fallback formatting
                                                  label += '<?php echo htmlspecialchars($currencySymbol, ENT_QUOTES, 'UTF-8'); ?>' + parseFloat(context.parsed).toFixed(2);
                                             }
                                         }
                                         return label;
                                     }
                                 },
                                 backgroundColor: isDarkMode ? 'rgba(40, 40, 60, 0.9)' : 'rgba(0, 0, 0, 0.8)',
                                 titleFont: { family: "'Poppins', sans-serif", size: 14 },
                                 bodyFont: { family: "'Poppins', sans-serif", size: 12 },
                                 padding: 10,
                                 cornerRadius: 4,
                                 titleColor: isDarkMode ? '#ffffff' : '#ffffff', // Tooltip title color
                                 bodyColor: isDarkMode ? '#ffffff' : '#ffffff',  // Tooltip body color
                             }
                         }
                     }
                 });
             };

             // Initial chart render
             renderExpenseChart();

        }); // End DOMContentLoaded
    </script>

</body>
</html>