<?php
session_start();
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "login";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("<script>alert('Database connection error. Please check configuration.'); window.history.back();</script>");
}
$signup_error = null;
$signin_error = null;
$signup_success = null;
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST["signUp"])) {
        $firstName = trim($_POST["fName"]);
        $lastName = trim($_POST["lName"]);
        $email = trim($_POST["email"]);
        $password_plain = trim($_POST["password"]);
        if (empty($firstName) || empty($lastName) || empty($email) || empty($password_plain)) {
            $signup_error = 'All fields are required for sign up!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $signup_error = 'Invalid email format.';
        } elseif (strlen($password_plain) < 6) {
            $signup_error = 'Password must be at least 6 characters long.';
        } else {
            $sql_check = "SELECT id FROM users WHERE email = ?";
            $stmt_check = $conn->prepare($sql_check);
            if ($stmt_check) {
                $stmt_check->bind_param("s", $email);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $signup_error = 'Email address already registered.';
                } else {
                    $hashed_password = password_hash($password_plain, PASSWORD_DEFAULT);
                    $sql_insert = "INSERT INTO users (firstName, lastName, email, password) VALUES (?, ?, ?, ?)";
                    $stmt_insert = $conn->prepare($sql_insert);
                    if ($stmt_insert) {
                        $stmt_insert->bind_param("ssss", $firstName, $lastName, $email, $hashed_password);
                        if ($stmt_insert->execute()) {
                            $signup_success = 'Registration successful! You can now log in.';
                        } else {
                            error_log("Signup Execute Error: " . $stmt_insert->error);
                            $signup_error = 'Registration failed. Please try again.';
                        }
                        $stmt_insert->close();
                    } else {
                        error_log("Signup Prepare Error: " . $conn->error);
                        $signup_error = 'An error occurred during registration. Please try again later.';
                    }
                }
                $stmt_check->close();
            } else {
                error_log("Email Check Prepare Error: " . $conn->error);
                $signup_error = 'An error occurred checking email. Please try again later.';
            }
        }
    }
    elseif (isset($_POST["signIn"])) {
        $email = trim($_POST["email"]);
        $password_plain = trim($_POST["password"]);
        if (empty($email) || empty($password_plain)) {
            $signin_error = 'Email and password are required for sign in!';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $signin_error = 'Invalid email format.';
        } else {
            $sql = "SELECT id, firstName, lastName, password FROM users WHERE email = ?";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->store_result();
                if ($stmt->num_rows === 1) {
                    $stmt->bind_result($id, $firstName, $lastName, $hashed_password);
                    $stmt->fetch();
                    if (password_verify($password_plain, $hashed_password)) {
                        session_regenerate_id(true);
                        $_SESSION["user_id"] = $id;
                        $_SESSION["firstName"] = $firstName;
                        $_SESSION["lastName"] = $lastName;
                        $_SESSION["email"] = $email;
                        $stmt->close();
                        $conn->close();
                        header("Location: dashboard.php");
                        exit();
                    } else {
                        $signin_error = 'Incorrect email or password.';
                    }
                } else {
                    $signin_error = 'Incorrect email or password.';
                }
                $stmt->close();
            } else {
                error_log("Login Prepare Error: " . $conn->error);
                $signin_error = 'An error occurred during login. Please try again later.';
            }
        }
    }
}
if (is_object($conn) && $conn->ping()) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Software Finance - Login/Signup</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --bg-body-start: #fdfbfb;
            --bg-body-end: #ebedee;
            --form-bg: #ffffff;
            --container-shadow: rgba(0, 0, 0, 0.1);
            --text-dark-primary: #333333;
            --text-dark-secondary: #6c757d;
            --text-light-primary: #ffffff;
            --input-bg-light: #f8f9fa;
            --input-border-light: #ced4da;
            --input-focus-border-light: #80bdff;
            --input-focus-bg-light: #ffffff;
            --input-icon-color: var(--text-dark-secondary);
            --button-accent-bg: #007bff;
            --button-accent-text: var(--text-light-primary);
            --button-accent-hover-bg: #0056b3;
            --toggle-bg-start: #007bff;
            --toggle-bg-end: #0056b3;
            --toggle-text: var(--text-light-primary);
            --toggle-button-bg: transparent;
            --toggle-button-border: var(--text-light-primary);
            --toggle-button-text: var(--text-light-primary);
            --toggle-button-hover-bg: rgba(255, 255, 255, 0.1);
            --error-bg-light: #f8d7da;
            --error-text-dark: #721c24;
            --error-border-light: #f5c6cb;
            --success-bg-light: #d4edda;
            --success-text-dark: #155724;
            --success-border-light: #c3e6cb;
            --border-radius-lg: 15px;
            --border-radius-md: 8px;
            --font-main: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --bg-text-color-dark: rgba(0, 0, 0, 0.06);
            --bg-text-size: clamp(3.5rem, 9vw, 7rem);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html {
            font-size: 16px;
        }

        body {
            font-family: var(--font-main);
            background-image: linear-gradient(to bottom, var(--bg-body-start), var(--bg-body-end));
            background-attachment: fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            color: var(--text-dark-primary);
            position: relative;
            overflow-x: hidden;
        }

        .background-text {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: var(--bg-text-size);
            font-weight: 700;
            color: var(--bg-text-color-dark);
            z-index: 0;
            white-space: nowrap;
            user-select: none;
            text-transform: uppercase;
            letter-spacing: 4px;
            pointer-events: none;
        }

        .container {
            background: var(--form-bg);
            border-radius: var(--border-radius-lg);
            box-shadow: 0 8px 25px 0 var(--container-shadow);
            position: relative;
            overflow: hidden;
            width: 680px;
            max-width: 100%;
            min-height: 520px;
            z-index: 1;
            display: flex;
            flex-direction: column;
        }

         .form-container {
            position: absolute;
            top: 0;
            height: 100%;
            transition: all 0.6s ease-in-out;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--form-bg);
        }

        .form-container form {
            background: transparent;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0 45px;
            width: 100%;
            height: 100%;
            text-align: center;
        }

        .form-container h1 {
            font-weight: 700;
            margin-top: 1rem;
            margin-bottom: 0.75rem;
            color: var(--text-dark-primary);
            font-size: 2.0rem;
        }

        .form-container span {
            font-size: 0.9rem;
            color: var(--text-dark-secondary);
            margin-bottom: 1.25rem;
        }

        .input-wrapper {
            position: relative;
            width: 100%;
            margin: 0.5rem 0;
        }

        .input-icon {
            position: absolute;
            top: 50%;
            left: 15px;
            transform: translateY(-50%);
            color: var(--input-icon-color);
            font-size: 1rem;
            pointer-events: none;
        }

        .form-container input {
            width: 100%;
            padding: 0.85rem 1.1rem 0.85rem 40px;
            background: var(--input-bg-light);
            border: 1px solid var(--input-border-light);
            border-radius: var(--border-radius-md);
            font-size: 0.95rem;
            color: var(--text-dark-primary);
            outline: none;
            transition: border-color 0.3s ease, background-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-container input[type="password"] {
            padding-right: 45px;
        }

        .form-container input::placeholder {
            color: var(--text-dark-secondary);
            opacity: 1;
        }

        .form-container input:-webkit-autofill,
        .form-container input:-webkit-autofill:hover,
        .form-container input:-webkit-autofill:focus,
        .form-container input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px var(--input-bg-light) inset !important;
            -webkit-text-fill-color: var(--text-dark-primary) !important;
            caret-color: var(--text-dark-primary);
            border: 1px solid var(--input-border-light);
        }

        .form-container input:focus {
            border-color: var(--input-focus-border-light);
            background-color: var(--input-focus-bg-light);
            box-shadow: 0 0 0 0.2rem rgba(0, 123, 255, 0.25);
        }

        .password-toggle-icon {
            position: absolute;
            top: 50%;
            right: 15px;
            transform: translateY(-50%);
            color: var(--input-icon-color);
            font-size: 1.1rem;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .password-toggle-icon:hover {
            color: var(--text-dark-primary);
        }

        .form-container button[type="submit"] {
            width: 100%;
            max-width: 220px;
            padding: 0.85rem;
            margin-top: 1.5rem;
            background-color: var(--button-accent-bg);
            color: var(--button-accent-text);
            border: none;
            border-radius: var(--border-radius-md);
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .form-container button[type="submit"]:hover {
            background-color: var(--button-accent-hover-bg);
        }
         .form-container button[type="submit"]:active {
            transform: scale(0.97);
        }

        .sign-in {
            left: 0;
            width: 50%;
            z-index: 2;
             border-radius: var(--border-radius-lg) 0 0 var(--border-radius-lg);
        }

        .sign-up {
            left: 0;
            width: 50%;
            opacity: 0;
            z-index: 1;
             border-radius: var(--border-radius-lg) 0 0 var(--border-radius-lg);
        }

        .toggle-container {
            position: absolute;
            top: 0;
            left: 50%;
            width: 50%;
            height: 100%;
            overflow: hidden;
            transition: all 0.6s ease-in-out;
            border-radius: 0 var(--border-radius-lg) var(--border-radius-lg) 0;
            z-index: 100;
        }

        .toggle {
            background: linear-gradient(to right, var(--toggle-bg-start), var(--toggle-bg-end));
            height: 100%;
            color: var(--toggle-text);
            position: relative;
            left: -100%;
            width: 200%;
            transform: translateX(0);
            transition: all 0.6s ease-in-out;
        }

        .toggle-panel {
            position: absolute;
            width: 50%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            padding: 0 35px;
            text-align: center;
            top: 0;
            transform: translateX(0);
            transition: all 0.6s ease-in-out;
        }

        .toggle-panel h1 {
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--toggle-text);
            font-size: 2.0rem;
        }

         .toggle-panel p {
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
            color: rgba(255, 255, 255, 0.9);
        }

        .toggle-panel button.hidden {
            width: auto;
            max-width: 160px;
            padding: 0.7rem 2rem;
            background-color: var(--toggle-button-bg);
            color: var(--toggle-button-text);
            border: 1.5px solid var(--toggle-button-border);
            border-radius: var(--border-radius-md);
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.1s ease;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-top: 0.6rem;
        }
        .toggle-panel button.hidden:hover {
             background-color: var(--toggle-button-hover-bg);
        }
         .toggle-panel button.hidden:active {
             transform: scale(0.97);
        }

        .toggle-left {
            transform: translateX(-200%);
        }

        .toggle-right {
            right: 0;
            transform: translateX(0);
        }

        .container.active .sign-in {
            transform: translateX(100%);
            opacity: 0;
        }

        .container.active .sign-up {
            transform: translateX(100%);
            opacity: 1;
            z-index: 5;
            animation: move 0.6s;
             border-radius: 0 var(--border-radius-lg) var(--border-radius-lg) 0;
        }

        @keyframes move {
            0%, 49.99% { opacity: 0; z-index: 1; }
            50%, 100% { opacity: 1; z-index: 5; }
        }

        .container.active .toggle-container {
            transform: translateX(-100%);
            border-radius: var(--border-radius-lg) 0 0 var(--border-radius-lg);
        }

        .container.active .toggle {
            transform: translateX(50%);
        }

        .container.active .toggle-left {
            transform: translateX(0);
        }

        .container.active .toggle-right {
            transform: translateX(200%);
        }

        .message {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: var(--border-radius-md);
            font-size: 0.9rem;
            font-weight: 500;
            text-align: left;
            width: 100%;
            line-height: 1.4;
            border: 1px solid transparent;
        }
        .error-message {
            background-color: var(--error-bg-light);
            color: var(--error-text-dark);
            border-color: var(--error-border-light);
        }
        .success-message {
             background-color: var(--success-bg-light);
            color: var(--success-text-dark);
            border-color: var(--success-border-light);
        }

        @media (max-width: 767px) {
            body {
                align-items: flex-start;
                padding-top: 5vh;
                padding-bottom: 5vh;
            }
            .container {
                width: 95%;
                max-width: 450px;
                min-height: 0;
                height: auto;
                display: block;
                overflow: hidden;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            }
            .toggle-container {
                display: none;
            }
            .form-container {
                position: relative;
                width: 100%;
                height: auto;
                transform: none !important;
                opacity: 1 !important;
                z-index: 1 !important;
                transition: none;
                padding: 35px 25px 30px 25px;
                border-radius: 0;
            }

             .container:not(.active-mobile) .form-container.sign-in,
             .container.active-mobile .form-container.sign-up {
                  border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
             }

            .form-container.sign-up {
                 display: none;
            }
             .container.active-mobile .form-container.sign-up {
                 display: flex;
            }
            .container.active-mobile .form-container.sign-in {
                 display: none;
            }

            .form-container h1 {
                font-size: 1.8rem;
                margin-bottom: 0.5rem;
                margin-top: 0.5rem;
            }
            .form-container span { font-size: 0.85rem; margin-bottom: 1rem;}
            .form-container input { padding-top: 0.8rem; padding-bottom: 0.8rem; font-size: 0.9rem;}
            .form-container button[type="submit"] { padding: 0.8rem; font-size: 0.95rem; margin-top: 1.2rem;}
            .message { margin-bottom: 0.8rem; }

             .input-icon {
                 font-size: 0.9rem;
                 left: 12px;
             }
             .form-container input {
                 padding-left: 35px;
             }
             .password-toggle-icon {
                 font-size: 1rem;
                 right: 12px;
             }
             .form-container input[type="password"] {
                 padding-right: 40px;
             }

            .mobile-toggle {
                display: block;
                text-align: center;
                padding: 15px 25px 20px 25px;
                font-size: 0.9rem;
                background: var(--form-bg);
                border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
                margin-top: -1px;
                position: relative;
                z-index: 0;
                border-top: 1px solid #e9ecef;
            }
            .mobile-toggle a {
                color: var(--button-accent-bg);
                font-weight: 500;
                text-decoration: none;
                cursor: pointer;
                transition: color 0.2s;
            }
             .mobile-toggle a:hover {
                 color: var(--button-accent-hover-bg);
                 text-decoration: underline;
             }
        }

        @media (min-width: 768px) {
             .mobile-toggle {
                 display: none;
             }
             .form-container {
                 border-radius: 0;
             }
             .form-container.sign-in {
                  border-radius: var(--border-radius-lg) 0 0 var(--border-radius-lg);
             }
              .form-container.sign-up {
                  border-radius: var(--border-radius-lg) 0 0 var(--border-radius-lg);
             }
             .container.active .form-container.sign-up {
                 border-radius: 0 var(--border-radius-lg) var(--border-radius-lg) 0;
             }
        }
    </style>
</head>
<body>

    <div class="background-text">Software Finance</div>

    <div class="container" id="container">
        <div class="form-container sign-up">
             <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                <h1>Create Account</h1>
                <span>Use your email for registration</span>
                <?php if ($signup_error): ?>
                    <p class="message error-message"><?php echo htmlspecialchars($signup_error); ?></p>
                <?php elseif ($signup_success): ?>
                    <p class="message success-message"><?php echo htmlspecialchars($signup_success); ?></p>
                <?php endif; ?>
                <div class="input-wrapper">
                    <i class="input-icon fas fa-user"></i>
                    <input type="text" name="fName" placeholder="First Name" required value="<?php echo isset($_POST['fName']) && ($signup_error || $signup_success) ? htmlspecialchars($_POST['fName']) : ''; ?>">
                </div>
                <div class="input-wrapper">
                     <i class="input-icon fas fa-user"></i>
                    <input type="text" name="lName" placeholder="Last Name" required value="<?php echo isset($_POST['lName']) && ($signup_error || $signup_success) ? htmlspecialchars($_POST['lName']) : ''; ?>">
                </div>
                <div class="input-wrapper">
                    <i class="input-icon fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email" required value="<?php echo isset($_POST['email']) && ($signup_error || $signup_success) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <div class="input-wrapper">
                    <i class="input-icon fas fa-lock"></i>
                    <input type="password" id="signup-password" name="password" placeholder="Password (min 6 chars)" required>
                    <i class="fas fa-eye password-toggle-icon" data-target="signup-password"></i>
                </div>
                <button type="submit" name="signUp">Sign Up</button>
            </form>
        </div>

        <div class="form-container sign-in">
             <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" novalidate>
                <h1>Sign In</h1>
                <span>Use your registered email and password</span>
                 <?php if ($signin_error): ?>
                    <p class="message error-message"><?php echo htmlspecialchars($signin_error); ?></p>
                <?php endif; ?>
                <div class="input-wrapper">
                     <i class="input-icon fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Email" required value="<?php echo isset($_POST['email']) && $signin_error ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <div class="input-wrapper">
                    <i class="input-icon fas fa-lock"></i>
                    <input type="password" id="signin-password" name="password" placeholder="Password" required>
                    <i class="fas fa-eye password-toggle-icon" data-target="signin-password"></i>
                </div>
                <button type="submit" name="signIn">Sign In</button>
            </form>
        </div>

        <div class="toggle-container">
            <div class="toggle">
                <div class="toggle-panel toggle-left">
                    <h1>Welcome Back!</h1>
                    <p>Already have an account? Sign in here to access your dashboard.</p>
                    <button class="hidden" id="login">Sign In</button>
                </div>
                <div class="toggle-panel toggle-right">
                    <h1>Hello, Future User!</h1>
                    <p>New here? Register with your details to get started with Software Finance.</p>
                    <button class="hidden" id="register">Sign Up</button>
                </div>
            </div>
        </div>

         <div class="mobile-toggle">
             <span class="signInLinkMobile"><a href="#">Don't have an account? Sign Up</a></span>
             <span class="signUpLinkMobile" style="display:none;"><a href="#">Already have an account? Sign In</a></span>
         </div>

    </div>

    <script>
        const container = document.getElementById('container');
        const registerBtn = document.getElementById('register');
        const loginBtn = document.getElementById('login');
        const signInLinkMobile = document.querySelector('.mobile-toggle .signInLinkMobile a');
        const signUpLinkMobile = document.querySelector('.mobile-toggle .signUpLinkMobile a');
        const mobileToggleDiv = document.querySelector('.mobile-toggle');

        const isMobile = () => window.innerWidth <= 767;

        if (registerBtn && loginBtn && !isMobile()) {
            registerBtn.addEventListener('click', () => {
                container.classList.add("active");
            });
            loginBtn.addEventListener('click', () => {
                container.classList.remove("active");
            });
        }

        if (signInLinkMobile && signUpLinkMobile && mobileToggleDiv && isMobile()) {
             signInLinkMobile.addEventListener('click', (e) => {
                 e.preventDefault();
                 container.classList.add('active-mobile');
                 if(document.querySelector('.mobile-toggle .signInLinkMobile')) document.querySelector('.mobile-toggle .signInLinkMobile').style.display = 'none';
                 if(document.querySelector('.mobile-toggle .signUpLinkMobile')) document.querySelector('.mobile-toggle .signUpLinkMobile').style.display = 'inline';
            });
             signUpLinkMobile.addEventListener('click', (e) => {
                 e.preventDefault();
                 container.classList.remove('active-mobile');
                 if(document.querySelector('.mobile-toggle .signUpLinkMobile')) document.querySelector('.mobile-toggle .signUpLinkMobile').style.display = 'none';
                 if(document.querySelector('.mobile-toggle .signInLinkMobile')) document.querySelector('.mobile-toggle .signInLinkMobile').style.display = 'inline';
            });
        }

        const passwordToggles = document.querySelectorAll('.password-toggle-icon');
        passwordToggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const targetInputId = toggle.getAttribute('data-target');
                const passwordInput = document.getElementById(targetInputId);
                if (passwordInput) {
                    const currentType = passwordInput.getAttribute('type');
                    passwordInput.setAttribute('type', currentType === 'password' ? 'text' : 'password');
                    toggle.classList.toggle('fa-eye');
                    toggle.classList.toggle('fa-eye-slash');
                }
            });
        });

        <?php
          $wasSignupAttempt = isset($_POST["signUp"]) || $signup_error || $signup_success;
          $wasFailedSigninAttempt = isset($_POST["signIn"]) && $signin_error;

          if ($wasSignupAttempt) {
              echo "if (!isMobile() && container) { container.classList.add('active'); }\n";
              echo "else if (isMobile() && container) { \n";
              echo "  container.classList.add('active-mobile'); \n";
              echo "  if(document.querySelector('.mobile-toggle .signInLinkMobile')) document.querySelector('.mobile-toggle .signInLinkMobile').style.display = 'none';\n";
              echo "  if(document.querySelector('.mobile-toggle .signUpLinkMobile')) document.querySelector('.mobile-toggle .signUpLinkMobile').style.display = 'inline';\n";
              echo "}\n";
          } elseif ($wasFailedSigninAttempt) {
              echo "if (!isMobile() && container) { container.classList.remove('active'); }\n";
              echo "else if (isMobile() && container) { \n";
              echo "  container.classList.remove('active-mobile'); \n";
              echo "  if(document.querySelector('.mobile-toggle .signUpLinkMobile')) document.querySelector('.mobile-toggle .signUpLinkMobile').style.display = 'none';\n";
              echo "  if(document.querySelector('.mobile-toggle .signInLinkMobile')) document.querySelector('.mobile-toggle .signInLinkMobile').style.display = 'inline';\n";
              echo "}\n";
          } else {
              echo "if (isMobile() && container && mobileToggleDiv) { \n";
              echo "  container.classList.remove('active-mobile'); \n";
              echo "  if(document.querySelector('.mobile-toggle .signUpLinkMobile')) document.querySelector('.mobile-toggle .signUpLinkMobile').style.display = 'none';\n";
              echo "  if(document.querySelector('.mobile-toggle .signInLinkMobile')) document.querySelector('.mobile-toggle .signInLinkMobile').style.display = 'inline';\n";
              echo "}\n";
          }
        ?>
    </script>
</body>
</html>