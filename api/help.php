<?php
require_once("store_header.php"); 

// Logic for handling the contact form submission
$message_sent = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_inquiry'])) {
    $name = mysqli_real_escape_string($conn, $_POST['name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $subject = mysqli_real_escape_string($conn, $_POST['subject']);
    $msg = mysqli_real_escape_string($conn, $_POST['message']);
    $p_id = $tenant_context['pharmacy_id'];
    $b_id = $branch_id;

    $ins_sql = "INSERT INTO help_inquiries (pharmacy_id, branch_id, client_name, client_email, subject, message) 
                VALUES ('$p_id', '$b_id', '$name', '$email', '$subject', '$msg')";
    
    if ($conn->query($ins_sql)) {
        $message_sent = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <style>
        .help-hero { background: linear-gradient(135deg, #2c3e50 0%, #00d2ff 100%); color: white; padding: 80px 0; border-radius: 0 0 50px 50px; }
        .search-container { max-width: 600px; margin: -35px auto 0; position: relative; z-index: 10; }
        .search-input { border-radius: 30px; padding: 25px 30px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); border: none; font-size: 1.1rem; }
        .support-card { border: none; border-radius: 20px; transition: all 0.3s ease; background: white; height: 100%; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .support-card:hover { transform: translateY(-10px); box-shadow: 0 15px 30px rgba(0,0,0,0.1); }
        .faq-item { border-bottom: 1px solid #eee; }
        .faq-question { padding: 20px 0; cursor: pointer; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .faq-answer { padding-bottom: 20px; color: #666; display: none; }
        .contact-form-card { border-radius: 30px; border: none; box-shadow: 0 20px 40px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="bg-light">

<div class="help-hero text-center">
    <div class="container">
        <h1 class="display-4 fw-bold mb-3 animate__animated animate__fadeInDown">How can we help today?</h1>
        <p class="lead opacity-75">Support for <?php echo $pharmacy_name; ?> - <?php echo $branch_name; ?></p>
    </div>
</div>

<div class="container">
    <div class="search-container animate__animated animate__zoomIn">
        <input type="text" id="faqSearch" class="form-control search-input" placeholder="Search for answers (e.g. delivery, payments, prescriptions)...">
    </div>

    <div class="row g-4 mt-5">
        <div class="col-md-4 text-center">
            <div class="support-card p-4">
                <div class="mb-3 text-success"><i class="fab fa-whatsapp fa-3x"></i></div>
                <h4>WhatsApp Support</h4>
                <p class="text-muted">Instant chat with our pharmacist.</p>
                <a href="https://wa.me/<?php echo $tenant_context['branch_phone']; ?>" class="btn btn-outline-success rounded-pill px-4">Chat Now</a>
            </div>
        </div>
        <div class="col-md-4 text-center">
            <div class="support-card p-4">
                <div class="mb-3 text-primary"><i class="fas fa-prescription fa-3x"></i></div>
                <h4>Prescription Help</h4>
                <p class="text-muted">Need help uploading your script?</p>
                <a href="upload_prescription.php?bid=<?php echo $branch_id; ?>" class="btn btn-outline-primary rounded-pill px-4">View Guide</a>
            </div>
        </div>
        <div class="col-md-4 text-center">
            <div class="support-card p-4">
                <div class="mb-3 text-danger"><i class="fas fa-phone-alt fa-3x"></i></div>
                <h4>Emergency Call</h4>
                <p class="text-muted">Available during business hours.</p>
                <a href="tel:<?php echo $tenant_context['branch_phone']; ?>" class="btn btn-outline-danger rounded-pill px-4">Call <?php echo $tenant_context['branch_phone']; ?></a>
            </div>
        </div>
    </div>

    <div class="row mt-5 pt-5">
        <div class="col-lg-7 mb-5">
            <h2 class="fw-bold mb-4">Common Questions</h2>
            <div class="faq-list" id="faqList">
                <div class="faq-item" data-keywords="prescription upload image script">
                    <div class="faq-question">How do I upload a prescription? <i class="fas fa-plus"></i></div>
                    <div class="faq-answer">Click on the 'Prescriptions' menu, select 'Upload New', and attach a clear image. Our pharmacist will verify it within 15 minutes.</div>
                </div>
                <div class="faq-item" data-keywords="delivery time kanyama lusaka how long">
                    <div class="faq-question">How long does delivery take? <i class="fas fa-plus"></i></div>
                    <div class="faq-answer">For <?php echo $tenant_context['location']; ?>, we offer express delivery (under 1 hour) and standard delivery (2-4 hours).</div>
                </div>
                <div class="faq-item" data-keywords="payment airtel mtn cash momo">
                    <div class="faq-question">What payment methods do you accept? <i class="fas fa-plus"></i></div>
                    <div class="faq-answer">We accept Cash on Delivery, Airtel Money, MTN MoMo, and Visa/Mastercard via our secure gateway.</div>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card contact-form-card p-4 p-md-5">
                <h3 class="fw-bold mb-4">Send a Message</h3>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Full Name</label>
                        <input type="text" name="name" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Email Address</label>
                        <input type="email" name="email" class="form-control rounded-3" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Subject</label>
                        <select name="subject" class="form-select rounded-3">
                            <option>Order Status</option>
                            <option>Product Inquiry</option>
                            <option>Payment Issue</option>
                            <option>Other</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-bold">Message</label>
                        <textarea name="message" class="form-control rounded-3" rows="4" required></textarea>
                    </div>
                    <button type="submit" name="send_inquiry" class="btn btn-dark w-100 py-3 rounded-pill fw-bold">Send Inquiry</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<script>
    $(document).ready(function() {
        // 1. FAQ Toggle Logic
        $('.faq-question').click(function() {
            const item = $(this).parent();
            item.find('.faq-answer').slideToggle();
            $(this).find('i').toggleClass('fa-plus fa-minus');
        });

        // 2. Live FAQ Search
        $("#faqSearch").on("keyup", function() {
            var value = $(this).val().toLowerCase();
            $("#faqList .faq-item").filter(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1 || 
                             $(this).data('keywords').toLowerCase().indexOf(value) > -1);
            });
        });

        // 3. Success Notification
        <?php if($message_sent): ?>
        Toastify({
            text: "Message sent! We will contact you soon.",
            duration: 5000,
            close: true,
            gravity: "top",
            position: "center",
            style: { background: "linear-gradient(to right, #00b09b, #96c93d)" }
        }).showToast();
        <?php endif; ?>
    });
</script>

</body>
</html>