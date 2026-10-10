<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Terms and Conditions | Home for the Tender</title>

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

        .btn-green {
            background: var(--green);
            color: white;
            border: 1px solid var(--green);
            border-radius: 8px;
            padding: 10px 18px;
            font-weight: 700;
        }

        .btn-green:hover {
            background: #269b4b;
            border-color: #269b4b;
            color: white;
        }

        .terms-hero {
            padding: 45px 0;
            background: linear-gradient(120deg, #eaf7ec, #fff);
        }

        .terms-title {
            color: var(--dark-green);
            font-weight: 700;
        }

        .terms-card {
            background: white;
            padding: 35px;
            border: 1px solid #e1eee3;
            border-radius: 16px;
            box-shadow: 0 8px 28px rgba(28,90,48,.08);
        }

        .terms-card h2 {
            color: var(--dark-green);
            font-size: 1.25rem;
            font-weight: 700;
            margin-top: 28px;
        }

        .terms-card p {
            line-height: 1.8;
            color: #52695a;
        }

        footer {
            background: #175c38;
            color: white;
            padding: 25px 0;
        }

        footer a {
            color: white;
        }

        @media (max-width: 576px) {
            .terms-card {
                padding: 22px;
            }
        }
    </style>
</head>

<body>

<!-- Matching header -->
<nav class="navbar">
    <div class="container py-2">
        <a class="navbar-brand text-decoration-none" href="index.php">
            <span class="brand-name">🏡 Home for the Tender</span>
            <span class="brand-tagline d-block">CARE · COMFORT · DIGNITY</span>
        </a>
    </div>
</nav>

<!-- Page heading -->
<section class="terms-hero">
    <div class="container">
        <h1 class="terms-title">Terms and Conditions</h1>
        <p class="mb-0">
            Please review the terms that apply when using our website.
        </p>
    </div>
</section>

<!-- Terms content -->
<main class="container py-5">
    <div class="terms-card">

        <a href="index.php#login" class="btn btn-green mb-3">
            &larr; Back to Login
        </a>

        <p>
            <strong>Home for the Tender</strong> aims to support organized
            resident care while protecting the dignity and privacy of residents.
            These terms describe expectations for using this website.
        </p>

        <h2>1. Acceptance of Terms</h2>
        <p>
            By using the website and accepting these terms, you acknowledge
            that you have read and understood the conditions for its use.
            If you do not agree, do not proceed with the system.
        </p>

        <h2>2. User Accounts and Responsibilities</h2>
        <p>
            Users must provide accurate account information, protect their
            passwords, and use only the features authorized for their roles.
            Do not share credentials or access another person's account.
        </p>

        <h2>3. Privacy and Personal Information</h2>
        <p>
            Personal information must be handled only for legitimate,
            authorized purposes and protected against unauthorized access,
            use, or disclosure. The facility should follow applicable
            Philippine privacy requirements, including Republic Act No. 10173,
            the Data Privacy Act of 2012.
        </p>

        <h2>4. Confidential Resident Records</h2>
        <p>
            Resident profiles, health information, and care records are
            confidential. Users may access only the information permitted by
            their responsibilities and assigned permissions.
        </p>

        <h2>5. Caretaker Responsibilities</h2>
        <p>
            Caretakers should use the system to review assigned residents,
            schedules, and care tasks, and to submit observations or reports
            as permitted. They must not change official medical records
            unless specifically authorized.
        </p>

        <h2>6. Acceptable Use</h2>
        <p>
            Users must not misuse the system, attempt to bypass security
            restrictions, disclose confidential information without
            authorization, or deliberately damage or disrupt the website.
        </p>

        <h2>7. Account Security and Reporting</h2>
        <p>
            Users should promptly notify the responsible administrator of
            suspected unauthorized access, lost credentials, or possible
            exposure of confidential information.
        </p>

        <h2>8. Data Accuracy and Care Decisions</h2>
        <p>
            System information should be entered accurately and kept up to
            date by authorized personnel. The website is a care-management
            tool and does not replace professional medical judgment or
            emergency procedures.
        </p>

        <h2>9. Changes to These Terms</h2>
        <p>
            These terms may be revised when needed. Users should review
            the current version when notified of changes.
        </p>

        <h2>10. Contact and Questions</h2>
        <p>
            For questions about these terms or the handling of personal
            information, contact the facility's authorized administrator
            or designated privacy contact.
        </p>

        <hr class="my-4">

        <p class="small text-secondary">
            This is a general template and is not legal advice. The facility
            should review and adapt it to its actual practices and applicable
            legal obligations before using the website with real resident data.
        </p>

        <a href="index.php#login" class="btn btn-green mt-2">
            &larr; Back to Login
        </a>

    </div>
</main>

<!-- Matching footer -->
<footer>
    <div class="container text-center">
        <strong>🏡 Home for the Tender</strong>
        <p class="mb-2 mt-2">Care · Comfort · Dignity</p>
        <a href="index.php#login">Back to Login</a>
        <span class="mx-2">|</span>
        <span>© <?= date('Y') ?> Home for the Tender</span>
    </div>
</footer>

</body>
</html>
