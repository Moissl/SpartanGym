<?php
if (!isset($base_path)) {
    $base_path = './';
}
include_once($base_path . 'assets/includes/db.php');
?>
<!DOCTYPE html>
<html lang="<?php echo $lang_code; ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $business_name; ?> - <?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?php echo $base_path; ?>assets/css/style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" href="<?php echo $base_path; ?>assets/img/brand/favicon.png" type="image/x-icon">
    <meta name="title" content="<?php echo $business_name; ?> - <?php echo $page_title; ?>">
    <meta name="description" content="<?php echo $description; ?>">
    <meta name="keywords" content="<?php echo $metakey; ?>">
    <meta name="robots" content="index, follow">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="author" content="<?php echo $business_name; ?>">
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?php echo $base_path; ?>" style="padding: 0;">
                <img src="<?php echo $base_path; ?>assets/img/brand/logo.png" alt="<?php echo $business_name; ?> Logo" style="max-height: 85px; width: auto; transition: transform 0.3s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php if ($page_title == $translations['mainpage']) echo 'active'; ?>" href="<?php echo $base_path; ?>"><?php echo $translations["mainpage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php if ($page_title == $translations['trainerspage']) echo 'active'; ?>" href="<?php echo $base_path; ?>trainers/"><?php echo $translations["trainerspage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php if ($page_title == $translations['pricespage']) echo 'active'; ?>" href="<?php echo $base_path; ?>prices/"><?php echo $translations["pricespage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php if ($page_title == $translations['contactpage']) echo 'active'; ?>" href="<?php echo $base_path; ?>contact/"><?php echo $translations["contactpage"]; ?></a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo $base_path; ?>login/" rel="noopener noreferrer" title="Login" class="btn btn-primary btn-login ms-lg-3">
                            <i class="bi bi-person-circle me-2"></i> <?php echo $translations["login"]; ?>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
