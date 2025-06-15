<?php
$loginRequired = true;
$titlePage = "List User";
$currentPage = 'directory';
$currentSubPage = null;
$permission = 'user-view';
include_once __DIR__ . '/../_templates/header.php';
?>

<?php if (permission($permission ?? null)) { ?>
    <div class="container-fluid flex-grow-1 container-p-y">

        <h4 class="fw-bold py-3 mb-4">
            <span class="text-muted fw-light"> Directory /</span> <?= $titlePage ?>
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
                            <!-- <button type="button" class="btn btn-info btn-sm float-end me-2" onclick="addUser()" title="Refresh">
                                <i class='bx bx-plus'></i> Add New User
                            </button> -->
                            <select id="filter_user_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Status </option>
                                <option value="1"> Active </option>
                                <option value="0"> Inactive </option>
                                <option value="2"> Banned </option>
                                <option value="4"> Unverified </option>
                            </select>

                            <select id="filter_gender_status" class="form-control form-control-sm me-2 float-end" style="width: 100px;" onchange="getDataList()">
                                <option value=""> All Gender </option>
                                <option value="1"> Male </option>
                                <option value="2"> Female </option>
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
                                            <th style="color:white"> Name </th>
                                            <th style="color:white"> Email </th>
                                            <th style="color:white"> Gender </th>
                                            <th style="color:white"> Status </th>
                                            <th style="color:white"> Action </th>
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
        });

        // GET DATATABLE (SERVER-SIDE)
        async function getDataList() {
            generateDatatableServer('dataList', 'controllers/UserController.php', 'nodataDiv', {
                    'action': 'listUserDatatable',
                    'user_status_filter': $("#filter_user_status").val(),
                    'user_gender_filter': $("#filter_gender_status").val()
                },
                [{
                        "data": "name",
                        "width": "40%",
                        "targets": 0
                    },
                    {
                        "data": "email",
                        "width": "25%",
                        "targets": 1
                    },
                    {
                        "data": "gender",
                        "width": "15%",
                        "targets": 2
                    },
                    {
                        "data": "status",
                        "width": "10%",
                        "targets": 3
                    },
                    {
                        // Action
                        "data": "action",
                        "render": function(data, type, row) {
                            return data;
                        },
                        "width": "15%",
                        "targets": -1,
                        "searchable": false,
                        "orderable": false
                    }
                ], 'bodyDiv');
        }

        // function addUser() {
        //     loadFormContent('views/directory/_userForm.php', 'userForm', '500px', 'controllers/UserController.php', 'Add User', {}, 'offcanvas');
        //     // $("#generaloffcanvas-right").css("z-index", "20000");
        // }

        async function editRecord(id) {
            const res = await callApi('post', "controllers/UserController.php", {
                'action': 'show',
                'id': id
            });

            const data = res.data.data;

            if (isSuccess(res)) {
                loadFormContent('views/directory/_userForm.php', 'userForm', '500px', 'controllers/UserController.php', 'Update User', data, 'offcanvas');
                // $("#generaloffcanvas-right").css("z-index", "20000");
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

        async function updateProfile(id) {
            alert('Function not ready to update profile for ' + id);
        }
    </script>
<?php } else {
    show_403();
} ?>

<?php include_once __DIR__ . '/../_templates/footer.php' ?>