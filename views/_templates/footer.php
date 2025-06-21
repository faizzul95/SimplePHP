<div class="content-backdrop fade"></div>
</div>
<!-- Content wrapper -->

</div>
<!-- / Layout page -->
</div>

<!-- Overlay -->
<div class="layout-overlay layout-menu-toggle"></div>
</div>
<!-- / Layout wrapper -->

<!-- Core JS -->
<!-- build:js assets/vendor/js/core.js -->
<script src="<?= asset('sneat/assets/libs/popper/popper.js'); ?>"></script>
<script src="<?= asset('sneat/assets/js/bootstrap.js'); ?>"></script>
<script src="<?= asset('sneat/assets/libs/perfect-scrollbar/perfect-scrollbar.js'); ?>"></script>

<script src="<?= asset('sneat/assets/js/menu.js'); ?>"></script>
<!-- endbuild -->

<!-- Vendors JS -->

<!-- Main JS -->
<script src="<?= asset('sneat/js/main.js'); ?>"></script>

<!-- Page JS -->
<script type="text/javascript">
    $(document).ready(function() {
        clock();
    });

    function clock() {
        let today = new Date().toLocaleDateString('en-GB', {
            month: '2-digit',
            day: '2-digit',
            year: 'numeric'
        });

        $("#currentTime").html(getClock('12', 'en', true) + ' | ' + today);
        setTimeout(clock, 1000);
    }

    async function signOut() {
        if (confirm("Are you sure you want to logout?")) {
            const res = await callApi('post', 'controllers/AuthController.php', {
                'action': 'logout'
            });

            const resCode = parseInt(res.data.code);
            noti(resCode, res.data.message);

            if (isSuccess(resCode)) {
                setTimeout(function() {
                    window.location.href = res.data.redirectUrl;
                }, 650);
            }
        }
    }

    async function updateCropperPhoto(modalTitle = null, id = null, entityId = null, entityFileType = null, entityType = null, currentImage = null, reloadFunction = null, folderGroup = 'unknown', folderType = 'unknown') {
        const data = {
            'id': id,
            'entity_id': entityId,
            'entity_type': entityType,
            'entity_file_type': entityFileType,
            'url': 'controllers/UploadController.php',
            'imagePath': currentImage,
            'reloadFunction': reloadFunction,
            'folder_group': folderGroup,
            'folder_type': folderType
        };

        loadFileContent('views/_templates/_uploadImageCropperModal.php', 'generalContent', '450px', modalTitle, data, 'offcanvas');
    }
</script>

<?= include '_modalGeneral.php' ?>

</body>

</html>