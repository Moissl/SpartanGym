<?php
$base_path = '../';
$page_title = 'trainerspage';
include_once('../assets/includes/header.php');

$sql = "SELECT * FROM trainers";
$result = $conn->query($sql);
?>

<style>
    .trainer-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .trainer-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }
</style>

<div class="container" style="margin-top: 120px;">
    <div class="row text-center justify-content-center mb-5">
        <div class="col-lg-8 col-md-10 mt-3">
            <h1 class="fw-bold mb-3"><i class="bi bi-award text-primary me-2"></i> <?php echo $translations["trainerspage"]; ?></h1>
            <p class="lead text-muted">Conoce a nuestros entrenadores expertos dispuestos a ayudarte a alcanzar tus metas.</p>
        </div>
    </div>
    <div class="row g-4 justify-content-center">
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <div class="col-lg-4 col-md-6">
                    <div class="card h-100 shadow-sm border-0 rounded-4 overflow-hidden trainer-card text-center">
                        <div class="card-body p-5 d-flex flex-column align-items-center">
                            <div class="mb-4 position-relative">
                                <div class="bg-primary rounded-circle position-absolute" style="width: 160px; height: 160px; top: 10px; left: -10px; opacity: 0.1;"></div>
                                <img src="<?php echo '../assets/img/trainers/trainer_' . $row['id'] . '.png'; ?>" alt="<?php echo $row['name']; ?>" class="img-fluid rounded-circle shadow-sm position-relative" style="width: 160px; height: 160px; object-fit: cover; border: 5px solid white;">
                            </div>
                            <h3 class="card-title fw-bold text-primary mb-3"><?php echo $row['name']; ?></h3>
                            <p class="card-text text-muted mb-4" style="font-size: 0.95rem; line-height: 1.6; flex-grow: 1;"><?php echo nl2br($row['description']); ?></p>
                            <!--
                            <div class="d-flex flex-column gap-2 mt-auto w-100">
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-3">
                                    <span class="text-secondary fw-semibold"><i class="bi bi-clock text-primary me-2"></i> 1 <?php echo $translations["hour"]; ?></span>
                                    <strong class="fs-5 text-dark"><?php echo $row['price_1hour']; ?> <?php echo $currency; ?></strong>
                                </div>
                                <div class="d-flex justify-content-between align-items-center bg-light p-3 rounded-3">
                                    <span class="text-secondary fw-semibold"><i class="bi bi-calendar-check text-primary me-2"></i> 10 <?php echo $translations["occasions"]; ?></span>
                                    <strong class="fs-5 text-dark"><?php echo $row['price_10sessions']; ?> <?php echo $currency; ?></strong>
                                </div>
                            </div>
                            -->
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php endif; ?>
    </div>
</div>

<?php include_once('../assets/includes/footer.php'); ?>