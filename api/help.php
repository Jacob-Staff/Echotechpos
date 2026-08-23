<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Context Branch ID Resolution
if (isset($_GET['bid']) && intval($_GET['bid']) > 0) {
    $branch_id = intval($_GET['bid']);
    $_SESSION['current_branch_id'] = $branch_id;
} elseif (isset($_SESSION['current_branch_id']) && intval($_SESSION['current_branch_id']) > 0) {
    $branch_id = intval($_SESSION['current_branch_id']);
} else {
    $branch_id = 10;
    $_SESSION['current_branch_id'] = $branch_id;
}

// 2. Include Header
require_once("store_header.php"); 

// Context safe extraction
$p_id            = isset($tenant_context['pharmacy_id']) ? intval($tenant_context['pharmacy_id']) : ($parent_pharmacy_id ?? 0);
$branch_phone    = !empty($tenant_context['branch_phone']) ? $tenant_context['branch_phone'] : '260974140989';
$branch_location = !empty($tenant_context['location']) ? $tenant_context['location'] : 'Lusaka';

// Form Handling
$message_sent = false;
$error_msg    = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_inquiry'])) {
    $name    = trim($_POST['name'] ?? '');
    $email   = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $subject = trim($_POST['subject'] ?? 'General Inquiry');
    $msg     = trim($_POST['message'] ?? '');

    if ($name && $email && $msg) {
        if ($ins_stmt = $conn->prepare("INSERT INTO help_inquiries (pharmacy_id, branch_id, client_name, client_email, subject, message) VALUES (?, ?, ?, ?, ?, ?)")) {
            $ins_stmt->bind_param("iissss", $p_id, $branch_id, $name, $email, $subject, $msg);
            if ($ins_stmt->execute()) {
                $message_sent = true;
            } else {
                $error_msg = "Database insert failed. Please try again.";
            }
            $ins_stmt->close();
        }
    } else {
        $error_msg = "Please fill in all required fields with a valid email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    
    <style>
        .help-hero { 
            background: linear-gradient(135deg, var(--echo-teal, #003339) 0%, var(--echo-blue, #1a4a7c) 100%); 
            color: white; 
            padding: 40px 15px 60px; 
            border-radius: 0 0 30px 30px; 
        }
        @media (min-width: 768px) {
            .help-hero {
                padding: 60px 0 80px;
                border-radius: 0 0 40px 40px;
            }
        }
        .search-container { 
            max-width: 600px; 
            margin: -28px auto 0; 
            position: relative; 
            z-index: 10; 
            padding: 0 15px;
        }
        .search-input { 
            border-radius: 30px; 
            padding: 14px 20px; 
            box-shadow: 0 8px 20px rgba(0,0,0,0.08); 
            border: 1px solid #eee; 
            font-size: 0.95rem; 
        }
        @media (min-width: 768px) {
            .search-input {
                padding: 18px 25px;
                font-size: 1rem;
            }
        }
        .support-card { 
            border: none; 
            border-radius: 18px; 
            transition: all 0.3s ease; 
            background: white; 
            height: 100%; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.04); 
        }
        .support-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 12px 24px rgba(0,0,0,0.08); 
        }
        .faq-item { 
            border-bottom: 1px solid #eee; 
        }
        .faq-question { 
            padding: 16px 0; 
            cursor: pointer; 
            font-weight: 600; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            color: var(--echo-teal, #003339);
            font-size: 0.98rem;
        }
        .faq-answer { 
            padding-bottom: 16px; 
            color: #555; 
            display: none; 
            font-size: 0.9rem;
            line-height: 1.5;
        }
        .contact-form-card { 
            border-radius: 20px; 
            border: none; 
            box-shadow: 0 8px 25px rgba(0,0,0,0.05); 
        }
    </style>
</head>
<body class="bg-light">

<div class="help-hero text-center">
    <div class="container">
        <h1 class="fs-2 fs-md-1 fw-bold mb-2 animate__animated animate__fadeInDown">How can we help today?</h1>
        <p class="lead opacity-75 fs-6 mb-0">Support for <?php echo htmlspecialchars($pharmacy_name ?? 'Pharmacy'); ?> - <?php echo htmlspecialchars($branch_name ?? 'Branch'); ?></p>
    </div>
</div>

<div class="container mb-5">
    <div class="search-container animate__animated animate__zoomIn">
        <input type="text" id="faqSearch" class="form-control search-input" placeholder="Search for answers (e.g. delivery, payments)...">
    </div>

    <div class="row g-3 g-md-4 mt-3 mt-md-4">
        <div class="col-12 col-sm-6 col-md-4 text-center">
            <div class="support-card p-3 p-md-4">
                <div class="mb-2 text-success"><i class="mdi mdi-whatsapp" style="font-size: 40px;"></i></div>
                <h6 class="fw-bold fs-6">WhatsApp Support</h6>
                <p class="text-muted small mb-3">Instant chat with our resident pharmacist.</p>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $branch_phone); ?>" target="_blank" class="btn btn-outline-success rounded-pill px-4 btn-sm fw-bold w-100 w-sm-auto">Chat Now</a>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4 text-center">
            <div class="support-card p-3 p-md-4">
                <div class="mb-2 text-primary"><i class="mdi mdi-file-document-edit-outline" style="font-size: 40px;"></i></div>
                <h6 class="fw-bold fs-6">Prescription Help</h6>
                <p class="text-muted small mb-3">Need help uploading your prescription?</p>
                <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-primary rounded-pill px-4 btn-sm fw-bold w-100 w-sm-auto">Upload Script</a>
            </div>
        </div>
        <div class="col-12 col-md-4 text-center">
            <div class="support-card p-3 p-md-4">
                <div class="mb-2 text-danger"><i class="mdi mdi-phone-in-talk" style="font-size: 40px;"></i></div>
                <h6 class="fw-bold fs-6">Emergency Call</h6>
                <p class="text-muted small mb-3">Available during branch business hours.</p>
                <a href="tel:<?php echo htmlspecialchars($branch_phone); ?>" class="btn btn-outline-danger rounded-pill px-4 btn-sm fw-bold w-100 w-sm-auto">Call <?php echo htmlspecialchars($branch_phone); ?></a>
            </div>
        </div>
    </div>

    <div class="row mt-4 mt-md-5 pt-2 pt-md-3">
        <div class="col-lg-7 mb-4 mb-lg-0">
            <h4 class="fw-bold mb-3 mb-md-4" style="color: var(--echo-teal, #003339);">Common Questions</h4>
            <div class="faq-list" id="faqList">
                <div class="faq-item" data-keywords="prescription upload image script order rx">
                    <div class="faq-question">
                        <span>How do I upload a prescription?</span> 
                        <i class="mdi mdi-plus fs-5 ms-2"></i>
                    </div>
                    <div class="faq-answer">
                        Click on the <strong>'Prescriptions'</strong> menu item at the top of the store, select <strong>'Upload New'</strong>, and attach a clear image or document. Our qualified pharmacist will review and process it within 15 minutes.
                    </div>
                </div>
                <div class="faq-item" data-keywords="delivery time location speed how long express">
                    <div class="faq-question">
                        <span>How long does delivery take?</span> 
                        <i class="mdi mdi-plus fs-5 ms-2"></i>
                    </div>
                    <div class="faq-answer">
                        For local delivery in <strong><?php echo htmlspecialchars($branch_location); ?></strong>, express fulfillment typically takes under 1 hour. Standard area delivery takes between 2 to 4 hours.
                    </div>
                </div>
                <div class="faq-item" data-keywords="payment airtel mtn cash momo money visa card mobile">
                    <div class="faq-question">
                        <span>What payment methods do you accept?</span> 
                        <i class="mdi mdi-plus fs-5 ms-2"></i>
                    </div>
                    <div class="faq-answer">
                        We accept Cash on Delivery, Mobile Money (MTN MoMo & Airtel Money), and direct bank transfers. You can view payment instructions during checkout.
                    </div>
                </div>
                <div class="faq-item" data-keywords="branch switch change pharmacy location">
                    <div class="faq-question">
                        <span>How do I order from a different branch?</span> 
                        <i class="mdi mdi-plus fs-5 ms-2"></i>
                    </div>
                    <div class="faq-answer">
                        Click on the location dropdown menu in the header. Switching branches will dynamically adjust stock availability and delivery options for your area.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card contact-form-card p-3 p-md-4">
                <h5 class="fw-bold mb-3" style="color: var(--echo-teal, #003339);">Send a Message</h5>
                <form method="POST" action="help.php?bid=<?php echo $branch_id; ?>">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="name" class="form-control rounded-3" value="<?php echo isset($_SESSION['client_name']) ? htmlspecialchars($_SESSION['client_name']) : ''; ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject</label>
                        <select name="subject" class="form-select rounded-3">
                            <option value="Order Status">Order Status</option>
                            <option value="Product Inquiry">Product Inquiry</option>
                            <option value="Payment Issue">Payment Issue</option>
                            <option value="Other">Other Inquiry</option>
                        </select>
                    </div>
                    <div class="mb-3 mb-md-4">
                        <label class="form-label small fw-bold">Message</label>
                        <textarea name="message" class="form-control rounded-3" rows="4" placeholder="How can we assist you?" required></textarea>
                    </div>
                    <button type="submit" name="send_inquiry" class="btn btn-dark w-100 py-2.5 rounded-pill fw-bold" style="background-color: var(--echo-teal, #003339);">
                        Send Inquiry
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
    $(document).ready(function() {
        // Accordion Toggle Logic
        $('.faq-question').on('click', function() {
            const answer = $(this).next('.faq-answer');
            const icon = $(this).find('i');
            
            answer.slideToggle(200);
            
            if (icon.hasClass('mdi-plus')) {
                icon.removeClass('mdi-plus').addClass('mdi-minus');
            } else {
                icon.removeClass('mdi-minus').addClass('mdi-plus');
            }
        });

        // Live Search Filter
        $("#faqSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#faqList .faq-item").filter(function() {
                var textMatch = $(this).text().toLowerCase().indexOf(value) > -1;
                var keywordMatch = ($(this).data('keywords') || '').toLowerCase().indexOf(value) > -1;
                $(this).toggle(textMatch || keywordMatch);
            });
        });

        // Toast Messages
        <?php if($message_sent): ?>
        Toastify({
            text: "Message sent! We will contact you shortly.",
            duration: 5000,
            close: true,
            gravity: "top",
            position: "center",
            style: { background: "linear-gradient(to right, #00b386, #003339)" }
        }).showToast();
        <?php elseif(!empty($error_msg)): ?>
        Toastify({
            text: "<?php echo htmlspecialchars($error_msg); ?>",
            duration: 5000,
            close: true,
            gravity: "top",
            position: "center",
            style: { background: "linear-gradient(to right, #e63946, #b71c1c)" }
        }).showToast();
        <?php endif; ?>
    });
</script>

</body>
</html>
