<div class="card">
    <div class="card-body">
        <h4 class="card-title">Receive Medicine</h4>
    </div>
    
    <form action="includes/delete_expired_inc.php" method="post" id="manage-receiving">
        <div class="col-md-12">
            <?php
            if (isset($_GET['id'])) {
                require "includes/conn.php";
                $id = mysqli_real_escape_string($conn, $_GET['id']);
                
                // Fetch the specific medicine details
                $sql = "SELECT * FROM store WHERE id = '$id'";
                $res = mysqli_query($conn, $sql);

                if ($res && mysqli_num_rows($res) > 0) {
                    $row = mysqli_fetch_assoc($res);
                    $medicine_name = $row['medicine_name'];
                    $Qty = $row['Qty'];
                    $price = $row['price'];
                    $total = $Qty * $price;
                    ?>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="control-label">Medicine Name</label>
                            <input type="text" name="medicine_name" value="<?php echo $medicine_name; ?>" class="form-control" readonly>
                        </div>
                        
                        <div class="col-md-2">
                            <label class="control-label">Available Quantity</label>
                            <input type="text" name="available_quantity" class="form-control text-right" value="<?php echo $Qty; ?>" readonly>
                            <input type="hidden" name="price" value="<?php echo $price; ?>">
                            <input type="hidden" name="id" value="<?php echo $id; ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="control-label">Expired Qty</label>
                            <input type="number" name="expired_qty" class="form-control text-right" placeholder="0" required>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="control-label">Expiry Date</label>
                            <input type="date" name="expiry_date" class="form-control" value="<?php echo $row['expiry_date']; ?>">
                        </div>
                    </div>

                    <div class="col-md-12 mb-3 text-right">
                        <button class="btn btn-danger btn-lg shadow-sm" name="delete" type="submit">
                            <i class="fa fa-trash"></i> Process Expired Stock
                        </button>
                    </div>
                    <?php
                } else {
                    echo "<div class='alert alert-warning'>Medicine record not found.</div>";
                }
            }
            ?>
        </div>
    </form>
</div>