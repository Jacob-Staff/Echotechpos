<?php 
require "store_header.php"; 
require "../includes/conn.php"; 

// 1. Get and Validate the Branch ID from the URL
$branch_id = isset($_GET['bid']) ? intval($_GET['bid']) : 0;

// 2. Security Check: Redirect if not logged in
if (!isset($_SESSION['client_id'])) {
    echo "<script>alert('Please login to upload a prescription'); window.location='../login_client.php';</script>";
    exit();
}

// 3. Fetch the Pharmacy ID and Branch Name to ensure data integrity
$pharmacy_id = 0;
$branch_name = "Our Pharmacy";

if ($branch_id > 0) {
    $stmt = mysqli_prepare($conn, "SELECT pharmacy_id, branch_name FROM branches WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $branch_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $pharmacy_id = $row['pharmacy_id'];
        $branch_name = $row['branch_name'];
    }
}

// 4. Critical Error Fallback if ID is invalid
if ($pharmacy_id == 0) {
    echo "<div class='container my-5 text-center'>
            <div class='alert alert-warning shadow-sm border-0'>
                <i class='mdi mdi-alert-circle-outline display-4 d-block mb-3 text-warning'></i>
                <h4 class='fw-bold'>Invalid Branch Selected</h4>
                <p>Please return to the store and select a pharmacy branch first.</p>
                <a href='index.php' class='btn btn-dark rounded-pill px-4'>Back to Home</a>
            </div>
          </div>";
    exit();
}

$client_id = $_SESSION['client_id'];
?>

<div class="container my-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="card border-0 shadow-lg" style="border-radius: 20px; overflow: hidden;">
                <div style="height: 6px; background: linear-gradient(90deg, #198754, #20c997);"></div>
                
                <div class="card-body p-4 p-lg-5">
                    <div class="text-center mb-4">
                        <div class="icon-circle mb-3">
                            <i class="mdi mdi-camera-plus text-success"></i>
                        </div>
                        <h4 class="fw-bold">Upload Prescription</h4>
                        <p class="text-muted small">Target Branch: <strong><?php echo htmlspecialchars($branch_name); ?></strong></p>
                        <p class="text-muted small">Snap a clear photo of your medical script. Our pharmacists will verify it shortly.</p>
                    </div>

                    <form action="process_prescription.php" method="POST" enctype="multipart/form-data" id="upload-form">
                        <input type="hidden" name="pharmacy_id" value="<?php echo $pharmacy_id; ?>">
                        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
                        <input type="hidden" name="client_id" value="<?php echo $client_id; ?>">
                        
                        <div class="upload-area mb-4" id="drop-zone">
                            <input type="file" name="prescription_file" id="file-input" hidden accept="image/*,application/pdf" required>
                            
                            <div id="upload-prompt">
                                <i class="mdi mdi-cloud-upload-outline display-4 text-primary mb-2"></i>
                                <p class="mb-0 fw-bold">Drag & drop or <span class="text-primary">browse</span></p>
                                <small class="text-muted">Supports JPG, PNG, PDF (Max 5MB)</small>
                            </div>

                            <div id="file-preview-container" class="d-none">
                                <img id="image-preview" src="#" alt="Preview" class="img-thumbnail mb-2" style="max-height: 200px;">
                                <div id="file-details" class="small fw-bold text-success"></div>
                                <button type="button" class="btn btn-sm btn-link text-danger mt-2" onclick="resetUpload(event)">Change File</button>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="small fw-bold text-muted mb-2"><i class="mdi mdi-message-text-outline me-1"></i>Special Instructions</label>
                            <textarea name="notes" class="form-control border-0 bg-light" rows="3" style="border-radius: 12px;" placeholder="e.g. Please include 1 pack of Panadol with this order..."></textarea>
                        </div>

                        <button type="submit" id="submit-btn" class="btn btn-success w-100 py-3 fw-bold rounded-pill shadow-sm">
                            <span class="btn-text">SUBMIT TO PHARMACY</span>
                            <div class="spinner-border spinner-border-sm d-none" role="status"></div>
                        </button>
                    </form>
                </div>
            </div>
            
            <div class="text-center mt-4">
                <div class="d-flex align-items-center justify-content-center text-muted small mb-3">
                    <i class="mdi mdi-shield-check text-success fs-5 me-2"></i>
                    <span>Secure & Confidential Handling</span>
                </div>
                <a href="../online_store.php?bid=<?php echo $branch_id; ?>" class="btn btn-light btn-sm rounded-pill px-4 border">
                    <i class="mdi mdi-arrow-left me-1"></i> Back to Store
                </a>
            </div>
        </div>
    </div>
</div>

<?php require "../includes/footer.php"; ?>

<style>
    :root { --echo-green: #198754; --echo-light: #f8fbf9; }
    .icon-circle { width: 80px; height: 80px; background: #e8f5e9; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; font-size: 40px; }
    .upload-area { border: 2px dashed #ced4da; border-radius: 15px; padding: 30px; background: var(--echo-light); transition: 0.3s; text-align: center; cursor: pointer; }
    .upload-area.dragover { border-color: var(--echo-green); background: #eaffee; transform: scale(1.02); }
    .upload-area:hover { border-color: var(--echo-green); }
    #submit-btn { background: var(--echo-green); border: none; transition: all 0.3s ease; }
    #submit-btn:hover { background: #157347; transform: translateY(-2px); }
</style>

<script>
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const prompt = document.getElementById('upload-prompt');
    const previewContainer = document.getElementById('file-preview-container');
    const imagePreview = document.getElementById('image-preview');
    const fileDetails = document.getElementById('file-details');
    const form = document.getElementById('upload-form');
    const submitBtn = document.getElementById('submit-btn');

    // Handle clicks on the upload zone
    dropZone.addEventListener('click', (e) => {
        if (e.target.tagName !== 'BUTTON') {
            fileInput.click();
        }
    });

    // Handle drag and drop visuals
    ['dragenter', 'dragover'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
        }, false);
    });

    dropZone.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files.length > 0) {
            fileInput.files = files;
            handleFiles(files[0]);
        }
    });

    fileInput.onchange = function() {
        if (this.files && this.files[0]) handleFiles(this.files[0]);
    };

    function handleFiles(file) {
        prompt.classList.add('d-none');
        previewContainer.classList.remove('d-none');
        fileDetails.innerText = file.name;

        if (file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (e) => {
                imagePreview.src = e.target.result;
                imagePreview.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        } else {
            imagePreview.classList.add('d-none');
            fileDetails.innerHTML = `<i class="mdi mdi-file-pdf-box text-danger" style="font-size: 50px;"></i><br>${file.name}`;
        }
    }

    function resetUpload(event) {
        if(event) event.stopPropagation(); // Prevent re-triggering file click
        fileInput.value = '';
        prompt.classList.remove('d-none');
        previewContainer.classList.add('d-none');
    }

    form.onsubmit = function() {
        submitBtn.disabled = true;
        submitBtn.querySelector('.btn-text').innerText = "Processing...";
        submitBtn.querySelector('.spinner-border').classList.remove('d-none');
    };
</script>