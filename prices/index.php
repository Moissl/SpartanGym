<?php
$base_path = '../';
$page_title = 'pricespage';
include_once('../assets/includes/header.php');

// Verificar si existe la columna 'hidden' para filtrar
$check_col = $conn->query("SHOW COLUMNS FROM tickets LIKE 'hidden'");
if ($check_col && $check_col->num_rows > 0) {
    $sql = "SELECT * FROM tickets WHERE hidden = 0";
} else {
    $sql = "SELECT * FROM tickets";
}
$result = $conn->query($sql);
?>
<style>
    .price-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .price-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }
</style>

<div class="container" style="margin-top: 120px;">
    <div class="row text-center justify-content-center mb-5">
        <div class="col-lg-8 col-md-10 mt-3">
            <h1 class="fw-bold mb-3"><i class="bi bi-tag-fill text-primary me-2"></i> <?php echo $translations["pricelist"];?></h1>
            <p class="lead text-muted">Encuentra el plan perfecto para ti. Sin costos ocultos.</p>
        </div>
    </div>
    <?php
    if ($result->num_rows > 0) {
        echo "<div class='row g-4 justify-content-center'>";

        while ($row = $result->fetch_assoc()) {
            echo "<div class='col-lg-3 col-md-4 col-sm-6'>";
            echo "<div class='card shadow-sm border-0 rounded-4 h-100 text-center price-card'>";
            echo "<div class='card-body p-4 d-flex flex-column'>";
            echo "<div class='mb-3 mt-2 d-flex justify-content-center'><span class='badge bg-primary bg-opacity-10 text-primary rounded-4 px-4 py-2 fs-6 fw-bold text-wrap lh-base' style='max-width: 100%;'>" . htmlspecialchars($row["name"]) . "</span></div>";
            echo "<h2 class='display-6 fw-bold mb-4 mt-3'>" . htmlspecialchars($row["price"]) . " <span class='fs-4 text-white fw-normal'>" . htmlspecialchars($currency) . "</span></h2>";
            echo "<hr class='text-muted opacity-25 w-75 mx-auto mb-4'>";
            echo "<div class='text-start mt-2 mb-auto px-2'>";
            echo "<p class='card-text d-flex align-items-center mb-3'><i class='bi bi-check-circle-fill text-success me-3 fs-5'></i> <span>" . $translations["tickettableexpiry"] . ": <strong>" . htmlspecialchars($row["expire_days"]) . " " . $translations['day'] . "</strong></span></p>";
            $occasions = $row["occasions"] === NULL ? $translations["unlimited"] : htmlspecialchars($row["occasions"]);
            echo "<p class='card-text d-flex align-items-center'><i class='bi bi-check-circle-fill text-success me-3 fs-5'></i> <span>" . $translations["tickettableoccassion"] . ": <strong>" . $occasions . "</strong></span></p>";
            echo "</div>";
            echo "</div>";
            echo "</div>";
            echo "</div>";
        }

        echo "</div>";
    } else {
        echo "<div class='alert alert-warning' role='alert'>" . $translations["notickets"] . "</div>";
    }
    ?>
</div>

<?php include_once('../assets/includes/footer.php'); ?>