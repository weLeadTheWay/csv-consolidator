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
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    </head>

    <body>
        <nav class="app-navbar navbar navbar-expand-lg sticky-top py-2">
            <div class="container">
                <a class="navbar-brand" href="./">
                    <i class="fa-solid fa-layer-group"></i>
                    CSV BOS Data Consolidator
                </a>
                <div class="d-flex gap-2">
                                        </div>
            </div>
        </nav>

        <div class="container py-4 py-md-5">
            <form method="POST" enctype="multipart/form-data" class="card p-3 p-md-4 mb-4 shadow-sm">
                <label class="form-label fw-semibold">Upload CSV File</label>
                <div>
                    <div class="row g-2 align-items-stretch flex-column flex-md-row">

                        <div class="col-12 col-md">
                            <input type="file" name="csv_file" class="form-control w-100" required accept=".csv">
                        </div>

                        <div class="col-12 col-md-auto d-grid">
                            <button class="btn btn-primary w-100">
                                Upload & Parse
                            </button>
                        </div>

                        <div class="col-12 col-md-auto d-grid">
                            <a href="index.php" class="btn btn-outline-danger w-100" id="clear-upload" title="Start Fresh">
                                <i class="bi bi-arrow-repeat me-1"></i>Clear
                            </a>
                        </div>

                    </div>
                </div>
            </form>

            <?php if (!empty($result['data'])): ?>
                <?php if (!empty($result['debug'])): ?>
                    <div class="alert alert-info">
                        <?= $result['debug'] ?>
                    </div>
                <?php endif; ?>

                <div class="card p-3 p-md-4 shadow-sm">

                    <h5 class="mb-3">Parsed CSV Data</h5>

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
    </body>
</html>