<?php  
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
ob_start();

require_once "../includes/conn.php";
require_once "../includes/auth.php";

date_default_timezone_set('Africa/Lusaka');

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

// Unique variable to prevent include collisions
$rx_sql = "SELECT p.*, c.full_name, c.phone 
           FROM prescriptions p 
           INNER JOIN clients c ON p.client_id = c.id 
           WHERE p.pharmacy_id = '$pharmacy_id' 
           AND p.branch_id = '$branch_id' 
           ORDER BY p.uploaded_at DESC"; 

$rx_res = mysqli_query($conn, $rx_sql);

if (!$rx_res) {
    die("Query Failed: " . mysqli_error($conn));
}

$total_requests = mysqli_num_rows($rx_res);
?>
    
    <style>
        :root {
            --primary-green: #198754;
            --soft-bg: #f8f9fa;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }

        body { font-family: 'Inter', sans-serif; background-color: var(--soft-bg); color: #333; }
        
        .page-wrapper { 
            padding: 30px; 
            min-height: 100vh; 
            margin-left: 240px; 
            padding-top: 90px; 
            transition: all 0.3s ease;
        }

        #main-wrapper[data-sidebartype="mini-sidebar"] .page-wrapper { margin-left: 70px; }

        @media (max-width: 768px) {
            .page-wrapper { margin-left: 0 !important; padding: 15px; padding-top: 80px; }
        }

        /* Header UI */
        .glass-header { 
            background: #fff; 
            padding: 25px; 
            border-radius: 16px; 
            box-shadow: var(--card-shadow); 
            margin-bottom: 30px; 
            border-bottom: 4px solid var(--primary-green);
        }

        /* Card UI */
        .rx-card { 
            background: #fff; 
            border-radius: 16px; 
            overflow: hidden; 
            border: none; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
        }

        .rx-card:hover { 
            transform: translateY(-8px); 
            box-shadow: var(--card-shadow); 
        }

        .img-container { 
            width: 100%; 
            height: 220px; 
            position: relative;
            background: #f1f1f1;
            overflow: hidden;
        }

        .img-container img { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            transition: 0.5s;
        }

        .rx-card:hover .img-container img { transform: scale(1.1); }

        .status-overlay {
            position: absolute;
            top: 15px;
            left: 15px;
            z-index: 2;
        }

        .badge-pill {
            padding: 6px 14px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .note-box {
            background: #fdfdfd;
            border: 1px dashed #ddd;
            border-radius: 10px;
            padding: 12px;
            font-size: 13px;
            color: #666;
            margin-bottom: 15px;
            height: 70px;
            overflow-y: auto;
        }

        /* Actions */
        .btn-action {
            border-radius: 10px;
            font-weight: 600;
            padding: 10px;
            transition: 0.2s;
        }

        .btn-whatsapp {
            background-color: #25D366;
            color: white;
            border: none;
        }

        .btn-whatsapp:hover { background-color: #128C7E; color: white; }

        .search-bar {
            border-radius: 50px;
            padding: 10px 20px;
            border: 1px solid #ddd;
            width: 100%;
            max-width: 400px;
        }
    </style>

        <div class="glass-header d-md-flex align-items-center justify-content-between">
            <div>
                <h3 class="fw-bold text-dark m-0">Prescription Inbox</h3>
                <p class="text-muted small m-0">Managing incoming requests for Branch ID: 13</p>
            </div>
            <div class="mt-3 mt-md-0 d-flex gap-2">
                <input type="text" id="rxSearch" class="search-bar shadow-sm" placeholder="Search patient name...">
                <div class="bg-success text-white px-4 py-2 rounded-pill d-flex align-items-center">
                    <span class="fw-bold"><?php echo $total_requests; ?></span>&nbsp;Active
                </div>
            </div>
        </div>

        <div class="row" id="rxContainer">
            <?php 
            if ($rx_res && $total_requests > 0): 
                mysqli_data_seek($rx_res, 0); 
                while ($row = mysqli_fetch_assoc($rx_res)): 
                    $file = $row['file_path'] ?? '';
                    $name = $row['full_name'] ?? 'Unknown Patient';
                    $phone = $row['phone'] ?? '';
                    $status = $row['status'] ?? 'Pending';
                    $date = isset($row['uploaded_at']) ? date('d M Y, H:i', strtotime($row['uploaded_at'])) : 'N/A';
                    $isPending = (strtolower($status) == 'pending');
            ?>
                <div class="col-xl-4 col-lg-6 col-md-6 mb-4 rx-item">
                    <div class="card rx-card shadow-sm">
                        <div class="img-container">
                            <div class="status-overlay">
                                <span class="badge-pill <?php echo $isPending ? 'bg-warning text-dark' : 'bg-success text-white'; ?>">
                                    <?php echo $status; ?>
                                </span>
                            </div>
                            <a href="../api/uploads/prescriptions/<?php echo $file; ?>" target="_blank">
                                <img src="../api/uploads/prescriptions/<?php echo $file; ?>" onerror="this.src='dist/img/no-image.png';">
                            </a>
                        </div>

                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <h5 class="fw-bold text-dark mb-0 patient-name"><?php echo htmlspecialchars($name); ?></h5>
                                    <small class="text-primary fw-600"><?php echo htmlspecialchars($phone); ?></small>
                                </div>
                                <small class="text-muted" style="font-size: 11px;"><?php echo $date; ?></small>
                            </div>
                            
                            <div class="note-box">
                                <i class="mdi mdi-message-text-outline me-1"></i>
                                <?php echo !empty($row['notes']) ? htmlspecialchars($row['notes']) : 'No instructions from patient.'; ?>
                            </div>

                            <div class="d-grid gap-2">
                                <?php if($isPending): ?>
                                    <a href="update_rx_status.php?id=<?php echo $row['id']; ?>&status=Ready" class="btn btn-success btn-action shadow-sm">
                                        <i class="mdi mdi-check-circle me-1"></i> Processed & Ready
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-light btn-action text-success fw-bold" disabled>
                                        <i class="mdi mdi-check-all me-1"></i> Prescription Ready
                                    </button>
                                <?php endif; ?>

                                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $phone); ?>?text=Hello%20<?php echo urlencode($name); ?>,%20this%20is%20PHARMANOVA.%20Your%20prescription%20is%20ready." 
                                   target="_blank" class="btn btn-whatsapp btn-action shadow-sm">
                                   <i class="mdi mdi-whatsapp me-1"></i> Notify via WhatsApp
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12 text-center py-5">
                    <i class="mdi mdi-pill text-muted" style="font-size: 60px; opacity: 0.2;"></i>
                    <h4 class="text-muted fw-light mt-3">No prescription requests today.</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Live Search Functionality
    $(document).ready(function(){
        $("#rxSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $(".rx-item").filter(function() {
                $(this).toggle($(this).find(".patient-name").text().toLowerCase().indexOf(value) > -1)
            });
        });
    });
</script>
<?php
$content = ob_get_clean();
require "../includes/myheader.php"; 
?>