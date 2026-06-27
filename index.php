<?php
$base_path = './';
$page_title = "mainpage";
include_once('assets/includes/header.php');

$sql = "SELECT * FROM opening_hours ORDER BY day ASC";
$result = $conn->query($sql);

$days = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $days[] = $row;
    }
}

$sql = "SELECT COUNT(*) AS total FROM temp_loggeduser";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $workoutpeoplenow = $row['total'];
} else {
    $workoutpeoplenow = 0;
}

if ($capacity > 0) {
    $capacityPercent = ($workoutpeoplenow / $capacity) * 100;
} else {
    $capacityPercent = 0;
}

$progresscolor = '';

if ($capacityPercent >= 0 && $capacityPercent < 70) {
    $progresscolor = 'success';
} elseif ($capacityPercent >= 70 && $capacityPercent < 90) {
    $progresscolor = 'warning';
} elseif ($capacityPercent >= 90) {
    $progresscolor = 'danger';
}

// Obtener imágenes de promociones
$promo_dir = 'assets/img/promotions/';
$promo_images = [];
if (is_dir($promo_dir)) {
    $files = scandir($promo_dir);
    foreach ($files as $file) {
        if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'])) {
            $promo_images[] = $promo_dir . $file;
        }
    }
}
?>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        .hero-section {
            background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.8)), url('assets/img/brand/background.png') no-repeat center center;
            background-size: cover;
            border-bottom-left-radius: 50px;
            border-bottom-right-radius: 50px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .hover-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .hover-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 25px rgba(0,0,0,0.1) !important;
        }
        .social-icon-container {
            transition: transform 0.2s ease;
            display: inline-block;
            color: white;
        }
        .social-icon-container:hover {
            transform: scale(1.1);
            color: #f8f9fa;
        }
        .schedule-row {
            transition: all 0.3s ease;
        }
        .schedule-row:hover {
            background-color: #f8f9fa !important;
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.05);
            z-index: 10;
            position: relative;
            border-color: transparent !important;
        }
    </style>

    <div class="hero-section text-center text-white py-5">
        <div class="container py-5">
            <h1 class="display-3 fw-bold mb-3"><?php echo $business_name; ?></h1>
            <p class="lead fs-4 opacity-75"><?php echo $description; ?></p>
        </div>
    </div>

    <section class="min-vh-75 d-flex align-items-center py-5">
        <div class="container mt-5">
            <div class="row g-5 justify-content-center align-items-center">
            <div class="col-lg-5 d-flex">
                <div class="d-flex flex-column gap-3 w-100">
                    <div class="card shadow-sm border-0 rounded-4 hover-card">
                        <div class="card-body d-flex flex-column">
                            <h2 class="card-title mb-3 fw-bold"><i class="bi bi-info-circle-fill text-primary me-2"></i> <?php echo $translations["about-us"]; ?></h2>
                            <p><?php echo $about_us; ?></p>
                        </div>
                    </div>
                    <div class="card shadow-sm border-0 rounded-4 hover-card">
                        <div class="card-body p-4">
                            <h5 class="card-title fw-bold mb-4"><i class="bi bi-people-fill text-primary me-2"></i> <?php echo $translations["capacitytext"]; ?></h5>
                            <div class="progress mb-3 rounded-pill" style="height: 25px; box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.2);">
                                <div class="progress-bar bg-<?php echo $progresscolor; ?>" role="progressbar" style="width: <?php echo $capacityPercent; ?>%;" aria-valuenow="<?php echo $capacityPercent; ?>" aria-valuemin="0" aria-valuemax="100"><?php echo round($capacityPercent); ?>%</div>
                            </div>
                            <p class="card-text mt-0 text-center mb-0 fw-semibold text-white"><?php echo $workoutpeoplenow; ?> / <?php echo $capacity; ?> <?php echo $translations["people"]; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 d-flex">
                <?php if (!empty($promo_images)): ?>
                    <div class="card w-100 shadow-sm border-0 rounded-4 overflow-hidden promo-container hover-card">
                        <div class="card-body p-0 d-flex justify-content-center align-items-center">
                            <div id="promoCarousel" class="carousel slide w-100" data-bs-ride="carousel" data-bs-interval="3000" aria-label="Promociones">
                                <div class="carousel-indicators">
                                    <?php foreach ($promo_images as $index => $image): ?>
                                        <button type="button" data-bs-target="#promoCarousel" data-bs-slide-to="<?= $index ?>" class="<?= $index === 0 ? 'active' : '' ?>" aria-current="<?= $index === 0 ? 'true' : 'false' ?>" aria-label="Slide <?= $index + 1 ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="carousel-inner">
                                    <?php foreach ($promo_images as $index => $image): ?>
                                        <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                                            <img src="<?= $image ?>" class="d-block promo-carousel-img" alt="Promoción" style="object-position: center;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <button class="carousel-control-prev" type="button" data-bs-target="#promoCarousel" data-bs-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Previous</span>
                                </button>
                                <button class="carousel-control-next" type="button" data-bs-target="#promoCarousel" data-bs-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="visually-hidden">Next</span>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col-12">
                <div class="row justify-content-center">
                    <div class="col-lg-8 col-md-10">
                        <div class="card shadow-lg border-0 rounded-4 hover-card">
                            <div class="card-header py-4 border-0" style="background: linear-gradient(135deg, #0950dc 0%, #1188E6 100%); border-top-left-radius: 1rem; border-top-right-radius: 1rem;">
                                <h2 class="text-center mb-0 fw-bold text-white"><i class="bi bi-clock-history me-2"></i> <?php echo $translations["opening-hours"]; ?></h2>
                            </div>
                            <div class="card-body p-0 bg-light" style="border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;">
                                <?php if (!empty($days)) : ?>
                                    <div class="d-flex flex-column">
                                        <?php foreach ($days as $index => $day) : 
                                            $dayIndex = $day['day'];
                                            $dayName = isset($dayNames[$dayIndex]) ? $dayNames[$dayIndex] : '';
                                            $isLast = ($index === count($days) - 1);
                                        ?>
                                            <div class="d-flex flex-column flex-sm-row justify-content-between align-items-center p-4 <?= !$isLast ? 'border-bottom' : '' ?> bg-white schedule-row" style="<?= $isLast ? 'border-bottom-left-radius: 1rem; border-bottom-right-radius: 1rem;' : '' ?>">
                                                <span class="fw-bold fs-5 text-uppercase text-dark mb-3 mb-sm-0 d-flex align-items-center">
                                                    <div class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex justify-content-center align-items-center me-3" style="width: 45px; height: 45px;">
                                                        <i class="bi bi-calendar-day fs-5"></i>
                                                    </div>
                                                    <?php echo $dayName; ?>
                                                </span>
                                                <?php if (is_null($day['open_time']) && is_null($day['close_time'])) : ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger rounded-pill fs-6 px-4 py-3 py-sm-2 shadow-sm border border-danger border-opacity-25 w-100" style="max-width: 250px;">
                                                        <?= $translations["closed"]; ?>
                                                    </span>
                                                <?php else : ?>
                                                    <span class="badge bg-success bg-opacity-10 text-success rounded-pill fs-6 px-4 py-3 py-sm-2 shadow-sm border border-success border-opacity-25 w-100" style="max-width: 250px;">
                                                        <i class="bi bi-clock me-2"></i> <?= date('H:i', strtotime($day['open_time'])) ?> - <?= date('H:i', strtotime($day['close_time'])) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-5">
            <div class="col text-center">
                <h2 class="mb-4 fw-bold">Síguenos en Redes Sociales</h2>
                <div class="d-flex justify-content-center align-items-center flex-wrap gap-5">
                    <div class="d-flex flex-column align-items-center">
                        <a href="https://www.instagram.com/thespartanscenter?igsh=ODBubHF3aGkzZG5l" target="_blank" class="social-icon-container">
                            <i class="bi bi-instagram fs-1"></i>
                        </a>
                        <a href="https://www.instagram.com/thespartanscenter?igsh=ODBubHF3aGkzZG5l" target="_blank" class="text-decoration-none text-white mt-2 small">@thespartanscenter</a>
                    </div>
                    <div class="d-flex flex-column align-items-center">
                        <a href="https://www.facebook.com/share/1DrSEXpEYx/?mibextid=wwXIfr" target="_blank" class="social-icon-container">
                            <i class="bi bi-facebook fs-1"></i>
                        </a>
                        <a href="https://www.facebook.com/share/1DrSEXpEYx/?mibextid=wwXIfr" target="_blank" class="text-decoration-none text-white mt-2 small">@TheSpartansCenter</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </section>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<?php include_once('assets/includes/footer.php'); ?>
