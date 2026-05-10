<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Study Planner - Educational Management System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #3498db;
            --secondary: #2ecc71;
            --accent: #e74c3c;
            --dark: #2c3e50;
            --light: #ecf0f1;
            --text: #34495e;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--text);
            background-color: #fff;
        }
        
        header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem 0;
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        nav {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        nav a {
            color: white;
            text-decoration: none;
            transition: 0.3s;
        }
        
        nav a:hover {
            opacity: 0.8;
            text-decoration: underline;
        }
        
        .auth-buttons {
            display: flex;
            gap: 1rem;
        }
        
        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #2980b9;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background-color: white;
            color: var(--primary);
        }
        
        .btn-secondary:hover {
            background-color: var(--light);
        }
        
        main {
            margin-top: 60px;
        }
        
        .hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 6rem 2rem;
            text-align: center;
        }
        
        .hero h1 {
            font-size: 3rem;
            margin-bottom: 1rem;
            animation: slideDown 0.8s ease;
        }
        
        .hero p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            animation: slideUp 0.8s ease;
        }
        
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .features {
            max-width: 1200px;
            margin: 4rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 1rem;
        }
        
        .feature-card h3 {
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .roles {
            background-color: var(--light);
            padding: 4rem 2rem;
        }
        
        .roles-container {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 2rem;
        }
        
        .role-card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            border-left: 4px solid var(--primary);
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }
        
        .role-card h3 {
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        footer {
            background-color: var(--dark);
            color: white;
            text-align: center;
            padding: 2rem;
            margin-top: 4rem;
        }
        
        .cta-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 4rem 2rem;
            text-align: center;
        }
        
        .cta-section h2 {
            font-size: 2rem;
            margin-bottom: 1rem;
        }
        
        .cta-section p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 768px) {
            .hero h1 {
                font-size: 2rem;
            }
            
            nav {
                gap: 1rem;
            }
            
            .header-container {
                padding: 0 1rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="logo">📚 Smart Study Planner</div>
            <nav>
                <a href="#features">Features</a>
                <a href="#roles">Roles</a>
                <a href="#about">About</a>
            </nav>
            <div class="auth-buttons">
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="dashboard.php" class="btn btn-primary">Dashboard</a>
                    <a href="logout.php" class="btn btn-secondary">Logout</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary">Login</a>
                    <a href="register.php" class="btn btn-secondary">Register</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main>
        <section class="hero">
            <h1>Smart Study Planner</h1>
            <p>A comprehensive educational management system for seamless learning</p>
            <?php if (!isset($_SESSION['user_id'])): ?>
                <a href="register.php" class="btn btn-secondary" style="background-color: white; color: var(--primary); padding: 0.8rem 2rem;">Get Started Now</a>
            <?php endif; ?>
        </section>

        <section id="features" class="features">
            <div class="feature-card">
                <div class="feature-icon">📝</div>
                <h3>Task Management</h3>
                <p>Create and assign tasks to students with deadlines and file attachments.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📅</div>
                <h3>Calendar Integration</h3>
                <p>Manage all your events and deadlines in an integrated calendar system.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔔</div>
                <h3>Notifications</h3>
                <p>Real-time notifications for task updates, submissions, and grades.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Progress Tracking</h3>
                <p>Monitor student progress and performance with detailed analytics.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🔐</div>
                <h3>Secure System</h3>
                <p>Email verification and role-based access control for security.</p>
            </div>
            <div class="feature-card">
                <div class="feature-icon">⚙️</div>
                <h3>Admin Dashboard</h3>
                <p>Complete system management and monitoring for administrators.</p>
            </div>
        </section>

        <section id="roles" class="roles">
            <h2 style="text-align: center; margin-bottom: 2rem; color: var(--dark);">User Roles</h2>
            <div class="roles-container">
                <div class="role-card">
                    <h3>👨‍🎓 Student</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li>✓ View assigned tasks</li>
                        <li>✓ Submit assignments</li>
                        <li>✓ Check grades and feedback</li>
                        <li>✓ Track calendar events</li>
                        <li>✓ Manage notifications</li>
                    </ul>
                </div>
                <div class="role-card">
                    <h3>👨‍🏫 Teacher</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li>✓ Create and manage tasks</li>
                        <li>✓ Add file resources</li>
                        <li>✓ Grade submissions</li>
                        <li>✓ Monitor student progress</li>
                        <li>✓ Send notifications</li>
                    </ul>
                </div>
                <div class="role-card">
                    <h3>🔐 Admin</h3>
                    <ul style="list-style: none; padding: 0;">
                        <li>✓ Manage all users</li>
                        <li>✓ View system statistics</li>
                        <li>✓ Monitor all tasks</li>
                        <li>✓ Control system settings</li>
                        <li>✓ View performance reports</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="cta-section">
            <h2>Ready to Transform Your Learning Experience?</h2>
            <p>Join Smart Study Planner today and streamline your educational journey</p>
            <a href="register.php" class="btn btn-primary" style="background-color: white; color: var(--primary); padding: 0.8rem 2rem;">Start Free Today</a>
        </section>
    </main>

    <footer id="about">
        <h3>About Smart Study Planner</h3>
        <p>Smart Study Planner is an innovative educational management system designed to bridge the gap between teachers and students.</p>
        <p>&copy; 2024 Smart Study Planner. All rights reserved.</p>
    </footer>
</body>
</html>
