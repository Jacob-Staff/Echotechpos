<?php
// 1. Load the header first (starts session and connects to DB)
require "store_header.php"; 
require "../includes/conn.php"; 

// 2. Explicitly pull from Session
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id']   ?? null;
$client_id   = $_SESSION['client_id']   ?? 0;

// 3. FAIL-SAFE: If pharmacy_id is missing, fetch it from the branches table using branch_id
if (empty($pharmacy_id) && !empty($branch_id)) {
    $check_pharm = mysqli_query($conn, "SELECT pharmacy_id FROM branches WHERE id = '$branch_id'");
    if ($row = mysqli_fetch_assoc($check_pharm)) {
        $pharmacy_id = $row['pharmacy_id'];
    }
}

// 4. Last resort: check Request parameters
if (!$pharmacy_id) { $pharmacy_id = $_REQUEST['pharmacy_id'] ?? 0; }
if (!$branch_id)   { $branch_id   = $_REQUEST['branch_id']   ?? 0; }

date_default_timezone_set('Africa/Lusaka');

$message = "";

// 5. PROCESSING UPLOAD
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['lab_file'])) {
    
    $test_type = mysqli_real_escape_string($conn, $_POST['test_type']);
    $notes = mysqli_real_escape_string($conn, $_POST['notes']);

    $target_dir = "uploads/lab_results/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }

    $file_extension = strtolower(pathinfo($_FILES["lab_file"]["name"], PATHINFO_EXTENSION));
    
    // Using $pharmacy_id here ensures the filename is prefixed correctly (e.g., LAB_10_...)
    $new_filename = "LAB_" . $pharmacy_id . "_" . time() . "_" . rand(100, 999) . "." . $file_extension;
    $target_file = $target_dir . $new_filename;

    $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
    
    if (!in_array($file_extension, $allowed_types)) {
        $message = "<div class='alert alert-danger shadow-sm'>❌ Invalid file type.</div>";
    } else {
        if (move_uploaded_file($_FILES["lab_file"]["tmp_name"], $target_file)) {
            
            // Final Database Insert
            $sql = "INSERT INTO lab_results (client_id, pharmacy_id, branch_id, file_path, test_type, notes, status) 
                    VALUES ('$client_id', '$pharmacy_id', '$branch_id', '$new_filename', '$test_type', '$notes', 'Pending')";
            
            if (mysqli_query($conn, $sql)) {
                $message = "
                <div class='alert alert-success shadow-sm border-0'>
                    <h5 class='fw-bold'>✔ Upload Successful!</h5>
                    Sent to: <strong>".($_SESSION['branch_name'] ?? 'Selected Branch')."</strong>
                </div>";
            } else {
                $message = "<div class='alert alert-danger'>Database error: " . mysqli_error($conn) . "</div>";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lab Submission | <?php echo $_SESSION['pharmacy_name'] ?? 'Pharmanova'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --primary-color: #198754; }
        body {
            background: #f0f2f5;
            font-family: 'Plus Jakarta Sans', sans-serif;
            min-height: 100vh;
        }
        .upload-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.05);
            padding: 35px;
            margin-top: 50px;
            border-top: 6px solid var(--primary-color);
        }
        .file-drop-area {
            border: 2px dashed #dee2e6;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            transition: 0.3s;
            background: #f8f9fa;
            position: relative;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }
        .file-drop-area.active { border-color: var(--primary-color); background: #eefdf5; }
        .file-input { position: absolute; left: 0; top: 0; height: 100%; width: 100%; opacity: 0; cursor: pointer; }
        .btn-submit {
            background: var(--primary-color);
            color: white;
            font-weight: 700;
            padding: 15px;
            border-radius: 12px;
            width: 100%;
            border: none;
            transition: 0.3s;
        }
        .btn-submit:hover { background: #146c43; transform: translateY(-2px); }
        .loading-spinner { display: none; }
        
        @media (min-width: 768px) {
            .landscape-row { display: flex; gap: 20px; align-items: flex-start; }
            .landscape-col { flex: 1; }
        }
    </style>
</head>
<body>

<div class="container-fluid px-md-5 mb-5">
    <div class="row justify-content-center">
        <div class="col-12 col-xl-10">
            <div class="upload-card">
                <div class="text-center mb-4">
                    <i class="fa-solid fa-microscope text-success fa-3x mb-3"></i>
                    <h3 class="fw-bold">Lab Submission</h3>
                    <p class="text-muted small">Submitting to: <strong><?php echo $_SESSION['branch_name'] ?? 'Selected Branch'; ?></strong></p>
                </div>

                <?php echo $message; ?>

                <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
                    <div class="landscape-row">
                        <div class="landscape-col">
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-uppercase">Type of Test</label>
                                <select name="test_type" class="form-select" required>
                                    <option value="">Select test type...</option>
                                    <option value="Malaria Test">Malaria Test</option>
                                    <option value="Full Blood Count">Full Blood Count</option>
                                    <option value="Urinalysis">Urinalysis</option>
                                    <option value="Kidney/Liver Function">Kidney/Liver Function</option>
                                    <option value="COVID-19">COVID-19</option>
                                    <option value="Other">Other (Specify in notes)</option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold small text-uppercase">Scan Result / Image</label>
                                <div class="file-drop-area" id="drop-zone">
                                    <i class="fa-solid fa-cloud-arrow-up fa-2x text-muted mb-2"></i>
                                    <p class="mb-1 small fw-bold" id="file-label">Click to select or drag photo</p>
                                    <span class="text-muted" style="font-size: 11px;">PDF, PNG, JPG (Max 5MB)</span>
                                    <input type="file" name="lab_file" class="file-input" accept="image/*,.pdf" required onchange="handleFile(this)">
                                </div>
                            </div>
                        </div>

                        <div class="landscape-col">
                            <div class="mb-4">
                                <label class="form-label fw-bold small text-uppercase">Patient Comments</label>
                                <textarea name="notes" class="form-control" style="height: 155px;" placeholder="Tell the pharmacist about any symptoms..."></textarea>
                            </div>

                            <button type="submit" class="btn-submit shadow-sm mt-2" id="submitBtn">
                                <span class="btn-text">UPLOAD SECURELY</span>
                                <span class="spinner-border spinner-border-sm loading-spinner" role="status"></span>
                            </button>
                        </div>
                    </div>
                </form>

                <div class="text-center mt-4 border-top pt-3">
                    <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="text-muted small text-decoration-none">
                        <i class="fa-solid fa-house me-1"></i> Back to Store
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function handleFile(input) {
        const label = document.getElementById('file-label');
        const zone = document.getElementById('drop-zone');
        if (input.files.length > 0) {
            label.innerHTML = `<span class="text-success fw-bold"><i class="fa-solid fa-file-circle-check"></i> ${input.files[0].name}</span>`;
            zone.classList.add('active');
        }
    }

    document.getElementById('uploadForm').onsubmit = function() {
        const btn = document.getElementById('submitBtn');
        const text = btn.querySelector('.btn-text');
        const spinner = btn.querySelector('.loading-spinner');
        
        text.style.display = 'none';
        spinner.style.display = 'inline-block';
        btn.disabled = true;
        btn.style.opacity = '0.7';
    };
</script>

</body>
</html>