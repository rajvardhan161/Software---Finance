<?php
session_start();

// --- Configuration ---
// $currencySymbol = '$'; // Not needed for tips page
$dashboardUrl = "dashboard.php"; // Used for back link

// --- Helper Function (Removed as not used) ---
// function formatCurrency($amount, $symbol = '$') { ... }

// --- Database Connection Details (Removed - Not needed for tips) ---

// --- Goal Variables (Removed) ---

// --- Tip Data (Original Logic) ---
$financialTips = [
     [ 'title' => 'Regularly Review Statements', 'tip' => "Review your bank and credit card statements regularly (at least monthly) to spot any unusual transactions, potential errors, or fraudulent activity early.", 'category' => 'Monitoring', 'icon' => 'fas fa-file-invoice-dollar' ],
     [ 'title' => 'Create and Follow a Budget', 'tip' => "Create a realistic budget that tracks your income and expenses. Knowing where your money goes is the first step to taking control of your finances. Use tools or apps if needed.", 'category' => 'Budgeting', 'icon' => 'fas fa-chart-pie' ],
     [ 'title' => 'Build an Emergency Fund', 'tip' => "Aim to build an emergency fund that covers 3-6 months of essential living expenses. This fund can protect you from debt during unexpected events like job loss or medical bills.", 'category' => 'Saving', 'icon' => 'fas fa-piggy-bank' ],
     [ 'title' => 'Tackle High-Interest Debt', 'tip' => "Prioritize paying off high-interest debt, such as credit cards, as quickly as possible. The interest saved can significantly boost your financial health.", 'category' => 'Debt Management', 'icon' => 'fas fa-credit-card' ],
     [ 'title' => 'Save for Retirement Early', 'tip' => "Start saving for retirement as early as possible, even if it's small amounts. Compound interest works wonders over long periods. Take advantage of employer matching if available.", 'category' => 'Retirement', 'icon' => 'fas fa-umbrella-beach' ],
     [ 'title' => 'Automate Your Savings', 'tip' => "Set up automatic transfers from your checking account to your savings or investment accounts each payday. This 'pay yourself first' strategy makes saving consistent.", 'category' => 'Saving', 'icon' => 'fas fa-robot' ],
     [ 'title' => 'Avoid Impulse Buying', 'tip' => "Before making a non-essential purchase, especially a large one, implement a waiting period (e.g., 24-48 hours). This helps differentiate needs from wants and reduces impulse buys.", 'category' => 'Spending Habits', 'icon' => 'fas fa-pause-circle' ],
     [ 'title' => 'Shop Smart', 'tip' => "Compare prices, look for discounts, use coupons, and consider buying used or refurbished items when appropriate. Small savings add up over time.", 'category' => 'Spending Habits', 'icon' => 'fas fa-shopping-cart' ],
     [ 'title' => 'Understand Needs vs. Wants', 'tip' => "Clearly distinguish between essential needs (housing, food, utilities) and discretionary wants (entertainment, dining out). Allocate your budget accordingly.", 'category' => 'Budgeting', 'icon' => 'fas fa-balance-scale' ],
     [ 'title' => 'Set SMART Financial Goals', 'tip' => "Define financial goals that are Specific, Measurable, Achievable, Relevant, and Time-bound (SMART). This provides clear targets and motivation.", 'category' => 'Goal Setting', 'icon' => 'fas fa-bullseye' ],
     [ 'title' => 'Diversify Investments', 'tip' => "Don't put all your investment eggs in one basket. Diversifying across different asset classes (stocks, bonds, real estate) can help manage risk.", 'category' => 'Investing', 'icon' => 'fas fa-chart-line' ],
     [ 'title' => 'Understand Your Credit Score', 'tip' => "Know your credit score and understand the factors that affect it. A good credit score is crucial for loans, mortgages, and even some rentals or jobs.", 'category' => 'Credit', 'icon' => 'fas fa-star-half-alt' ],
     [ 'title' => 'Review Insurance Coverage', 'tip' => "Periodically review your insurance policies (health, auto, home, life) to ensure you have adequate coverage and are getting the best rates.", 'category' => 'Risk Management', 'icon' => 'fas fa-shield-alt' ],
     [ 'title' => 'Plan for Taxes', 'tip' => "Understand basic tax principles and plan accordingly. Consider tax-advantaged accounts and keep good records to maximize deductions.", 'category' => 'Taxes', 'icon' => 'fas fa-calculator' ],
];
$categories = array_unique(array_column($financialTips, 'category'));
sort($categories);
$tipOfTheDay = $financialTips[array_rand($financialTips)];

// --- Get User Name (Original Logic) ---
$userName = $_SESSION['username'] ?? "Teja";

// --- Get Flash Message (Original Logic) ---
$flashMessage = $_SESSION['flash_message'] ?? null;
if ($flashMessage) { unset($_SESSION['flash_message']); }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Wellness Hub | Tips & Insights</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/@lottiefiles/lottie-player@latest/dist/lottie-player.js"></script>
    <style>
        /* --- Merged CSS with UI Enhancements --- */
        :root {
            /* ... Color and Font Variables (Mostly unchanged) ... */
            --primary-color: #0d6efd; --primary-hover: #0b5ed7; --primary-gradient: linear-gradient(135deg, #3b82f6, #0d6efd); --primary-gradient-hover: linear-gradient(135deg, #0d6efd, #0a58ca); --input-focus-border: var(--primary-color); --input-focus-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.3); --background-gradient: linear-gradient(160deg, #eef5ff 0%, #f8f9fa 100%);
            --success-color: #198754; --success-hover: #157347; --success-gradient: linear-gradient(135deg, #20c997, #198754); --success-gradient-hover: linear-gradient(135deg, #198754, #105c3a); --success-bg: #d1e7dd; --success-border: #a3cfbb;
            --danger-color: #dc3545; --danger-hover: #b02a37; --danger-bg: #f8d7da; --danger-border: #f1aeb5;
            --warning-color: #ffc107; --warning-bg: #fff3cd; --warning-border: #ffecb5;
            --secondary-color: #6c757d; --secondary-hover: #5a6268; --light-bg: #f8f9fa; --white-bg: rgba(255, 255, 255, 0.95); --card-border-color: rgba(0, 0, 0, 0.08); --input-border-color: #ced4da; --text-color: #212529; --text-muted: #6c757d; --header-bg: #212529; --header-color: #f8f9fa; --font-family: 'Poppins', sans-serif; --border-radius: 1rem; --border-radius-sm: 0.5rem; /* Smaller radius for tags/buttons */ --box-shadow: 0 12px 35px rgba(100, 116, 139, 0.15); --input-shadow: 0 1px 3px rgba(0,0,0,0.05); --card-hover-shadow: 0 16px 45px rgba(100, 116, 139, 0.18); --content-max-width: 1100px;
            /* Glass effect */
            --glass-bg: rgba(255, 255, 255, 0.7); --glass-border-color: rgba(255, 255, 255, 0.4);
            /* Dark Mode */
            --dark-bg: #111827; --dark-card-bg: rgba(31, 41, 55, 0.9); --dark-header-bg: #1f2937; --dark-header-color: #e5e7eb; --dark-text-color: #f3f4f6; --dark-text-muted: #9ca3af; --dark-border-color: rgba(255, 255, 255, 0.15); --dark-input-border: #4b5563; --dark-input-bg: rgba(55, 65, 81, 0.6); --dark-focus-shadow: 0 0 0 0.25rem rgba(96, 165, 250, 0.35); --dark-gradient: linear-gradient(160deg, #1f2937 0%, #111827 100%); --dark-white-bg: var(--dark-card-bg); --dark-box-shadow: 0 12px 35px rgba(0, 0, 0, 0.25); --dark-card-hover-shadow: 0 16px 45px rgba(0, 0, 0, 0.3);
            --dark-glass-bg: rgba(31, 41, 55, 0.75); --dark-glass-border-color: rgba(255, 255, 255, 0.2);
             /* Category Colors (Basic Examples - Add more as needed) */
            --category-budgeting: #0d6efd; --category-saving: #198754; --category-investing: #6f42c1; --category-debt: #dc3545; --category-monitoring: #fd7e14; --category-default: #6c757d;
        }
        html[data-theme='dark'] { /* Dark mode overrides */
             /* ... existing overrides ... */
             --light-bg: var(--dark-bg); --white-bg: var(--dark-card-bg); --card-border-color: var(--dark-border-color); --input-border-color: var(--dark-input-border); --text-color: var(--dark-text-color); --text-muted: var(--dark-text-muted); --input-focus-shadow: var(--dark-focus-shadow); --background-gradient: var(--dark-gradient); --input-shadow: 0 2px 5px rgba(0,0,0,0.2); --box-shadow: var(--dark-box-shadow); --card-hover-shadow: var(--dark-card-hover-shadow); --header-bg: var(--dark-header-bg); --header-color: var(--dark-header-color); --success-bg: rgba(22, 163, 74, 0.2); --success-border: rgba(22, 163, 74, 0.5); --danger-bg: rgba(239, 68, 68, 0.2); --danger-border: rgba(239, 68, 68, 0.5); --warning-bg: rgba(255, 193, 7, 0.15); --warning-border: rgba(255, 193, 7, 0.4);
             --glass-bg: var(--dark-glass-bg); --glass-border-color: var(--dark-glass-border-color);
        }
        /* Global Styles, Body, Scrollbar */
        * { box-sizing: border-box; margin: 0; padding: 0; } html { scroll-behavior: smooth; } body { font-family: var(--font-family); background: var(--background-gradient); color: var(--text-color); line-height: 1.7; font-size: 16px; -webkit-font-smoothing: antialiased; padding-top: 80px; min-height: 100vh; display: flex; flex-direction: column; transition: background 0.3s ease, color 0.3s ease; } body::-webkit-scrollbar { width: 8px; } body::-webkit-scrollbar-track { background: transparent; } body::-webkit-scrollbar-thumb { background-color: var(--secondary-color); border-radius: 10px; border: 2px solid transparent; background-clip: content-box; } html[data-theme='dark'] body::-webkit-scrollbar-thumb { background-color: var(--dark-text-muted); }
        /* Header / Navbar */
        .header { background-color: var(--header-bg); color: var(--header-color); padding: 1rem 2.5rem; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); flex-wrap: wrap; position: fixed; top: 0; left: 0; width: 100%; z-index: 1050; transition: background-color 0.3s ease; } .header-logo { display: flex; align-items: center; gap: 0.6rem; text-decoration: none; color: inherit;} .header-logo i { color: var(--primary-color); font-size: 1.5rem; } .header-logo h1 { font-size: 1.5rem; font-weight: 600; margin: 0; letter-spacing: -0.5px; } .header-controls { display: flex; align-items: center; gap: 1.5rem; } .user-info { font-size: 0.9rem; opacity: 0.9; } .user-info span { margin-right: 1rem; } .user-info a { color: #ced4da; text-decoration: none; transition: color 0.2s ease; } .user-info a:hover { color: var(--header-color); } .theme-toggle-btn { background: none; border: none; color: var(--header-color); font-size: 1.3rem; cursor: pointer; padding: 0.3rem; transition: color 0.3s ease; opacity: 0.8;} .theme-toggle-btn:hover { color: var(--primary-color); opacity: 1; }
        /* Main Container & Page Header */
        .container { max-width: var(--content-max-width); margin: 2.5rem auto; padding: 0 1.5rem; flex-grow: 1; }
        .page-header { text-align: center; margin-bottom: 3rem; display: flex; flex-direction: column; align-items: center; gap: 1rem; } .lottie-container { width: 90px; height: 90px; margin-bottom: 0.5rem; } .page-title { color: var(--primary-color); margin-bottom: 0.75rem; font-size: 2.4rem; font-weight: 700; letter-spacing: -1px; } .intro-text { color: var(--text-muted); font-size: 1.1rem; max-width: 700px; margin: 0 auto; font-weight: 400; }
        /* Flash Messages */
        .message-container { max-width: var(--content-max-width); margin: -1.5rem auto 1.5rem auto; padding: 0 1.5rem; min-height: 1px; /* Prevent collapse */ } .flash-message { padding: 1rem 1.5rem; margin-bottom: 1rem; border-radius: var(--border-radius-input); border: 1px solid transparent; font-size: 0.95rem; display: flex; align-items: center; gap: 0.8rem; box-shadow: var(--input-shadow); background-color: var(--white-bg); backdrop-filter: blur(5px); transition: opacity 0.5s ease-out, transform 0.5s ease-out; } html[data-theme='dark'] .flash-message { background-color: var(--dark-card-bg); } .flash-message i { font-size: 1.3rem; flex-shrink: 0; } .flash-message.success { background-color: var(--success-bg); color: #0f5132; border-color: var(--success-border); } .flash-message.success i { color: var(--success-color); } html[data-theme='dark'] .flash-message.success { background-color: rgba(22, 163, 74, 0.2); color: #a7f3d0; border-color: rgba(22, 163, 74, 0.5); } .flash-message.error { background-color: var(--danger-bg); color: #842029; border-color: var(--danger-border); } .flash-message.error i { color: var(--danger-color); } html[data-theme='dark'] .flash-message.error { background-color: rgba(239, 68, 68, 0.2); color: #fecaca; border-color: rgba(239, 68, 68, 0.5); } .flash-message.warning { background-color: var(--warning-bg); color: #664d03; border-color: var(--warning-border); } .flash-message.warning i { color: var(--warning-color); } html[data-theme='dark'] .flash-message.warning { background-color: rgba(255, 193, 7, 0.15); color: #ffeaa7; border-color: rgba(255, 193, 7, 0.4); }
        /* Removed .validation-errors */

        /* Featured Tip Section Enhanced */
        .featured-tip-section { background: var(--primary-gradient); color: #fff; padding: 2.5rem 2rem; margin-top: 1rem; margin-bottom: 3rem; border-radius: var(--border-radius); box-shadow: 0 10px 30px rgba(0, 86, 179, 0.25); text-align: center; position: relative; overflow: hidden; }
        .featured-tip-section::before { /* Subtle gradient animation */ content: ''; position: absolute; top: 0; left: -100%; width: 300%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.1), transparent); animation: shine 8s infinite linear; z-index: 0;}
        @keyframes shine { 0% { left: -150%; } 50% { left: 150%; } 100% { left: 150%; } }
        .featured-tip-section > * { position: relative; z-index: 1; }
        .featured-tip-title { margin-top: 0; margin-bottom: 1.5rem; font-size: 1.8rem; font-weight: 600; letter-spacing: -0.5px; color: #fff; display: inline-block; /* For glow */ position: relative; }
        .featured-tip-title i { margin: 0 0.5rem; opacity: 0.8; vertical-align: middle; }
        .featured-tip-title::after { /* Top Tip Glow */ content: ''; position: absolute; top: 50%; left: 50%; width: 110%; height: 130%; background: radial-gradient(ellipse at center, rgba(255,255,255,0.15) 0%,rgba(255,255,255,0) 70%); transform: translate(-50%, -50%); border-radius: 50%; z-index: -1; animation: pulseGlow 3s infinite ease-in-out; pointer-events: none;}
        @keyframes pulseGlow { 0%, 100% { opacity: 0.5; transform: translate(-50%, -50%) scale(1); } 50% { opacity: 0.8; transform: translate(-50%, -50%) scale(1.1); } }
        .featured-tip-content .tip-card { background-color: var(--glass-bg); backdrop-filter: blur(8px); border: 1px solid var(--glass-border-color); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1); padding: 1.8rem; border-radius: var(--border-radius-sm); text-align: left; display: flex; align-items: center; gap: 1.5rem; } html[data-theme='dark'] .featured-tip-content .tip-card { background-color: var(--dark-glass-bg); border-color: var(--dark-glass-border-color); }
        .featured-tip-content .tip-icon { font-size: 2.5rem; color: #fff; flex-shrink: 0; opacity: 0.9; } .featured-tip-content .tip-content { flex-grow: 1; } .featured-tip-content h3 { color: #f0f0f0; font-size: 1.4rem; margin-bottom: 0.6rem; font-weight: 600; } .featured-tip-content p { color: rgba(255, 255, 255, 0.9); margin-bottom: 1rem; font-size: 1rem; opacity: 1; } .featured-tip-content .tip-category { background-color: rgba(0, 0, 0, 0.25); color: #f8f9fa; border: none; padding: 0.3rem 0.8rem; font-weight: 500; font-size: 0.75rem; border-radius: 5px; text-transform: uppercase; }

        /* Tip Filter Controls */
        .controls-container { background-color: var(--glass-bg); backdrop-filter: blur(8px); padding: 1.5rem 2rem; border-radius: var(--border-radius); margin-bottom: 3rem; box-shadow: var(--card-shadow); border: 1px solid var(--glass-border-color); transition: background-color 0.3s ease, border-color 0.3s ease; } html[data-theme='dark'] .controls-container { background-color: var(--dark-glass-bg); border-color: var(--dark-glass-border-color); }
        .controls-title { font-size: 0.9rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 1rem; text-align: center; } .filter-buttons { display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: center; }
        .filter-btn { background-color: rgba(233, 236, 239, 0.6); color: #495057; border: 1px solid rgba(222, 226, 230, 0.6); padding: 0.5rem 1rem; border-radius: var(--border-radius-sm); cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease-out; white-space: nowrap; box-shadow: 0 1px 2px rgba(0,0,0,0.05); } html[data-theme='dark'] .filter-btn { background-color: rgba(75, 85, 99, 0.6); color: var(--dark-text-muted); border-color: rgba(75, 85, 99, 0.8); }
        .filter-btn:hover { background-color: rgba(222, 226, 230, 0.8); border-color: #ced4da; transform: translateY(-1px); } html[data-theme='dark'] .filter-btn:hover { background-color: rgba(96, 110, 128, 0.7); border-color: var(--dark-text-muted); }
        .filter-btn.active { background-image: var(--primary-gradient); color: #fff; border-color: transparent; font-weight: 600; box-shadow: 0 3px 8px rgba(13, 110, 253, 0.3); transform: translateY(-1px); } html[data-theme='dark'] .filter-btn.active { box-shadow: 0 3px 8px rgba(96, 165, 250, 0.3); }

        /* Tips Grid & Cards */
        .tips-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.8rem; align-items: stretch; margin-bottom: 3rem; }
        .tip-card { /* Base styles */ background-color: var(--glass-bg); backdrop-filter: blur(10px); border-radius: var(--border-radius); box-shadow: var(--card-shadow); border: 1px solid var(--glass-border-color); padding: 1.8rem; display: flex !important; align-items: flex-start; gap: 1.4rem; transition: transform 0.25s ease-in-out, box-shadow 0.25s ease-in-out, opacity 0.3s ease-out; opacity: 1; position: relative; /* For quote style */ }
        /* Quote Style */
        .tip-card::before { content: ''; position: absolute; left: 0; top: 1.8rem; bottom: 1.8rem; width: 5px; background-color: var(--primary-color); opacity: 0.5; border-radius: 0 3px 3px 0; transition: opacity 0.3s ease; }
        .tip-card:hover::before { opacity: 0.8; }
        html[data-theme='dark'] .tip-card { background-color: var(--dark-glass-bg); border-color: var(--dark-glass-border-color); }
        .tip-card.hidden { transform: scale(0.95); opacity: 0; pointer-events: none; display: flex !important; transition: transform 0.2s ease-in, opacity 0.2s ease-in, height 0s 0.2s, margin 0s 0.2s, padding 0s 0.2s; height: 0; margin: 0; padding: 0; overflow: hidden; border: none; } .tip-card:hover { transform: translateY(-5px) scale(1.01); box-shadow: var(--card-hover-shadow); }
        .tips-grid .tip-card .tip-icon { font-size: 2rem; flex-shrink: 0; color: var(--primary-color); width: 45px; text-align: center; margin-top: 0.2em; opacity: 0.85; transition: transform 0.3s ease; } .tips-grid .tip-card:hover .tip-icon { transform: scale(1.1) rotate(-5deg); } .tips-grid .tip-card .tip-content { flex-grow: 1; } .tips-grid .tip-card h3 { color: var(--text-color); margin-top: 0; margin-bottom: 0.6rem; font-size: 1.2rem; font-weight: 600; line-height: 1.4; } .tips-grid .tip-card p { margin-bottom: 1rem; color: var(--text-muted); font-size: 0.95rem; font-weight: 400; /* Placeholder for expand/collapse */ /* max-height: 5.1em; /* Approx 3 lines */ /* overflow: hidden; */ /* position: relative; */ }
        /* Placeholder for "Read More" */
        /* .tip-card p.expandable::after { content: '... Read More'; position: absolute; bottom: 0; right: 0; background: linear-gradient(to right, transparent, var(--glass-bg) 50%); padding-left: 1rem; color: var(--primary-color); cursor: pointer; font-weight: 500; font-size: 0.9em; } */
        /* html[data-theme='dark'] .tip-card p.expandable::after { background: linear-gradient(to right, transparent, var(--dark-glass-bg) 50%); } */
        /* .tip-card p.expanded { max-height: none; } */
        /* .tip-card p.expanded::after { content: 'Show Less'; } */

        .tips-grid .tip-card .tip-category { display: inline-block; font-size: 0.7rem; background-color: rgba(238, 242, 247, 0.7); color: #5a7899; padding: 0.25rem 0.7rem; border-radius: 15px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; border: none; margin-bottom: 0.8rem; transition: background-color 0.3s ease, color 0.3s ease; }
        html[data-theme='dark'] .tips-grid .tip-card .tip-category { background-color: rgba(55, 65, 81, 0.7); color: var(--dark-text-muted); }
        /* --- Category Color Coding --- */
        .tip-card[data-category="Budgeting"] .tip-category { background-color: rgba(13, 110, 253, 0.15); color: #0a58ca; } html[data-theme='dark'] .tip-card[data-category="Budgeting"] .tip-category { background-color: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .tip-card[data-category="Saving"] .tip-category { background-color: rgba(25, 135, 84, 0.15); color: #146c43; } html[data-theme='dark'] .tip-card[data-category="Saving"] .tip-category { background-color: rgba(16, 185, 129, 0.2); color: #6ee7b7; }
        .tip-card[data-category="Investing"] .tip-category { background-color: rgba(111, 66, 193, 0.15); color: #6f42c1; } html[data-theme='dark'] .tip-card[data-category="Investing"] .tip-category { background-color: rgba(167, 139, 250, 0.2); color: #c4b5fd; }
        .tip-card[data-category="Debt Management"] .tip-category { background-color: rgba(220, 53, 69, 0.1); color: #b02a37; } html[data-theme='dark'] .tip-card[data-category="Debt Management"] .tip-category { background-color: rgba(244, 63, 94, 0.2); color: #fda4af; }
        .tip-card[data-category="Monitoring"] .tip-category { background-color: rgba(253, 126, 20, 0.15); color: #fd7e14; } html[data-theme='dark'] .tip-card[data-category="Monitoring"] .tip-category { background-color: rgba(251, 146, 60, 0.2); color: #fdba74; }
        /* Add more category styles */

         /* Placeholder buttons within tip card */
        .tip-actions { margin-top: 1rem; display: flex; gap: 0.5rem; opacity: 0.7; }
        .tip-action-btn { background: none; border: none; padding: 0.2rem 0.4rem; font-size: 0.8rem; color: var(--text-muted); cursor: pointer; transition: color 0.2s; border-radius: var(--border-radius-sm); }
        .tip-action-btn:hover { color: var(--primary-color); background-color: rgba(13, 110, 253, 0.1); }
        .tip-action-btn i { margin-right: 0.3em; }
        html[data-theme='dark'] .tip-action-btn:hover { color: #93c5fd; background-color: rgba(59, 130, 246, 0.15); }

        .no-results { display: none; text-align: center; padding: 3rem 1rem; color: var(--secondary-color); font-style: italic; grid-column: 1 / -1; font-size: 1.1rem; background-color: rgba(255, 255, 255, 0.7); backdrop-filter: blur(5px); border-radius: var(--border-radius); border: 1px dashed var(--glass-border-color); } html[data-theme='dark'] .no-results { background-color: var(--dark-glass-bg); border-color: var(--dark-glass-border-color); } .no-results i { display: block; font-size: 2.5rem; margin-bottom: 1rem; color: #ced4da; }
        /* Inspirational Quote Section Placeholder */
        .inspirational-quote { text-align: center; margin: 3rem auto; padding: 1.5rem; max-width: 700px; border-left: 4px solid var(--primary-color); background-color: var(--white-bg); box-shadow: var(--input-shadow); border-radius: var(--border-radius-sm); }
        .inspirational-quote p { font-style: italic; font-size: 1.1rem; color: var(--text-muted); margin-bottom: 0.5rem; }
        .inspirational-quote cite { font-size: 0.9rem; color: var(--secondary-color); }
        html[data-theme='dark'] .inspirational-quote { background-color: var(--dark-card-bg); border-left-color: #3b82f6; }

        /* Removed Goal Modal Styles */

        /* Back Link, Footer */
        .back-link-container { text-align: center; margin-top: 4rem; padding-top: 2rem; border-top: 1px solid var(--card-border-color); transition: border-color 0.3s ease; } html[data-theme='dark'] .back-link-container { border-top-color: var(--dark-border-color); } .back-link { color: var(--secondary-color); text-decoration: none; font-size: 1rem; display: inline-flex; align-items: center; gap: 0.5rem; transition: color 0.2s, background-color 0.2s; padding: 0.7rem 1.5rem; border: 1px solid var(--card-border-color); border-radius: var(--border-radius-sm); font-weight: 500; } html[data-theme='dark'] .back-link { border-color: var(--dark-border-color); } .back-link:hover { color: var(--primary-color); background-color: rgba(13, 110, 253, 0.1); border-color: rgba(13, 110, 253, 0.2); } html[data-theme='dark'] .back-link:hover { background-color: rgba(59, 130, 246, 0.15); border-color: rgba(59, 130, 246, 0.3); } .back-link i { margin-right: 0.3em; }
        .footer { text-align: center; margin-top: auto; padding: 2rem 1rem 1.5rem 1rem; font-size: 0.9rem; color: var(--text-muted); border-top: 1px solid var(--card-border-color); background-color: var(--light-bg); transition: background-color 0.3s ease, border-color 0.3s ease; } html[data-theme='dark'] .footer { background-color: var(--dark-bg); border-top-color: var(--dark-border-color); }
        /* Animation Keyframes */
        @keyframes popIn { 0% { transform: scale(0.5); opacity: 0; } 60% { transform: scale(1.1); opacity: 1; } 100% { transform: scale(1); } }
        /* Responsiveness */
        @media (max-width: 992px) { .container { max-width: 90%; } .tips-grid { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); } }
        @media (max-width: 768px) { .header { padding: 0.8rem 1.5rem; } .header-logo h1 { font-size: 1.4rem; } .user-info span { display: none; } .container { margin: 2rem auto; padding: 0 1rem;} .message-container { padding: 0 1rem; margin: 1rem auto;} .page-title { font-size: 2rem; } .intro-text { font-size: 1rem; } .featured-tip-section { padding: 2rem 1.5rem; backdrop-filter: blur(8px); } .featured-tip-title { font-size: 1.6rem; } .featured-tip-content .tip-card { flex-direction: column; align-items: flex-start; text-align: left; gap: 1rem; backdrop-filter: blur(5px);} .featured-tip-content .tip-icon { font-size: 2rem; margin-bottom: 0.5rem; } .featured-tip-content h3 { font-size: 1.25rem; } .tips-grid { grid-template-columns: 1fr; gap: 1.5rem; } .tip-card { padding: 1.5rem; gap: 1.2rem; backdrop-filter: blur(8px); } .tips-grid .tip-card .tip-icon { font-size: 1.8rem; width: 40px; margin-top: 0.1em; } .filter-buttons { gap: 0.5rem; } .filter-btn { padding: 0.4rem 0.8rem; font-size: 0.85rem;} }
        @media (max-width: 576px) { .header { flex-direction: column; align-items: flex-start; } .header-logo { margin-bottom: 0.5rem; } .user-info { margin-left: 0; text-align: left; width: 100%; margin-top: 0.5rem;} .container { margin: 1.5rem auto; } .page-title { font-size: 1.8rem; } .featured-tip-section { border-radius: var(--border-radius-sm); } .controls-container { padding: 1rem; backdrop-filter: blur(5px); } .tip-card { padding: 1.2rem; backdrop-filter: blur(5px); } .tips-grid .tip-card .tip-icon { font-size: 1.6rem; width: 35px; } .tips-grid .tip-card h3 { font-size: 1.1rem; } .tips-grid .tip-card p { font-size: 0.9rem; } .back-link { padding: 0.6rem 1.2rem; font-size: 0.9rem; } .footer { padding: 1.5rem 1rem; font-size: 0.85rem; } }

    </style>
</head>
<body>

    <header class="header">
        <a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="header-logo">
             <i class="fas fa-lightbulb"></i><h1>Financial Tips</h1> <!-- Updated Title & Icon -->
        </a>
        <div class="header-controls">
            <div class="user-info"><span>Welcome, <?php echo htmlspecialchars($userName); ?>!</span></div>
            <button id="theme-toggle" class="theme-toggle-btn" title="Toggle theme"><i class="fas fa-moon"></i></button>
            <a href="logout.php" title="Logout" style="color: #ced4da; text-decoration:none; display: inline-flex; align-items: center; gap: 0.3rem;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </header>

     <div class="message-container">
        <?php // Flash message display (no changes needed) ?>
        <?php if ($flashMessage): ?><div class="flash-message <?php echo htmlspecialchars($flashMessage['type']); ?>"> ... </div><?php endif; ?>
     </div>

    <div class="container">
        <!-- Page Header for Tips -->
         <div class="page-header">
             <div class="lottie-container">
                  <lottie-player src="https://assets9.lottiefiles.com/packages/lf20_zv25KW.json" background="transparent" speed="1" style="width: 100%; height: 100%;" loop autoplay title="Lightbulb idea icon"></lottie-player>
             </div>
            <h1 class="page-title">Smart Financial Tips</h1>
            <p class="intro-text">Empower your financial journey with these actionable tips. Filter by category to focus on specific areas.</p>
        </div>

        <!-- Featured Tip Section -->
        <section class="featured-tip-section">
             <h2 class="featured-tip-title"><i class="fas fa-star" title="Editor's Pick"></i> Today's Top Tip <i class="fas fa-star" title="Editor's Pick"></i></h2>
             <div class="featured-tip-content"><div class="tip-card"><?php if (!empty($tipOfTheDay['icon'])): ?><div class="tip-icon"><i class="<?php echo htmlspecialchars($tipOfTheDay['icon']); ?>"></i></div><?php endif; ?><div class="tip-content"><?php if (!empty($tipOfTheDay['title'])): ?><h3><?php echo htmlspecialchars($tipOfTheDay['title']); ?></h3><?php endif; ?><p><?php echo htmlspecialchars($tipOfTheDay['tip']); ?></p><?php if (!empty($tipOfTheDay['category'])): ?><span class="tip-category"><?php echo htmlspecialchars($tipOfTheDay['category']); ?></span><?php endif; ?></div></div></div>
        </section>

        <!-- Inspirational Quote Placeholder -->
        <section class="inspirational-quote">
            <p>"The best way to predict the future is to create it."</p>
            <cite>– Peter Drucker (Example)</cite>
        </section>

        <!-- Filter Controls -->
        <div class="controls-container">
             <h3 class="controls-title">Explore Tips by Category</h3>
             <!-- Placeholder Search Bar -->
             <!-- <div style="margin-bottom: 1rem; text-align: center;"><input type="search" placeholder="Search tips..." style="padding: 0.5rem; border-radius: var(--border-radius-sm); border: 1px solid var(--input-border-color);" disabled title="Search not implemented"></div> -->
            <div class="filter-buttons"><button class="filter-btn active" data-category="all" title="Show all categories"><i class="fas fa-list-ul fa-xs"></i> Show All</button><?php foreach ($categories as $category): ?><button class="filter-btn" data-category="<?php echo htmlspecialchars($category); ?>" title="Show tips for <?php echo htmlspecialchars($category); ?>"><?php echo htmlspecialchars($category); ?></button><?php endforeach; ?></div>
        </div>

        <!-- Tips Grid -->
        <div class="tips-grid" id="tipsGrid">
            <?php if (empty($financialTips)): ?><div class="no-results" style="display: block;"><i class="fas fa-info-circle"></i> No financial tips available.</div>
            <?php else: ?>
                <?php foreach ($financialTips as $index => $tipData): ?>
                    <div class="tip-card" data-category="<?php echo htmlspecialchars($tipData['category']); ?>" data-index="<?php echo $index; ?>">
                        <?php if (!empty($tipData['icon'])): ?><div class="tip-icon"><i class="<?php echo htmlspecialchars($tipData['icon']); ?>"></i></div><?php endif; ?>
                        <div class="tip-content">
                            <?php if (!empty($tipData['title'])): ?><h3><?php echo htmlspecialchars($tipData['title']); ?></h3><?php endif; ?>
                            <p><?php echo htmlspecialchars($tipData['tip']); ?>
                               <!-- Placeholder Read More - Requires JS -->
                               <!-- <span class="read-more-btn" style="color: var(--primary-color); cursor: pointer; font-weight: 500;"> Read More</span> -->
                            </p>
                            <?php if (!empty($tipData['category'])): ?><span class="tip-category" style="background-color: hsla(var(--category-<?php echo strtolower(str_replace(' ', '-', $tipData['category'])); ?>, 200), 100%, 50%, 0.15); color: hsl(var(--category-<?php echo strtolower(str_replace(' ', '-', $tipData['category'])); ?>, 200), 80%, 30%);"><?php echo htmlspecialchars($tipData['category']); ?></span><?php endif; ?>
                            <!-- Placeholder Action Buttons -->
                            <div class="tip-actions">
                                <button class="tip-action-btn" title="Save to Favorites (Not Implemented)"><i class="far fa-bookmark"></i> Save</button>
                                <button class="tip-action-btn" title="Rate Tip (Not Implemented)"><i class="far fa-thumbs-up"></i> Rate</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                 <div class="no-results" id="noResultsMessage"><i class="fas fa-search"></i> No tips match your filter.</div>
            <?php endif; ?>
        </div>
         <!-- Placeholder for Next/Previous Buttons -->
         <!-- <div style="text-align: center; margin-bottom: 2rem;"><button disabled>Previous Tip</button> <button disabled>Next Tip</button></div> -->


        <div class="back-link-container"><a href="<?php echo htmlspecialchars($dashboardUrl); ?>" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></div>
    </div>

    <!-- Goal Add/Edit Modal Removed -->

    <footer class="footer">© <?php echo date('Y'); ?> Financial Wellness Hub. Your journey to financial freedom starts here.</footer>

    <script>
        // --- Dark Mode Toggle Logic ---
        const themeToggle = document.getElementById('theme-toggle'); const htmlElement = document.documentElement; const sunIconClass = 'fas fa-sun'; const moonIconClass = 'fas fa-moon';
        const setMode = (isDark) => { const iconElement = themeToggle?.querySelector('i'); if (isDark) { htmlElement.setAttribute('data-theme', 'dark'); localStorage.setItem('theme', 'dark'); if (iconElement) iconElement.className = sunIconClass; if (themeToggle) themeToggle.title = 'Switch to light'; } else { htmlElement.removeAttribute('data-theme'); localStorage.setItem('theme', 'light'); if (iconElement) iconElement.className = moonIconClass; if (themeToggle) themeToggle.title = 'Switch to dark'; } };
        if (themeToggle) { const isInitiallyDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches; const savedTheme = localStorage.getItem('theme'); const initialIconClass = ((savedTheme === 'dark') || (!savedTheme && isInitiallyDark)) ? sunIconClass : moonIconClass; themeToggle.querySelector('i').className = initialIconClass; if ((savedTheme === 'dark') || (!savedTheme && isInitiallyDark)) { htmlElement.setAttribute('data-theme', 'dark'); themeToggle.title = 'Switch to light'; } else { htmlElement.removeAttribute('data-theme'); themeToggle.title = 'Switch to dark'; } themeToggle.addEventListener('click', () => { setMode(!htmlElement.hasAttribute('data-theme')); }); } else { const isInitiallyDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches; if (localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && isInitiallyDark)) { htmlElement.setAttribute('data-theme', 'dark'); } }

        // --- Flash Message Auto-Hide ---
        const flashMessageDiv = document.getElementById('flash-message'); if (flashMessageDiv) { setTimeout(() => { flashMessageDiv.style.transition = 'opacity 0.5s ease-out, transform 0.5s ease-out'; flashMessageDiv.style.opacity = '0'; flashMessageDiv.style.transform = 'translateY(-10px)'; flashMessageDiv.addEventListener('transitionend', () => flashMessageDiv.remove(), { once: true }); }, 5000); }

        // --- Tip Filtering JS ---
        document.addEventListener('DOMContentLoaded', () => {
            const filterButtons = document.querySelectorAll('.filter-btn'); const tipsGrid = document.getElementById('tipsGrid'); const tipCards = tipsGrid ? tipsGrid.querySelectorAll(':scope > .tip-card') : []; const noResultsMessage = document.getElementById('noResultsMessage'); let currentFilter = 'all';
            filterButtons.forEach(button => { button.addEventListener('click', () => { filterButtons.forEach(btn => btn.classList.remove('active')); button.classList.add('active'); currentFilter = button.getAttribute('data-category'); applyFilter(); }); });
            function applyFilter() { if (!tipsGrid) return; let visibleCount = 0; tipCards.forEach(card => { const cardCategory = card.getAttribute('data-category'); const matchesFilter = (currentFilter === 'all' || cardCategory === currentFilter); if (matchesFilter) { card.classList.remove('hidden'); visibleCount++; } else { card.classList.add('hidden'); } }); if (noResultsMessage) { noResultsMessage.style.display = (visibleCount === 0 && tipCards.length > 0) ? 'block' : 'none'; } }
            if (noResultsMessage) noResultsMessage.style.display = 'none'; // Initially hide
        });

        // --- Placeholder JS for Read More (Example - Needs more work) ---
        // document.querySelectorAll('.read-more-btn').forEach(btn => {
        //     btn.addEventListener('click', (e) => {
        //         const p = e.target.closest('p');
        //         if (p) {
        //              p.classList.toggle('expanded');
        //              // Update button text if needed
        //         }
        //     });
        // });

    </script>

</body>
</html>