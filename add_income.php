<?php
session_start();

// --- Configuration ---
$currencySymbol = '₹'; // Changed to Rupee symbol as per placeholder example
$incomeCategoriesList = ['Salary', 'Freelance', 'Investment', 'Bonus', 'Refund', 'Interest', 'Gift', 'Other'];
$receivedMethods = ['Direct Deposit', 'Bank Transfer', 'UPI', 'PayPal', 'Cash', 'Check', 'Other'];
$userName = $_SESSION['username'] ?? "User";
$dashboardUrl = "dashboard.php"; // URL for the back/dashboard button

// --- Database Connection ---
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "login";
$conn = null;
$dbConnectionError = null;
$recentIncomes = [];
$categoryDataForChart = [];
$monthlyTotal = 0;

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Set default fetch mode

    // Ensure table exists (Your existing code - REMOVED tags column)
    $conn->exec("CREATE TABLE IF NOT EXISTS incomes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        income_date DATE NOT NULL,
        income_category VARCHAR(255) NOT NULL,
        income_amount DECIMAL(12, 2) NOT NULL,
        received_method VARCHAR(255) NULL,
        income_description TEXT NULL,
        receipt_path VARCHAR(255) NULL -- Added for Receipt Upload feature (requires PHP processing change)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // --- Fetch Data for Sidebar/Analytics ---
    // Fetch Recent Incomes (Last 5)
    $stmtRecent = $conn->query("SELECT id, income_date, income_category, income_amount FROM incomes ORDER BY income_date DESC, id DESC LIMIT 5");
    $recentIncomes = $stmtRecent->fetchAll();

    // Fetch Data for Pie Chart (Category Totals)
    $stmtChart = $conn->query("SELECT income_category, SUM(income_amount) as total FROM incomes GROUP BY income_category ORDER BY total DESC");
    $categoryDataRaw = $stmtChart->fetchAll();
    // Prepare for Chart.js
    $categoryLabels = [];
    $categoryTotals = [];
    foreach ($categoryDataRaw as $row) {
        $categoryLabels[] = $row['income_category'];
        $categoryTotals[] = $row['total'];
    }
    $categoryDataForChart = [
        'labels' => $categoryLabels,
        'totals' => $categoryTotals
    ];

    // Fetch Monthly Income Summary
    $stmtMonthly = $conn->prepare("SELECT SUM(income_amount) as monthly_total FROM incomes WHERE MONTH(income_date) = MONTH(CURDATE()) AND YEAR(income_date) = YEAR(CURDATE())");
    $stmtMonthly->execute();
    $resultMonthly = $stmtMonthly->fetch();
    $monthlyTotal = $resultMonthly['monthly_total'] ?? 0;


} catch(PDOException $e) {
    $dbConnectionError = "Database connection failed: " . $e->getMessage();
    error_log("Add Income DB Connection/Query Error: " . $e->getMessage());
    // Don't wipe out existing errors if form submission also failed
    $errors['database_connection'] = $dbConnectionError;
}


// --- Form Processing ---
$errors = $errors ?? []; // Initialize if not set by DB error
$submittedData = $_POST;
$flashMessage = '';
$flashType = 'success'; // Can be 'success' or 'error'

if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    $flashType = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dbConnectionError) { // Only process if DB connection is OK

    // Sanitize and Validate Inputs
    $income_date = filter_input(INPUT_POST, 'income_date', FILTER_SANITIZE_SPECIAL_CHARS);
    $income_amount = filter_input(INPUT_POST, 'income_amount', FILTER_VALIDATE_FLOAT);
    $income_category = filter_input(INPUT_POST, 'income_category', FILTER_SANITIZE_SPECIAL_CHARS);
    $received_method = filter_input(INPUT_POST, 'received_method', FILTER_SANITIZE_SPECIAL_CHARS);
    $income_description = filter_input(INPUT_POST, 'income_description', FILTER_SANITIZE_SPECIAL_CHARS, FILTER_FLAG_NO_ENCODE_QUOTES);
    $income_description = trim($income_description);
    // REMOVED tags sanitization
    // File upload requires different handling in $_FILES - NOT IMPLEMENTED

    // Validation Logic (Your existing code + minor tweaks)
    if (empty($income_date)) {
        $errors['income_date'] = "Date is required.";
    } else {
        $d = DateTime::createFromFormat('Y-m-d', $income_date);
        if (!$d || $d->format('Y-m-d') !== $income_date) {
            $errors['income_date'] = "Invalid date format. Please use YYYY-MM-DD.";
        }
    }

    if ($income_amount === false || $income_amount === null) {
        $errors['income_amount'] = "Invalid amount. Please enter a valid number.";
    } elseif ($income_amount <= 0) {
        $errors['income_amount'] = "Amount must be positive.";
    }

    if (empty($income_category)) {
        $errors['income_category'] = "Category is required.";
    }

    // --- Database Insertion (If no validation errors) ---
    if (empty($errors) && $conn) {
        try {
            $conn->beginTransaction();

            // !! IMPORTANT: Modify SQL and binding if implementing Receipts !!
            $sql = "INSERT INTO incomes (income_date, income_category, income_amount, received_method, income_description)
                    VALUES (:income_date, :income_category, :income_amount, :received_method, :income_description)";
            // To save receipt path, add column to SQL and bind them below

            $stmt = $conn->prepare($sql);

            $stmt->bindParam(':income_date', $income_date);
            $stmt->bindParam(':income_category', $income_category);
            $stmt->bindParam(':income_amount', $income_amount);

            $received_method_param = !empty($received_method) ? $received_method : null;
            $stmt->bindParam(':received_method', $received_method_param, $received_method_param === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

            $income_description_param = !empty($income_description) ? $income_description : null;
            $stmt->bindParam(':income_description', $income_description_param, $income_description_param === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

            // REMOVED commented-out binding for tags

            // Example: Binding receipt path if implemented (requires adding :receipt_path to SQL and upload handling)
            // $receipt_path_param = ... // Get path after successful upload
            // $stmt->bindParam(':receipt_path', $receipt_path_param, ...);

            $stmt->execute();
            $conn->commit();

            $_SESSION['flash_message'] = "Income of " . htmlspecialchars($currencySymbol) . number_format($income_amount, 2) . " from '" . htmlspecialchars($income_category) . "' added successfully!";
            $_SESSION['flash_type'] = 'success';

            // Clear submitted data after success
            $submittedData = [];

            // Redirect to clear POST data and show flash message
            header("Location: " . htmlspecialchars($_SERVER['PHP_SELF']));
            exit();

        } catch(PDOException $e) {
            $conn->rollBack();
            $errors['database_insert'] = "Database error saving income: " . $e->getMessage(); // More specific error
            error_log("Income Insert Error [SQLSTATE " . $e->getCode() . "]: " . $e->getMessage());
            $flashMessage = "Failed to add income due to a database error.";
            $flashType = 'error';
        }
    } elseif (!$conn && empty($errors)) {
        // This case should be covered by $dbConnectionError check earlier
        $errors['database_connection'] = "Cannot add income: Database connection is not available.";
        $flashMessage = "Cannot add income: Database connection is not available.";
        $flashType = 'error';
    } else if (!empty($errors)) {
        $flashMessage = "Please fix the errors in the form below.";
        $flashType = 'error';
    }
}

// Close connection if it was opened
if ($conn) {
    $conn = null;
}

// --- Helper Functions ---
function getValue(string $fieldName, $default = '') {
    global $submittedData;
    // Use submitted data first, otherwise default
    $value = $submittedData[$fieldName] ?? $default;
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
    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format(floatval($amount), 2);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Income - Finance Tracker</title>
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
            --primary-color: #198754;
            --primary-hover: #157347;
            --primary-gradient: linear-gradient(135deg, #20c997, #198754);
            --primary-gradient-hover: linear-gradient(135deg, #198754, #105c3a);
            --secondary-color: #6c757d;
            --secondary-hover: #5a6268;
            --success-color: #198754;
            --success-bg: #d1e7dd;
            --success-border: #a3cfbb;
            --error-color: #dc3545;
            --error-bg: #f8d7da;
            --error-border: #f1aeb5;
            --light-bg: #f4f7f9;
            --white-bg: #ffffff; /* MODIFICATION: Solid white background */
            --card-border-color: rgba(0, 0, 0, 0.08); /* Subtle border */
            --input-border-color: #ced4da;
            --input-focus-border: var(--primary-color);
            --input-focus-shadow: rgba(25, 135, 84, 0.3); /* Slightly stronger focus */
            --text-color: #212529;
            --text-muted: #6c757d;
            --font-family: 'Poppins', sans-serif;
            --border-radius: 1rem; /* Bigger radius */
            --border-radius-input: 0.6rem; /* Bigger radius */
            --box-shadow: 0 12px 35px rgba(40, 40, 90, 0.12); /* Smoother shadow */
            --input-shadow: 0 2px 5px rgba(0,0,0,0.05);
            --background-gradient: linear-gradient(160deg, #e9fcf7 0%, #f4f7f9 100%); /* Soft background gradient */
            --content-max-width: 1200px; /* Max width for overall layout */

            /* Dark Mode Variables */
            --dark-bg: #1a1a2e;
            --dark-card-bg: rgba(26, 26, 46, 0.85);
            --dark-text-color: #e4e6eb;
            --dark-text-muted: #adb5bd;
            --dark-border-color: rgba(255, 255, 255, 0.15);
            --dark-input-border: #4f4f6f;
            --dark-input-bg: rgba(40, 40, 60, 0.5); /* Keep dark bg variable */
            --dark-focus-shadow: rgba(50, 200, 150, 0.3);
            --dark-gradient: linear-gradient(160deg, #16213e 0%, #1a1a2e 100%);
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
        }

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
        /* ... (navbar styles remain unchanged) ... */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 2rem;
            background-color: var(--white-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-bottom: 1px solid var(--card-border-color);
            z-index: 1000;
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        .navbar-brand {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--primary-color);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .navbar-brand i { font-size: 1.2em; }
        .navbar-controls { display: flex; align-items: center; gap: 1rem; }
        .theme-toggle-btn {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.4rem;
            cursor: pointer;
            padding: 0.3rem;
            transition: color 0.3s ease;
        }
        .theme-toggle-btn:hover { color: var(--primary-color); }
        .nav-link {
            color: var(--text-muted);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        .nav-link:hover { color: var(--primary-color); }
        .nav-link i { margin-right: 0.4rem; }


        /* --- Main Layout --- */
        /* ... (main layout styles remain unchanged) ... */
        .main-wrapper {
            max-width: var(--content-max-width);
            margin: 2rem auto;
            padding: 0 1rem; /* Add horizontal padding */
            display: flex;
            gap: 2.5rem; /* Spacing between form and sidebar */
        }
        .form-container {
            flex: 1 1 60%; /* Takes up more space initially */
            min-width: 300px; /* Ensure form doesn't get too squished */
        }
        .sidebar-container {
            flex: 1 1 35%; /* Takes up less space */
            min-width: 280px;
            display: flex;
            flex-direction: column;
            gap: 1.5rem; /* Space between sidebar items */
        }


        /* --- Form Card Styling --- */
        /* ... (form card styles remain unchanged) ... */
        .form-card { /* Renamed from .container */
            width: 100%;
            padding: 2.5rem 3rem;
            background-color: var(--white-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: var(--border-radius); /* Applied new radius */
            box-shadow: var(--box-shadow); /* Applied new shadow */
            border: 1px solid var(--card-border-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }


        /* --- Page Header --- */
        /* ... (page header styles remain unchanged) ... */
        .page-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--primary-color);
            margin-bottom: 2rem; /* Consistent spacing */
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--card-border-color);
        }
        .page-header i { font-size: 2rem; opacity: 0.9; }
        .page-header h1 { font-size: 1.75rem; font-weight: 700; margin: 0; }
        .lottie-container { /* For optional Lottie animation */
             width: 60px;
             height: 60px;
             margin-right: 1rem; /* Space between Lottie and Title */
        }


        /* --- Form Styling --- */
        .income-form { display: flex; flex-direction: column; gap: 1.5rem; } /* Increased gap */
        .form-group { position: relative; }
        .form-group label:not(.static-label) {
            position: absolute;
            left: 1.1rem;
            top: 50%;
            transform: translateY(-50%);
            font-weight: 500;
            color: var(--text-muted);
            background-color: transparent;
            padding: 0 0.3rem;
            transition: all 0.2s ease-out;
            pointer-events: none;
            font-size: 1rem;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-group label i { opacity: 0.7; }

        /* Floated Label Styles */
        .form-control:focus + label:not(.static-label),
        .form-control:not(:placeholder-shown) + label:not(.static-label),
        .form-select:focus + label:not(.static-label),
        .form-select:valid + label:not(.static-label) { /* Use :valid for select */
            top: 0;
            transform: translateY(-50%) scale(0.85);
            font-weight: 600;
            color: var(--primary-color);
            /* --- MODIFICATION: Use correct background based on theme --- */
            background-color: var(--white-bg);
            border-radius: 4px;
            z-index: 1;
            transition: all 0.2s ease-out, background-color 0.3s ease;
        }
        /* Adjust background for dark mode */
        html[data-theme='dark'] .form-control:focus + label:not(.static-label),
        html[data-theme='dark'] .form-control:not(:placeholder-shown) + label:not(.static-label),
        html[data-theme='dark'] .form-select:focus + label:not(.static-label),
        html[data-theme='dark'] .form-select:valid + label:not(.static-label) {
            background-color: var(--dark-card-bg); /* Match dark card bg */
        }

        textarea.form-control:focus + label:not(.static-label),
        textarea.form-control:not(:placeholder-shown) + label:not(.static-label) {
             top: 0.8rem; /* Adjust for textarea */
             transform: translateY(-100%) scale(0.85);
        }

        /* --- Input Fields --- */
        .form-control,
        .form-select {
            width: 100%;
            padding: 1rem 1.2rem; /* Slightly more padding */
            border: 1px solid var(--input-border-color);
            border-radius: var(--border-radius-input); /* Applied new radius */
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out, background-color 0.3s ease;
            /* --- MODIFICATION: Default background to white --- */
            background-color: #ffffff;
            color: var(--text-color); /* Actual text color */
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            box-shadow: var(--input-shadow);
            position: relative;
            z-index: 0;
        }
        /* Override background for dark mode */
        html[data-theme='dark'] .form-control,
        html[data-theme='dark'] .form-select {
             background-color: var(--dark-input-bg, rgba(40, 40, 60, 0.5));
             color: var(--dark-text-color);
        }

        /* Placeholder Styles */
        .form-control::placeholder {
            color: var(--text-muted); /* Use muted color for placeholder */
            opacity: 0.7;
        }
        /* Hide placeholder visually when label is floated */
        .form-control:not(:placeholder-shown)::placeholder {
            color: transparent;
        }
        /* Dark mode placeholder */
         html[data-theme='dark'] .form-control::placeholder {
             color: var(--dark-text-muted);
             opacity: 0.6;
         }


        .form-select {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e");
             background-repeat: no-repeat;
             background-position: right 1.1rem center;
             background-size: 16px 12px;
             padding-right: 3rem;
             color: var(--text-muted); /* Initial placeholder color */
        }
         /* Style select when an option IS selected */
         .form-select:valid {
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
        .form-control:focus,
        .form-select:focus {
            border-color: var(--input-focus-border);
            outline: 0;
            box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow);
            z-index: 2;
        }
        .form-control:focus-visible,
        .form-select:focus-visible { /* Glowing effect using box-shadow */
            outline: none;
            box-shadow: 0 0 0 3px var(--input-focus-shadow), /* Inner glow */
                        0 0 10px 2px var(--input-focus-shadow), /* Outer glow */
                        var(--input-shadow); /* Keep original shadow */
            border-color: var(--input-focus-border);
        }

        textarea.form-control {
            /* --- MODIFICATION: Increase min-height --- */
            min-height: 150px; /* Adjust value as needed */
            resize: vertical;
            padding-top: 1.5rem; /* More space for label */
             /* --- MODIFICATION: Explicitly set text color for textarea --- */
             color: var(--text-color); /* Ensure text is visible */
        }
         /* --- MODIFICATION: Ensure dark mode text color for textarea --- */
         html[data-theme='dark'] textarea.form-control {
              color: var(--dark-text-color);
         }


        /* --- Input Group --- */
        .input-group {
            position: relative; display: flex; align-items: stretch; width: 100%;
            box-shadow: var(--input-shadow); border-radius: var(--border-radius-input);
            overflow: hidden; border: 1px solid var(--input-border-color);
            transition: border-color 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
             /* --- MODIFICATION: Set input group background to white --- */
             background-color: #ffffff;
        }
         /* --- MODIFICATION: Set dark mode background for input group --- */
         html[data-theme='dark'] .input-group {
              background-color: var(--dark-input-bg);
         }

         .input-group:focus-within {
            border-color: var(--input-focus-border);
            box-shadow: 0 0 0 0.25rem var(--input-focus-shadow), var(--input-shadow);
            z-index: 2;
         }
         .input-group:focus-within:has(:focus-visible) { /* Apply glow to group */
             outline: none;
             box-shadow: 0 0 0 3px var(--input-focus-shadow),
                         0 0 10px 2px var(--input-focus-shadow),
                         var(--input-shadow);
             border-color: var(--input-focus-border);
         }
        .input-group-text {
            display: flex; align-items: center; padding: 1rem 1.2rem;
            font-size: 1rem; font-weight: 500; color: var(--text-muted);
            text-align: center; white-space: nowrap;
            background-color: rgba(248, 249, 250, 0.7);
            border: none; border-right: 1px solid var(--input-border-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        html[data-theme='dark'] .input-group-text {
            background-color: rgba(50, 50, 80, 0.6);
            border-right: 1px solid var(--dark-input-border);
             color: var(--dark-text-muted);
        }
        .input-group .form-control {
            position: relative; flex: 1 1 auto; width: 1%; min-width: 0;
            border-radius: 0; border: none; box-shadow: none; z-index: 0;
            /* Background is now transparent to let group background show */
            background-color: transparent;
        }
        .input-group:focus-within .form-control:focus,
        .input-group:focus-within .form-control:focus-visible {
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
        /* ... (validation styles remain unchanged) ... */
         .form-control.is-invalid,
        .form-select.is-invalid {
            border-color: var(--error-color) !important;
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-repeat: no-repeat !important;
             background-position: right 1.1rem center !important;
             background-size: 1em 1em !important;
             padding-right: calc(1.5em + 1.2rem) !important; /* Adjust padding */
        }
        /* Special handling for date input with its own icon */
         .date-input-container .form-control.is-invalid {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             /* Position error icon to the left of the date icon */
             background-position: center right 3.2rem !important; /* Adjust position */
             padding-right: calc(1.5em + 3rem) !important; /* Increase padding */
         }
        .input-group.is-invalid { border-color: var(--error-color) !important; }
        .input-group.is-invalid .form-control {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-repeat: no-repeat !important; background-position: right 0.8rem center !important; background-size: 1em 1em !important; padding-right: calc(1.5em + 0.8rem) !important;
         }
        .form-select.is-invalid {
             background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%236c757d' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"), url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
             background-position: right 1.1rem center, center right 3rem !important; /* Adjust second position */
             background-size: 16px 12px, 1em 1em !important; padding-right: 4.5rem !important;
         }
         html[data-theme='dark'] .form-select.is-invalid {
              background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3e%3cpath fill='none' stroke='%23adb5bd' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m2 5 6 6 6-6'/%3e%3c/svg%3e"), url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e") !important;
         }
        .form-control.is-invalid:focus,
        .form-select.is-invalid:focus,
        .input-group.is-invalid:focus-within {
            border-color: var(--error-color) !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25), var(--input-shadow);
        }
        .input-group.is-invalid:focus-within:has(:focus-visible) {
             box-shadow: 0 0 0 3px rgba(220, 53, 69, 0.25), 0 0 10px 2px rgba(220, 53, 69, 0.25), var(--input-shadow);
        }
        .error-message {
            color: var(--error-color); font-size: 0.85rem;
            margin-top: 0.5rem; font-weight: 500;
        }


        /* --- Buttons --- */
        /* ... (button styles remain unchanged) ... */
         .button-container {
            margin-top: 2.5rem; display: flex; justify-content: space-between;
            align-items: center; gap: 1rem; flex-wrap: wrap;
        }
        .btn {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 0.6rem; padding: 0.8rem 1.8rem; border: none;
            border-radius: var(--border-radius); font-size: 0.95rem; font-weight: 600;
            text-decoration: none; cursor: pointer; transition: all 0.25s ease-in-out;
            white-space: nowrap; letter-spacing: 0.5px; text-transform: uppercase;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn:hover {
             transform: translateY(-2px) scale(1.02);
             box-shadow: 0 7px 18px rgba(0,0,0,0.15);
        }
        .btn:active {
             transform: translateY(0) scale(0.98);
             box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .btn:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn .spinner {
            display: inline-block;
            width: 1em;
            height: 1em;
            border: 2px solid currentColor;
            border-right-color: transparent;
            border-radius: 50%;
            animation: spinner-border .75s linear infinite;
        }
        @keyframes spinner-border { to { transform: rotate(360deg); } }

        .btn-primary {
            background-image: var(--primary-gradient); color: #fff; order: 2;
            background-size: 150% auto; transition: all 0.3s ease-in-out;
        }
        .btn-primary:hover {
            background-image: var(--primary-gradient-hover); background-position: right center;
            box-shadow: 0 8px 20px rgba(25, 135, 84, 0.35);
        }
        .btn-secondary {
            background-color: transparent; color: var(--secondary-color);
            border: 1px solid var(--card-border-color); order: 1; box-shadow: none;
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease;
        }
        .btn-secondary:hover {
            background-color: rgba(0,0,0,0.03); border-color: var(--secondary-color);
            transform: translateY(0); box-shadow: none; color: var(--secondary-hover);
        }
        html[data-theme='dark'] .btn-secondary {
            color: var(--dark-text-muted);
            border-color: var(--dark-border-color);
        }
        html[data-theme='dark'] .btn-secondary:hover {
            background-color: rgba(255,255,255,0.05);
            border-color: var(--dark-text-color);
            color: var(--dark-text-color);
        }
        .btn i { font-size: 1em; transition: transform 0.2s ease-out; }
        .btn:hover i:not(.spinner) { transform: rotate(-5deg) scale(1.1); }


        /* --- Flash Messages & Errors --- */
        /* ... (flash message styles remain unchanged) ... */
         .message {
            padding: 1rem 1.25rem; margin-bottom: 1.5rem; /* Less margin */
            border-radius: var(--border-radius-input); border: 1px solid transparent;
            display: flex; align-items: flex-start; gap: 0.8rem; font-size: 0.95rem;
            background-color: var(--white-bg); /* Use card background */
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
            opacity: 1;
            transform: translateY(0);
        }
        .message.fade-out {
            opacity: 0;
            transform: translateY(-10px);
        }
        .message i { font-size: 1.3rem; margin-top: 0.15rem; flex-shrink: 0; }
        .message-content { flex-grow: 1; }
        .message-content strong { display: block; margin-bottom: 0.3rem; font-weight: 600; }
        .message-success {
            color: var(--success-color); background-color: var(--success-bg);
            border-color: var(--success-border);
        }
        .message-success i { color: var(--success-color); }
        .message-error {
            color: var(--error-color); background-color: var(--error-bg);
            border-color: var(--error-border);
        }
        .message-error i { color: var(--error-color); }
        html[data-theme='dark'] .message-success {
            background-color: rgba(25, 135, 84, 0.2); border-color: rgba(25, 135, 84, 0.5); color: #a3cfbb;
        }
        html[data-theme='dark'] .message-error {
            background-color: rgba(220, 53, 69, 0.2); border-color: rgba(220, 53, 69, 0.5); color: #f1aeb5;
        }
        .message-error ul { margin-top: 0.5rem; margin-bottom: 0; padding-left: 1.25rem; }
        .message-error li { margin-bottom: 0.3rem; }
        .database-error-message { margin-top: 0.5rem; font-weight: 500; }
        .db-connection-error-block { text-align: center; }
        .db-connection-error-block i { font-size: 2.5rem; color: var(--error-color); margin-bottom: 1rem; }
        .db-connection-error-block strong { display: block; font-size: 1.1rem; margin-bottom: 0.5rem; }
        .db-connection-error-block p { color: var(--text-muted); margin-bottom: 1.5rem; }


        /* --- Sidebar Widgets --- */
        /* ... (sidebar styles remain unchanged) ... */
        .sidebar-widget {
            background-color: var(--white-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: var(--border-radius);
            padding: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
            border: 1px solid var(--card-border-color);
            transition: background-color 0.3s ease, border-color 0.3s ease;
        }
        .widget-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--card-border-color);
            display: flex;
            align-items: center;
            gap: 0.6rem;
            color: var(--primary-color);
        }
        .widget-title i { opacity: 0.8; }

        /* Monthly Summary */
        .monthly-summary .amount {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--success-color);
            margin-bottom: 0.5rem;
            display: block;
        }
        .monthly-summary .label {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        .monthly-summary .progress-bar-container {
            background-color: #e9ecef;
            border-radius: 0.25rem;
            height: 8px;
            overflow: hidden;
        }
        html[data-theme='dark'] .monthly-summary .progress-bar-container { background-color: rgba(255,255,255,0.1); }
        .monthly-summary .progress-bar {
            background-color: var(--success-color);
            height: 100%;
            width: 60%; /* Example width - Calculate based on goal */
            border-radius: 0.25rem;
            transition: width 0.5s ease-in-out;
        }

        /* Recent Entries List */
        .recent-entries-list {
            list-style: none;
            padding: 0;
            margin: 0;
            max-height: 300px; /* Limit height and make scrollable */
            overflow-y: auto;
            padding-right: 0.5rem; /* Space for scrollbar */
        }
        .recent-entry {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0.2rem; /* More vertical padding */
            border-bottom: 1px dashed var(--card-border-color);
            transition: background-color 0.2s ease;
        }
        .recent-entry:last-child { border-bottom: none; }
        .recent-entry:hover { background-color: rgba(0,0,0,0.02); }
        html[data-theme='dark'] .recent-entry:hover { background-color: rgba(255,255,255,0.04); }
        .entry-details .category {
            font-weight: 500;
            color: var(--text-color);
            display: block;
            font-size: 0.95rem;
        }
        .entry-details .date {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        .entry-amount {
            font-weight: 600;
            color: var(--success-color);
            font-size: 1rem;
        }

        /* Pie Chart */
        .chart-container { position: relative; height: 280px; width: 100%; }

        /* --- Custom Scrollbar (Webkit) --- */
        /* ... (scrollbar styles remain unchanged) ... */
        .recent-entries-list::-webkit-scrollbar { width: 8px; }
        .recent-entries-list::-webkit-scrollbar-track { background: transparent; border-radius: 10px; }
        .recent-entries-list::-webkit-scrollbar-thumb {
            background-color: rgba(0, 0, 0, 0.2);
            border-radius: 10px;
            border: 2px solid transparent;
             background-clip: content-box;
        }
        .recent-entries-list::-webkit-scrollbar-thumb:hover { background-color: rgba(0, 0, 0, 0.4); }
        html[data-theme='dark'] .recent-entries-list::-webkit-scrollbar-thumb { background-color: rgba(255, 255, 255, 0.2); }
        html[data-theme='dark'] .recent-entries-list::-webkit-scrollbar-thumb:hover { background-color: rgba(255, 255, 255, 0.4); }


        /* --- Footer --- */
        /* ... (footer styles remain unchanged) ... */
         .footer {
            text-align: center;
            margin: 3rem auto 1rem auto;
            padding: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            width: 100%;
            max-width: var(--content-max-width);
        }


        /* --- Responsive Design --- */
        /* ... (responsive styles remain unchanged) ... */
        @media (max-width: 992px) {
            .main-wrapper {
                flex-direction: column;
                gap: 2rem;
            }
            .form-container, .sidebar-container {
                flex-basis: auto; /* Reset flex basis */
                width: 100%;
            }
            .navbar { padding: 0.8rem 1rem; }
        }

        @media (max-width: 768px) {
            body { padding-top: 70px; } /* Adjust for slightly smaller navbar */
            .form-card { padding: 2rem 1.5rem; }
            .page-header h1 { font-size: 1.5rem; }
            .page-header i { font-size: 1.7rem; }
            .button-container { justify-content: center; } /* Center buttons on mobile */
             .recent-entries-list { max-height: 250px; }
             .chart-container { height: 250px; }
        }

        @media (max-width: 480px) {
            html { font-size: 15px; }
            .navbar { padding: 0.6rem 1rem; }
            .navbar-brand { font-size: 1.2rem; }
            .theme-toggle-btn { font-size: 1.3rem; }
            body { padding-top: 65px; }
            .form-card { padding: 1.5rem 1rem; }
            .page-header { margin-bottom: 1.5rem; gap: 0.8rem; }
            .page-header h1 { font-size: 1.35rem; }
            .page-header i { font-size: 1.5rem; }
            .form-control, .form-select, .input-group-text { padding: 0.9rem 1rem; } /* Ensure good tap size */
            .form-group label { left: 1rem; }
            .btn { padding: 0.9rem 1.5rem; font-size: 0.9rem; width: 100%; } /* Full width buttons */
            .button-container { flex-direction: column-reverse; align-items: stretch; gap: 0.8rem; }
            .footer { margin-top: 2rem; }
            .main-wrapper { margin-top: 1rem; } /* Less top margin on mobile */
        }


         /* Style for static label above file input */
        .static-label {
            position: static;
            transform: none;
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            display: block;
            font-weight: 500;
        }
        .file-input {
            padding-top: 0.8rem;
            padding-bottom: 0.8rem;
        }
         /* Align file input text better */
         input[type="file"].form-control {
             line-height: 1.8; /* Adjust line height */
             color: var(--text-muted); /* Style placeholder text */
         }
         html[data-theme='dark'] input[type="file"].form-control {
             color: var(--dark-text-muted);
         }


    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="navbar-brand">
            <i class="fa-solid fa-wallet"></i> Finance Tracker
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
                         <lottie-player src="https://assets6.lottiefiles.com/packages/lf20_qpsnmykx.json" background="transparent" speed="1" style="width: 100%; height: 100%;" loop autoplay></lottie-player>
                         <!-- Find animations at lottiefiles.com. Replace src with your animation URL -->
                     </div>
                    <h1>Add New Income</h1>
                </header>

                <!-- Flash Messages & Errors -->
                <?php if (!empty($flashMessage)): ?>
                    <div class="message message-<?php echo $flashType; ?> <?php echo ($flashType == 'success') ? 'js-flash-success' : ''; ?>" role="alert">
                        <i class="fa-solid <?php echo ($flashType == 'success') ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
                        <div class="message-content">
                            <strong><?php echo ($flashType == 'success') ? 'Success!' : 'Notice:'; ?></strong>
                            <p><?php echo htmlspecialchars($flashMessage); ?></p>
                            <?php if ($flashType == 'error' && !empty($errors) && !isset($errors['database_connection']) && !isset($errors['database_insert'])): ?>
                                <ul>
                                    <?php foreach ($errors as $field => $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif (isset($errors['database_insert'])): ?>
                                 <p class="database-error-message"><?php echo htmlspecialchars($errors['database_insert']); ?></p>
                             <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($dbConnectionError)): ?>
                     <div class="message message-error db-connection-error-block">
                          <i class="fa-solid fa-database"></i>
                          <div class="message-content">
                              <strong>Database Connection Failed</strong>
                              <p><?php echo htmlspecialchars($dbConnectionError); ?></p>
                              <p>Unable to load or save income data. Please check settings or contact support.</p>
                          </div>
                     </div>
                <?php endif; ?>

                <!-- Income Form -->
                <?php if (empty($dbConnectionError)): // Only show form if DB connection is okay ?>
                    <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post" class="income-form" id="income-form" novalidate enctype="multipart/form-data">
                        <div class="form-group">
                           <div class="date-input-container">
                                <input type="date" id="income_date" name="income_date" class="form-control <?php echo hasError('income_date') ? 'is-invalid' : ''; ?>"
                                       value="<?php echo getValue('income_date', date('Y-m-d')); ?>" required aria-describedby="date-error" placeholder=" "> <!-- Placeholder added for CSS -->
                                 <span class="date-icon"><i class="fa-solid fa-calendar-days"></i></span>
                            </div>
                             <label for="income_date"><i class="fa-regular fa-calendar-days"></i> Date Received</label>
                            <?php echo displayError('income_date'); ?>
                        </div>


                        <div class="form-group">
                             <div class="input-group <?php echo hasError('income_amount') ? 'is-invalid' : ''; ?>">
                                <span class="input-group-text"><?php echo htmlspecialchars($currencySymbol); ?></span>
                                <input type="number" id="income_amount" name="income_amount" step="0.01" min="0.01"
                                       class="form-control" placeholder=" " <!-- Placeholder added for CSS -->
                                       <?php echo getValue('income_amount'); ?>
                             </div>
                             <!-- Label associated with input group -->
                             <label for="income_amount">Amount</label>
                             <?php echo displayError('income_amount'); ?>
                        </div>

                        <div class="form-group">
                            <select id="income_category" name="income_category" class="form-select <?php echo hasError('income_category') ? 'is-invalid' : ''; ?>" required aria-describedby="category-error">
                                <option value="" <?php echo (getValue('income_category') == '') ? 'selected' : ''; ?> disabled></option> <!-- Placeholder value -->
                                <?php foreach ($incomeCategoriesList as $category):
                                    $selected = (getValue('income_category') === $category) ? 'selected' : ''; ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($category); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="income_category"><i class="fa-solid fa-briefcase"></i> Source / Category</label>
                             <?php echo displayError('income_category'); ?>
                        </div>

                        <div class="form-group">
                            <select id="received_method" name="received_method" class="form-select <?php echo hasError('received_method') ? 'is-invalid' : ''; ?>" aria-describedby="method-error">
                                <option value="" <?php echo (getValue('received_method') == '') ? 'selected' : ''; ?>></option> <!-- Placeholder value -->
                                 <?php foreach ($receivedMethods as $method):
                                     $selected = (getValue('received_method') === $method) ? 'selected' : ''; ?>
                                    <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $selected; ?>>
                                        <?php echo htmlspecialchars($method); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <label for="received_method"><i class="fa-solid fa-money-bill-transfer"></i> Received Method</label>
                             <?php echo displayError('received_method'); ?>
                        </div>

                         <div class="form-group">
                            <textarea id="income_description" name="income_description" rows="3" placeholder=" " class ="form-control"><?php echo hasError('income_description') ? 'is-invalid' : ''; ?><?php echo getValue('income_description'); ?></textarea>
                            <label for="income_description"><i class="fa-regular fa-pen-to-square"></i> Notes / Description</label>
                            <?php echo displayError('income_description'); ?>
                        </div>

                        <!-- Extra Fields -->
                        <!-- REMOVED Tags Input Field -->

                        <div class="form-group">
                             <label for="receipt_upload" class="static-label"><i class="fa-solid fa-receipt"></i> Upload Receipt (Optional)</label>
                            <input type="file" id="receipt_upload" name="receipt_upload" class="form-control file-input" accept="image/*,.pdf">
                             <!-- File upload processing requires PHP backend changes -->
                        </div>
                        <!-- End Extra Fields -->


                        <div class="button-container">
                             <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="btn btn-secondary">
                                 <i class="fa-solid fa-xmark"></i> Cancel
                             </a>
                             <button type="submit" class="btn btn-primary" id="submit-button">
                                 <span class="btn-icon"><i class="fa-solid fa-plus-circle"></i></span>
                                 <span class="btn-text">Add Income</span>
                             </button>
                        </div>

                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sidebar Section -->
        <aside class="sidebar-container">
            <!-- Monthly Income Summary -->
            <div class="sidebar-widget monthly-summary">
                <h3 class="widget-title"><i class="fa-solid fa-calendar-day"></i> This Month's Income</h3>
                <span class="amount"><?php echo formatCurrency($monthlyTotal, $currencySymbol); ?></span>
                <div class="label">Total income received this month</div>
                <!-- Mini Progress Bar (Example: Needs a target value) -->
                <!-- <div class="progress-bar-container" title="Progress towards monthly goal (Example)">
                    <div class="progress-bar" style="width: <?php // echo ($monthlyGoal > 0) ? min(100, ($monthlyTotal / $monthlyGoal) * 100) : 0; ?>%;"></div>
                </div> -->
            </div>

            <!-- Category Pie Chart -->
            <div class="sidebar-widget">
                 <h3 class="widget-title"><i class="fa-solid fa-chart-pie"></i> Income Categories</h3>
                 <div class="chart-container">
                     <?php if (!empty($categoryDataForChart['labels'])): ?>
                         <canvas id="incomeCategoryChart"></canvas>
                     <?php else: ?>
                         <p style="text-align: center; color: var(--text-muted); padding-top: 2rem;">No income data yet to display chart.</p>
                     <?php endif; ?>
                 </div>
            </div>

            <!-- Recent Income List -->
            <div class="sidebar-widget">
                <h3 class="widget-title"><i class="fa-solid fa-list-ul"></i> Recent Entries</h3>
                <?php if (!empty($recentIncomes)): ?>
                    <ul class="recent-entries-list">
                        <?php foreach ($recentIncomes as $entry): ?>
                            <li class="recent-entry">
                                <div class="entry-details">
                                    <span class="category"><?php echo htmlspecialchars($entry['income_category']); ?></span>
                                    <span class="date"><?php echo date("M d, Y", strtotime($entry['income_date'])); ?></span>
                                </div>
                                <span class="entry-amount"><?php echo formatCurrency($entry['income_amount'], $currencySymbol); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                     <p style="text-align: center; color: var(--text-muted); padding-top: 1rem;">No recent income entries found.</p>
                <?php endif; ?>
            </div>
        </aside>

    </div>

     <footer class="footer">
         © <?php echo date('Y'); ?> Finance Tracker App. All rights reserved.
     </footer>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // --- Select Placeholder Styling ---
            const selects = document.querySelectorAll('.form-select');
            selects.forEach(select => {
                const updateSelectPlaceholder = () => {
                     const isDark = document.documentElement.hasAttribute('data-theme');
                    if (select.value === "" || (select.options[select.selectedIndex] && select.options[select.selectedIndex].disabled)) {
                        select.classList.add('placeholder-shown');
                        select.style.color = isDark ? 'var(--dark-text-muted)' : 'var(--text-muted)'; // Use muted color
                    } else {
                        select.classList.remove('placeholder-shown');
                         select.style.color = isDark ? 'var(--dark-text-color)' : 'var(--text-color)'; // Use standard text color
                    }
                };
                updateSelectPlaceholder(); // Initial check
                select.addEventListener('change', updateSelectPlaceholder);
                 select.addEventListener('blur', updateSelectPlaceholder); // Also update on blur
            });

            // --- Float Label Logic (Only for initial check and dynamic bg updates) ---
            const handleLabelFloat = (inputElement) => {
                const group = inputElement.closest('.form-group');
                if (!group) return;

                const label = group.querySelector('label:not(.static-label)');
                if (!label) return;

                const checkInput = inputElement.closest('.input-group') ? inputElement.closest('.input-group').querySelector('.form-control') : inputElement;

                const shouldFloat = checkInput.value !== "" || (checkInput.tagName === 'SELECT' && checkInput.value !== "") || document.activeElement === checkInput || checkInput.type === 'date';

                const isDark = document.documentElement.hasAttribute('data-theme');
                const labelBgColor = getComputedStyle(document.documentElement).getPropertyValue(isDark ? '--dark-card-bg' : '--white-bg').trim();

                if (shouldFloat) {
                    label.style.top = '0';
                    label.style.transform = 'translateY(-50%) scale(0.85)';
                    label.style.fontWeight = '600';
                    label.style.color = 'var(--primary-color)';
                    label.style.backgroundColor = labelBgColor;
                    label.style.borderRadius = '4px';
                    label.style.padding = '0 0.3rem';
                    label.style.zIndex = '1';
                    label.style.left = '1.1rem';

                    if (checkInput.tagName === 'TEXTAREA') {
                        label.style.top = '0.8rem';
                        label.style.transform = 'translateY(-100%) scale(0.85)';
                    }
                } else {
                    label.style.top = '50%';
                    label.style.transform = 'translateY(-50%)';
                    label.style.fontWeight = '500';
                    label.style.color = 'var(--text-muted)';
                    label.style.backgroundColor = 'transparent';
                    label.style.borderRadius = '0';
                    label.style.padding = '0 0.3rem';
                    label.style.zIndex = '0';
                    if (label.htmlFor === 'income_amount') {
                         label.style.left = '3.5rem'; // Adjust if needed for amount
                    } else {
                         label.style.left = '1.1rem';
                    }
                }
            };

             // --- Float Label Initial State Check & Event Listeners ---
              const inputsAndSelects = document.querySelectorAll('.form-control, .form-select');
              inputsAndSelects.forEach(element => {
                  // Ensure placeholder=" " is set for inputs/textareas if not using JS to force float
                  if (element.tagName !== 'SELECT' && !element.hasAttribute('placeholder')) {
                       element.setAttribute('placeholder', ' ');
                  }
                  handleLabelFloat(element); // Check initial state
                  element.addEventListener('focus', () => handleLabelFloat(element));
                  element.addEventListener('blur', () => handleLabelFloat(element));
                  element.addEventListener('input', () => handleLabelFloat(element));
                  if (element.type === 'date' || element.tagName === 'SELECT') {
                      element.addEventListener('change', () => handleLabelFloat(element));
                  }
              });

            // --- Success Message Fade Out ---
            const successFlash = document.querySelector('.js-flash-success');
            if (successFlash) {
                setTimeout(() => {
                    successFlash.classList.add('fade-out');
                    successFlash.addEventListener('transitionend', () => {
                         if(successFlash.parentNode) { successFlash.parentNode.removeChild(successFlash); }
                    }, { once: true });
                }, 5000);
            }

            // --- Form Loading State ---
            const incomeForm = document.getElementById('income-form');
            const submitButton = document.getElementById('submit-button');
            const buttonText = submitButton.querySelector('.btn-text');
            const buttonIconSpan = submitButton.querySelector('.btn-icon');
            const originalIconHTML = buttonIconSpan ? buttonIconSpan.innerHTML : '<i class="fa-solid fa-plus-circle"></i>';

            if (incomeForm && submitButton && buttonText && buttonIconSpan) {
                incomeForm.addEventListener('submit', (e) => {
                    submitButton.disabled = true;
                    buttonText.textContent = 'Saving...';
                    buttonIconSpan.innerHTML = '<span class="spinner"></span>';
                });
            }

             // --- Dark Mode Toggle ---
             const themeToggle = document.getElementById('theme-toggle');
             const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null;
             const sunIcon = 'fa-solid fa-sun';
             const moonIcon = 'fa-solid fa-moon';

             const setMode = (isDark) => {
                 const iconElement = themeToggle.querySelector('i');
                 if (isDark) {
                     document.documentElement.setAttribute('data-theme', 'dark');
                     localStorage.setItem('theme', 'dark');
                     iconElement.className = sunIcon;
                     themeToggle.title = 'Switch to light theme';
                 } else {
                     document.documentElement.removeAttribute('data-theme');
                     localStorage.setItem('theme', 'light');
                     iconElement.className = moonIcon;
                     themeToggle.title = 'Switch to dark theme';
                 }
                 // Re-render chart and update label backgrounds
                 if (window.incomeChartInstance) {
                     window.incomeChartInstance.destroy();
                     renderIncomeChart();
                 }
                  applyLabelBackground(); // Update floated label backgrounds
             };

             // Function to update label backgrounds on theme change
             const applyLabelBackground = () => {
                  document.querySelectorAll('.form-group label:not(.static-label)').forEach(label => {
                      if (label.style.transform.includes('scale(0.85)')) { // Check if floated
                          const isDark = document.documentElement.hasAttribute('data-theme');
                          label.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue(isDark ? '--dark-card-bg' : '--white-bg').trim();
                      }
                  });
             };

             if (currentTheme === 'dark') { setMode(true); }
             else { setMode(false); }

             themeToggle.addEventListener('click', () => {
                 setMode(!document.documentElement.hasAttribute('data-theme'));
             });

            // --- Pie Chart Rendering ---
            const renderIncomeChart = () => {
                 const ctx = document.getElementById('incomeCategoryChart');
                 if (!ctx) return;

                 const categoryData = <?php echo json_encode($categoryDataForChart); ?>;
                 if (!categoryData || !categoryData.labels || categoryData.labels.length === 0) return;

                 const isDarkMode = document.documentElement.hasAttribute('data-theme');
                 const labelColor = isDarkMode ? '#e4e6eb' : '#212529';
                 const chartColors = [
                     '#198754', '#20c997', '#0dcaf0', '#ffc107', '#fd7e14',
                     '#6f42c1', '#d63384', '#6c757d'
                 ];
                 const backgroundColors = categoryData.labels.map((_, i) => chartColors[i % chartColors.length] + (isDarkMode ? 'B3' : 'CC'));
                 const borderColors = categoryData.labels.map((_, i) => chartColors[i % chartColors.length]);

                 if (window.incomeChartInstance) window.incomeChartInstance.destroy(); // Destroy previous instance

                 window.incomeChartInstance = new Chart(ctx, {
                     type: 'pie',
                     data: {
                         labels: categoryData.labels,
                         datasets: [{
                             label: 'Income by Category',
                             data: categoryData.totals,
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
                                 labels: { color: labelColor, padding: 15, font: { size: 12, family: "'Poppins', sans-serif" } }
                             },
                             tooltip: {
                                 callbacks: {
                                     label: function(context) {
                                         let label = context.label || '';
                                         if (label) { label += ': '; }
                                         if (context.parsed !== null) {
                                             label += new Intl.NumberFormat(document.documentElement.lang || 'en-US', { style: 'currency', currency: '<?php echo $currencySymbol === '₹' ? 'INR' : ($currencySymbol === '$' ? 'USD' : 'USD'); ?>' }).format(context.parsed);
                                         }
                                         return label;
                                     }
                                 },
                                 backgroundColor: isDarkMode ? 'rgba(40, 40, 60, 0.9)' : 'rgba(0, 0, 0, 0.8)',
                                 titleFont: { family: "'Poppins', sans-serif" }, bodyFont: { family: "'Poppins', sans-serif" }
                             }
                         }
                     }
                 });
             };

             renderIncomeChart(); // Initial render

        }); // End DOMContentLoaded
    </script>

</body>
</html>