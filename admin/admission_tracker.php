<?php
session_start();

require_once __DIR__ . '/../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['admin', 'staff'], true)
) {
    header('Location: ../index.php');
    exit;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$trends = [
    'weekly' => ['labels' => [], 'values' => []],
    'monthly' => ['labels' => [], 'values' => []],
    'yearly' => ['labels' => [], 'values' => []]
];

$errorMessage = '';

try {
    // Weekly admissions: today and the previous six days.
    $stmt = $pdo->query(
        "SELECT DATE(admission_date) AS period_date,
                COUNT(*) AS total
         FROM residents
         WHERE admission_date >= CURDATE() - INTERVAL 6 DAY
           AND admission_date < CURDATE() + INTERVAL 1 DAY
         GROUP BY DATE(admission_date)"
    );

    $weeklyRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));

        $trends['weekly']['labels'][] =
            date('D, M j', strtotime($date));

        $trends['weekly']['values'][] =
            (int) ($weeklyRows[$date] ?? 0);
    }

    // Monthly admissions: current month and previous eleven months.
    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(admission_date, '%Y-%m') AS period_date,
                COUNT(*) AS total
         FROM residents
         WHERE admission_date >=
               DATE_FORMAT(CURDATE() - INTERVAL 11 MONTH, '%Y-%m-01')
           AND admission_date <
               DATE_FORMAT(CURDATE() + INTERVAL 1 MONTH, '%Y-%m-01')
         GROUP BY DATE_FORMAT(admission_date, '%Y-%m')"
    );

    $monthlyRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    for ($i = 11; $i >= 0; $i--) {
        $date = date('Y-m', strtotime("first day of -$i months"));

        $trends['monthly']['labels'][] =
            date('M Y', strtotime($date . '-01'));

        $trends['monthly']['values'][] =
            (int) ($monthlyRows[$date] ?? 0);
    }

    // Yearly admissions: current year and previous four years.
    $stmt = $pdo->query(
        "SELECT YEAR(admission_date) AS period_date,
                COUNT(*) AS total
         FROM residents
         WHERE admission_date >= MAKEDATE(YEAR(CURDATE()) - 4, 1)
           AND admission_date < MAKEDATE(YEAR(CURDATE()) + 1, 1)
         GROUP BY YEAR(admission_date)"
    );

    $yearlyRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    for (
        $year = (int) date('Y') - 4;
        $year <= (int) date('Y');
        $year++
    ) {
        $trends['yearly']['labels'][] = (string) $year;

        $trends['yearly']['values'][] =
            (int) ($yearlyRows[$year] ?? 0);
    }

} catch (PDOException $e) {
    $errorMessage =
        'Unable to load admission statistics. Please check your database.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admission Tracker - Home for the Aged</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f5f6fa;
            font-family: Arial, sans-serif;
            color: #252525;
            overflow-x: hidden;
        }

        /* SIDEBAR LOGO (injected at top of sidebar) */
        .sidebar-logo {
            display: block;
            padding: 10px 4px 6px;
        }

        .sidebar-logo a {
            display: block;
        }

        .sidebar-logo img {
            display: block;
            width: 100%;
            max-width: 300px;
            height: auto;
            margin: 0 auto;
            transform: scale(1.1);
        }

        .app-main {
            margin-left: 250px !important;
            width: calc(100% - 250px) !important;
            max-width: none !important;
            min-width: 0;
            min-height: 100vh;
            padding: 28px !important;
            box-shadow: none !important;
            overflow-x: hidden;
        }

        .page-header {
            margin-bottom: 22px;
        }

        .page-header h2 {
            color: #06451f;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .page-header p {
            color: #555555;
            margin: 0;
        }

        .graph-card {
            background: #ffffff;
            border-radius: 10px;
            padding: 22px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .graph-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 20px;
        }

        .graph-header h5 {
            color: #06451f;
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .graph-header p {
            color: #555555;
            font-size: 13px;
            margin: 5px 0 0;
        }

        .trend-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .trend-button {
            padding: 8px 13px;
            border: 1px solid #dddddd;
            border-radius: 7px;
            background: #ffffff;
            color: #444444;
            font-size: 13px;
            cursor: pointer;
        }

        .trend-button.active,
        .trend-button:hover {
            background: #06451f;
            border-color: #06451f;
            color: #ffffff;
        }

        .chart-container {
            position: relative;
            width: 100%;
            height: 380px;
        }

        .back-link {
            display: inline-block;
            margin-top: 18px;
            color: #06451f;
            font-size: 14px;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        .error-message {
            color: #a32121;
            background: #fff0f0;
            border-radius: 7px;
            padding: 12px;
        }

        .sidebar-link.active {
            box-shadow: none !important;
            outline: none !important;
        }

        @media (max-width: 760px) {
            .app-main {
                margin-left: 0 !important;
                width: 100% !important;
                padding: 18px 12px !important;
            }
        }

        @media (max-width: 576px) {
            .page-header h2 {
                font-size: 23px;
            }

            .graph-card {
                padding: 15px;
            }

            .chart-container {
                height: 280px;
            }
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

<div class="app-main">

    <div class="page-header">
        <h2>Admission Tracker</h2>
        <p>Monitor resident admissions across different time periods.</p>
    </div>

    <section class="graph-card">

        <div class="graph-header">
            <div>
                <h5>Admission Statistics</h5>
                <p>Number of residents admitted over time</p>
            </div>

            <div class="trend-controls">
                <button
                    type="button"
                    class="trend-button"
                    data-period="weekly"
                >
                    Weekly
                </button>

                <button
                    type="button"
                    class="trend-button active"
                    data-period="monthly"
                >
                    Monthly
                </button>

                <button
                    type="button"
                    class="trend-button"
                    data-period="yearly"
                >
                    Yearly
                </button>
            </div>
        </div>

        <?php if ($errorMessage !== ''): ?>

            <p class="error-message"><?= h($errorMessage) ?></p>

        <?php else: ?>

            <div class="chart-container">
                <canvas id="admissionChart"></canvas>
            </div>

        <?php endif; ?>

    </section>

    <a href="dashboard.php" class="back-link">
        &larr; Back to Dashboard
    </a>

</div>

<!-- Puts the logo at the top of the sidebar and hides the old text title -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector(
        '.app-sidebar, .sidebar, #sidebar, .sidebar-container, aside, nav'
    );

    if (!sidebar) {
        return;
    }

    // Hide the old text title and subtitle
    var oldTexts = ['home for the aged', 'care management system'];

    sidebar.querySelectorAll('*').forEach(function (el) {
        if (
            el.children.length === 0 &&
            oldTexts.indexOf(el.textContent.trim().toLowerCase()) !== -1
        ) {
            el.style.display = 'none';
        }
    });

    // Add the logo
    if (!sidebar.querySelector('.sidebar-logo')) {
        var logo = document.createElement('div');
        logo.className = 'sidebar-logo';
        logo.innerHTML =
            '<a href="/homefor_thetander/admin/dashboard.php">' +
            '<img src="/homefor_thetander/images/white1.png" ' +
            'alt="Home for the Aged - Care, Comfort, Dignity">' +
            '</a>';

        sidebar.insertBefore(logo, sidebar.firstChild);
    }
});
</script>

</main>

<?php if ($errorMessage === ''): ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
    const trends = <?= json_encode(
        $trends,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    ) ?>;

    const buttons = document.querySelectorAll('.trend-button');

    const chart = new Chart(
        document.getElementById('admissionChart'),
        {
            type: 'line',

            data: {
                labels: trends.monthly.labels,

                datasets: [{
                    label: 'Resident Admissions',
                    data: trends.monthly.values,
                    borderColor: '#06451f',
                    backgroundColor: 'rgba(6, 69, 31, 0.10)',
                    borderWidth: 3,
                    pointBackgroundColor: '#06451f',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    fill: true,
                    tension: 0.3
                }]
            },

            options: {
                responsive: true,
                maintainAspectRatio: false,

                interaction: {
                    intersect: false,
                    mode: 'index'
                },

                plugins: {
                    legend: {
                        display: false
                    },

                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Admissions: ' + context.parsed.y;
                            }
                        }
                    }
                },

                scales: {
                    y: {
                        beginAtZero: true,

                        ticks: {
                            precision: 0,
                            color: '#555555'
                        },

                        title: {
                            display: true,
                            text: 'Number of admissions',
                            color: '#555555'
                        },

                        grid: {
                            color: '#eeeeee'
                        }
                    },

                    x: {
                        ticks: {
                            color: '#555555'
                        },

                        grid: {
                            display: false
                        }
                    }
                }
            }
        }
    );

    buttons.forEach(function(button) {
        button.addEventListener('click', function() {
            const period = button.dataset.period;

            chart.data.labels = trends[period].labels;
            chart.data.datasets[0].data = trends[period].values;
            chart.update();

            buttons.forEach(function(item) {
                item.classList.toggle('active', item === button);
            });
        });
    });
</script>

<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>