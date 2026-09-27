<?php
// Include your existing database connection
require_once __DIR__ . '/db.php';

$students = [];
$documents = [];
$requests = [];
$error = null;
$dbConnected = false;

try {
    // Check connection
    $pdo->query("SELECT 1");
    $dbConnected = true;

    // Fetch all students, ordering by the newest first
    // We do NOT select the password column for security reasons
    $stmt = $pdo->query("SELECT id, first_name, middle_name, last_name, student_id, email, course, year_level, academic_status, created_at FROM students ORDER BY id DESC");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch uploaded documents if table exists
    try {
        $docStmt = $pdo->query("SELECT id, student_id, document_type, original_filename, file_size_bytes, status, uploaded_at FROM student_documents ORDER BY id DESC LIMIT 20");
        $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $documents = [];
    }

    // Fetch requests if table exists
    try {
        $reqStmt = $pdo->query("SELECT id, student_id, title, department, reference_number, status, created_at FROM student_requests ORDER BY id DESC LIMIT 20");
        $requests = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $requests = [];
    }

} catch (\PDOException $e) {
    $error = "Database Error: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Life | Admin Dashboard</title>
    <!-- Load Bootstrap 5 CSS for modern styling -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Matching the Android App's Vibe */
        :root {
            --maroon-primary: #5B0E1B;
            --secondary-ruby: #E11D48;
            --background-sand: #FAF8FF;
        }
        body {
            background-color: #f8f9fa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        .navbar-custom {
            background-color: var(--maroon-primary);
        }
        .navbar-custom .navbar-brand {
            color: white;
            font-weight: bold;
        }
        .card-header-custom {
            background-color: var(--maroon-primary);
            color: white;
            font-weight: 600;
        }
        .table-hover tbody tr:hover {
            background-color: #f1f1f1;
        }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-custom shadow-sm mb-4">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex items-center gap-2" href="#">
                🎓 Southwestern University PHINMA · Student Life Admin
            </a>
            <div class="d-flex align-items-center gap-3">
                <?php if ($dbConnected): ?>
                    <span class="badge bg-success px-3 py-2">✓ PostgreSQL Connected</span>
                <?php else: ?>
                    <span class="badge bg-danger px-3 py-2">✕ Database Offline</span>
                <?php endif; ?>
                <a href="test_connection.php" target="_blank" class="btn btn-sm btn-outline-light">Test Endpoint</a>
            </div>
        </div>
    </nav>

    <!-- Main Content Container -->
    <div class="container-fluid px-4">
        
        <div class="row mb-4">
            <div class="col-md-3 mb-2">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center">
                        <h6 class="text-muted text-uppercase small font-weight-bold">Total Registered Students</h6>
                        <h2 class="text-dark fw-bold mb-0"><?php echo count($students); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center">
                        <h6 class="text-muted text-uppercase small font-weight-bold">Uploaded Documents</h6>
                        <h2 class="text-primary fw-bold mb-0"><?php echo count($documents); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center">
                        <h6 class="text-muted text-uppercase small font-weight-bold">Student Requests</h6>
                        <h2 class="text-info fw-bold mb-0"><?php echo count($requests); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-2">
                <div class="card shadow-sm border-0">
                    <div class="card-body text-center">
                        <h6 class="text-muted text-uppercase small font-weight-bold">Backend Architecture</h6>
                        <span class="badge bg-dark mt-1">PHP PDO + PostgreSQL</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Directory Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header card-header-custom d-flex justify-content-between align-items-center">
                <span>Student Directory</span>
                <button class="btn btn-sm btn-light text-dark fw-bold" onclick="location.reload()">Refresh Data</button>
            </div>
            <div class="card-body p-0">
                
                <?php if ($error): ?>
                    <div class="alert alert-danger m-3" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-striped mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th># ID</th>
                                    <th>Student ID</th>
                                    <th>Last Name</th>
                                    <th>First Name</th>
                                    <th>Middle Name</th>
                                    <th>University Email</th>
                                    <th>Program / Year</th>
                                    <th>Registration Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4 text-muted">No students registered yet. Run seed_demo_data.sql or sign up through the app.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string)$student['id']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($student['student_id']); ?></span></td>
                                            <td class="fw-bold"><?php echo htmlspecialchars($student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['first_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['middle_name'] ?? ''); ?></td>
                                            <td><a href="mailto:<?php echo htmlspecialchars($student['email']); ?>"><?php echo htmlspecialchars($student['email']); ?></a></td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($student['course'] ?? 'BS Computer Science'); ?> • 
                                                    <?php echo htmlspecialchars($student['year_level'] ?? '3rd Year'); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php 
                                                    $date = new DateTime($student['created_at']);
                                                    echo $date->format('M d, Y h:i A'); 
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-success">Active Scholar</span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- Recent Uploaded Documents -->
        <?php if (!empty($documents)): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white fw-bold">
                Recently Uploaded Documents (student_documents table)
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Student ID</th>
                            <th>Document Type</th>
                            <th>Original Filename</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th>Uploaded At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$doc['id']); ?></td>
                                <td><code><?php echo htmlspecialchars($doc['student_id']); ?></code></td>
                                <td><strong><?php echo htmlspecialchars($doc['document_type']); ?></strong></td>
                                <td><?php echo htmlspecialchars($doc['original_filename']); ?></td>
                                <td><?php echo number_format($doc['file_size_bytes'] / 1024, 1); ?> KB</td>
                                <td><span class="badge bg-warning text-dark"><?php echo htmlspecialchars($doc['status']); ?></span></td>
                                <td><?php echo htmlspecialchars($doc['uploaded_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
