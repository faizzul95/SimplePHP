<div class="container-xxl d-flex flex-column justify-content-center align-items-center min-vh-100 text-center">
    <h1 class="display-1 fw-bold mb-3"><?= $title; ?></h1>
    <h4 class="mb-4"><?= $message; ?></h4>
    
    <div class="mt-3">
        <img 
            src="<?= asset($image); ?>" 
            alt="403 error illustration" 
            class="img-fluid" 
            style="max-width: 500px;"
        />
    </div>
</div>