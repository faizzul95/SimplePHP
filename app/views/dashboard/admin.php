<?php includeTemplate('header'); ?>

<?php if (requirePagePermission()) : ?>
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            <?= showPageTitle() ?>
        </h4>

        <div class="col-lg-12 order-2 mb-4">

            <div class="row">
                <div class="col-sm-6 col-lg-3 mb-4">
                    <div class="card card-border-shadow-primary h-100 loadingCard">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="avatar me-4">
                                    <span class="avatar-initial rounded bg-label-info"><i class="icon-base bx bx-user-check icon-lg"></i></span>
                                </div>
                                <h4 class="mb-0" id="userActive">0</h4>
                            </div>
                            <p class="mb-2">Active</p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-4">
                    <div class="card card-border-shadow-warning h-100 loadingCard">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="avatar me-4">
                                    <span class="avatar-initial rounded bg-label-warning"><i class="icon-base bx bx-user-x icon-lg"></i></span>
                                </div>
                                <h4 class="mb-0" id="userInactive">0</h4>
                            </div>
                            <p class="mb-2">Inactive</p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-4">
                    <div class="card card-border-shadow-danger h-100 loadingCard">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="avatar me-4">
                                    <span class="avatar-initial rounded bg-label-danger"><i class="icon-base bx bx-user-minus icon-lg"></i></span>
                                </div>
                                <h4 class="mb-0" id="userSuspended">0</h4>
                            </div>
                            <p class="mb-2">Banned/Suspended</p>
                        </div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3 mb-4">
                    <div class="card card-border-shadow-info h-100 loadingCard">
                        <div class="card-body">
                            <div class="d-flex align-items-center mb-2">
                                <div class="avatar me-4">
                                    <span class="avatar-initial rounded bg-label-primary"><i class="icon-base bx bx-time-five icon-lg"></i></span>
                                </div>
                                <h4 class="mb-0" id="userNotVerify">0</h4>
                            </div>
                            <p class="mb-2">Not Verify</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card h-100 mt-2">
                <div class="card-body">
                    // Any Content Here
                </div>
            </div>
        </div>

    </div>

    <script type="text/javascript">
        $(document).ready(async function() {
            loading('.loadingCard', true);
            await getDashboardData();
            loading('.loadingCard', false);

        });

        async function getDashboardData() {

            const res = await callApi('post', "controllers/DashboardController.php", {
                'action': 'countAdminDashboard'
            });

            if (isSuccess(res)) {
                const data = res.data.data;
                $('#userActive').text(data.userActive);
                $('#userInactive').text(data.userInactive);
                $('#userSuspended').text(data.userSuspended);
                $('#userNotVerify').text(data.userNotVerify);
            }
        }
    </script>
<?php endif; ?>

<?php includeTemplate('footer'); ?>