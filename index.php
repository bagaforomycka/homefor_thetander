<?php
session_start();

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'caretaker') {
        header('Location: caretaker/dashboard.php');
        exit;
    }

    if (in_array($_SESSION['role'], ['admin', 'staff'], true)) {
        header('Location: admin/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Home for the Tender | Compassionate Care</title>

    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet">

    <style>
        :root {
            --green: #33b960;
            --dark-green: #17683a;
            --pale-green: #edf8ef;
            --text: #304b3b;
        }

        html {
            scroll-behavior: smooth;
            scroll-padding-top: 25px;
        }

        body {
            margin: 0;
            background: #f8fbf8;
            color: var(--text);
            font-family: Arial, sans-serif;
        }

        .navbar {
            background: #fff;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }

        .brand-name {
            color: var(--dark-green);
            font-size: 1.45rem;
            font-weight: 700;
        }

        .brand-tagline {
            color: #64816c;
            font-size: .8rem;
            letter-spacing: 2px;
        }

        .hero {
            background: linear-gradient(120deg, #eaf7ec, #fff 60%, #e3f3e4);
            padding: 65px 0;
        }

        .hero-title {
            color: var(--dark-green);
            font-size: clamp(2.2rem, 4vw, 3.5rem);
            font-weight: 700;
            line-height: 1.18;
        }

        .hero-description {
            color: #506b59;
            font-size: 1.05rem;
            line-height: 1.85;
        }

        .hero-quote {
            border-left: 4px solid var(--green);
            padding: 12px 16px;
            color: var(--dark-green);
            font-style: italic;
            background: rgba(255,255,255,.65);
            border-radius: 0 8px 8px 0;
        }

        .login-card {
            padding: 32px;
            background: #fff;
            border: 1px solid #e1eee3;
            border-radius: 18px;
            box-shadow: 0 12px 35px rgba(28, 90, 48, .12);
        }

        .login-title, .section-title {
            color: var(--dark-green);
            font-weight: 700;
        }

        .form-control, .form-select {
            padding: 12px;
            border-radius: 8px;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--green);
            box-shadow: 0 0 0 .2rem rgba(51,185,96,.16);
        }

        .btn-green {
            background: var(--green);
            color: #fff;
            border: 1px solid var(--green);
            padding: 12px;
            border-radius: 8px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #269b4b;
            border-color: #269b4b;
            color: #fff;
        }

        .btn-outline-green {
            color: var(--dark-green);
            border: 1px solid var(--green);
            border-radius: 8px;
            padding: 11px;
            font-weight: 700;
        }

        .btn-outline-green:hover {
            background: var(--pale-green);
            color: var(--dark-green);
        }

        .green-link {
            color: var(--dark-green);
        }

        .offers {
            padding: 65px 0;
            background: #fff;
        }

        .offer-card {
            height: 100%;
            padding: 28px 20px;
            border: 1px solid #e3eee4;
            border-radius: 15px;
            background: #f8fbf8;
            transition: transform .2s, box-shadow .2s;
        }

        .offer-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(25,90,45,.08);
        }

        .offer-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 66px;
            height: 66px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: #e0f3e3;
            font-size: 30px;
        }

        .offer-card h5 {
            color: var(--dark-green);
            font-weight: 700;
        }

        footer {
            background: #175c38;
            color: #fff;
            padding: 25px 0;
        }

        footer a {
            color: #fff;
        }

        @media (max-width: 767px) {
            .hero {
                padding: 40px 0;
            }

            .login-card {
                padding: 24px;
            }

            .offers {
                padding: 45px 0;
            }
        }
    </style>
</head>

<body>

<!-- Navigation -->
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container py-2">
        <a class="navbar-brand text-decoration-none" href="index.php">
            <span class="brand-name">🏡 Home for the Tender</span>
            <span class="brand-tagline d-block">CARE · COMFORT · DIGNITY</span>
        </a>

        <div class="ms-auto d-flex gap-2 align-items-center">
            <a href="#what-we-offer"
               class="btn btn-outline-success btn-sm">Our Services</a>
            <a href="#login" class="btn btn-success btn-sm">Log In</a>
        </div>
    </div>
</nav>

<!-- Welcome section and login -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">

            <div class="col-lg-6">
                <span class="badge rounded-pill text-bg-success mb-3">
                    Compassionate Senior Care
                </span>

                <h1 class="hero-title">
                    A Place Where Care Feels Like Family
                </h1>

                <p class="hero-description mt-4">
                    At Home for the Tender, we believe every senior deserves
                    to feel safe, respected, valued, and loved. We aim to
                    provide a welcoming environment where residents can
                    experience comfort, dignity, and meaningful connections.
                </p>

                <p class="hero-description">
                    Our care management system helps authorized staff
                    coordinate resident information, schedules, care tasks,
                    and observations to support organized and attentive care.
                </p>

                <div class="hero-quote mt-4">
                    “Every resident deserves kindness, dignity, and a place
                    to call home.”
                </div>

                <a href="#what-we-offer"
                   class="btn btn-green mt-4 px-4">
                    Discover What We Offer ↓
                </a>
            </div>

            <!-- Login panel -->
            <div class="col-lg-5 offset-lg-1" id="login">
                <div class="login-card">

                    <div class="text-center mb-4">
                        <div class="display-6">💚</div>
                        <h2 class="login-title">Welcome Back</h2>
                        <p class="text-secondary">
                            Log in to your account to continue.
                        </p>
                    </div>

                    <form action="auth/login.php" method="POST">
                        <div class="mb-3">
                            <label for="role" class="form-label fw-semibold">
                                Account Role
                            </label>
                            <select class="form-select" id="role"
                                    name="role" required>
                                <option value="">Select your role</option>
                                <option value="admin">Admin</option>
                                <option value="staff">Staff</option>
                                <option value="caretaker">Caretaker</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="username" class="form-label fw-semibold">
                                Username
                            </label>
                            <input type="text" class="form-control"
                                   id="username" name="username"
                                   placeholder="Enter your username"
                                   autocomplete="username" required>
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label fw-semibold">
                                Password
                            </label>
                            <input type="password" class="form-control"
                                   id="password" name="password"
                                   placeholder="Enter your password"
                                   autocomplete="current-password" required>
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox"
                                   id="showPassword"
                                   onchange="togglePassword()">
                            <label class="form-check-label"
                                   for="showPassword">
                                Show password
                            </label>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox"
                                   id="accept_terms" name="accept_terms"
                                   value="1" required>
                            <label class="form-check-label"
                                   for="accept_terms">
                                I have read and agree to the
                                <a href="terms.php" target="_blank"
                                   rel="noopener" class="green-link">
                                    Terms and Conditions
                                </a>.
                            </label>
                        </div>

                        <button type="submit" class="btn btn-green w-100">
                            Log In
                        </button>
                    </form>

                    <div class="text-center text-secondary my-3">OR</div>

                    <a href="auth/register.php"
                       class="btn btn-outline-green w-100">
                        ＋ Create Account
                    </a>

                    <p class="text-center small mt-3 mb-0">
                        Forgot your password?
                        <a href="auth/forgot_password.php" class="green-link">
                            Reset it here
                        </a>
                    </p>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- What we offer -->
<section class="offers" id="what-we-offer">
    <div class="container">
        <div class="text-center mb-5">
            <h2 class="section-title display-6">What We Offer</h2>
            <p class="text-secondary mt-3">
                Thoughtful support focused on comfort, well-being,
                and the dignity of every resident.
            </p>
        </div>

        <div class="row g-4">
            <div class="col-sm-6 col-lg-3">
                <div class="offer-card text-center">
                    <div class="offer-icon">🏡</div>
                    <h5>Comfortable Living</h5>
                    <p class="text-secondary mb-0">
                        A welcoming environment that prioritizes
                        residents' comfort, safety, and peace of mind.
                    </p>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="offer-card text-center">
                    <div class="offer-icon">❤️</div>
                    <h5>Health Information</h5>
                    <p class="text-secondary mb-0">
                        Organized health information for authorized
                        personnel to support care coordination.
                    </p>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="offer-card text-center">
                    <div class="offer-icon">🤝</div>
                    <h5>Dedicated Caregivers</h5>
                    <p class="text-secondary mb-0">
                        Caregiving guided by kindness, respect,
                        and attention to individual needs.
                    </p>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3">
                <div class="offer-card text-center">
                    <div class="offer-icon">🌷</div>
                    <h5>Activities and Well-being</h5>
                    <p class="text-secondary mb-0">
                        Opportunities for social connection,
                        recreation, and meaningful daily activities.
                    </p>
                </div>
            </div>
        </div>

        <div class="text-center mt-5">
            <h4 class="section-title">Care with Compassion. Support with Dignity.</h4>
            <p class="text-secondary">
                Because a caring home is more than a place to stay —
                it is a place to belong.
            </p>
            <a href="#login" class="btn btn-green px-4 mt-2">
                Log In to Continue
            </a>
        </div>
    </div>
</section>

<!-- Footer -->
<footer>
    <div class="container text-center">
        <strong>🏡 Home for the Tender</strong>
        <p class="mb-2 mt-2">Care · Comfort · Dignity</p>
        <a href="terms.php">Terms and Conditions</a>
        <span class="mx-2">|</span>
        <span>© <?= date('Y') ?> Home for the Tender</span>
    </div>
</footer>

<script>
function togglePassword() {
    const field = document.getElementById('password');
    field.type = document.getElementById('showPassword').checked
        ? 'text'
        : 'password';
}
</script>

</body>
</html>
