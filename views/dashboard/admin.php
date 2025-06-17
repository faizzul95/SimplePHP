<?php
$loginRequired = true;
$titlePage = "Dashboard";
$currentPage = 'dashboard';
$currentSubPage = null;
include_once __DIR__ . '/../_templates/header.php';
?>

<div class="container-fluid flex-grow-1 container-p-y">

    <h4 class="fw-bold py-3 mb-4">
        <span class="text-muted fw-light"> Dashboard </span>
    </h4>

    <div class="col-lg-12 order-2 mb-4">
        <div class="card h-100">
            <div class="card-body">
                // Any Content Here
            </div>
        </div>
    </div>

</div>

<script type="text/javascript">
    $(document).ready(async function() {

    });
</script>

<?php include_once __DIR__ . '/../_templates/footer.php' ?>