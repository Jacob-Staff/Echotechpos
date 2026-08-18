<?php
session_start();
require "../includes/conn.php";
require "../includes/auth.php";

$pharmacy_id = $_SESSION['pharmacy_id'] ?? null;
$branch_id   = $_SESSION['branch_id'] ?? null;

if (!$pharmacy_id || !$branch_id) {
    header("Location: ../login.php");
    exit;
}

// Fetch Pharmacy Name for the title
$display_name = "Pharmacy POS";
$p_query = $conn->prepare("SELECT name FROM pharmacies WHERE id = ? LIMIT 1");
$p_query->bind_param("i", $pharmacy_id);
$p_query->execute();
$res = $p_query->get_result();
if($row = $res->fetch_assoc()) { $display_name = $row['name']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Global Search - <?php echo $display_name; ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f9; font-family: 'Poppins', sans-serif; }
        .search-container {
            background: #fff; border-radius: 12px; padding: 2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-top: 20px;
        }
        .search-input { border-radius: 30px 0 0 30px; border: 2px solid #198754; }
        .search-input:focus { box-shadow: none; border-color: #146c43; }
        .search-btn { border-radius: 0 30px 30px 0; background: #198754; border: 2px solid #198754; }
        .results-card { margin-top: 20px; min-height: 200px; }
        .section-title { background: #198754; color: #fff; padding: 8px 15px; border-radius: 6px; margin-top: 20px; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; }
        .badge-result { font-size: 0.8rem; }
    </style>
</head>
<body>

<?php 
    include "../includes/header.php"; 
    include "../includes/aside.php"; 
?>

<div class="container pb-5">
    <div class="search-container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h3 class="mb-0"><i class="bi bi-search text-success"></i> Global Search</h3>
                <small class="text-muted">Searching records for: <strong><?php echo $display_name; ?></strong></small>
            </div>
            <i class="bi bi-database-fill-check text-success h2"></i>
        </div>

        <div class="input-group">
            <input type="text" id="searchBox" class="form-control search-input" placeholder="Type medicine name, invoice number, or supplier...">
            <button class="btn btn-primary search-btn" type="button" id="searchBtn">
                <i class="bi bi-search"></i> Search
            </button>
        </div>
    </div>

    <div id="results" class="results-card bg-white p-4 rounded shadow-sm">
        <div class="text-center py-5">
            <i class="bi bi-keyboard text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-2">Enter at least 2 characters to search the database...</p>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
$(document).ready(function(){
    let timer;
    $("#searchBox").on("input", function(){
        clearTimeout(timer);
        var query = $(this).val();
        if(query.length > 1) {
            timer = setTimeout(function() {
                load_data(query, 1);
            }, 300); // Wait 300ms after typing stops
        }
    });

    $("#searchBtn").click(function(){
        load_data($("#searchBox").val(), 1);
    });

    function load_data(query, page){
        $("#results").html('<div class="text-center py-5"><div class="spinner-border text-success" role="status"></div><p class="mt-2">Searching...</p></div>');
        $.ajax({
            url: "search_loader.php",
            method: "POST",
            data: {query:query, page:page},
            success:function(data){
                $("#results").html(data);
            }
        });
    }
});
</script>
</body>
</html>