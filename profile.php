<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['firstName'] = 'Richard';
    $_SESSION['lastName'] = 'Hendricks';
    $_SESSION['email'] = 'richard@fusionauth.io';
    if (!isset($_SESSION['simulated_login'])) {
         error_log("Profile page accessed without session - using dummy data for development.");
         $_SESSION['simulated_login'] = true;
    }
}


$servername = "localhost";
$username = "root";
$dbPassword = "";
$dbname = "login";

$conn = new mysqli($servername, $username, $dbPassword, $dbname);
$dbErrorMessage = '';
if ($conn->connect_error) {
    error_log("Profile Connection failed: " . $conn->connect_error);
    $dbErrorMessage = "Database connection error. Please try again later.";
}

$userId = $_SESSION['user_id'];
$currentFirstName = $_SESSION['firstName'] ?? 'User';
$currentLastName = $_SESSION['lastName'] ?? '';
$currentEmail = $_SESSION['email'] ?? 'Not Available';
$currentHashedPassword = '';

$errors = [];
$successMessage = '';

if ($conn && !$conn->connect_error) {
    $sqlFetch = "SELECT firstName, lastName, email, password FROM users WHERE id = ?";
    $stmtFetch = $conn->prepare($sqlFetch);
    if ($stmtFetch) {
        $stmtFetch->bind_param("i", $userId);
        if ($stmtFetch->execute()) {
             $stmtFetch->store_result();
             if ($stmtFetch->num_rows === 1) {
                 $stmtFetch->bind_result($dbFirstName, $dbLastName, $dbEmail, $currentHashedPassword);
                 $stmtFetch->fetch();
                 $currentFirstName = $dbFirstName;
                 $currentLastName = $dbLastName;
                 $currentEmail = $dbEmail;
                 $_SESSION['firstName'] = $currentFirstName;
                 $_SESSION['lastName'] = $currentLastName;
                 $_SESSION['email'] = $currentEmail;
             } else {
                 error_log("User ID {$userId} found in session but not in database.");
                 $errors[] = "Your user account could not be found. Please log out and log in again.";
                 // session_unset(); session_destroy();
                 $currentHashedPassword = '';
             }
        } else {
             error_log("Profile Fetch Execute Error: " . $stmtFetch->error);
             $errors[] = "Could not retrieve your profile data at this time.";
        }
        $stmtFetch->close();
    } else {
        error_log("Profile Fetch Prepare Error: " . $conn->error);
        $errors[] = "Could not retrieve profile data due to a server error.";
    }
} else if ($dbErrorMessage) {
    $errors[] = $dbErrorMessage;
}


$canProcessForms = ($conn && !$conn->connect_error && empty(array_filter($errors, fn($e) => str_contains($e, 'account could not be found') || str_contains($e, 'Database connection error') || str_contains($e, 'server error'))));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canProcessForms) {

    if (isset($_POST['submit_profile'])) {
        $newFirstName = trim(filter_input(INPUT_POST, 'firstname', FILTER_SANITIZE_SPECIAL_CHARS));
        $newLastName = trim(filter_input(INPUT_POST, 'lastName', FILTER_SANITIZE_SPECIAL_CHARS));
        $profileUpdateErrors = [];

        if (empty($newFirstName)) { $profileUpdateErrors[] = "First name cannot be empty."; }
        if (mb_strlen($newFirstName) > 50) { $profileUpdateErrors[] = "First name is too long."; }
        if (empty($newLastName)) { $profileUpdateErrors[] = "Last name cannot be empty."; }
        if (mb_strlen($newLastName) > 50) { $profileUpdateErrors[] = "Last name is too long."; }


        if (empty($profileUpdateErrors)) {
            $sqlUpdateProfile = "UPDATE users SET firstName = ?, lastName = ? WHERE id = ?";
            $stmtUpdateProfile = $conn->prepare($sqlUpdateProfile);
            if ($stmtUpdateProfile) {
                $stmtUpdateProfile->bind_param("ssi", $newFirstName, $newLastName, $userId);
                if ($stmtUpdateProfile->execute()) {
                    $_SESSION['firstName'] = $newFirstName;
                    $_SESSION['lastName'] = $newLastName;
                    if ($conn && $conn->ping()) { $conn->close(); }
                    header("Location: " . basename($_SERVER['PHP_SELF']) . "?success=" . urlencode("Profile updated successfully!"));
                    exit();
                } else {
                    error_log("Profile Update Execute Error: " . $stmtUpdateProfile->error);
                    $errors[] = "Failed to update profile. Please try again.";
                }
                $stmtUpdateProfile->close();
            } else {
                error_log("Profile Update Prepare Error: " . $conn->error);
                $errors[] = "Error preparing profile update.";
            }
        } else {
             $errors = array_merge($errors, $profileUpdateErrors);
             $currentFirstName = $newFirstName;
             $currentLastName = $newLastName;
        }
    }

    elseif (isset($_POST['change_password'])) {
        $inputCurrentPassword = $_POST['old_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $passwordChangeErrors = [];

        if (empty($inputCurrentPassword)) { $passwordChangeErrors[] = "Current password is required."; }
        if (empty($newPassword)) { $passwordChangeErrors[] = "New password cannot be empty."; }
        elseif (strlen($newPassword) < 8) { $passwordChangeErrors[] = "New password must be at least 8 characters long."; }
        if ($newPassword !== $confirmPassword) { $passwordChangeErrors[] = "New password and confirmation do not match."; }
        if ($newPassword === $inputCurrentPassword && !empty($newPassword)) { $passwordChangeErrors[] = "New password cannot be the same as the current password."; }


        if (empty($passwordChangeErrors)) {
            if (!empty($currentHashedPassword)) {
                 if (password_verify($inputCurrentPassword, $currentHashedPassword)) {
                    $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                    $sqlUpdatePass = "UPDATE users SET password = ? WHERE id = ?";
                    $stmtUpdatePass = $conn->prepare($sqlUpdatePass);
                    if ($stmtUpdatePass) {
                        $stmtUpdatePass->bind_param("si", $newHashedPassword, $userId);
                        if ($stmtUpdatePass->execute()) {
                            if ($conn && $conn->ping()) { $conn->close(); }
                            header("Location: " . basename($_SERVER['PHP_SELF']) . "?success=" . urlencode("Password changed successfully!"));
                            exit();
                        } else {
                            error_log("Password Update Execute Error: " . $stmtUpdatePass->error);
                            $passwordChangeErrors[] = "Failed to update password. Please try again.";
                        }
                        $stmtUpdatePass->close();
                    } else {
                        error_log("Password Update Prepare Error: " . $conn->error);
                        $passwordChangeErrors[] = "Error preparing password update.";
                    }
                } else {
                     $passwordChangeErrors[] = "Incorrect current password.";
                }
            } else {
                 $passwordChangeErrors[] = "Cannot verify current password due to a profile loading issue. Please refresh.";
                 error_log("Attempted password change but currentHashedPassword was empty for user ID: {$userId}");
            }
        }
        $errors = array_merge($errors, $passwordChangeErrors);
        $_POST['change_password_toggle'] = 'on';
    }

}

$messageType = '';
$messageContentForDisplay = '';
$popupIconClass = '';

if (!empty($errors)) {
    $messageType = 'error';
    $popupIconClass = 'fa-times-circle';
    $errorItems = '';
    foreach ($errors as $error) {
        $errorItems .= "<li>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</li>";
    }
    $simpleErrorMessage = implode(' ', $errors);
    $messageContentForDisplay = "<strong>Please fix the following:</strong><ul>" . $errorItems . "</ul>";
    $messageContentForPopup = htmlspecialchars($simpleErrorMessage, ENT_QUOTES, 'UTF-8');

} elseif (isset($_GET['success'])) {
    $messageType = 'success';
    $popupIconClass = 'fa-check-circle';
    $decodedSuccess = urldecode($_GET['success']);
    $messageContentForDisplay = htmlspecialchars($decodedSuccess, ENT_QUOTES, 'UTF-8');
    $messageContentForPopup = $messageContentForDisplay;
}

if ($conn && is_object($conn) && $conn->ping()) {
     $conn->close();
}

$fullName = trim($currentFirstName . ' ' . $currentLastName);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Profile | <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" integrity="sha512-xh6O/CkQoPOWDdYTDqeRdPCVd1SpvCA9XXcUnZS2FmJNp1coAFzvtCN9BmamE+4aHK8yyUHUSCcJHgXloTyT2A==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <style>
        :root {
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-tertiary: #9ca3af;
            --accent-blue: #3b82f6;
            --error-bg: #fee2e2;
            --error-border: #ef4444;
            --error-text: #b91c1c;
            --success-bg: #dcfce7;
            --success-border: #22c55e;
            --success-text: #15803d;
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            --border-radius-lg: 1rem;
            --border-radius-md: 0.5rem;
            --transition-speed: 0.3s;
        }

        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        body {
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            font-family: var(--font-sans);
            color: var(--text-primary);
            padding-top: 2rem;
            padding-bottom: 2rem;
        }

        .message-popup {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            color: white;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            opacity: 0;
            transform: translateY(-20px);
            transition: opacity 0.5s ease-out, transform 0.5s ease-out;
            z-index: 1000;
        }
        .message-popup.show { opacity: 1; transform: translateY(0); }
        .message-popup.error { background-color: #ef4444; }
        .message-popup.success { background-color: #22c55e; }
        .message-popup i { font-size: 1.25rem; }

        .profile-card {
            background-color: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: var(--border-radius-lg);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
            border: none;
            overflow: hidden;
            color: var(--text-primary);
        }

        input:focus, select:focus {
            border-color: #4f46e5 !important;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.5) !important;
            outline: none !important;
        }
        input[type="text"], input[type="email"], input[type="password"], select {
            color: var(--text-primary);
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.75rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        input[readonly] { background-color: #e5e7eb; cursor: default; opacity: 0.8; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; padding: 0.75rem 1.5rem; border-radius: var(--border-radius-md); font-weight: 600; transition: all 0.3s ease; cursor: pointer; border: none; line-height: 1.25rem; font-size: 0.875rem; }
        .btn-primary { background-color: #4f46e5; color: white; }
        .btn-primary:hover { background-color: #4338ca; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); }
        .btn-secondary { background-color: #10b981; color: white; }
        .btn-secondary:hover { background-color: #059669; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }
        .btn-success { background-color: #10b981; color: white; }
        .btn-success:hover { background-color: #059669; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3); }


        .message-inline { padding: 0.85rem 1.1rem; margin-bottom: 1.5rem; border-radius: 6px; font-size: 0.9rem; display: none; border-left-width: 4px; }
        .message-inline.error { background-color: var(--error-bg); border-color: var(--error-border); color: var(--error-text); }
        .message-inline.success { background-color: var(--success-bg); border-color: var(--success-border); color: var(--success-text); }
        .message-inline.show { display: block; animation: fadeIn 0.5s ease-out; }
        .message-inline ul { margin-top: 0.5rem; padding-left: 1.5rem; list-style: disc; }
        .message-inline strong { font-weight: 600; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .toggle-switch { position: relative; display: inline-block; width: 40px; height: 20px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; border-radius: 20px; transition: .4s; }
        .toggle-slider:before { position: absolute; content: ""; height: 12px; width: 12px; left: 4px; bottom: 4px; background-color: white; border-radius: 50%; transition: .4s; }
        input:checked + .toggle-slider { background-color: #4f46e5; }
        input:focus + .toggle-slider { box-shadow: 0 0 1px #4f46e5; }
        input:checked + .toggle-slider:before { transform: translateX(20px); }

        .bottom-nav {
            padding: 1rem 1.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(0, 0, 0, 0.1);
            background-color: rgba(255, 255, 255, 0.1);
        }
        .bottom-nav select {
            padding-right: 2rem; font-size: 0.875rem;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.5rem center; background-repeat: no-repeat; background-size: 1.5em 1.5em;
            -webkit-appearance: none; appearance: none;
             border-radius: 6px; border: 1px solid #d1d5db;
        }
         .cancel-link a {
            color: #4f46e5;
            font-size: 0.875rem; font-weight: 500; text-decoration: none; transition: color 0.2s;
            display: inline-flex; align-items: center; gap: 0.25rem;
        }
        .cancel-link a:hover { color: #4338ca; text-decoration: underline; }

        .back-link {
            display: inline-flex; align-items: center; gap: 0.5rem; color: #ffffff;
            font-weight: 500; margin-bottom: 1.5rem; padding: 0.5rem 1rem;
            border-radius: 0.5rem; background-color: rgba(0, 0, 0, 0.2);
            transition: background-color 0.3s ease; text-decoration: none;
        }
        .back-link:hover { background-color: rgba(0, 0, 0, 0.4); }

    </style>
</head>
<body class="antialiased text-gray-800">

    <div id="messagePopup" class="message-popup <?php echo $messageType; ?>" role="alert">
        <?php if (!empty($messageContentForPopup)): ?>
            <i class="fas <?php echo $popupIconClass; ?>"></i>
            <span><?php echo $messageContentForPopup; ?></span>
        <?php endif; ?>
    </div>

    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <a href="javascript:history.back()" class="back-link">
            <i class="fas fa-arrow-left"></i> Go Back
        </a>

        <header class="mb-8 text-center text-white">
            <h1 class="text-4xl font-bold tracking-tight mb-1">Welcome, <?php echo htmlspecialchars($currentFirstName, ENT_QUOTES, 'UTF-8'); ?>!</h1>
            <p class="text-xl opacity-90">Manage your profile information.</p>
        </header>

        <div class="profile-card">
            <div class="p-6 sm:p-8">

                <div id="messageArea" class="message-inline <?php echo $messageType; ?> <?php echo (!empty($messageContentForDisplay) && $messageType) ? 'show' : ''; ?>" role="alert">
                    <?php echo $messageContentForDisplay; ?>
                </div>

                <div class="md:flex md:space-x-8 lg:space-x-12">

                    <div class="md:w-1/3 text-center md:text-left flex flex-col items-center mb-6 md:mb-0">
                        <div class="mb-4">
                            <div class="h-32 w-32 rounded-full bg-gray-200 flex items-center justify-center mx-auto shadow-md">
                                <i class="fas fa-user text-6xl text-gray-500"></i>
                            </div>
                        </div>
                        <h2 class="text-lg font-semibold text-gray-800">
                            <?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>
                        </h2>
                         <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8'); ?></p>
                    </div>

                    <div class="md:w-2/3">
                        <form action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" method="POST" class="space-y-4">
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($currentEmail, ENT_QUOTES, 'UTF-8'); ?>" readonly class="mt-1 block w-full shadow-sm sm:text-sm">
                            </div>
                            <div>
                                <label for="firstname" class="block text-sm font-medium text-gray-700">First name</label>
                                <input type="text" name="firstname" id="firstname" value="<?php echo htmlspecialchars($currentFirstName, ENT_QUOTES, 'UTF-8'); ?>" required maxlength="50" class="mt-1 block w-full shadow-sm sm:text-sm">
                            </div>
                            <div>
                                <label for="lastName" class="block text-sm font-medium text-gray-700">Last name</label>
                                <input type="text" name="lastName" id="lastName" value="<?php echo htmlspecialchars($currentLastName, ENT_QUOTES, 'UTF-8'); ?>" required maxlength="50" class="mt-1 block w-full shadow-sm sm:text-sm">
                            </div>

                            <div class="flex items-center justify-between pt-2">
                                <label for="change_password_toggle" class="block text-sm font-medium text-gray-700 cursor-pointer">Change password</label>
                                <label class="toggle-switch">
                                    <input type="checkbox" id="change_password_toggle" name="change_password_toggle"
                                           <?php echo (isset($_POST['change_password_toggle']) || (isset($_POST['change_password']) && !empty($errors) && $messageType == 'error')) ? 'checked' : ''; ?>
                                           aria-controls="password-change-section" aria-expanded="false">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>

                             <div class="pt-2">
                                <button type="submit" name="submit_profile" class="btn btn-primary w-full sm:w-auto justify-center">
                                     <i class="fas fa-save opacity-90"></i>
                                     Save Profile Changes
                                </button>
                             </div>
                        </form>

                        <div id="password-change-section" class="mt-6 pt-6 border-t border-gray-200 <?php echo (isset($_POST['change_password_toggle']) || (isset($_POST['change_password']) && !empty($errors) && $messageType == 'error')) ? '' : 'hidden'; ?>">
                             <h3 class="text-md font-medium leading-6 text-gray-900 mb-4">Update Your Password</h3>
                             <form action="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'])); ?>" method="POST" class="space-y-4">
                                 <div>
                                    <label for="old_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                    <input type="password" name="old_password" id="old_password" required autocomplete="current-password" class="mt-1 block w-full shadow-sm sm:text-sm" placeholder="Enter your current password">
                                 </div>
                                 <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                    <input type="password" name="new_password" id="new_password" required autocomplete="new-password" minlength="8" class="mt-1 block w-full shadow-sm sm:text-sm" placeholder="Minimum 8 characters">
                                     <p class="mt-1 text-xs text-gray-500">Must be at least 8 characters long.</p>
                                 </div>
                                 <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                    <input type="password" name="confirm_password" id="confirm_password" required autocomplete="new-password" minlength="8" class="mt-1 block w-full shadow-sm sm:text-sm" placeholder="Re-enter new password">
                                 </div>
                                 <div class="pt-2">
                                     <button type="submit" name="change_password" class="btn btn-secondary w-full sm:w-auto justify-center">
                                        <i class="fas fa-shield-alt opacity-90"></i>
                                        Change Password
                                     </button>
                                 </div>
                             </form>
                         </div>

                    </div>
                </div>

            </div>

            <div class="bottom-nav flex items-center justify-between">
                <div>
                    <label for="language" class="sr-only">Language</label>
                    <select id="language" name="language" class="block w-full pl-3 pr-8 py-1.5 bg-white shadow-sm focus:outline-none sm:text-sm">
                        <option>English</option>
                    </select>
                </div>
                <div class="cancel-link">
                    <a href="dashboard.php">
                        <i class="fas fa-arrow-left text-xs"></i> Cancel and go back
                    </a>
                </div>
            </div>

        </div>

    </main>

    <script>
        window.addEventListener('load', function() {
            const messagePopup = document.getElementById('messagePopup');
            const messageSpan = messagePopup ? messagePopup.querySelector('span') : null;

            if (messagePopup && messageSpan && messageSpan.textContent.trim()) {
                messagePopup.classList.add('show');

                setTimeout(function() {
                    messagePopup.classList.remove('show');
                }, 5000);
            }
        });

        document.addEventListener('DOMContentLoaded', () => {
            const toggleCheckbox = document.getElementById('change_password_toggle');
            const passwordSection = document.getElementById('password-change-section');
            const messageArea = document.getElementById('messageArea');

            const togglePasswordSection = () => {
                if (!passwordSection) return;
                if (toggleCheckbox.checked) {
                    passwordSection.classList.remove('hidden');
                    toggleCheckbox.setAttribute('aria-expanded', 'true');
                } else {
                    passwordSection.classList.add('hidden');
                    toggleCheckbox.setAttribute('aria-expanded', 'false');
                }
            };

            if (toggleCheckbox) {
                toggleCheckbox.addEventListener('change', togglePasswordSection);
            }

            if (messageArea && messageArea.innerHTML.trim() !== '' && messageArea.classList.contains('show')) {
                 /*
                 setTimeout(() => {
                     messageArea.style.transition = 'opacity 0.5s ease-out';
                     messageArea.style.opacity = '0';
                     setTimeout(() => {
                         messageArea.classList.remove('show');
                         messageArea.style.opacity = '1';
                         // Optionally clear content: messageArea.innerHTML = '';
                     }, 500);
                 }, 7000);
                 */
            }
        });
    </script>
</body>
</html>