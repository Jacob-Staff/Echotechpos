<?php
require "includes/head.php";
require "includes/conn.php";

if (isset($_GET['invoice'])) {
    // Sanitize the input
    $invoice = mysqli_real_escape_string($conn, $_GET['invoice']);
} else {
    die("Invoice ID missing.");
}
?>

<style>
@media print {
    body * { visibility: hidden; }
    .print-container, .print-container * { visibility: visible; }
    .print-container { position: absolute; left: 0; top: 0; width: 100%; }
}
body { font-family: 'Maven Pro', sans-serif; background-color: #f5f5f5; }
hr { color: #0000004f; margin: 5px 0; }
.add td { color: #adadad; text-transform: uppercase; font-size: 12px; font-weight: bold; }
.content { font-size: 14px; }
.total-section { background: #f9f9f9; padding: 10px; border-radius: 5px; }
</style>

<div align="left" class="px-5 mb-5 no-print">
    <br>
    <a href="invoice.php" class="btn btn-secondary">← Back to List</a>
</div>

<body>
<div class="container mt-5 mb-3">
    <div class="row d-flex justify-content-center">
        <div class="col-md-9">
            <div class="card print-container p-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="d-flex flex-row align-items-center">
                        <img src="assets/images/logo-icon.png" width="48" class="mr-2">
                        <h3 class="font-weight-bold mb-0">Echo Prime Ltd</h3>
                    </div>
                    <div class="text-right">
                        <h4 class="text-muted">INVOICE</h4>
                        <span>#<?php echo $invoice; ?></span>
                    </div>
                </div>
                
                <hr>
                
                <div class="row mt-3">
                    <div class="col-6">
                        <h5>From:</h5>
                        <address>
                            <strong>Echo Prime Ltd</strong><br>
                            Lusaka, Zambia<br>
                            Phone: +260 XXX XXX XXX<br>
                            Email: info@echoprime.com
                        </address>
                    </div>
                    <div class="col-6 text-right">
                        <h5>To:</h5>
                        <?php 
                        $patient_sql = "SELECT * FROM patients WHERE patient_no = '$invoice'";
                        $p_res = mysqli_query($conn, $patient_sql);
                        if($p_row = mysqli_fetch_assoc($p_res)){
                            echo "<strong>".$p_row['patient_name']."</strong><br>";
                            echo "Patient ID: ".$p_row['patient_no'];
                        }
                        ?>
                    </div>
                </div>

                <div class="products mt-4">
                    <h6 class="text-primary">Medicine Prescribed</h6>
                    <table class="table table-sm">
                        <thead>
                            <tr class="add">
                                <td>Item Description</td>
                                <td>Qty</td>
                                <td>Unit Price</td>
                                <td class="text-right">Total</td>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $med_total = 0;
                            $sql = "SELECT * FROM sales_order WHERE invoice = '$invoice'";
                            $res = mysqli_query($conn, $sql);
                            while ($rows = mysqli_fetch_assoc($res)) {
                                $sub = $rows['qty'] * $rows['price'];
                                $med_total += $sub;
                                echo "<tr class='content'>
                                        <td>{$rows['medicine_name']}</td>
                                        <td>{$rows['qty']}</td>
                                        <td>".number_format($rows['price'], 2)."</td>
                                        <td class='text-right'>".number_format($sub, 2)."</td>
                                      </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <div class="products mt-3">
                    <h6 class="text-primary">Laboratory Tests</h6>
                    <table class="table table-sm">
                        <tbody>
                            <?php 
                            $lab_total = 0;
                            $sql = "SELECT * FROM lab_results WHERE patient_no = '$invoice'";
                            $res = mysqli_query($conn, $sql);
                            if(mysqli_num_rows($res) > 0){
                                while ($rows = mysqli_fetch_assoc($res)) {
                                    $lab_total += $rows['price'];
                                    echo "<tr class='content'>
                                            <td width='50%'>{$rows['test_name']}</td>
                                            <td>1</td>
                                            <td>".number_format($rows['price'], 2)."</td>
                                            <td class='text-right'>".number_format($rows['price'], 2)."</td>
                                          </tr>";
                                }
                            } else { echo "<tr><td colspan='4' class='text-muted'>No lab tests recorded.</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>

                <div class="products mt-3">
                    <h6 class="text-primary">Services Offered</h6>
                    <table class="table table-sm">
                        <tbody>
                            <?php 
                            $srv_total = 0;
                            $sql = "SELECT * FROM service_order WHERE patient_no = '$invoice'";
                            $res = mysqli_query($conn, $sql);
                            while ($rows = mysqli_fetch_assoc($res)) {
                                $srv_total += $rows['price'];
                                echo "<tr class='content'>
                                        <td width='50%'>{$rows['service_name']}</td>
                                        <td>1</td>
                                        <td>".number_format($rows['price'], 2)."</td>
                                        <td class='text-right'>".number_format($rows['price'], 2)."</td>
                                      </tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <hr>
                
                <div class="row">
                    <div class="col-7">
                        <p class="text-muted small"><em>Note: This is a system-generated legal invoice for Echo Prime Ltd services and pharmaceutical supplies.</em></p>
                    </div>
                    <div class="col-5">
                        <table class="table table-borderless">
                            <tr>
                                <td><strong>Grand Total:</strong></td>
                                <td class="text-right text-success">
                                    <h3>K <?php echo number_format(($med_total + $lab_total + $srv_total), 2); ?></h3>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="text-center mt-4 no-print">
                <button class="btn btn-primary px-5" onclick="window.print()">Print Invoice</button>
            </div>
        </div>
    </div>
</div>
</body>
</html>