<!DOCTYPE html>

<!-- =========================================================
* Sneat - Bootstrap 5 HTML Admin Template - Pro | v1.0.0
==============================================================

* Product Page: https://themeselection.com/products/sneat-bootstrap-html-admin-template/
* Created by: ThemeSelection
* License: You must have a valid license purchased in order to legally use the theme for your project.
* Copyright ThemeSelection (https://themeselection.com)

=========================================================
 -->
<!-- beautify ignore:start -->
<html
    lang="en"
    class="light-style"
    dir="ltr"
    data-theme="theme-default"
    data-assets-path="../assets/"
    data-template="vertical-menu-template-free">

<head>
    <meta charset="utf-8" />
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />

    <title><?= APP_NAME ?> | <?= $title; ?> </title>

    <base href="<?= BASE_URL ?>">
    <meta name="base_url" content="<?= BASE_URL ?>" />
    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?= asset('sneat/assets/img/favicon/favicon.ico'); ?>" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

    <!-- Icons. Uncomment required icon fonts -->
    <link rel="stylesheet" href="<?= asset('sneat/assets/fonts/boxicons.css'); ?>" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="<?= asset('sneat/assets/css/core.css'); ?>" class="template-customizer-core-css" />
    <link rel="stylesheet" href="<?= asset('sneat/assets/css/theme-default.css'); ?>" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="<?= asset('sneat/assets/css/demo.css'); ?>" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="<?= asset('sneat/assets/libs/perfect-scrollbar/perfect-scrollbar.css'); ?>" />

    <!-- Page CSS -->
    <!-- Page -->
    <link rel="stylesheet" href="<?= asset('sneat/assets/css/pages/page-misc.css'); ?>" />

    <!-- Helpers -->
    <script src="<?= asset('sneat/assets/js/helpers.js'); ?>"></script>

    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->
    <script src="<?= asset('sneat/assets/js/config.js'); ?>"></script>
</head>

<body>
    <!-- Content -->

    <!-- Error -->
    <div class="container-xxl container-p-y">
        <div class="misc-wrapper">
            <h2 class="mb-2 mx-2">Page Not Found :(</h2>
            <p class="mb-4 mx-2"><?= $message; ?></p>
            <a href="javascript:void(0)" onclick="window.history.back();" class="btn btn-primary">Back to Previous Page</a>
            <div class="mt-3">
                <img
                    src="<?= asset($image); ?>"
                    alt="page-misc-error-light"
                    width="500"
                    class="img-fluid"
                    data-app-dark-img="illustrations/page-misc-error-dark.png"
                    data-app-light-img="illustrations/page-misc-error-light.png" />
            </div>
        </div>
    </div>
    <!-- /Error -->

    <!-- / Content -->

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
    <script src="<?= asset('sneat/assets/libs/jquery/jquery.js'); ?>"></script>
    <script src="<?= asset('sneat/assets/libs/popper/popper.js'); ?>"></script>
    <script src="<?= asset('sneat/assets/js/bootstrap.js'); ?>"></script>
    <script src="<?= asset('sneat/assets/libs/perfect-scrollbar/perfect-scrollbar.js'); ?>"></script>

    <script src="<?= asset('sneat/assets/js/menu.js'); ?>"></script>
    <!-- endbuild -->

    <!-- Vendors JS -->

    <!-- Main JS -->
    <script src="<?= asset('sneat/assets/js/main.js'); ?>"></script>

    <!-- Page JS -->

</body>

</html>