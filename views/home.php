<?php 
$type = $_GET['type'] ?? null;
$fileId = $_GET['file'] ?? null;

$hasSession = !empty($_GET['type']) || !empty($_GET['file']);
$showSetup = !$hasSession;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>CSV Consolidator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/styles.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

    <body>

        <nav class="app-navbar navbar navbar-expand-lg sticky-top py-2">
            <div class="container">
                <a class="navbar-brand" href="./">
                    <i class="fa-solid fa-layer-group"></i>
                    CSV BOS Data Consolidator
                </a>
            </div>
        </nav>

        <div class="container py-4 py-md-5">

            <!-- =========================
                BIG CARD (ONLY FIRST LOAD)
            ========================== -->
            <?php if ($showSetup): ?>
                <div class="card p-3 p-md-4 mb-4 shadow-sm">
                    <label class="form-label fw-semibold mb-3">Select File Type</label>

                    <div class="row g-3">

                        <!-- SALES -->
                        <div class="col-12 col-md-6">
                            <a href="index.php?type=sales" class="text-decoration-none">
                                <div class="border rounded p-4 h-100 type-card">
                                    <h5 class="mb-2">📊 Sales Tracking Masterfile</h5>
                                    <p class="text-muted mb-0">
                                        Upload and consolidate sales tracking CSV data.
                                    </p>
                                </div>
                            </a>
                        </div>

                        <!-- DELCON -->
                        <div class="col-12 col-md-6">
                            <a href="index.php?type=delcon" class="text-decoration-none">
                                <div class="border rounded p-4 h-100 type-card">
                                    <h5 class="mb-2">📦 Delcon w/ SI Masterlist</h5>
                                    <p class="text-muted mb-0">
                                        Upload and consolidate Delcon SI data.
                                    </p>
                                </div>
                            </a>
                        </div>

                    </div>
                </div>
            <?php endif; ?>


            <!-- =========================
                DROPDOWN (WHEN TYPE EXISTS)
            ========================== -->
            <?php if ($type || $fileId): ?>
                <div class="d-flex justify-content-end mb-3">

                    <div class="dropdown">
                        <button class="btn btn-outline-primary dropdown-toggle"
                                data-bs-toggle="dropdown">

                            <?= $type === 'sales'
                                ? '📊 Sales Tracking'
                                : '📦 Delcon' ?>

                        </button>

                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <a class="dropdown-item" href="index.php?type=sales">
                                    📊 Sales Tracking Masterfile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="index.php?type=delcon">
                                    📦 Delcon w/ SI Masterlist
                                </a>
                            </li>
                        </ul>
                    </div>

                </div>
            <?php endif; ?>


            <!-- =========================
                UPLOAD FORM
            ========================== -->
            <?php if ($type || $fileId): ?>

                <form method="POST" enctype="multipart/form-data"
                    class="card p-3 p-md-4 mb-4 shadow-sm">

                    <input type="hidden" name="type"
                        value="<?= htmlspecialchars($type) ?>">

                    <label class="form-label fw-semibold">
                        <?= $type === 'delcon'
                            ? '📦 Delcon'
                            : '📊 Sales Tracking' ?>
                    </label>

                    <div class="text-muted small mb-2">

                        <?php if ($type === 'delcon'): ?>

                            Upload a CSV file with the following required columns:<br>
                            <strong>SI Number</strong>, <strong>Unit Price</strong>, 
                            <strong>Secondary Quantity</strong>, <strong>Secondary UOM</strong>, 
                            <strong>Receipt Qty</strong>, <strong>Receipt Kilos</strong>, 
                            <strong>Return Qty</strong>.

                        <?php else: ?>

                            Upload a single CSV file for processing.
                            Required columns: <strong>Document No</strong>, <strong>Status</strong>, 
                            <strong>Total Amount</strong>, <strong>Customer Code</strong>, 
                            <strong>Customer Name</strong>, <strong>Business Center</strong>, 
                            <strong>Division</strong>, <strong>Profit Center</strong>.

                        <?php endif; ?>

                    </div>

                    <div class="row g-2 align-items-stretch flex-column flex-md-row">

                        <div class="col-12 col-md">
                            <input type="file" name="csv_file"
                                class="form-control w-100"
                                required accept=".csv">
                        </div>

                        <div class="col-12 col-md-auto d-grid">
                            <button class="btn btn-primary w-100">
                                Upload & Parse
                            </button>
                        </div>

                        <div class="col-12 col-md-auto d-grid">
                            <a href="index.php?type=<?= htmlspecialchars($type) ?>"
                            class="btn btn-outline-danger w-100">
                                <i class="bi bi-arrow-repeat me-1"></i> Clear
                            </a>
                        </div>

                    </div>
                </form>

            <?php endif; ?>


            <!-- =========================
                RESULTS
            ========================== -->
            <?php if (!empty($result['data'])): ?>

                <div class="card p-3 p-md-4 shadow-sm">

                    <h5 class="mb-3">Parsed CSV Data</h5>

                    <?php if (!empty($fileId)): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">

                            <div class="text-muted small">
                                File ready: <strong><?= htmlspecialchars($fileId) ?></strong>
                            </div>

                            <button id="importBtn"
                                    class="btn btn-success btn-sm"
                                    onclick="startImport()">

                                <span id="btnText">
                                    <i class="bi bi-upload me-1"></i> Import to Database
                                </span>

                                <span id="btnSpinner" class="spinner-border spinner-border-sm d-none"></span>
                            </button>

                        </div>
                    <?php endif; ?>

                    <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                        <table class="table table-bordered table-striped table-sm align-middle">

                            <thead class="table-light">
                                <tr>
                                    <?php foreach (array_keys($result['data'][0]) as $header): ?>
                                        <th><?= htmlspecialchars($header) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($result['data'] as $row): ?>
                                    <tr>
                                        <?php foreach ($row as $cell): ?>
                                            <td><?= htmlspecialchars($cell) ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>

                    <nav class="mt-3">
                        <ul class="pagination flex-wrap justify-content-center">
                            <?= generatePagination($currentPage, $totalPages, $fileId) ?>
                        </ul>
                    </nav>

                </div>

            <?php elseif (!empty($result['error'])): ?>
                <div class="alert alert-danger">
                    <?= $result['error'] ?>
                </div>
            <?php endif; ?>

        </div>

        <!-- TOAST -->
        <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 9999">
            <div id="importToast" class="toast text-bg-success border-0">
                <div class="d-flex">
                    <div class="toast-body" id="toastMessage">Import successful</div>
                    <button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>

        <script>
        function startImport() {
            const btn = document.getElementById('importBtn');
            const spinner = document.getElementById('btnSpinner');
            const text = document.getElementById('btnText');

            btn.disabled = true;
            spinner.classList.remove('d-none');
            text.innerHTML = "Importing...";

            const url = "index.php?file=<?= htmlspecialchars($fileId ?? '') ?>&import=1&type=<?= htmlspecialchars($type ?? 'sales') ?>";

            fetch(url)
                .then(res => res.text())
                .then(text => JSON.parse(text))
                .then(data => {

                    document.getElementById('toastMessage').innerHTML =
                        `Processed: ${data.processed || 0} | Changes: ${data.changed_count || 0} | Total DB: ${data.total || 0}`;

                    new bootstrap.Toast(document.getElementById('importToast')).show();

                    btn.disabled = false;
                    spinner.classList.add('d-none');
                    text.innerHTML = '<i class="bi bi-upload me-1"></i> Import to Database';
                })
                .catch(err => {
                    console.error(err);
                    alert("Import failed");

                    btn.disabled = false;
                    spinner.classList.add('d-none');
                    text.innerHTML = "Import to Database";
                });
        }
        </script>

        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
</html>