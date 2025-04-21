<?php

session_start();


$currencySymbol = '$';


function formatCurrency($amount, $symbol = '$') {
    $amount = floatval($amount);
    return htmlspecialchars($symbol, ENT_QUOTES, 'UTF-8') . number_format($amount, 2);
}

// --- Database Connection Details (CHECK THESE!) ---
$servername = "localhost";
$username = "root";
$password = ""; // Ensure this is correct for your MySQL setup!
$dbname = "login"; // Ensure this database exists!
$conn = null;


$errors = []; // Local errors array for POST/GET validation
$goalsData = [];
$editGoalData = null; // Used ONLY if edit POST fails validation
$errorMessage = ''; // For DB connection errors

// --- Fetch Goals and Prepare Data ---
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->exec("SET NAMES 'utf8mb4'"); // Good practice


    // --- Ensure table exists ---
    $conn->exec("CREATE TABLE IF NOT EXISTS financial_goals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        target DECIMAL(12, 2) NOT NULL,
        current DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
        deadline DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP

    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");


    // --- Handle POST/GET Requests (Original Logic) ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $conn->beginTransaction();

        // Add Goal Logic
        if (isset($_POST['add_goal'])) {
            $name = filter_input(INPUT_POST, 'goal_name', FILTER_SANITIZE_SPECIAL_CHARS);
            $target = filter_input(INPUT_POST, 'goal_target', FILTER_VALIDATE_FLOAT);
            $deadlineInput = filter_input(INPUT_POST, 'goal_deadline', FILTER_SANITIZE_SPECIAL_CHARS);
            $deadline = (!empty($deadlineInput)) ? $deadlineInput : null;

             // --- Validation (Original Logic) ---
            if (empty(trim($name))) { $errors[] = "Goal name is required."; }
            if ($target === false || $target === null || $target <= 0) { $errors[] = "Target amount must be a positive number."; }
            if ($deadline !== null) {
                $d = DateTime::createFromFormat('Y-m-d', $deadline);
                // Original validation check for deadline
                if (!$d || $d->format('Y-m-d') !== $deadline || $deadline < date('Y-m-d')) {
                    $errors[] = "Invalid or past deadline date format.";
                }
            }
            // --- End Validation ---

            if (empty($errors)) {
                // --- Database Insert (Original Logic) ---
                $sql = "INSERT INTO financial_goals (name, target, current, deadline) VALUES (:name, :target, 0.00, :deadline)";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':target', $target);
                $stmt->bindParam(':deadline', $deadline, $deadline === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->execute();
                $conn->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Goal '$name' added successfully!"];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                 // --- Handle Validation Errors (Original Logic) ---
                 $conn->rollBack();
                 $_POST['refill_action'] = 'add'; // Flag for JS refill
                 // Flash message set in original code
                 $_SESSION['flash_message'] = ['type' => 'error', 'text' => "Failed to add goal. Please check errors below."];
                 // NOTE: The $errors array will be available locally for the JS block later
            }
        }

        // Edit Goal Logic
        elseif (isset($_POST['edit_goal'])) {
            $editId = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT);
            $name = filter_input(INPUT_POST, 'edit_name', FILTER_SANITIZE_SPECIAL_CHARS);
            $target = filter_input(INPUT_POST, 'edit_target', FILTER_VALIDATE_FLOAT);
            $current = filter_input(INPUT_POST, 'edit_current', FILTER_VALIDATE_FLOAT);
            $deadlineInput = filter_input(INPUT_POST, 'edit_deadline', FILTER_SANITIZE_SPECIAL_CHARS);
            $deadline = (!empty($deadlineInput)) ? $deadlineInput : null;

             // --- Validation (Original Logic) ---
            if ($editId === false || $editId === null || $editId <=0) { $errors[] = "Invalid goal ID."; }
            if (empty(trim($name))) { $errors[] = "Goal name is required."; }
            if ($target === false || $target === null || $target <= 0) { $errors[] = "Target amount must be a positive number."; }
            if ($current === false || $current === null || $current < 0) { $errors[] = "Current amount cannot be negative."; }
            // Original check for current exceeding target
            if ($current > $target && $target > 0) { $errors[] = "Current amount cannot exceed the target amount."; }
             if ($deadline !== null) {
                $d = DateTime::createFromFormat('Y-m-d', $deadline);
                 // Original validation check for deadline
                 if (!$d || $d->format('Y-m-d') !== $deadline || $deadline < date('Y-m-d')) {
                    $errors[] = "Invalid or past deadline date format.";
                }
            }
             // --- End Validation ---

            if (empty($errors)) {
                 // --- Database Update (Original Logic) ---
                $sql = "UPDATE financial_goals SET name = :name, target = :target, current = :current, deadline = :deadline WHERE id = :id";
                $stmt = $conn->prepare($sql);
                // Bind parameters as before...
                $stmt->bindParam(':id', $editId, PDO::PARAM_INT);
                $stmt->bindParam(':name', $name);
                $stmt->bindParam(':target', $target);
                $stmt->bindParam(':current', $current);
                $stmt->bindParam(':deadline', $deadline, $deadline === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->execute();
                $conn->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => "Goal '$name' updated successfully!"];
                header("Location: " . $_SERVER['PHP_SELF']);
                exit;
            } else {
                 // --- Handle Validation Errors (Original Logic) ---
                $conn->rollBack();
                 // Prepare data for JS refill (Original Logic)
                 $editGoalData = [
                     'id' => $editId, 'name' => $name, 'target' => $target,
                     'current' => $current, 'deadline' => $deadlineInput
                 ];
                 $_POST['refill_action'] = 'edit'; // Flag for JS refill
                 // Flash message set in original code
                 $_SESSION['flash_message'] = ['type' => 'error', 'text' => "Failed to update goal. Please check errors below."];
                 // NOTE: The $errors array and $editGoalData will be available locally for the JS block later
            }
        }
         // Safety rollback if transaction started but nothing happened
         if ($conn->inTransaction()) { $conn->rollBack(); }
    }

    // GET Delete Logic (Original Logic)
    elseif ($_SERVER["REQUEST_METHOD"] == "GET" && isset($_GET['delete_id'])) {
         $deleteId = filter_input(INPUT_GET, 'delete_id', FILTER_VALIDATE_INT);
         if ($deleteId && $deleteId > 0) {
             // --- Transaction and Delete (Original Logic) ---
             try {
                $conn->beginTransaction();
                // Fetch name before deleting (Original Logic)
                $fetchStmt = $conn->prepare("SELECT name FROM financial_goals WHERE id = :id");
                $fetchStmt->bindParam(':id', $deleteId, PDO::PARAM_INT);
                $fetchStmt->execute();
                $goalNameToDelete = $fetchStmt->fetchColumn();

                // Delete query (Original Logic)
                $sql = "DELETE FROM financial_goals WHERE id = :id";
                $stmt = $conn->prepare($sql);
                $stmt->bindParam(':id', $deleteId, PDO::PARAM_INT);
                $stmt->execute();
                $conn->commit();
                $deletedMsg = $goalNameToDelete ? "Goal '$goalNameToDelete' deleted." : "Goal deleted.";
                $_SESSION['flash_message'] = ['type' => 'success', 'text' => $deletedMsg];
             } catch (PDOException $e) {
                 $conn->rollBack(); // Rollback on delete error
                 $_SESSION['flash_message'] = ['type' => 'error', 'text' => "Error deleting goal."];
                 error_log("Goal Delete Error: " . $e->getMessage());
             }
             header("Location: " . $_SERVER['PHP_SELF']); // Redirect (Original Logic)
             exit;
         }
          // Note: Original code didn't explicitly handle invalid delete ID here, redirect happens anyway
    }

    // --- Fetch Goals for Display ---
    $stmt = $conn->query("SELECT * FROM financial_goals ORDER BY ISNULL(deadline) ASC, deadline ASC, name ASC"); // Original sort order
    $goalsData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Get User Name (Original Logic) ---
    $userName = $_SESSION['username'] ?? "Teja"; // Using the default from user's code

} catch (PDOException $e) {
    // --- Handle DB Errors (Original Logic) ---
    $errorMessage = "Database connection or operation failed. Please try again later."; // Original message
    error_log("Goals Page DB Error: " . $e->getMessage());
    $goalsData = []; // Clear data on error
} finally {
    // Close connection (Original Logic)
    $conn = null;
}

// --- Retrieve Flash Message (Original Logic) ---
$flashMessage = null;
if (isset($_SESSION['flash_message'])) {
    $flashMessage = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Note: $errors array is populated locally within the POST block if validation fails
// Note: $editGoalData is populated locally within the POST block if edit validation fails

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Financial Goals | Dashboard</title>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Lottie Player -->
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>

    <style>
        /* --- Base & Theme Variables (Enhanced) --- */
        :root {
            --primary-color: #0d6efd; --primary-hover: #0b5ed7; --primary-gradient: linear-gradient(135deg, #3b82f6, #0d6efd); --primary-gradient-hover: linear-gradient(135deg, #0d6efd, #0a58ca); --input-focus-border: var(--primary-color); --input-focus-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.3); /* Enhanced focus */ --background-gradient: linear-gradient(160deg, #eef5ff 0%, #f8f9fa 100%); /* Soft gradient */
            --success-color: #198754; --success-hover: #157347; --success-gradient: linear-gradient(135deg, #20c997, #198754); --success-gradient-hover: linear-gradient(135deg, #198754, #105c3a); --success-bg: #d1e7dd; --success-border: #a3cfbb;
            --danger-color: #dc3545; --danger-hover: #b02a37; --danger-bg: #f8d7da; --danger-border: #f1aeb5;
            --warning-color: #ffc107; /* Yellow for medium progress */ --warning-bg: #fff3cd; --warning-border: #ffecb5;
            --secondary-color: #6c757d; --secondary-hover: #5a6268; --light-bg: #f8f9fa; --white-bg: rgba(255, 255, 255, 0.95); --card-border-color: rgba(0, 0, 0, 0.08); --input-border-color: #ced4da; --text-color: #212529; --text-muted: #6c757d; --header-bg: #212529; /* Darker header */ --header-color: #f8f9fa; --font-family: 'Poppins', sans-serif; --border-radius: 1rem; /* Increased */ --border-radius-input: 0.6rem; /* Increased */ --box-shadow: 0 12px 35px rgba(100, 116, 139, 0.15); /* Smoother */ --input-shadow: 0 1px 3px rgba(0,0,0,0.05); --card-hover-shadow: 0 16px 45px rgba(100, 116, 139, 0.18); /* Enhanced hover */ --content-max-width: 1100px; /* Adjusted */ --input-padding-x: 1.1rem; --input-padding-y: 0.9rem; --label-default-left: calc(var(--input-padding-x) + 1.8rem); --currency-symbol-width: 1.8rem;
            /* Dark Mode Variables */
            --dark-bg: #111827; --dark-card-bg: rgba(31, 41, 55, 0.9); --dark-header-bg: #1f2937; --dark-header-color: #e5e7eb; --dark-text-color: #f3f4f6; --dark-text-muted: #9ca3af; --dark-border-color: rgba(255, 255, 255, 0.15); --dark-input-border: #4b5563; --dark-input-bg: rgba(55, 65, 81, 0.6); --dark-focus-shadow: 0 0 0 0.25rem rgba(96, 165, 250, 0.35); --dark-gradient: linear-gradient(160deg, #1f2937 0%, #111827 100%); --dark-white-bg: var(--dark-card-bg); --dark-box-shadow: 0 12px 35px rgba(0, 0, 0, 0.25); --dark-card-hover-shadow: 0 16px 45px rgba(0, 0, 0, 0.3);
        }
        html[data-theme='dark'] { /* Apply dark mode variables */
            --light-bg: var(--dark-bg); --white-bg: var(--dark-card-bg); --card-border-color: var(--dark-border-color); --input-border-color: var(--dark-input-border); --text-color: var(--dark-text-color); --text-muted: var(--dark-text-muted); --input-focus-shadow: var(--dark-focus-shadow); --background-gradient: var(--dark-gradient); --input-shadow: 0 2px 5px rgba(0,0,0,0.2); --box-shadow: var(--dark-box-shadow); --card-hover-shadow: var(--dark-card-hover-shadow); --header-bg: var(--dark-header-bg); --header-color: var(--dark-header-color); --success-bg: rgba(22, 163, 74, 0.2); --success-border: rgba(22, 163, 74, 0.5); --danger-bg: rgba(239, 68, 68, 0.2); --danger-border: rgba(239, 68, 68, 0.5); --warning-bg: rgba(255, 193, 7, 0.15); --warning-border: rgba(255, 193, 7, 0.4);
        }
        /* Global Styles & Body */
        * { box-sizing: border-box; margin: 0; padding: 0; } html { scroll-behavior: smooth; }
        body { font-family: var(--font-family); background: var(--background-gradient); color: var(--text-color); line-height: 1.7; font-size: 16px; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; padding-top: 80px; /* Space for fixed header */ min-height: 100vh; display: flex; flex-direction: column; transition: background 0.3s ease, color 0.3s ease; }
        /* Custom Scrollbar */
        body::-webkit-scrollbar { width: 8px; } body::-webkit-scrollbar-track { background: transparent; } body::-webkit-scrollbar-thumb { background-color: var(--secondary-color); border-radius: 10px; border: 2px solid transparent; background-clip: content-box; } html[data-theme='dark'] body::-webkit-scrollbar-thumb { background-color: var(--dark-text-muted); }

        /* Header / Navbar */
        .header { background-color: var(--header-bg); color: var(--header-color); padding: 1rem 2.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); flex-wrap: wrap; position: fixed; /* Stick to top */ top: 0; left: 0; width: 100%; z-index: 1050; transition: background-color 0.3s ease; }
        .header-logo { display: flex; align-items: center; gap: 0.6rem; } .header-logo i { color: var(--primary-color); font-size: 1.5rem; } .header-logo h1 { font-size: 1.5rem; font-weight: 600; margin: 0; letter-spacing: -0.5px; } .header-controls { display: flex; align-items: center; gap: 1.5rem; } .user-info { font-size: 0.9rem; opacity: 0.9; } .user-info span { margin-right: 1rem; } .user-info a { color: #ced4da; text-decoration: none; transition: color 0.2s ease; } .user-info a:hover { color: var(--header-color); } .theme-toggle-btn { background: none; border: none; color: var(--header-color); font-size: 1.3rem; cursor: pointer; padding: 0.3rem; transition: color 0.3s ease; opacity: 0.8;} .theme-toggle-btn:hover { color: var(--primary-color); opacity: 1; }

        /* Main Container & Page Header */
        .container { max-width: var(--content-max-width); margin: 2.5rem auto; padding: 0 1.5rem; flex-grow: 1; }
        .page-header { text-align: center; margin-bottom: 3rem; display: flex; flex-direction: column; align-items: center; gap: 1rem; } .lottie-container { width: 90px; height: 90px; margin-bottom: 0.5rem; } .page-title { color: var(--primary-color); margin-bottom: 0.75rem; font-size: 2.4rem; font-weight: 700; letter-spacing: -1px; } .intro-text { color: var(--text-muted); font-size: 1.1rem; max-width: 700px; margin: 0 auto; font-weight: 400; }

        /* Flash Messages & Validation Errors */
        .message-container { max-width: var(--content-max-width); margin: -1.5rem auto 1.5rem auto; padding: 0 1.5rem; }
        .flash-message { padding: 1rem 1.5rem; margin-bottom: 1rem; border-radius: var(--border-radius-input); border: 1px solid transparent; font-size: 0.95rem; display: flex; align-items: center; gap: 0.8rem; box-shadow: var(--input-shadow); background-color: var(--white-bg); backdrop-filter: blur(5px); transition: opacity 0.5s ease-out, transform 0.5s ease-out; }
        html[data-theme='dark'] .flash-message { background-color: var(--dark-card-bg); } .flash-message i { font-size: 1.3rem; flex-shrink: 0; } .flash-message.success { background-color: var(--success-bg); color: #0f5132; border-color: var(--success-border); } .flash-message.success i { color: var(--success-color); } html[data-theme='dark'] .flash-message.success { background-color: rgba(22, 163, 74, 0.2); color: #a7f3d0; border-color: rgba(22, 163, 74, 0.5); } .flash-message.error { background-color: var(--danger-bg); color: #842029; border-color: var(--danger-border); } .flash-message.error i { color: var(--danger-color); } html[data-theme='dark'] .flash-message.error { background-color: rgba(239, 68, 68, 0.2); color: #fecaca; border-color: rgba(239, 68, 68, 0.5); } .flash-message.warning { background-color: var(--warning-bg); color: #664d03; border-color: var(--warning-border); } .flash-message.warning i { color: var(--warning-color); } html[data-theme='dark'] .flash-message.warning { background-color: rgba(255, 193, 7, 0.15); color: #ffeaa7; border-color: rgba(255, 193, 7, 0.4); }
        .validation-errors { color: #842029; background-color: var(--danger-bg); border-color: var(--danger-border); padding: 1rem 1.5rem; margin-bottom: 1.5rem; border-radius: var(--border-radius-input); border: 1px solid transparent; box-shadow: var(--input-shadow); }
        html[data-theme='dark'] .validation-errors { background-color: rgba(239, 68, 68, 0.2); color: #fecaca; border-color: rgba(239, 68, 68, 0.5); } .validation-errors strong { display: block; margin-bottom: 0.5rem; font-weight: 600; } .validation-errors ul { margin: 0; padding-left: 1.2rem; } .validation-errors li { margin-bottom: 0.3rem; font-size: 0.9rem; }

        /* Goals Grid & Cards */
        .goals-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.8rem; list-style: none; padding: 0; }
        .goal-card { background-color: var(--white-bg); backdrop-filter: blur(12px); border-radius: var(--border-radius); box-shadow: var(--box-shadow); border: 1px solid var(--card-border-color); padding: 1.5rem 1.8rem; display: flex; flex-direction: column; transition: transform 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94), box-shadow 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94), background-color 0.3s ease; position: relative; overflow: hidden; }
        .goal-card:hover { transform: translateY(-6px) scale(1.015); box-shadow: var(--card-hover-shadow); } /* Enhanced hover */
        html[data-theme='dark'] .goal-card { background-color: var(--dark-card-bg); }
        .goal-card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; gap: 0.5rem; }
        .goal-card-header h3 { color: var(--text-color); margin: 0; font-size: 1.25rem; font-weight: 600; line-height: 1.4; flex-grow: 1; display: flex; align-items: center; gap: 0.6rem; /* Space for icon */ }
        .goal-icon { font-size: 1.1em; /* Slightly larger icon */ color: var(--primary-color); opacity: 0.85; width: 20px; text-align: center;} /* Goal Icon Style */
        .goal-actions { font-size: 0.9em; white-space: nowrap; flex-shrink: 0; display: flex; align-items: center; }
        .goal-actions .action-btn { background: none; border: none; cursor: pointer; padding: 5px; font-size: 1.1rem; color: var(--text-muted); vertical-align: middle; margin-left: 0.4rem; opacity: 0.7; transition: opacity 0.2s, color 0.2s, transform 0.2s ease-out; }
        .goal-actions .action-btn:hover { opacity: 1; transform: scale(1.15) rotate(-5deg); } .goal-actions .action-btn.edit:hover { color: var(--primary-color); } .goal-actions .action-btn.delete:hover { color: var(--danger-color); }
        .goal-details { margin-bottom: 1rem; font-size: 0.95rem; /* Kept size */ color: var(--text-muted); flex-grow: 1; }
        .goal-details .amount-line { margin-bottom: 0.6rem; font-size: 1rem; } .goal-details strong { color: var(--text-color); font-weight: 600; } .goal-amount-current { color: var(--success-color); } .goal-amount-target { color: var(--text-color); } .goal-details .fa-check-circle { color: var(--success-color); margin-left: 5px; animation: popIn 0.5s ease-out; }
        .goal-deadline { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; /* Smaller meta text */ margin-top: 0.5rem; opacity: 0.9; } .goal-deadline i { font-size: 0.9em; opacity: 0.7; } .goal-deadline .date { font-weight: 500; color: var(--text-color); } .goal-deadline .no-deadline { font-style: italic; opacity: 0.7;}

        /* Progress Bar Footer */
        .goal-card-footer { margin-top: auto; padding-top: 1rem; border-top: 1px solid var(--card-border-color); transition: border-color 0.3s ease;} html[data-theme='dark'] .goal-card-footer { border-top-color: var(--dark-border-color); }
        .progress-info { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; font-size: 0.9rem; color: var(--text-muted); }
        .progress-label { font-weight: 500; display: flex; align-items: center; gap: 0.4rem;}
        .progress-emoji { font-size: 1.1em; } /* Emoji feedback */
        .progress-percentage { font-weight: 600; color: var(--primary-color); display: none; /* Hide old percentage, use label on bar */ }
        /* New Progress Bar Style */
        .progress-bar-container { background-color: rgba(0, 0, 0, 0.07); border-radius: 50px; height: 22px; /* Taller */ overflow: hidden; position: relative; width: 100%; transition: background-color 0.3s ease; display: flex; align-items: center; padding: 0 2px; }
        html[data-theme='dark'] .progress-bar-container { background-color: rgba(255, 255, 255, 0.1); }
        .progress-bar { height: calc(100% - 4px); border-radius: 50px; transition: width 0.8s cubic-bezier(0.65, 0, 0.35, 1), background-color 0.5s ease; display: flex; align-items: center; justify-content: flex-end; padding-right: 8px; position: relative; }
        /* Color coding classes */
        .progress-bar.low { background-color: var(--danger-color); } /* Red */
        .progress-bar.medium { background-color: var(--warning-color); } /* Yellow */
        .progress-bar.high { background-color: var(--success-color); } /* Green */
        /* Label inside bar */
        .progress-bar-label { font-size: 0.75rem; font-weight: 600; color: rgba(255, 255, 255, 0.9); /* White label */ line-height: 1; position: absolute; right: 10px; top: 50%; transform: translateY(-50%); mix-blend-mode: difference; filter: invert(1) grayscale(1) contrast(100); }
        .progress-bar.low .progress-bar-label, .progress-bar.medium .progress-bar-label { color: rgba(0,0,0,0.7); mix-blend-mode: normal; filter: none;} /* Darker label on light bars */

        /* Add Goal Button */
        .add-goal-section { margin: 3rem 0 2rem 0; text-align: center; }
        .button-add { display: inline-flex; align-items: center; gap: 0.6rem; background-image: var(--success-gradient); color: #fff; padding: 0.8rem 1.8rem; border: none; border-radius: 50px; text-decoration: none; font-size: 1.05rem; font-weight: 500; cursor: pointer; transition: all 0.25s ease-in-out; box-shadow: 0 4px 12px rgba(25, 135, 84, 0.25); background-size: 150% auto; } .button-add i { font-size: 1em; } .button-add:hover { background-image: var(--success-gradient-hover); box-shadow: 0 8px 20px rgba(25, 135, 84, 0.3); transform: translateY(-3px) scale(1.03); background-position: right center; } .button-add:active { transform: translateY(0) scale(0.98); box-shadow: 0 2px 5px rgba(25, 135, 84, 0.2); }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 1060; /* Higher z-index */ left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5); backdrop-filter: blur(6px); }
        .modal-content { background-color: var(--white-bg); backdrop-filter: blur(15px); margin: 5% auto; padding: 30px 35px; border: 1px solid var(--card-border-color); width: 90%; max-width: 580px; border-radius: var(--border-radius); position: relative; box-shadow: 0 15px 50px rgba(0,0,0,0.25); animation: slideDown 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94); transition: background-color 0.3s ease, border-color 0.3s ease; }
        html[data-theme='dark'] .modal-content { background-color: var(--dark-white-bg); border-color: var(--dark-border-color); } @keyframes slideDown { from { opacity: 0; transform: translateY(-25px); } to { opacity: 1; transform: translateY(0); } }
        .modal-close { color: var(--text-muted); position: absolute; top: 15px; right: 20px; font-size: 2rem; line-height: 1; font-weight: bold; cursor: pointer; transition: color 0.2s ease; } .modal-close:hover, .modal-close:focus { color: var(--danger-color); text-decoration: none; } .modal h2 { margin-top: 0; margin-bottom: 2rem; font-size: 1.6rem; font-weight: 600; color: var(--primary-color); text-align: center; }

        /* Modal Form */
        .modal-form .form-group { position: relative; margin-bottom: 1.8rem; margin-top: 0.8rem; } .modal-form .input-with-icon { position: relative; } .modal-form .input-icon { position: absolute; left: var(--input-padding-x); top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 0.95rem; pointer-events: none; opacity: 0.6; transition: color 0.2s ease, opacity 0.2s ease; } .modal-form .form-control:focus ~ .input-icon { color: var(--primary-color); opacity: 1; }
        .modal-form label { position: absolute; left: var(--label-default-left); top: 50%; transform: translateY(-50%); font-weight: 500; color: var(--text-muted); background-color: transparent; padding: 0 0.3rem; transition: all 0.2s ease-out, background-color 0.3s ease; pointer-events: none; font-size: 1rem; white-space: nowrap; }
        .modal-form label[for="modal_goal_target"], .modal-form label[for="modal_edit_current"] { left: calc(var(--currency-symbol-width) + var(--input-padding-x)); }
        .modal-form .form-control { width: 100%; padding: var(--input-padding-y) var(--input-padding-x); border: 1px solid var(--input-border-color); border-radius: var(--border-radius-input); font-size: 1rem; font-family: inherit; transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.3s ease, color 0.3s ease; background-color: var(--white-bg); /* Use variable */ color: var(--text-color); box-shadow: var(--input-shadow); position: relative; z-index: 0; padding-left: var(--label-default-left); }
        .modal-form input#modal_goal_target, .modal-form input#modal_edit_current { padding-left: calc(var(--currency-symbol-width) + var(--input-padding-x)); }
        html[data-theme='dark'] .modal-form .form-control { background-color: var(--dark-input-bg); } .modal-form .form-control::placeholder { color: transparent; }
        .modal-form .form-control:focus + label, .modal-form .form-control:not(:placeholder-shown) + label { top: 0; transform: translateY(-50%) scale(0.85); font-weight: 600; color: var(--primary-color); background-color: var(--white-bg); border-radius: 4px; z-index: 1; left: var(--input-padding-x); padding: 0 0.4rem; }
        html[data-theme='dark'] .modal-form .form-control:focus + label, html[data-theme='dark'] .modal-form .form-control:not(:placeholder-shown) + label { background-color: var(--dark-white-bg); }
        .modal-form .form-control:focus { border-color: var(--input-focus-border); outline: 0; box-shadow: var(--input-focus-shadow), var(--input-shadow); z-index: 2; } /* Enhanced focus */
        .modal-form input[type="number"] { appearance: textfield; -moz-appearance: textfield;}
        .modal-form .form-control.is-invalid { border-color: var(--danger-color) !important; } .modal-form .form-control.is-invalid:focus { box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25), var(--input-shadow); } .modal-form .form-control.is-invalid ~ .input-icon { color: var(--danger-color); opacity: 1;} .error-message { color: var(--danger-color); font-size: 0.875em; margin-top: 0.3rem; display: block; min-height: 1em; padding-left: 5px; }

        /* Modal Buttons */
        .modal-buttons { display: flex; justify-content: flex-end; gap: 0.8rem; margin-top: 2rem; border-top: 1px solid var(--card-border-color); padding-top: 1.5rem; transition: border-color 0.3s ease; } html[data-theme='dark'] .modal-buttons { border-top-color: var(--dark-border-color); }
        .button { /* Base button styles */ display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem; padding: 0.7rem 1.5rem; font-size: 1rem; border-radius: var(--border-radius-input); border: none; cursor: pointer; transition: all 0.25s ease-in-out; white-space: nowrap; letter-spacing: 0.5px; text-transform: uppercase; font-weight: 600; position: relative; overflow: hidden; }
        .button:hover:not(:disabled) { transform: translateY(-2px) scale(1.02); } .button:active:not(:disabled) { transform: translateY(0) scale(0.98); } .button:disabled { opacity: 0.65; cursor: not-allowed; transform: none; background-image: none !important; background-color: var(--secondary-color) !important; box-shadow: none !important;}
        .button-primary { background-image: var(--primary-gradient); color: #fff; box-shadow: 0 4px 15px rgba(13, 110, 253, 0.25);} .button-primary:hover:not(:disabled) { background-image: var(--primary-gradient-hover); box-shadow: 0 6px 20px rgba(13, 110, 253, 0.3); }
        .button-success { background-image: var(--success-gradient); color: #fff; box-shadow: 0 4px 15px rgba(25, 135, 84, 0.25); } .button-success:hover:not(:disabled) { background-image: var(--success-gradient-hover); box-shadow: 0 6px 20px rgba(25, 135, 84, 0.3); }
        .button-secondary { background-color: transparent; color: var(--secondary-color); border: 1px solid var(--card-border-color); box-shadow: none; } .button-secondary:hover:not(:disabled) { background-color: rgba(0,0,0,0.03); border-color: var(--secondary-color); } html[data-theme='dark'] .button-secondary { color: var(--dark-text-muted); border-color: var(--dark-border-color); } html[data-theme='dark'] .button-secondary:hover:not(:disabled) { background-color: rgba(255,255,255,0.05); border-color: var(--dark-text-color); color: var(--dark-text-color); }

        /* Empty State */
        .empty-state { text-align: center; padding: 4rem 2rem; background-color: var(--white-bg); border-radius: var(--border-radius); border: 1px dashed var(--card-border-color); /* Adjusted border */ margin-top: 2rem; backdrop-filter: blur(5px); transition: background-color 0.3s ease, border-color 0.3s ease; } html[data-theme='dark'] .empty-state { background-color: var(--dark-card-bg); border-color: var(--dark-border-color); } .empty-state i { font-size: 3.5rem; color: var(--primary-color); opacity: 0.5; margin-bottom: 1.5rem; display: block; } .empty-state p { font-size: 1.2rem; color: var(--text-muted); margin-bottom: 1.5rem; }

        /* Back Link */
        .back-link-container { text-align: center; margin-top: 2.5rem; margin-bottom: 1rem; } .back-link { color: var(--secondary-color); text-decoration: none; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 0.3rem; transition: color 0.2s, background-color 0.2s; padding: 0.5rem 1rem; border-radius: var(--border-radius-input); } .back-link:hover { color: var(--primary-color); background-color: rgba(13, 110, 253, 0.1); } html[data-theme='dark'] .back-link:hover { background-color: rgba(59, 130, 246, 0.15); }

        /* Footer */
        .footer { text-align: center; margin-top: auto; padding: 1.5rem 1rem; font-size: 0.9rem; color: var(--text-muted); border-top: 1px solid var(--card-border-color); background-color: rgba(255, 255, 255, 0.7); backdrop-filter: blur(5px); transition: background-color 0.3s ease, border-color 0.3s ease;} html[data-theme='dark'] .footer { background-color: rgba(31, 41, 55, 0.7); border-top-color: var(--dark-border-color); }

        /* Animation Keyframes */
        @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 60% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(1); } }

        /* Responsiveness */
        @media (max-width: 992px) { /* ... */ body { padding-top: 70px; } }
        @media (max-width: 768px) { /* ... */ body { padding-top: 65px; } .header-controls .user-info span { display: none; } .header-controls { gap: 1rem; } }
        @media (max-width: 576px) { /* ... */ body { padding-top: 110px; } .header { flex-direction: column; align-items: flex-start; } .header-logo { margin-bottom: 0.5rem; width: 100%;} .header-controls { width: 100%; justify-content: space-between; margin-top: 0.5rem;} .user-info { margin-right: auto; } .lottie-container { width: 70px; height: 70px; } .goal-card-header { flex-direction: column; } .goal-card-header h3 { font-size: 1.2rem; } .goal-actions { margin-top: 0.3rem; } .modal-content { padding: 25px; margin: 5% auto;} .modal h2 { font-size: 1.4rem; } .modal-buttons { flex-direction: column-reverse; gap: 0.6rem; align-items: stretch; } .modal-buttons .button { width: 100%; }
            .modal-form label { left: var(--label-default-left); } .modal-form .form-control { padding-left: var(--label-default-left); } .modal-form label[for="modal_goal_target"], .modal-form label[for="modal_edit_current"] { left: calc(var(--currency-symbol-width) + var(--input-padding-x));} .modal-form input#modal_goal_target, .modal-form input#modal_edit_current { padding-left: calc(var(--currency-symbol-width) + var(--input-padding-x));} .modal-form .form-control:focus + label, .modal-form .form-control:not(:placeholder-shown) + label { left: var(--input-padding-x); }
        }
    </style>
</head>
<body>

    <header class="header">
        <a href="dashboard.php" class="header-logo" style="text-decoration: none; color: inherit;">
             <i class="fas fa-piggy-bank"></i><h1>FinGoals</h1> <!-- Changed Title & Icon -->
        </a>
        <div class="header-controls">
            <div class="user-info"><span>Welcome, <?php echo htmlspecialchars($userName); ?>!</span></div>
            <button id="theme-toggle" class="theme-toggle-btn" title="Toggle theme"><i class="fas fa-moon"></i></button> <!-- Dark Mode Toggle -->
            <a href="logout.php" title="Logout" style="color: #ced4da; text-decoration:none; display: inline-flex; align-items: center; gap: 0.3rem;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

     <div class="message-container">
        <?php if ($errorMessage): ?><div class="flash-message error"><i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($errorMessage); ?></span></div><?php endif; ?>
        <?php if ($flashMessage): ?><div class="flash-message <?php echo htmlspecialchars($flashMessage['type']); ?>"><?php if ($flashMessage['type'] == 'success'): ?><i class="fas fa-check-circle"></i><?php elseif ($flashMessage['type'] == 'error'): ?><i class="fas fa-times-circle"></i><?php elseif ($flashMessage['type'] == 'warning'): ?><i class="fas fa-exclamation-triangle"></i><?php endif; ?><span><?php echo htmlspecialchars($flashMessage['text']); ?></span></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="validation-errors"><strong><i class="fas fa-exclamation-triangle"></i> Please fix errors:</strong><ul><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>
     </div>

    <div class="container">
        <div class="page-header">
             <div class="lottie-container"><lottie-player src="https://assets4.lottiefiles.com/packages/lf20_v7gj8hb1.json" background="transparent" speed="1" style="width: 100%; height: 100%;" loop autoplay title="Financial goal icon"></lottie-player></div> <!-- Lottie Animation -->
            <h1 class="page-title">My Financial Goals</h1>
            <p class="intro-text">Set targets, track savings, visualize progress, and achieve your financial aspirations.</p> <!-- Slightly updated text -->
        </div>

        <?php if (empty($goalsData) && !$errorMessage): ?>
            <div class="empty-state"><i class="fas fa-bullseye"></i><p>No goals yet.</p><button class="button-add" onclick="openGoalModal('add')"><i class="fas fa-plus"></i> Add First Goal</button></div>
        <?php elseif (!empty($goalsData)): ?>
            <ul class="goals-grid">
                <?php foreach ($goalsData as $goal): ?>
                    <?php
                        $target = floatval($goal['target']);
                        $current = floatval($goal['current']);
                        $progress = ($target > 0) ? round(($current / $target) * 100) : 0;
                        $progress = min(100, max(0, $progress));
                        $deadlineFormatted = $goal['deadline'] ? date("M j, Y", strtotime($goal['deadline'])) : null;
                        $isComplete = ($current >= $target && $target > 0);
                        // --- UI Enhancement Data ---
                        $progressClass = $progress < 30 ? 'low' : ($progress < 75 ? 'medium' : 'high'); // Color coding class
                        // Basic icon detection
                        $goalIcon = 'fa-bullseye'; $goalNameLower = strtolower($goal['name']);
                        if (strpos($goalNameLower, 'car') !== false) $goalIcon = 'fa-car'; elseif (strpos($goalNameLower, 'house') !== false || strpos($goalNameLower, 'home') !== false) $goalIcon = 'fa-home'; elseif (strpos($goalNameLower, 'vacation') !== false || strpos($goalNameLower, 'travel') !== false) $goalIcon = 'fa-plane-departure'; elseif (strpos($goalNameLower, 'emergency') !== false) $goalIcon = 'fa-briefcase-medical'; elseif (strpos($goalNameLower, 'bike') !== false || strpos($goalNameLower, 'motorcycle') !== false) $goalIcon = 'fa-motorcycle'; elseif (strpos($goalNameLower, 'invest') !== false) $goalIcon = 'fa-chart-line'; elseif (strpos($goalNameLower, 'debt') !== false) $goalIcon = 'fa-credit-card';
                        // Emoji feedback
                        $progressEmoji = $progress < 10 ? '😟' : ($progress < 50 ? '😐' : ($progress < 90 ? '🙂' : ($progress < 100 ? '😃' : '🥳')));
                    ?>
                    <li class="goal-card <?php echo $isComplete ? 'goal-complete' : ''; ?>">
                        <div class="goal-card-header">
                             <h3><i class="fas <?php echo $goalIcon; ?> goal-icon"></i> <?php echo htmlspecialchars($goal['name']); ?></h3>
                            <span class="goal-actions">
                                <button class="action-btn edit" title="Edit Goal" data-id="<?php echo $goal['id']; ?>" data-name="<?php echo htmlspecialchars($goal['name'], ENT_QUOTES); ?>" data-target="<?php echo $target; ?>" data-current="<?php echo $current; ?>" data-deadline="<?php echo htmlspecialchars($goal['deadline'] ?? ''); ?>" onclick="openGoalModal('edit', this)"><i class="fas fa-edit"></i></button>
                                <a href="?delete_id=<?php echo $goal['id']; ?>" class="action-btn delete" title="Delete Goal" onclick="return confirm('Delete goal \'<?php echo htmlspecialchars(addslashes($goal['name']), ENT_QUOTES); ?>\'?');"><i class="fas fa-trash-alt"></i></a>
                            </span>
                        </div>
                        <div class="goal-details">
                            <div class="amount-line"> Saved: <strong class="goal-amount-current"><?php echo formatCurrency($current, $currencySymbol); ?></strong> / <span class="goal-amount-target"><?php echo formatCurrency($target, $currencySymbol); ?></span> <?php if($isComplete): ?><i class="fas fa-check-circle" title="Goal Achieved!"></i><?php endif; ?> </div>
                            <div class="goal-deadline"> <i class="far fa-calendar-alt"></i> <?php if ($deadlineFormatted): ?>Target: <span class="date"><?php echo $deadlineFormatted; ?></span><?php else: ?><span class="no-deadline">No target date</span><?php endif; ?> </div>
                        </div>
                        <div class="goal-card-footer">
                            <div class="progress-info"> <span class="progress-label"><span class="progress-emoji"><?php echo $progressEmoji; ?></span> Progress</span> </div>
                            <div class="progress-bar-container"> <div class="progress-bar <?php echo $progressClass; ?>" style="width: <?php echo $progress; ?>%;"><span class="progress-bar-label"><?php echo $progress; ?>%</span></div> </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
         <?php endif; ?>

         <?php if (!$errorMessage): // Show Add button if DB connection ok ?>
             <div class="add-goal-section"> <button class="button-add" onclick="openGoalModal('add')"><i class="fas fa-plus"></i> Add New Goal</button> </div>
         <?php endif; ?>

        <div class="back-link-container"><a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></div>
    </div>

    <!-- Goal Add/Edit Modal -->
    <div id="goalModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeGoalModal()" title="Close">×</span><h2 id="modalTitle">Add Goal</h2>
            <form id="goalForm" method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="modal-form" novalidate onsubmit="return validateGoalForm();">
                <input type="hidden" name="edit_id" id="edit_id">
                <div class="form-group"><div class="input-with-icon"><span class="input-icon"><i class="fas fa-flag-checkered"></i></span><input type="text" id="modal_goal_name" name="goal_name" required maxlength="255" placeholder=" " class="form-control" title="e.g., Emergency Fund"></div><label for="modal_goal_name">Goal Name:</label><span class="error-message" id="modalNameError"></span></div>
                <div class="form-group"><div class="input-with-icon"><span class="input-icon"><?php echo htmlspecialchars($currencySymbol); ?></span><input type="number" id="modal_goal_target" name="goal_target" required step="0.01" min="0.01" placeholder=" " class="form-control" title="Total needed"></div><label for="modal_goal_target">Target Amount:</label><span class="error-message" id="modalTargetError"></span></div>
                <div class="form-group" id="current_amount_group" style="display: none;"><div class="input-with-icon"><span class="input-icon"><i class="fas fa-coins"></i></span><input type="number" id="modal_edit_current" name="edit_current" step="0.01" min="0" placeholder=" " class="form-control" title="Amount saved"></div><label for="modal_edit_current">Current Amount Saved:</label><span class="error-message" id="modalCurrentError"></span></div>
                <div class="form-group"><div class="input-with-icon"><span class="input-icon"><i class="far fa-calendar-alt"></i></span><input type="date" id="modal_goal_deadline" name="goal_deadline" min="<?php echo date('Y-m-d'); ?>" placeholder=" " class="form-control" title="Target date (Optional)"></div><label for="modal_goal_deadline">Target Deadline (Opt.):</label><span class="error-message" id="modalDeadlineError"></span></div>
                <div class="modal-buttons"><button type="button" class="button button-secondary" onclick="closeGoalModal()">Cancel</button><button type="submit" class="button" id="modalSubmitButton" name="add_goal">Add Goal</button></div>
            </form>
        </div>
    </div>

    <footer class="footer">© <?php echo date('Y'); ?> Financial Wellness Hub. Track and achieve your goals.</footer>

    <script>
        // --- Core Modal JS (Original Logic) ---
        const modal = document.getElementById('goalModal');
        const modalTitle = document.getElementById('modalTitle');
        const goalForm = document.getElementById('goalForm');
        const editIdInput = document.getElementById('edit_id');
        const nameInput = document.getElementById('modal_goal_name');
        const targetInput = document.getElementById('modal_goal_target');
        const currentGroup = document.getElementById('current_amount_group');
        const currentInput = document.getElementById('modal_edit_current');
        const deadlineInput = document.getElementById('modal_goal_deadline');
        const submitButton = document.getElementById('modalSubmitButton');
        const nameErrorEl = document.getElementById('modalNameError');
        const targetErrorEl = document.getElementById('modalTargetError');
        const currentErrorEl = document.getElementById('modalCurrentError');
        const deadlineErrorEl = document.getElementById('modalDeadlineError');
        const today = new Date().toISOString().split('T')[0];
        if (deadlineInput) deadlineInput.setAttribute('min', today);

        // --- Floating Label Logic (Enhanced from previous version) ---
        const handleModalLabelFloat = (inputElement) => { /* ... robust logic ... */
             const group = inputElement.closest('.form-group'); if (!group) return; const label = group.querySelector('label'); if (!label) return;
             const isFilled = inputElement.value.trim() !== ''; const isActive = document.activeElement === inputElement; const isAutofilled = inputElement.matches(':autofill'); const shouldFloat = isFilled || isActive || isAutofilled || inputElement.type === 'date';
             const modalContent = modal.querySelector('.modal-content'); const bgColor = modalContent ? getComputedStyle(modalContent).getPropertyValue('background-color').trim() : 'var(--white-bg)';
             if (shouldFloat) { label.style.top = '0'; label.style.transform = 'translateY(-50%) scale(0.85)'; label.style.fontWeight = '600'; label.style.color = 'var(--primary-color)'; label.style.backgroundColor = bgColor; label.style.zIndex = '1'; label.style.left = 'var(--input-padding-x)'; label.style.padding = '0 0.4rem';
             } else { label.style.top = '50%'; label.style.transform = 'translateY(-50%)'; label.style.fontWeight = '500'; label.style.color = 'var(--text-muted)'; label.style.backgroundColor = 'transparent'; label.style.zIndex = 'auto'; label.style.padding = '0 0.3rem'; const inputIcon = group.querySelector('.input-icon'); if (inputIcon && (inputElement.id === 'modal_goal_target' || inputElement.id === 'modal_edit_current')) { label.style.left = 'calc(var(--currency-symbol-width) + var(--input-padding-x))'; } else if (inputIcon) { label.style.left = 'var(--label-default-left)'; } else { label.style.left = 'var(--input-padding-x)'; } }
        };
        document.querySelectorAll('#goalModal .form-control').forEach(input => { input.addEventListener('focus', () => handleModalLabelFloat(input)); input.addEventListener('blur', () => handleModalLabelFloat(input)); input.addEventListener('input', () => handleModalLabelFloat(input)); if (input.type === 'date') { input.addEventListener('change', () => handleModalLabelFloat(input)); } setTimeout(() => handleModalLabelFloat(input), 100); });
        function clearModalErrors() { /* ... same logic ... */ nameErrorEl.textContent = ''; nameInput.classList.remove('is-invalid'); targetErrorEl.textContent = ''; targetInput.classList.remove('is-invalid'); currentErrorEl.textContent = ''; currentInput.classList.remove('is-invalid'); deadlineErrorEl.textContent = ''; deadlineInput.classList.remove('is-invalid'); document.querySelectorAll('#goalModal .form-control').forEach(input => { handleModalLabelFloat(input); }); }
        function forceLabelFloat(inputElement) { setTimeout(() => handleModalLabelFloat(inputElement), 50); }

        // --- Open/Close Modals (Original Logic) ---
        function openGoalModal(mode, buttonElement = null) { /* ... same logic ... */
             goalForm.reset(); clearModalErrors(); editIdInput.value = ''; currentGroup.style.display = 'none'; currentInput.removeAttribute('required');
             nameInput.name = 'goal_name'; targetInput.name = 'goal_target'; deadlineInput.name = 'goal_deadline'; submitButton.name = 'add_goal';
             submitButton.disabled = false; // Re-enable button
             submitButton.className = 'button button-success'; // Default class
             submitButton.textContent = 'Add Goal'; // Default text

             if (mode === 'add') { modalTitle.textContent = 'Add New Goal'; /* Default settings applied above */ }
             else if (mode === 'edit' && buttonElement?.dataset.id) {
                 modalTitle.textContent = 'Edit Goal'; submitButton.textContent = 'Update Goal'; submitButton.name = 'edit_goal'; submitButton.className = 'button button-primary';
                 const goal = buttonElement.dataset; editIdInput.value = goal.id; nameInput.value = goal.name; targetInput.value = parseFloat(goal.target).toFixed(2); currentInput.value = parseFloat(goal.current).toFixed(2); deadlineInput.value = goal.deadline || '';
                 currentGroup.style.display = 'block'; currentInput.setAttribute('required', '');
                 nameInput.name = 'edit_name'; targetInput.name = 'edit_target'; deadlineInput.name = 'edit_deadline';
                 forceLabelFloat(nameInput); forceLabelFloat(targetInput); forceLabelFloat(currentInput); if (deadlineInput.value) forceLabelFloat(deadlineInput);
             } else { console.error("Invalid mode/data"); return; }
             modal.style.display = 'block'; setTimeout(() => nameInput?.focus(), 150);
         }
        function closeGoalModal() { modal.style.display = 'none'; }
        window.addEventListener('click', (event) => { if (event.target == modal) closeGoalModal(); });
        document.addEventListener('keydown', (event) => { if (event.key === "Escape" && modal.style.display === 'block') closeGoalModal(); });

        // --- Client-Side Validation (Original Logic) ---
        function validateGoalForm() { /* ... same validation logic ... */
            let isValid = true; clearModalErrors();
            if (nameInput.value.trim() === '') { nameErrorEl.textContent = 'Name required.'; nameInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(nameInput); }
            const targetVal = parseFloat(targetInput.value); if (isNaN(targetVal) || targetVal <= 0) { targetErrorEl.textContent = 'Positive target required.'; targetInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(targetInput); }
            if (currentGroup.style.display === 'block') { const currentVal = parseFloat(currentInput.value); if (isNaN(currentVal) || currentVal < 0) { currentErrorEl.textContent = 'Current cannot be negative.'; currentInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(currentInput);} else if (targetVal > 0 && currentVal > targetVal) { currentErrorEl.textContent = 'Current cannot exceed target.'; currentInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(currentInput); } }
            const deadlineVal = deadlineInput.value; if (deadlineVal) { const selectedDate = new Date(deadlineVal + 'T00:00:00'); const todayDate = new Date(); todayDate.setHours(0, 0, 0, 0); if (isNaN(selectedDate.getTime())) { deadlineErrorEl.textContent = 'Invalid date.'; deadlineInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(deadlineInput); } else if (selectedDate < todayDate) { deadlineErrorEl.textContent = 'Deadline cannot be past.'; deadlineInput.classList.add('is-invalid'); isValid = false; forceLabelFloat(deadlineInput); } }
            // Added focus logic from previous good version
            if (!isValid) { const firstInvalid = goalForm.querySelector('.is-invalid'); if(firstInvalid) { firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(() => firstInvalid.focus(), 300); } }
            return isValid;
        }

        // --- Refill Form on Server Error (Using Original PHP Error Variables) ---
        <?php if (!empty($errors) && isset($_POST['refill_action'])): ?>
        document.addEventListener('DOMContentLoaded', () => {
            const action = '<?php echo $_POST['refill_action']; ?>';
             // Check if edit failed and $editGoalData was set
            <?php if ($_POST['refill_action'] === 'edit' && isset($editGoalData)): ?>
                 const refillData = <?php echo json_encode($editGoalData); ?>;
                 const simulatedButton = { dataset: { id: refillData.id, name: refillData.name, target: refillData.target, current: refillData.current, deadline: refillData.deadline || '' }};
                 openGoalModal('edit', simulatedButton);
            <?php elseif ($_POST['refill_action'] === 'add'): ?>
                 openGoalModal('add'); // Open add modal first
                 // Refill from $_POST directly (as per original logic flow)
                 nameInput.value = '<?php echo htmlspecialchars($_POST['goal_name'] ?? '', ENT_QUOTES); ?>';
                 targetInput.value = '<?php echo htmlspecialchars($_POST['goal_target'] ?? '', ENT_QUOTES); ?>';
                 deadlineInput.value = '<?php echo htmlspecialchars($_POST['goal_deadline'] ?? '', ENT_QUOTES); ?>';
                 // Force float labels if values exist after refill
                 if(nameInput.value) forceLabelFloat(nameInput); if(targetInput.value) forceLabelFloat(targetInput); if(deadlineInput.value) forceLabelFloat(deadlineInput);
            <?php endif; ?>

            // Display validation errors from the PHP $errors array
            <?php if (!empty($errors)): ?>
                 const errorsFromServer = <?php echo json_encode($errors); ?>;
                 // Assume errors is a simple array of strings as per original PHP
                 errorsFromServer.forEach(msg => {
                      if (msg.toLowerCase().includes('name')) { nameErrorEl.textContent = msg; nameInput.classList.add('is-invalid'); forceLabelFloat(nameInput); }
                      else if (msg.toLowerCase().includes('target')) { targetErrorEl.textContent = msg; targetInput.classList.add('is-invalid'); forceLabelFloat(targetInput); }
                      else if (msg.toLowerCase().includes('current')) { currentErrorEl.textContent = msg; currentInput.classList.add('is-invalid'); forceLabelFloat(currentInput); }
                      else if (msg.toLowerCase().includes('deadline')) { deadlineErrorEl.textContent = msg; deadlineInput.classList.add('is-invalid'); forceLabelFloat(deadlineInput); }
                      // Add more checks if needed based on exact error messages
                 });
                 // Focus first error
                 const firstInvalid = goalForm.querySelector('.is-invalid'); if(firstInvalid) { firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(() => firstInvalid.focus(), 300); }
             <?php endif; ?>
        });
        <?php endif; ?>

        // --- Dark Mode Toggle Logic ---
        const themeToggle = document.getElementById('theme-toggle'); const htmlElement = document.documentElement; const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null; const sunIconClass = 'fas fa-sun'; const moonIconClass = 'fas fa-moon';
        const setMode = (isDark) => { const iconElement = themeToggle?.querySelector('i'); if (isDark) { htmlElement.setAttribute('data-theme', 'dark'); localStorage.setItem('theme', 'dark'); if (iconElement) iconElement.className = sunIconClass; if (themeToggle) themeToggle.title = 'Switch to light'; } else { htmlElement.removeAttribute('data-theme'); localStorage.setItem('theme', 'light'); if (iconElement) iconElement.className = moonIconClass; if (themeToggle) themeToggle.title = 'Switch to dark'; } /* No chart rendering needed */ if (modal.style.display === 'block') { document.querySelectorAll('#goalModal .form-control').forEach(input => { handleModalLabelFloat(input); }); } };
        if (themeToggle) { const isInitiallyDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches; const savedTheme = localStorage.getItem('theme'); const initialIconClass = ((savedTheme === 'dark') || (!savedTheme && isInitiallyDark)) ? sunIconClass : moonIconClass; themeToggle.querySelector('i').className = initialIconClass; if ((savedTheme === 'dark') || (!savedTheme && isInitiallyDark)) { htmlElement.setAttribute('data-theme', 'dark'); themeToggle.title = 'Switch to light'; } else { htmlElement.removeAttribute('data-theme'); themeToggle.title = 'Switch to dark'; } themeToggle.addEventListener('click', () => { setMode(!htmlElement.hasAttribute('data-theme')); }); } else { const isInitiallyDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches; if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && isInitiallyDark)) { htmlElement.setAttribute('data-theme', 'dark'); } }

        // --- Flash Message Auto-Hide ---
        const flashMessageDiv = document.getElementById('flash-message'); if (flashMessageDiv) { setTimeout(() => { flashMessageDiv.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out'; flashMessageDiv.style.opacity = '0'; flashMessageDiv.style.transform = 'translateY(-10px)'; flashMessageDiv.addEventListener('transitionend', () => flashMessageDiv.remove(), { once: true }); }, 5000); }

    </script>

</body>
</html>