<?php 
session_start();
require "includes/head.php";

// Initialize variables to avoid "Undefined variable" errors
$id = $_SESSION['patient_no'] ?? '';
$invoice = $_SESSION['invoice'] ?? '';
$error_msg = $_SESSION['out-stock'] ?? 'An unknown error occurred during checkout.';

// Clear the error message so it doesn't show again on refresh
unset($_SESSION['out-stock']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <style>
        body { background: #f2f2f2; font-family: 'Poppins', sans-serif; }
        .payment {
            border: 1px solid #f2f2f2;
            height: auto;
            padding-bottom: 30px;
            border-radius: 20px;
            background: #fff;
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .payment_header {
            padding: 20px;
            border-radius: 20px 20px 0px 0px;
        }
        .check {
            margin: 0px auto;
            width: 60px;
            height: 60px;
            border-radius: 100%;
            background: #fff;
            text-align: center;
        }
        .check i {
            vertical-align: middle;
            line-height: 60px;
            font-size: 30px;
        }
        .content { text-align: center; padding: 20px; }
        .content h1 { font-size: 28px; font-weight: 700; color: #dc3545; margin-bottom: 15px; }
        .content p { color: #666; margin-bottom: 25px; }
        .content a {
            display: inline-block;
            width: 200px;
            text-decoration: none;
            color: #fff;
            border-radius: 30px;
            padding: 10px 20px;
            background: #dc3545;
            transition: all ease-in-out 0.3s;
            font-weight: 600;
        }
        .content a:hover { background: #000; color: #fff; }
    </style>
</head>
<body>
<div class="container">
   <div class="row">
      <div class="col-md-6 mx-auto mt-5">
         <div class="payment">
            <div class="payment_header bg-danger text-center">
               <div class="check">
                   <i class="fa fa-exclamation-triangle text-danger" aria-hidden="true"></i>
               </div>
            </div>
            <div class="content">
               <h1>Transaction Failed</h1>
               <p><?php echo htmlspecialchars($error_msg); ?></p>
               
               <?php if ($id && $invoice): ?>
                  <a href="prescription.php?invoice=<?php echo urlencode($invoice); ?>&patient_id=<?php echo urlencode($id); ?>">
                    <i class="fa fa-arrow-left me-2"></i> Go Back 
                  </a>
               <?php else: ?>
                  <a href="index.php">Return Home</a>
               <?php endif; ?>
            </div>
         </div>
      </div>
   </div>
</div>
</body>
</html>