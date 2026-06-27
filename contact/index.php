<?php
$base_path = '../';
$page_title = 'contactpage';
include_once('../assets/includes/header.php');

require_once '../vendor/autoload.php';

$alerts_html = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $name = $_POST['name'];
  $userEmail = $_POST['email'];
  $userMessage = $_POST['message'];

  $transport = (new Swift_SmtpTransport($smtp_host, $smtp_port, $smtp_encryption))
    ->setUsername($smtp_username)
    ->setPassword($smtp_password);

  $mailer = new Swift_Mailer($transport);

  $adminMessage = (new Swift_Message($translations["newmessagefromwebsite"]))
    ->setFrom([$userEmail => $name])
    ->setTo([$smtp_username])
    ->setBody(
      $translations["fullname"] . ": " . $name . "\n" .
        $translations["email"] . ": " . $userEmail . "\n" .
        $translations["message"] . ": " . $userMessage . "\n"
    );

  $result = $mailer->send($adminMessage);
  $editedcontent = <<<EOD
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html data-editor-version="2" class="sg-campaigns" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1">
  <!--[if !mso]><!-->
  <meta http-equiv="X-UA-Compatible" content="IE=Edge">
  <!--<![endif]-->
  <!--[if (gte mso 9)|(IE)]>
  <xml>
    <o:OfficeDocumentSettings>
      <o:AllowPNG/>
      <o:PixelsPerInch>96</o:PixelsPerInch>
    </o:OfficeDocumentSettings>
  </xml>
  <![endif]-->
  <!--[if (gte mso 9)|(IE)]>
<style type="text/css">
body {width: 600px;margin: 0 auto;}
table {border-collapse: collapse;}
table,td {mso-table-lspace: 0pt;mso-table-rspace: 0pt;}
img {-ms-interpolation-mode: bicubic;}
</style>
<![endif]-->
  <style type="text/css">
body, p, div {
  font-family: arial,helvetica,sans-serif;
  font-size: 14px;
}
body {
  color: #000000;
}
body a {
  color: #1188E6;
  text-decoration: none;
}
p { margin: 0; padding: 0; }
table.wrapper {
  width:100% !important;
  table-layout: fixed;
  -webkit-font-smoothing: antialiased;
  -webkit-text-size-adjust: 100%;
  -moz-text-size-adjust: 100%;
  -ms-text-size-adjust: 100%;
}
img.max-width {
  max-width: 100% !important;
}
.column.of-2 {
  width: 50%;
}
.column.of-3 {
  width: 33.333%;
}
.column.of-4 {
  width: 25%;
}
ul ul ul ul  {
  list-style-type: disc !important;
}
ol ol {
  list-style-type: lower-roman !important;
}
ol ol ol {
  list-style-type: lower-latin !important;
}
ol ol ol ol {
  list-style-type: decimal !important;
}
@media screen and (max-width:480px) {
  .preheader .rightColumnContent,
  .footer .rightColumnContent {
    text-align: left !important;
  }
  .preheader .rightColumnContent div,
  .preheader .rightColumnContent span,
  .footer .rightColumnContent div,
  .footer .rightColumnContent span {
    text-align: left !important;
  }
  .preheader .rightColumnContent,
  .preheader .leftColumnContent {
    font-size: 80% !important;
    padding: 5px 0;
  }
  table.wrapper-mobile {
    width: 100% !important;
    table-layout: fixed;
  }
  img.max-width {
    height: auto !important;
    max-width: 100% !important;
  }
  a.bulletproof-button {
    display: block !important;
    width: auto !important;
    font-size: 80%;
    padding-left: 0 !important;
    padding-right: 0 !important;
  }
  .columns {
    width: 100% !important;
  }
  .column {
    display: block !important;
    width: 100% !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
  }
  .social-icon-column {
    display: inline-block !important;
  }
}
</style>
  <!--user entered Head Start--><!--End Head user entered-->
</head>
<body>
  <center class="wrapper" data-link-color="#1188E6" data-body-style="font-size:14px; font-family:arial,helvetica,sans-serif; color:#000000; background-color:#FFFFFF;">
    <div class="webkit">
      <table cellpadding="0" cellspacing="0" border="0" width="100%" class="wrapper" bgcolor="#FFFFFF">
        <tr>
          <td valign="top" bgcolor="#FFFFFF" width="100%">
            <table width="100%" role="content-container" class="outer" align="center" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td width="100%">
                  <table width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr>
                      <td>
                        <!--[if mso]>
<center>
<table><tr><td width="600">
<![endif]-->
                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px;" align="center">
                                  <tr>
                                    <td role="modules-container" style="padding:0px 0px 0px 0px; color:#000000; text-align:left;" bgcolor="#FFFFFF" width="100%" align="left"><table class="module preheader preheader-hide" role="module" data-type="preheader" border="0" cellpadding="0" cellspacing="0" width="100%" style="display: none !important; mso-hide: all; visibility: hidden; opacity: 0; color: transparent; height: 0; width: 0;">
<tr>
  <td role="module-content">
    <p></p>
  </td>
</tr>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:0px 0px 0px 0px;" bgcolor="#FFFFFF" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="wrapper" role="module" data-type="image" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="dae5891b-ceee-40f7-9315-02ea0b72592e">
<tbody>
  <tr>
    <td style="font-size:6px; line-height:10px; padding:0px 0px 0px 0px;" valign="top" align="center">
      <img class="max-width" border="0" style="display:block; color:#000000; text-decoration:none; font-family:Helvetica, arial, sans-serif; font-size:16px; max-width:20% !important; width:20%; height:auto !important;" width="116" alt="" data-proportionally-constrained="true" data-responsive="true" src="{$domain_url}/assets/img/brand/logo.png">
    </td>
  </tr>
</tbody>
</table></td>
    </tr>
  </tbody>
</table></td>
  </tr>
</tbody>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:10px 0px 10px 0px;" bgcolor="#FFFFFF" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="module" role="module" data-type="text" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="c09ad11e-b6f0-426b-bfb5-e854fb1d6b4e">
<tbody>
  <tr>
    <td style="padding:18px 0px 18px 0px; line-height:30px; text-align:inherit;" height="100%" valign="top" bgcolor="" role="module-content"><div><h2 style="text-align: center">{$business_name}</h2>
<div style="font-family: inherit; text-align: center">{$translations["dear"]} {$name}</div>
<div style="font-family: inherit; text-align: center">{$translations["smtpcontactcontent"]}</div>
<div style="font-family: inherit; text-align: center">{$userMessage}</div>
<div></div></div>
</td>
  </tr>
</tbody>
</table><table border="0" cellpadding="0" cellspacing="0" align="center" width="100%" role="module" data-type="columns" style="padding:0px 0px 0px 0px;" bgcolor="#252525" data-distribution="1">
<tbody>
  <tr role="module-content">
    <td height="100%" valign="top"><table width="580" style="width:580px; border-spacing:0; border-collapse:collapse; margin:0px 10px 0px 10px;" cellpadding="0" cellspacing="0" align="left" border="0" bgcolor="" class="column column-0">
  <tbody>
    <tr>
      <td style="padding:0px;margin:0px;border-spacing:0;"><table class="wrapper" role="module" data-type="image" border="0" cellpadding="0" cellspacing="0" width="100%" style="table-layout: fixed;" data-muid="70667641-28f8-4e30-850f-c1783cac6e0b">
<tbody>
  <tr>
    <td style="font-size:6px; line-height:10px; padding:0px 0px 0px 0px;" valign="top" align="center">
      <img class="max-width" border="0" style="display:block; color:#000000; text-decoration:none; font-family:Helvetica, arial, sans-serif; font-size:16px; max-width:10% !important; width:10%; height:auto !important;" width="58" alt="" data-proportionally-constrained="true" data-responsive="true" src="https://gymoneglobal.com/assets/img/text-color-logo.png">
    </td>
  </tr>
</tbody>
</table></td>
    </tr>
  </tbody>
</table></td>
  </tr>
</tbody>
</table><div data-role="module-unsubscribe" class="module" role="module" data-type="unsubscribe" style="background-color:#252525; color:#444444; font-size:12px; line-height:20px; padding:0px 0px 0px 0px; text-align:Center;" data-muid="4e838cf3-9892-4a6d-94d6-170e474d21e5"><div class="Unsubscribe--addressLine"></div><p style="font-size:12px; line-height:20px;"><a class="Unsubscribe--unsubscribeLink" href="https://gymoneglobal.com/" target="_blank" style="">Gymoneglobal.com</a></p></div></td>
                                  </tr>
                                </table>
                                <!--[if mso]>
                              </td>
                            </tr>
                          </table>
                        </center>
                        <![endif]-->
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    </div>
  </center>
</body>
</html>
EOD;

  $userConfirmationMessage = (new Swift_Message($translations["thankyouforyouremail"]))
    ->setFrom([$smtp_username => $business_name])
    ->setTo([$userEmail])
    ->setBody($editedcontent, 'text/html');

  $resultUser = $mailer->send($userConfirmationMessage);

  if ($result && $resultUser) {
    $alerts_html .= '<div class="alert alert-success" role="alert">
                            ' . $translations["successsndedemail"] . '
                        </div>';
    header("Refresh:2");
  } else {
    $alerts_html .= '<div class="alert alert-danger" role="alert">
                            ' . $translations["unexpected-error"] . '
                        </div>';
    header("Refresh:2");
  }
}
?>
<style>
    .contact-card {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .contact-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }
    .icon-circle {
        transition: transform 0.3s ease;
    }
    .contact-card:hover .icon-circle {
        transform: scale(1.1) rotate(5deg);
    }
</style>
<div class="container" style="margin-top: 120px;">
    <div class="row text-center justify-content-center mb-5">
        <div class="col-lg-8 col-md-10 mt-3">
            <h1 class="fw-bold mb-3 text-white"><i class="bi bi-envelope-paper-fill text-primary me-2"></i> <?php echo $translations["contactpage"]; ?></h1>
            <p class="lead text-white">Estamos aquí para ayudarte. Ponte en contacto con nosotros.</p>
        </div>
    </div>
    <div class="row justify-content-center g-4">
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 text-center shadow-sm border-0 rounded-4 contact-card bg-dark">
                <div class="card-body p-4">
                    <div class="d-inline-flex align-items-center justify-content-center fs-1 text-primary bg-primary bg-opacity-10 rounded-circle mb-4 icon-circle" style="width: 80px; height: 80px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-geo-alt-fill" viewBox="0 0 16 16">
                            <path d="M8 16s6-5.686 6-10A6 6 0 0 0 2 6c0 4.314 6 10 6 10m0-7a3 3 0 1 1 0-6 3 3 0 0 1 0 6"/>
                        </svg>
                    </div>
                    <h4 class="card-title fw-bold mb-3 text-white"><?php echo $translations["location"]; ?></h4>
                    <p class="card-text text-white fs-5"><?php echo $country; ?>, <?php echo $city; ?>, <?php echo $street; ?> <?php echo $hause_no; ?></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 text-center shadow-sm border-0 rounded-4 contact-card bg-dark">
                <div class="card-body p-4">
                    <a href="mailto:<?php echo $mailadress; ?>" class="d-inline-flex align-items-center justify-content-center fs-1 text-primary bg-primary bg-opacity-10 rounded-circle mb-4 icon-circle text-decoration-none" style="width: 80px; height: 80px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-envelope-at-fill" viewBox="0 0 16 16">
                            <path d="M2 2A2 2 0 0 0 .05 3.555L8 8.414l7.95-4.859A2 2 0 0 0 14 2zm-2 9.8V4.698l5.803 3.546zm6.761-2.97-6.57 4.026A2 2 0 0 0 2 14h6.256A4.5 4.5 0 0 1 8 12.5a4.49 4.49 0 0 1 1.606-3.446l-.367-.225L8 9.586zM16 9.671V4.697l-5.803 3.546.338.208A4.5 4.5 0 0 1 12.5 8c1.414 0 2.675.652 3.5 1.671"/>
                            <path d="M15.834 12.244c0 1.168-.577 2.025-1.587 2.025-.503 0-1.002-.228-1.12-.648h-.043c-.118.416-.543.643-1.015.643-.77 0-1.259-.542-1.259-1.434v-.529c0-.844.481-1.4 1.26-1.4.585 0 .87.333.953.63h.03v-.568h.905v2.19c0 .272.18.42.411.42.315 0 .639-.415.639-1.39v-.118c0-1.277-.95-2.326-2.484-2.326h-.04c-1.582 0-2.64 1.067-2.64 2.724v.157c0 1.867 1.237 2.654 2.57 2.654h.045c.507 0 .935-.07 1.18-.18v.731c-.219.1-.643.175-1.237.175h-.044C10.438 16 9 14.82 9 12.646v-.214C9 10.36 10.421 9 12.485 9h.035c2.12 0 3.314 1.43 3.314 3.034zm-4.04.21v.227c0 .586.227.8.581.8.31 0 .564-.17.564-.743v-.367c0-.516-.275-.708-.572-.708-.346 0-.573.245-.573.791"/>
                        </svg>
                    </a>
                    <h4 class="card-title fw-bold mb-3 text-white"><?php echo $translations["email"]; ?></h4>
                    <p class="card-text text-white fs-5"><a href="mailto:<?php echo $mailadress; ?>" class="text-decoration-none text-white"><?php echo $mailadress; ?></a></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 text-center shadow-sm border-0 rounded-4 contact-card bg-dark">
                <div class="card-body p-4">
                    <a href="tel:<?php echo $phoneno; ?>" class="d-inline-flex align-items-center justify-content-center fs-1 text-primary bg-primary bg-opacity-10 rounded-circle mb-4 icon-circle text-decoration-none" style="width: 80px; height: 80px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-telephone-forward-fill" viewBox="0 0 16 16">
                            <path fill-rule="evenodd" d="M1.885.511a1.745 1.745 0 0 1 2.61.163L6.29 2.98c.329.423.445.974.315 1.494l-.547 2.19a.68.68 0 0 0 .178.643l2.457 2.457a.68.68 0 0 0 .644.178l2.189-.547a1.75 1.75 0 0 1 1.494.315l2.306 1.794c.829.645.905 1.87.163 2.611l-1.034 1.034c-.74.74-1.846 1.065-2.877.702a18.6 18.6 0 0 1-7.01-4.42 18.6 18.6 0 0 1-4.42-7.009c-.362-1.03-.037-2.137.703-2.877zm10.761.135a.5.5 0 0 1 .708 0l2.5 2.5a.5.5 0 0 1 0 .708l-2.5 2.5a.5.5 0 0 1-.708-.708L14.293 4H9.5a.5.5 0 0 1 0-1h4.793l-1.647-1.646a.5.5 0 0 1 0-.708"/>
                        </svg>
                    </a>
                    <h4 class="card-title fw-bold mb-3 text-white"><?php echo $translations["fno"]; ?></h4>
                    <p class="card-text text-white fs-5"><a href="tel:<?php echo $phoneno; ?>" class="text-decoration-none text-white"><?php echo $phoneno; ?></a></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 text-center shadow-sm border-0 rounded-4 contact-card bg-dark">
                <div class="card-body p-4">
                    <a href="https://www.instagram.com/thespartanscenter?igsh=ODBubHF3aGkzZG5l" target="_blank" class="d-inline-flex align-items-center justify-content-center fs-1 text-primary bg-primary bg-opacity-10 rounded-circle mb-4 icon-circle text-decoration-none" style="width: 80px; height: 80px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-instagram" viewBox="0 0 16 16">
                            <path d="M8 0C5.829 0 5.556.01 4.703.048 3.85.088 3.269.222 2.76.42a3.917 3.917 0 0 0-1.417.923A3.927 3.927 0 0 0 .42 2.76C.222 3.268.087 3.85.048 4.7.01 5.555 0 5.827 0 8.001c0 2.172.01 2.444.048 3.297.04.852.174 1.433.372 1.942.205.526.478.972.923 1.417.444.445.89.719 1.416.923.51.198 1.09.333 1.942.372C5.555 15.99 5.827 16 8 16s2.444-.01 3.298-.048c.851-.04 1.434-.174 1.943-.372a3.916 3.916 0 0 0 1.416-.923c.445-.445.718-.891.923-1.417.197-.509.332-1.09.372-1.942C15.99 10.445 16 10.173 16 8s-.01-2.445-.048-3.299c-.04-.851-.175-1.433-.372-1.941a3.926 3.926 0 0 0-.923-1.417A3.911 3.911 0 0 0 13.24.42c-.51-.198-1.092-.333-1.943-.372C10.443.01 10.172 0 7.998 0h.003zm-.717 1.442h.718c2.136 0 2.389.007 3.232.046.78.035 1.204.166 1.486.275.373.145.64.319.92.599.28.28.453.546.598.92.11.281.24.705.275 1.485.039.843.047 1.096.047 3.231s-.008 2.389-.047 3.232c-.035.78-.166 1.203-.275 1.485a2.47 2.47 0 0 1-.599.919c-.28.28-.546.453-.92.598-.28.11-.704.24-1.485.276-.843.038-1.096.047-3.232.047s-2.39-.009-3.233-.047c-.78-.036-1.203-.166-1.485-.276a2.478 2.478 0 0 1-.92-.598 2.48 2.48 0 0 1-.6-.92c-.109-.281-.24-.705-.275-1.485-.038-.843-.047-1.096-.047-3.232 0-2.136.009-2.388.047-3.231.036-.78.166-1.204.276-1.486.145-.373.319-.64.599-.92.28-.28.546-.453.92-.598.282-.11.705-.24 1.485-.276.738-.034 1.024-.044 2.515-.045v.002zm4.988 1.328a.96.96 0 1 0 0 1.92.96.96 0 0 0 0-1.92zm-4.27 1.122a4.109 4.109 0 1 0 0 8.217 4.109 4.109 0 0 0 0-8.217zm0 1.441a2.667 2.667 0 1 1 0 5.334 2.667 2.667 0 0 1 0-5.334z"/>
                        </svg>
                    </a>
                    <h4 class="card-title fw-bold mb-3 text-white">Instagram</h4>
                    <p class="card-text text-white fs-5"><a href="https://www.instagram.com/thespartanscenter?igsh=ODBubHF3aGkzZG5l" target="_blank" class="text-decoration-none text-white">@thespartanscenter</a></p>
                </div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="card h-100 text-center shadow-sm border-0 rounded-4 contact-card bg-dark">
                <div class="card-body p-4">
                    <a href="https://www.facebook.com/share/1DrSEXpEYx/?mibextid=wwXIfr" target="_blank" class="d-inline-flex align-items-center justify-content-center fs-1 text-primary bg-primary bg-opacity-10 rounded-circle mb-4 icon-circle text-decoration-none" style="width: 80px; height: 80px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="currentColor" class="bi bi-facebook" viewBox="0 0 16 16">
                            <path d="M16 8.049c0-4.446-3.582-8.05-8-8.05C3.58 0-.002 3.603-.002 8.05c0 4.017 2.926 7.347 6.75 7.951v-5.625h-2.03V8.05H6.75V6.275c0-2.017 1.195-3.131 3.022-3.131.876 0 1.791.157 1.791.157v1.98h-1.009c-.993 0-1.303.621-1.303 1.258v1.51h2.218l-.354 2.326H9.25V16c3.824-.604 6.75-3.934 6.75-7.951"/>
                        </svg>
                    </a>
                    <h4 class="card-title fw-bold mb-3 text-white">Facebook</h4>
                    <p class="card-text text-white fs-5"><a href="https://www.facebook.com/share/1DrSEXpEYx/?mibextid=wwXIfr" target="_blank" class="text-decoration-none text-white">The Spartans Center</a></p>
                </div>
            </div>
        </div>
    </div>
    <div class="row justify-content-center mt-5">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 bg-dark">
                <div class="card-body p-5">
                    <h2 class="card-title text-center fw-bold mb-4 text-white"><?php echo $translations["contactform"]; ?></h2>
                    <?php echo $alerts_html; ?>
                    <form method="post">
                        <div class="mb-4">
                            <label for="name" class="form-label fw-semibold text-white"><?php echo $translations["fullname"]; ?>:</label>
                            <input type="text" class="form-control form-control-lg bg-light border-0" id="name" name="name" required placeholder="Tu nombre">
                        </div>
                        <div class="mb-4">
                            <label for="email" class="form-label fw-semibold text-white"><?php echo $translations["email"]; ?>:</label>
                            <input type="email" class="form-control form-control-lg bg-light border-0" id="email" name="email" required placeholder="tu@email.com">
                        </div>
                        <div class="mb-4">
                            <label for="message" class="form-label fw-semibold text-white"><?php echo $translations["message"]; ?>:</label>
                            <textarea class="form-control form-control-lg bg-light border-0" id="message" name="message" rows="5" required placeholder="¿En qué podemos ayudarte?"></textarea>
                        </div>
                        <div class="text-center mt-4">
                            <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5 fw-bold shadow-sm"><?php echo $translations["send"]; ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>


<?php include_once('../assets/includes/footer.php'); ?>
