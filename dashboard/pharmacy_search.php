<?php
session_start();
// Create connection
require "includes/conn.php";
require "includes/auth.php"; // Ensuring the user is logged in

/** * RULE 1: MULTI-TENANT FILTERING
 */
$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

// Security check
if (!$pharmacy_id || !$branch_id) {
    echo "<tr><td colspan='8' class='text-danger'>Session Expired. Please login again.</td></tr>";
    exit();
}
?>

<form method="post" action="manage_receiving.php">
<?php
// Capture search name from POST
$search_name = mysqli_real_escape_string($conn, $_POST['name'] ?? '');

/**
 * UPDATED QUERY: Pulls data ONLY for the current pharmacy and branch
 */
$sql = "SELECT * FROM pharmacy_stock 
        WHERE medicine_name LIKE '%$search_name%' 
        AND pharmacy_id = '$pharmacy_id' 
        AND branch_id = '$branch_id'";

$result = mysqli_query($conn, $sql);
$sn = 1;

if (mysqli_num_rows($result) > 0) {
    while ($row = mysqli_fetch_assoc($result)) {
        $id            = $row['id'];
        $medicine_name = $row['medicine_name'];
        $pharmacy_Qty  = $row['pharmacy_Qty'];
        $price         = $row['price'];
        $expiry_date   = $row['expiry_date'];
        $dosage_sold   = $row['dosage_sold'];
        $price_dosage  = $row['price_dosage'];
        ?>
        <tbody id="output">
            <tr>
                <th scope="row"><?php echo $sn++; ?></th>
                <td><?php echo htmlspecialchars($medicine_name); ?></td>
                
                <?php if ($pharmacy_Qty < 2): ?>
                    <td class='text-white text-bold bg-danger'><?php echo $pharmacy_Qty; ?></td>
                <?php else: ?>
                    <td class='text-bold'><?php echo $pharmacy_Qty; ?></td>
                <?php endif; ?>

                <td>
                    <?php 
                    if ($dosage_sold == "Yes") {
                        echo number_format($price_dosage, 2);
                    } else {
                        echo number_format($price, 2);
                    }
                    ?>
                </td>

                <td>
                    <?php 
                    if ($dosage_sold == "Yes") {
                        echo number_format($price_dosage * $pharmacy_Qty, 2);
                    } else {
                        echo number_format($price * $pharmacy_Qty, 2);
                    }
                    ?>
                </td>

                <td><?php echo $expiry_date; ?></td>
                <td>
                    <a href="update_pharmacy.php?id=<?php echo $id; ?>" class="btn btn-success" type="button">Update Stock</a>
                </td>
            </tr>
        </tbody>
        <?php
    }
} else {
    echo "<tbody><tr><td colspan='8' class='text-center p-4'>0 results found for this branch.</td></tr></tbody>";
}
?>
</form>