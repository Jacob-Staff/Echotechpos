<?php
session_start();
require "../includes/conn.php";
require "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

// Update status
if (isset($_GET['approve_id'])) {
    $aid = mysqli_real_escape_string($conn, $_GET['approve_id']);
    mysqli_query($conn, "UPDATE lab_results SET status = 'Ready' WHERE id = '$aid' AND branch_id = '$branch_id'");
    header("Location: view_lab_results.php?msg=Status Updated Successfully");
    exit();
}

$pageTitle = "Lab Results | PHARMANOVA";

// Start capturing content
ob_start();
?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h3 class="fw-bold text-dark mb-0">
                    <i class="mdi mdi-flask-outline text-primary me-2"></i> INCOMING LAB RESULTS
                </h3>
                <p class="text-muted mb-0">Manage and process digital lab results for this branch</p>
            </div>
            <button onclick="window.print()" class="btn btn-light border fw-bold px-4">
                <i class="mdi mdi-printer me-1"></i> PRINT
            </button>
        </div>

        <?php if(isset($_GET['msg'])): ?>
            <div class="alert alert-success border-0 shadow-sm d-flex align-items-center">
                <i class="mdi mdi-check-circle me-2"></i> <?php echo htmlspecialchars($_GET['msg']); ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>DATE RECEIVED</th>
                        <th>TEST TYPE & CLIENT</th>
                        <th>PATIENT NOTES</th>
                        <th>RESULT FILE</th>
                        <th>STATUS</th>
                        <th class="text-center">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sql = "SELECT lab.*, c.full_name 
                            FROM lab_results lab 
                            LEFT JOIN clients c ON lab.client_id = c.id 
                            WHERE lab.pharmacy_id = '$pharmacy_id' 
                            AND lab.branch_id = '$branch_id'
                            ORDER BY lab.uploaded_at DESC";
                    
                    $res = mysqli_query($conn, $sql);
                    if ($res && mysqli_num_rows($res) > 0) {
                        while ($row = mysqli_fetch_assoc($res)) {
                            $client_name = !empty($row['full_name']) ? htmlspecialchars($row['full_name']) : "Guest/Walk-in";
                            $status_badge = ($row['status'] == 'Pending') ? 'bg-warning text-dark' : 'bg-success text-white';
                            $date = date('d M, Y', strtotime($row['uploaded_at']));
                            $time = date('H:i', strtotime($row['uploaded_at']));
                            echo "<tr>
                                <td><div class='fw-bold text-dark'>$date</div><small class='text-muted'>$time</small></td>
                                <td><div class='fw-bold text-primary text-uppercase' style='font-size: 0.85rem;'>".htmlspecialchars($row['test_type'])."</div>
                                    <small class='text-muted'><i class='mdi mdi-account-circle me-1'></i> $client_name</small></td>
                                <td><div class='text-muted' style='max-width: 250px; font-size: 0.8rem; line-height: 1.4;'>"
                                    . (!empty($row['notes']) ? htmlspecialchars($row['notes']) : "<i>No notes</i>") .
                                "</div></td>
                                <td><a href='../api/uploads/lab_results/{$row['file_path']}' target='_blank' class='btn btn-view btn-sm fw-bold shadow-sm'>
                                    <i class='mdi mdi-file-pdf text-danger'></i> VIEW PDF</a></td>
                                <td><span class='badge $status_badge px-3 py-2'>{$row['status']}</span></td>
                                <td class='text-center'>";
                            if ($row['status'] == 'Pending') {
                                echo "<a href='?approve_id={$row['id']}' class='btn btn-success btn-sm fw-bold px-3'>MARK READY</a>";
                            } else {
                                echo "<span class='text-muted fw-bold small'><i class='mdi mdi-check-all'></i> DONE</span>";
                            }
                            echo "</td></tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6' class='text-center py-5 text-muted'>No results found.</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// Save content to $content variable
$content = ob_get_clean();

// Include the template (myheader.php)
require "../includes/myheader.php";