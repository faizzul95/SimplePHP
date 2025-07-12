<?php
include_once __DIR__ . '/../_templates/header.php';
?>

<?php if (requirePagePermission()) : ?>
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            <?= showPageTitle() ?>
        </h4>

        <div class="col-lg-12 order-2 mb-4">
            <div class="card h-100">
                <div class="card-body">
                    <!-- FILTER -->
                    <div class="row">
                        <div class="col-xl-12 mb-4">
                            <button type="button" class="btn btn-warning btn-sm float-end" onclick="getDataList()" title="Refresh">
                                <i class='bx bx-refresh'></i>
                            </button>

                            <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="addUser()" title="Refresh">
                                <i class='bx bx-plus'></i> Add New User
                            </button>

                            <select id="filter_user_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Status </option>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                                <option value="2"> Banned </option>
                                <option value="4"> Unverified </option>
                            </select>

                            <!-- <select id="filter_deleted_user" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All User </option>
                                <option value="1"> Deleted User </option>
                            </select> -->

                            <select id="filter_gender_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Gender </option>
                                <option value="1"> Male </option>
                                <option value="2"> Female </option>
                            </select>

                            <select id="filter_profile" class="form-control form-control-sm me-2 float-end" style="width: 180px;" onchange="getDataList()">
                                <option value=""> All Profiles </option>
                            </select>
                        </div>
                    </div>

                    <!-- DATATABLE -->
                    <div id="bodyDiv" class="row">
                        <div class="col-xl-12 mb-4">
                            <div id="nodataDiv" style="display: none;"> <?= nodata() ?> </div>
                            <div id="dataListDiv" class="table-responsive" style="display: block;">
                                <table id="dataList" class="table table-responsive table-hover table-striped table-bordered collapsed nowrap" width="100%">
                                    <thead class="table-dark">
                                        <tr>
                                            <th style="color:white"> Avatar </th>
                                            <th style="color:white"> Name </th>
                                            <th style="color:white"> Contact Information </th>
                                            <th style="color:white"> Gender </th>
                                            <th style="color:white"> Status </th>
                                            <th style="color:white"> # </th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>

    </div>

    <script type="text/javascript">
        $(document).ready(async function() {
            await getDataList();
            await getProfileList('filter_profile');
        });

        async function getProfileList(id, includeAll = true) {
            const res = await callApi('post', "controllers/RoleController.php", {
                'action': 'listSelectOptionRole'
            });

            if (isSuccess(res)) {

                const data = res.data.data;

                $("#" + id).empty();

                if (includeAll) {
                    $("#" + id).append('<option value=""> All Profiles </option>');
                } else {
                    $("#" + id).append('<option value=""> - Select - </option>');
                }

                data.forEach(function(item) {
                    $("#" + id).append('<option value="' + item.id + '">' + item.role_name + '</option>');
                });
            }
        }

        // GET DATATABLE (SERVER-SIDE)
        async function getDataList() {
            generateDatatableServer('dataList', 'controllers/UserController.php', 'nodataDiv', {
                    'action': 'listUserDatatable',
                    'user_status_filter': $("#filter_user_status").val(),
                    'user_gender_filter': $("#filter_gender_status").val(),
                    'user_profile_filter': $("#filter_profile").val(),
                    'user_deleted_filter': $("#filter_deleted_user").val()
                },
                [{
                        "data": "avatar",
                        "width": "5%",
                        "targets": 0
                    },
                    {
                        "data": "name",
                        // "width": "40%",
                        "targets": 1
                    },
                    {
                        "data": "contact",
                        "width": "35%",
                        "targets": 2
                    },
                    {
                        "data": "gender",
                        "width": "8%",
                        "targets": 3
                    },
                    {
                        "data": "status",
                        "width": "7%",
                        "targets": 4
                    },
                    {
                        // Action
                        "data": "action",
                        "render": function(data, type, row) {
                            return data;
                        },
                        "targets": -1,
                        "width": "3%",
                        "searchable": false,
                        "orderable": false
                    }
                ], 'bodyDiv');
        }

        function addUser() {
            loadFormContent('views/directory/_userForm.php', 'userForm', '550px', 'controllers/UserController.php', 'Add User', {}, 'offcanvas');
        }

        async function editRecord(id) {
            const res = await callApi('post', "controllers/UserController.php", {
                'action': 'show',
                'id': id
            });

            if (isSuccess(res)) {
                loadFormContent('views/directory/_userForm.php', 'userForm', '550px', 'controllers/UserController.php', 'Update User', res.data.data, 'offcanvas');
            }
        }

        async function deleteRecord(id) {
            Swal.fire({
                title: 'Are you sure?',
                html: 'You won\'t be able to revert this action!<br><strong>This item will be permanently deleted.</strong>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Remove it!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const res = await callApi('post', "controllers/UserController.php", {
                        'action': 'destroy',
                        'id': id
                    });

                    if (isSuccess(res)) {
                        const response = res.data;
                        noti(response.code, response.message);

                        getDataList(); // reload
                    }
                }
            })
        }

        async function resetPassword(id) {
            Swal.fire({
                title: 'Are you sure?',
                html: 'This will reset the user\'s password. The user will need to change their password upon next login.<br><br><strong>Do you want to continue?</strong>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, Reset NOW!',
                reverseButtons: true,
                customClass: {
                    container: 'swal2-customCss'
                },
            }).then(async (result) => {
                if (result.isConfirmed) {
                    const res = await callApi('post', "controllers/AuthController.php", {
                        'action': 'resetPassword',
                        'id': id
                    });

                    if (isSuccess(res)) {
                        const response = res.data;
                        noti(response.code, response.message);

                        getDataList(); // reload
                    }
                }
            })
        }
    </script>
<?php endif; ?>

<?php include_once __DIR__ . '/../_templates/footer.php' ?>